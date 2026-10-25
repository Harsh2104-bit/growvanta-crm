/**
 * Express application.
 *
 * The route table mirrors the surface the client already calls:
 *
 *   /api/health            - no auth
 *   /api/public/*          - public lead-capture surface, its own rate limit
 *   /api/v1/*              - API-key authenticated external ingestion
 *   /api/auth/*            - session issuing; rate limited, CSRF-protected
 *   /webhooks/*            - signed third-party callbacks (Meta); no session
 *   everything else        - requires a session, then a per-module permission
 */

import express from 'express';
import cookieParser from 'cookie-parser';
import cors from 'cors';

import config from './config/index.js';
import { dbScalar } from './lib/db.js';
import { jsonOk } from './lib/response.js';
import {
  ah,
  apiRateLimit,
  attachActor,
  csrfProtect,
  errorHandler,
  issueCsrfCookie,
  notFoundHandler,
  requireAuth,
  securityHeaders,
} from './middleware/index.js';
import authRoutes from './routes/auth.js';

export function createApp() {
  const app = express();

  // Behind nginx/PM2 the client IP arrives in X-Forwarded-For; rate limiting
  // and audit rows are worthless without it.
  app.set('trust proxy', true);
  app.disable('x-powered-by');

  app.use(
    cors({
      origin(origin, callback) {
        // Same-origin and non-browser callers (curl, Meta's webhook) send no
        // Origin header at all; they are not what CORS is protecting.
        if (!origin) return callback(null, true);
        if (config.corsOrigins.includes(origin)) return callback(null, true);
        return callback(null, false);
      },
      credentials: true,
      allowedHeaders: ['Content-Type', 'Authorization', 'X-CSRF-Token', 'X-API-Key'],
      exposedHeaders: ['Content-Disposition'],
    })
  );

  app.use(express.json({ limit: '2mb' }));
  app.use(express.urlencoded({ extended: false, limit: '2mb' }));
  app.use(cookieParser());
  app.use(securityHeaders);

  // -------------------------------------------------------------------------
  // Unauthenticated
  // -------------------------------------------------------------------------
  app.get(
    '/api/health',
    ah(async (req, res) => {
      let dbOk = true;
      try {
        await dbScalar('SELECT 1');
      } catch {
        dbOk = false;
      }
      return jsonOk(res, {
        status: dbOk ? 'ok' : 'degraded',
        database: dbOk ? 'up' : 'down',
        environment: config.env,
        time: new Date().toISOString(),
      });
    })
  );

  // -------------------------------------------------------------------------
  // Session-bearing surface
  // -------------------------------------------------------------------------
  const guarded = express.Router();
  guarded.use(apiRateLimit);
  guarded.use(issueCsrfCookie);
  guarded.use(attachActor);
  guarded.use(csrfProtect);

  // `requireAuth` is applied per-module rather than to the whole router
  // because /auth/login has to be reachable without a session.
  guarded.use('/auth', authRoutes);

  app.use('/api', guarded);

  app.use(notFoundHandler);
  app.use(errorHandler);

  return app;
}

export default createApp;
