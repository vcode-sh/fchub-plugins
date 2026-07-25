/**
 * The smallest static host that can serve a plugin directory over HTTP.
 *
 * Deliberately not the Vite dev server, which is what fchub-memberships uses.
 * Vite would rewrite the HTML to inject `/@vite/client`, open an HMR socket,
 * and transform every module it serves — and the one thing this suite has to
 * prove is that the *built* bundle behaves in a browser. A host that quietly
 * hands the browser development modules cannot prove anything about the ZIP.
 *
 * Zero dependencies on purpose: `npm run test:smoke` has to stay fast, offline,
 * and free of Docker, and adding a static-server package to achieve forty lines
 * of `createServer` would be a poor trade.
 *
 * Run it directly if you want to look at the thing yourself:
 *
 *   node smoke/server.js
 *   open http://127.0.0.1:4173/smoke/index.html?fixture=incompatible
 */
import { createReadStream, realpathSync, statSync } from 'node:fs'
import { createServer } from 'node:http'
import { extname, join, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * The plugin directory. Nothing above it is reachable — and that is enforced
 * twice, because one check is not enough. resolve() flattens `..` lexically,
 * which a symlink pointing out of the tree walks straight past; realpathSync()
 * follows the link and the second check catches it.
 */
const ROOT = realpathSync(resolve(fileURLToPath(new URL('..', import.meta.url))))

const PORT = Number(process.env.FCHUB_SMOKE_PORT || 4173)
const HOST = '127.0.0.1'

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
  '.png': 'image/png',
}

function send(response, status, body, type = 'text/plain; charset=utf-8') {
  response.writeHead(status, { 'Content-Type': type, 'Cache-Control': 'no-store' })
  response.end(body)
}

const server = createServer((request, response) => {
  const url = new URL(request.url, `http://${HOST}:${PORT}`)
  const pathname = decodeURIComponent(url.pathname)

  // Chromium asks for this unprompted, and a 404 lands in the console listeners
  // the suite watches for genuine errors.
  if (pathname === '/favicon.ico') {
    response.writeHead(204)
    response.end()

    return
  }

  // There is no WordPress here. If the interface ever reaches this, its fetch
  // stub failed to install, and a loud JSON failure is far easier to diagnose
  // than a 404 page arriving where an envelope was expected.
  if (pathname.startsWith('/wp-json/')) {
    send(
      response,
      502,
      JSON.stringify({
        success: false,
        code: 'smoke_no_backend',
        message: 'The smoke host has no WordPress behind it. The fixture stub did not install.',
        product: null,
      }),
      TYPES['.json'],
    )

    return
  }

  // Which checkout this host is serving. Playwright waits on this URL, so a
  // stale server from another clone left running on this port answers 404 and
  // fails the wait by name — rather than being adopted silently and mismatching
  // every screenshot for no visible reason.
  if (pathname === '/__smoke/identity') {
    send(response, 200, JSON.stringify({ root: ROOT, pid: process.pid }), TYPES['.json'])

    return
  }

  const target = resolve(join(ROOT, pathname === '/' ? '/smoke/index.html' : pathname))

  // resolve() has already flattened any `..`, so a path that no longer starts
  // at ROOT was trying to leave it.
  if (target !== ROOT && !target.startsWith(ROOT + sep)) {
    send(response, 403, 'Outside the plugin directory.')

    return
  }

  let real
  let stats

  try {
    // Symlinks resolved before the second containment check, because a link
    // inside the tree pointing outside it survives the lexical one intact.
    real = realpathSync(target)
    stats = statSync(real)
  } catch {
    send(response, 404, `Not here: ${pathname}`)

    return
  }

  if (real !== ROOT && !real.startsWith(ROOT + sep)) {
    send(response, 403, 'Leaves the plugin directory through a symlink.')

    return
  }

  if (!stats.isFile()) {
    send(response, 404, `Not a file: ${pathname}`)

    return
  }

  const stream = createReadStream(real)

  // Headers are written on `open`, so a file that cannot be read — removed
  // mid-run, EACCES, a full descriptor table — still gets a named 500. Without
  // this handler the stream throws, takes the host down, and every remaining
  // test fails on a connection reset instead of a sentence.
  stream.once('open', () => {
    response.writeHead(200, {
      'Content-Type': TYPES[extname(real)] || 'application/octet-stream',
      'Content-Length': stats.size,
      'Cache-Control': 'no-store',
    })

    stream.pipe(response)
  })

  stream.once('error', (error) => {
    process.stderr.write(`FCHub smoke host: cannot read ${pathname} — ${error.message}\n`)

    if (response.headersSent) {
      response.destroy(error)

      return
    }

    send(response, 500, `Could not read ${pathname}: ${error.code || 'unknown'}`)
  })
})

// EADDRINUSE is the one startup failure worth naming: it means something else
// already holds the port, which is exactly the situation reuseExistingServer
// exists to survive and the identity route exists to detect.
server.on('error', (error) => {
  process.stderr.write(`FCHub smoke host: could not listen on ${HOST}:${PORT} — ${error.message}\n`)
  process.exitCode = 1
})

server.listen(PORT, HOST, () => {
  process.stdout.write(`FCHub smoke host on http://${HOST}:${PORT}/smoke/index.html\n`)
})
