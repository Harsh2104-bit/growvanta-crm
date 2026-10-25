/**
 * HTTP entry point. Kept separate from app.js so the test suite can import the
 * application without binding a port.
 */

import config from './config/index.js';
import { createApp } from './app.js';
import { closePool, dbScalar } from './lib/db.js';

const app = createApp();

const server = app.listen(config.port, async () => {
  console.log(`[server] Growvanta CRM API listening on http://localhost:${config.port} (${config.env})`);
  try {
    await dbScalar('SELECT 1');
    console.log(`[server] database ${config.db.user}@${config.db.host}:${config.db.port}/${config.db.database} reachable`);
  } catch (err) {
    // Not fatal: the process stays up so /api/health can report "degraded"
    // and a container orchestrator sees a live port with a failing check,
    // which is far easier to diagnose than a crash loop.
    console.error('[server] database unreachable at boot:', err.message);
  }
});

async function shutdown(signal) {
  console.log(`[server] ${signal} received, shutting down`);
  server.close(async () => {
    await closePool();
    process.exit(0);
  });
  // Don't let a hung connection hold the process open forever.
  setTimeout(() => process.exit(1), 10_000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

export default server;
