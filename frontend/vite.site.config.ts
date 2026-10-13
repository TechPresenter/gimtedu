import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Public WEBSITE bundle (React islands + vanilla TS enhancements) — separate from the admin SPA build.
 *
 *   Dev:   npm run dev:site    -> http://127.0.0.1:5174 (PHP pages load /@vite/client + /site/main.tsx from here when
 *                                 config/local.php sets app.site_dev_server = 'http://127.0.0.1:5174')
 *   Build: npm run build:site  -> ../assets/site (manifest at assets/site/.vite/manifest.json, read by includes/config.php)
 *
 * Code splitting: the entry (site/main.tsx) is tiny and has no React; every island is a dynamic import and React +
 * ReactDOM live in one shared "react" chunk that is only downloaded by pages that contain islands.
 */
export default defineConfig(({ command }) => ({
  root: __dirname,
  base: command === 'build' ? './' : '/',
  plugins: [react()],
  // Own dependency cache so this server never fights the admin dev server (:5173) over node_modules/.vite
  cacheDir: 'node_modules/.vite-site',
  resolve: {
    alias: { '@site': path.resolve(__dirname, 'site') },
  },
  // No CSS is imported by the site bundle (styles come from assets/css/style.css, built by tools/build-css.sh).
  // An empty PostCSS config keeps the admin's postcss/tailwind setup from being applied here.
  css: { postcss: { plugins: [] } },
  optimizeDeps: {
    entries: ['site/main.tsx', 'site/islands/*.tsx'],
    include: ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime', 'react/jsx-dev-runtime', 'lucide-react', 'clsx'],
  },
  server: {
    port: 5174,
    strictPort: true,
    host: '127.0.0.1',
    cors: true,
    origin: 'http://127.0.0.1:5174',
    hmr: { host: '127.0.0.1', port: 5174 },
  },
  build: {
    outDir: path.resolve(__dirname, '../assets/site'),
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    target: 'es2019',
    cssCodeSplit: true,
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 200,
    rollupOptions: {
      input: { main: path.resolve(__dirname, 'site/main.tsx') },
      output: {
        entryFileNames: 'js/[name]-[hash].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: 'media/[name]-[hash][extname]',
        // React + ReactDOM in one shared chunk; lucide icons are left to Rollup so each island only pulls the
        // icons it uses (the curated lib/Icon.tsx map lands in its own small shared chunk).
        manualChunks(id) {
          if (/[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/.test(id)) return 'react';
          return undefined;
        },
      },
    },
  },
}));
