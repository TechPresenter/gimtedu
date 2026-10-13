import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Dev:   npm run dev  (Vite on :5173, proxies /api, /uploads, /assets to the PHP server - GIMT_API_TARGET, default http://127.0.0.1:8000)
 *        open http://localhost:5173/admin/
 * Build: npm run build  -> ../admin/build (served by admin/index.php, no Node needed in production)
 */
export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.GIMT_API_TARGET || process.env.GIMT_API_TARGET || 'http://127.0.0.1:8000';
  return {
    plugins: [react()],
    base: command === 'build' ? './' : '/admin/',
    resolve: {
      alias: { '@': path.resolve(__dirname, 'src') },
    },
    server: {
      port: Number(env.VITE_PORT || process.env.VITE_PORT || 5173),
      strictPort: false,
      host: '127.0.0.1',
      // index.css loads the self-hosted fonts from ../assets/fonts
      fs: { allow: [path.resolve(__dirname, '..')] },
      proxy: {
        '/api': { target, changeOrigin: false },
        '/assets': { target },
        '/admin/print': { target },
      },
    },
    build: {
      outDir: '../admin/build',
      emptyOutDir: true,
      manifest: true,
      sourcemap: false,
      chunkSizeWarningLimit: 900,
      rollupOptions: {
        output: {
          manualChunks: {
            react: ['react', 'react-dom', 'react-router-dom'],
            query: ['@tanstack/react-query'],
            charts: ['chart.js', 'react-chartjs-2'],
          },
        },
      },
    },
  };
});
