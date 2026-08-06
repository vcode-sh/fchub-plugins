import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = fileURLToPath(new URL('../', import.meta.url));
const pluginRoot = `${repositoryRoot}/plugins/fchub-memberships`;
const developerReference =
  `${repositoryRoot}/web-docs/content/docs/fchub-memberships/developer-reference.mdx`;
const runtimeRegistrar =
  `${pluginRoot}/app/Modules/Runtime/FluentCartRuntimeModule.php`;

function normaliseRoute(route) {
  return route.replace(/\(\?P<([^>]+)>[^)]+\)/g, '{$1}');
}

function routeKey(method, route) {
  return `${method.trim().toUpperCase()} ${normaliseRoute(route.trim())}`;
}

function expandDynamicRoutes(route, source, file) {
  let routes = [route.replaceAll('{$idPattern}', '{id}')];
  const variables = new Set(
    [...route.matchAll(/\{\$(\w+)\}/g)]
      .map((match) => match[1])
      .filter((name) => name !== 'idPattern'),
  );

  for (const variable of variables) {
    const loop = source.match(
      new RegExp(`foreach\\s*\\(\\s*\\[([^\\]]+)\\]\\s+as\\s+\\$${variable}\\s*\\)`),
    );
    assert.ok(loop, `Dynamic route values for $${variable} missing in ${file}`);

    const values = [...loop[1].matchAll(/['"]([^'"]+)['"]/g)]
      .map((match) => match[1]);
    assert.ok(values.length > 0, `Dynamic route values for $${variable} are empty in ${file}`);

    routes = routes.flatMap((candidate) =>
      values.map((value) => candidate.replaceAll(`{$${variable}}`, value))
    );
  }

  for (const candidate of routes) {
    assert.doesNotMatch(candidate, /\{\$\w+\}/, `Unexpanded dynamic route in ${file}`);
  }

  return routes;
}

function registeredControllerFiles() {
  const registrar = readFileSync(runtimeRegistrar, 'utf8');
  const classes = [
    ...registrar.matchAll(/\\(FChubMemberships\\[^:\s]+)::registerRoutes\(\);/g),
  ].map((match) => match[1]);

  return classes.map((className) =>
    `${pluginRoot}/app/${className.replace(/^FChubMemberships\\/, '').replaceAll('\\', '/')}.php`
  );
}

function registrationCalls(source, file) {
  const calls = [];
  let cursor = 0;

  while ((cursor = source.indexOf('register_rest_route', cursor)) !== -1) {
    const start = source.indexOf('(', cursor);
    let depth = 0;
    let quote = null;
    let escaped = false;
    let end = start;

    for (; end < source.length; end += 1) {
      const character = source[end];
      if (quote !== null) {
        if (escaped) {
          escaped = false;
        } else if (character === '\\') {
          escaped = true;
        } else if (character === quote) {
          quote = null;
        }
        continue;
      }
      if (character === "'" || character === '"') {
        quote = character;
      } else if (character === '(') {
        depth += 1;
      } else if (character === ')') {
        depth -= 1;
        if (depth === 0) {
          break;
        }
      }
    }

    assert.notEqual(end, source.length, `Unclosed register_rest_route() call in ${file}`);
    calls.push(source.slice(cursor, end + 1));
    cursor = end + 1;
  }

  return calls;
}

function registeredRoutePairs() {
  const pairs = new Set();

  for (const file of registeredControllerFiles()) {
    const source = readFileSync(file, 'utf8');
    for (const call of registrationCalls(source, file)) {
      const route = call.match(
        /register_rest_route\([^,]+,\s*['"]([^'"]+)['"]/,
      )?.[1];
      assert.ok(route, `Route path missing from registration in ${file}`);

      const methodDeclarations = [
        ...call.matchAll(/['"]methods['"]\s*=>\s*['"]([^'"]+)['"]/g),
      ];
      assert.ok(methodDeclarations.length > 0, `Route methods missing for ${route} in ${file}`);

      for (const expandedRoute of expandDynamicRoutes(route, source, file)) {
        for (const declaration of methodDeclarations) {
          for (const method of declaration[1].split(',')) {
            pairs.add(routeKey(method, expandedRoute));
          }
        }
      }
    }
  }

  return pairs;
}

function documentedRoutePairs() {
  const document = readFileSync(developerReference, 'utf8');
  const pairs = new Set();

  for (const line of document.split(/\r?\n/)) {
    const columns = line.split('|').map((column) => column.trim());
    if (columns.length < 4 || !/^(?:GET|POST|PUT|PATCH|DELETE)(?:\s*,\s*(?:GET|POST|PUT|PATCH|DELETE))*$/.test(columns[1])) {
      continue;
    }
    const route = columns[2].match(/^`(\/[^`]+)`$/)?.[1];
    if (!route) {
      continue;
    }
    for (const method of columns[1].split(',')) {
      pairs.add(routeKey(method, route));
    }
  }

  return pairs;
}

function compareRoutePairs(registered, documented) {
  return {
    undocumented: [...registered].filter((pair) => !documented.has(pair)).sort(),
    stale: [...documented].filter((pair) => !registered.has(pair)).sort(),
  };
}

test('developer reference matches every registered route method and path', () => {
  const registered = registeredRoutePairs();
  const documented = documentedRoutePairs();
  const difference = compareRoutePairs(registered, documented);

  assert.deepEqual(difference, { undocumented: [], stale: [] });
  assert.equal(registered.size, 99);
});

test('route comparison rejects documented pairs absent from registration', () => {
  const difference = compareRoutePairs(
    new Set(['GET /real']),
    new Set(['GET /real', 'POST /stale']),
  );

  assert.deepEqual(difference, {
    undocumented: [],
    stale: ['POST /stale'],
  });
});
