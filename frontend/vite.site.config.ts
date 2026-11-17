import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import fs from 'node:fs';
import path from 'node:path';

/**
 * lucide icons that go to the shared 'ui' chunk (file names, kebab-case): every icon of the curated site/lib/Icon.tsx
 * map (read from its import list) + the icons several islands import directly. Others stay inside their island.
 */
const kebab = (s: string) => s.replace(/([a-z0-9])([A-Z])/g, '$1-$2').replace(/([a-zA-Z])(\d)/g, '$1-$2').toLowerCase();
const iconMapImports = (() => {
  try {
    const src = fs.readFileSync(path.resolve(__dirname, 'site/lib/Icon.tsx'), 'utf8');
    const block = src.match(/import\s*\{([^}]*)\}\s*from\s*'lucide-react'/);
    return block ? block[1].split(',').map((s) => s.trim().replace(/^type\s+/, '').split(/\s+as\s+/)[0]).filter((s) => /^[A-Z]/.test(s)) : [];
  } catch {
    return [];
  }
})();
const SHARED_ICONS = new Set(
  [...iconMapImports, 'ArrowRight', 'ArrowLeft', 'ArrowDown', 'X', 'Play', 'Pause', 'Search', 'LoaderCircle', 'ChevronDown', 'ChevronLeft',
    'ChevronRight', 'RotateCcw', 'Phone', 'PhoneCall', 'Check', 'MapPin', 'CircleAlert', 'CalendarDays'].map(kebab),
);

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
        // Fewer, larger shared chunks instead of a dozen sub-2 KB files per page (each extra file costs a round trip on
        // HTTP/1.1 — ~0.3 s from India to the Sydney origin):
        //   react — React + ReactDOM + the island runtime (runtime/mount.tsx): what every page with islands needs
        //   ui    — the lucide icons several islands use (+ the curated lib/Icon.tsx map, GoldMark) + clsx
        // Only modules that do NOT import the entry's own code (core/*) may be grouped: Rollup moves a grouped module's
        // dependencies into its chunk, and the tiny entry (no React) would then import that chunk statically on every
        // page. hooks / CountUp / Modal / forms import core/* and stay with Rollup's default splitting.
        manualChunks(id) {
          const n = id.replace(/\\/g, '/');
          if (/\/node_modules\/(react|react-dom|scheduler)\//.test(n) || n.endsWith('/site/runtime/mount.tsx')) return 'react';
          const icon = n.match(/\/node_modules\/lucide-react\/dist\/esm\/icons\/([a-z0-9-]+)\.mjs$/);
          // icons: the lib/Icon.tsx map + the ones several islands import go to 'ui'; an icon only one island uses stays in it
          if (icon) return SHARED_ICONS.has(icon[1]) ? 'ui' : undefined;
          if (/\/node_modules\/(lucide-react|clsx)\//.test(n) || /\/site\/lib\/(Icon|GoldMark|Scribble)\.tsx$/.test(n)) return 'ui';
          return undefined;
        },
      },
    },
  },
}));
