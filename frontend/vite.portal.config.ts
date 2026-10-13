import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';
import path from 'node:path';

/**
 * STUDENT & FACULTY PORTAL SPA (/portal/) - separate from the admin SPA (vite.config.ts) and the website bundle
 * (vite.site.config.ts). Source: frontend/portal (imports the shared admin UI kit through the '@/...' alias).
 *
 *   Dev:   npm run dev:portal    -> http://127.0.0.1:5175/portal/  (proxies /api and /assets to the PHP server :8000)
 *   Build: npm run build:portal  -> ../portal/build (manifest at portal/build/.vite/manifest.json, read by portal/index.php)
 *   Types: npm run typecheck:portal
 */
export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, __dirname, '');
  const target = env.GIMT_API_TARGET || process.env.GIMT_API_TARGET || 'http://127.0.0.1:8000';
  return {
    root: path.resolve(__dirname, 'portal'),
    base: command === 'build' ? './' : '/portal/',
    plugins: [react()],
    // Own dependency cache so this server never fights the admin (:5173) / website (:5174) servers over node_modules/.vite
    cacheDir: path.resolve(__dirname, 'node_modules/.vite-portal'),
    resolve: {
      alias: {
        '@portal': path.resolve(__dirname, 'portal'),
        '@': path.resolve(__dirname, 'src'),
      },
    },
    css: {
      postcss: { plugins: [tailwindcss({ config: path.resolve(__dirname, 'portal/tailwind.config.cjs') }), autoprefixer()] },
    },
    optimizeDeps: {
      entries: [path.resolve(__dirname, 'portal/main.tsx')],
      include: ['react', 'react-dom', 'react-dom/client', 'react-router-dom', '@tanstack/react-query', '@headlessui/react', 'lucide-react', 'clsx'],
    },
    server: {
      port: 5175,
      strictPort: true,
      host: '127.0.0.1',
      // portal.css loads the self-hosted fonts from ../assets/fonts and the shared UI kit lives in ../src
      fs: { allow: [path.resolve(__dirname, '..')] },
      proxy: {
        '/api': { target, changeOrigin: false },
        '/assets': { target },
      },
    },
    build: {
      outDir: path.resolve(__dirname, '../portal/build'),
      emptyOutDir: true,
      manifest: true,
      sourcemap: false,
      chunkSizeWarningLimit: 700,
      rollupOptions: {
        input: path.resolve(__dirname, 'portal/index.html'),
        output: {
          manualChunks: {
            react: ['react', 'react-dom', 'react-router-dom'],
            query: ['@tanstack/react-query'],
            ui: ['@headlessui/react'],
          },
        },
      },
    },
  };
});
