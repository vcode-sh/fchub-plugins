import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const workflow = readFileSync(new URL('./release.yml', import.meta.url), 'utf8');
const alwaysCondition = /if:\s*['"]?\s*(?:\$\{\{\s*)?always\s*\(\s*\)(?:\s*\}\})?\s*['"]?\s*(?:#.*)?$/m;

for (const equivalent of ['if: always()', 'if: ${{ always() }}', 'if: "${{ always() }}"']) {
  assert.match(equivalent, alwaysCondition, `always condition matcher must recognise: ${equivalent}`);
}

const step = (name) => {
  const marker = `      - name: ${name}`;
  const start = workflow.indexOf(marker);
  assert.notEqual(start, -1, `missing release step: ${name}`);

  const next = workflow.indexOf('\n      - name:', start + marker.length);
  return {
    start,
    content: workflow.slice(start, next === -1 ? workflow.length : next),
  };
};

const membershipsGate = (name, command) => {
  const current = step(name);
  assert.match(
    current.content,
    /if: steps\.tag\.outputs\.slug == 'fchub-memberships'/,
    `${name} must only run for fchub-memberships releases`,
  );
  assert.match(current.content, command, `${name} must execute its required gate`);
  assert.doesNotMatch(current.content, /continue-on-error:\s*true/, `${name} must fail the job`);
  assert.doesNotMatch(current.content, alwaysCondition, `${name} must not bypass an earlier failure`);
  return current.start;
};

const headerValidation = step('Validate version in plugin header');
const versionsValidation = step('Check versions.json consistency');
const phpSetup = step('Setup PHP (fchub-memberships)');
assert.match(
  phpSetup.content,
  /if: steps\.tag\.outputs\.slug == 'fchub-memberships'/,
  'PHP 8.4 setup must only run for Memberships releases',
);
assert.match(phpSetup.content, /shivammathur\/setup-php@v2/);
assert.match(
  phpSetup.content,
  /php-version: '8\.4'/,
  'PHPUnit 13 requires PHP 8.4 or newer in the release runner',
);
assert.doesNotMatch(
  versionsValidation.content,
  /continue-on-error:\s*true/,
  'versions.json mismatch must fail the release job',
);
assert.match(
  versionsValidation.content,
  /::error::versions\.json has/,
  'versions.json mismatch must be reported as an error',
);
assert.match(
  versionsValidation.content,
  /exit 1/,
  'versions.json mismatch must stop the release job',
);

const orderedSteps = [
  ['Validate version in plugin header', headerValidation.start],
  ['Check versions.json consistency', versionsValidation.start],
  ['Setup PHP (fchub-memberships)', phpSetup.start],
  ['Install Composer dependencies (fchub-memberships)', membershipsGate('Install Composer dependencies (fchub-memberships)', /composer install/)],
  ['Audit Composer dependencies (fchub-memberships)', membershipsGate('Audit Composer dependencies (fchub-memberships)', /composer audit --locked --no-interaction/)],
  ['Run PHPUnit (fchub-memberships)', membershipsGate('Run PHPUnit (fchub-memberships)', /\.\/vendor\/bin\/phpunit/)],
  ['Install npm dependencies (fchub-memberships)', membershipsGate('Install npm dependencies (fchub-memberships)', /npm ci/)],
  ['Audit npm dependencies (fchub-memberships)', membershipsGate('Audit npm dependencies (fchub-memberships)', /npm audit/)],
  ['Run Vitest (fchub-memberships)', membershipsGate('Run Vitest (fchub-memberships)', /npm run test(?:\s|$)/)],
  ['Run Playwright (fchub-memberships)', membershipsGate('Run Playwright (fchub-memberships)', /npm run test:smoke/)],
  ['Build assets (fchub-memberships)', step('Build assets (fchub-memberships)').start],
  ['Build ZIP', step('Build ZIP').start],
];

for (let index = 1; index < orderedSteps.length; index += 1) {
  const [previousName, previousIndex] = orderedSteps[index - 1];
  const [currentName, currentIndex] = orderedSteps[index];
  assert.ok(previousIndex < currentIndex, `${previousName} must precede ${currentName}`);
}

for (const artifactStep of ['Build assets (fchub-memberships)', 'Build ZIP', 'Create GitHub Release']) {
  const artifact = step(artifactStep);
  assert.doesNotMatch(artifact.content, alwaysCondition, `${artifactStep} must not bypass failed validation`);
}

console.log('Memberships release artifact gates contract passed.');
