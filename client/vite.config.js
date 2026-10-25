/**
 * Vite configuration.
 *
 * `/api` and `/webhooks` are proxied to the Express server so the browser sees
 * one origin in development. That matters for more than convenience: the
 * session and CSRF cookies are SameSite=Lax, so a cross-origin XHR from
 * :5173 to :4000 would never send them and every request would look signed
 * out. In production the same shape is produced by nginx serving `dist/` and
 * forwarding `/api` to the Node process.
 */
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_API_PROXY || 'http://127.0.0.1:4000';

  return {
    plugins: [react()],
    server: {
      port: Number(env.VITE_PORT || 5173),
      strictPort: true,
      proxy: {
        '/api': { target, changeOrigin: false },
        '/webhooks': { target, changeOrigin: false },
        '/uploads': { target, changeOrigin: false },
      },
    },
    build: {
      outDir: 'dist',
      sourcemap: mode !== 'production',
    },
  };
});
