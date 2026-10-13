/**
 * Tailwind config for the Student & Faculty portal SPA (built by vite.portal.config.ts).
 * Website "Design v3" palette: deep navy (brand, from the shared preset) + orange-red ("flame") + warm greys.
 * The `slate` scale is re-tuned so the shared admin UI kit (which uses slate-*) automatically renders warm greys in
 * light mode and deep navy surfaces in dark mode.
 */
const path = require('node:path');
const preset = require('../../tailwind.preset.cjs');

/** @type {import('tailwindcss').Config} */
module.exports = {
  presets: [preset],
  content: [
    path.join(__dirname, 'index.html'),
    path.join(__dirname, '**/*.{ts,tsx}'),
    path.join(__dirname, '../src/components/ui/**/*.{ts,tsx}'),
    path.join(__dirname, '../src/components/charts/**/*.{ts,tsx}'),
    path.join(__dirname, '../src/lib/**/*.{ts,tsx}'),
  ],
  theme: {
    extend: {
      colors: {
        slate: {
          50: '#F8F6F3', 100: '#F2EEE9', 200: '#E7E1D9', 300: '#D5CDC3', 400: '#9C978F', 500: '#6C7080',
          600: '#4A5266', 700: '#333C52', 800: '#1E2943', 900: '#121D35', 950: '#0A1328',
        },
        flame: {
          50: '#FEF4EF', 100: '#FDE5D9', 200: '#FAC8B0', 300: '#F6A07D', 400: '#F37B4F', 500: '#F05A28',
          600: '#D03E16', 700: '#B83511', 800: '#922B12', 900: '#772713', 950: '#401107',
        },
        sand: { 50: '#FBFAF8', 100: '#F5F2EE', 200: '#EDE8E2', 300: '#E0D8CE' },
      },
      boxShadow: {
        card: '0 1px 2px rgba(11,42,91,.04), 0 6px 20px -8px rgba(11,42,91,.10)',
        soft: '0 14px 34px -14px rgba(11,42,91,.28)',
        pop: '0 24px 60px -16px rgba(7,26,59,.38)',
        glow: '0 10px 30px -10px rgba(240,90,40,.55)',
      },
      keyframes: {
        'fade-up': { from: { opacity: '0', transform: 'translateY(10px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        'sheet-up': { from: { transform: 'translateY(100%)' }, to: { transform: 'translateY(0)' } },
        drift: { '0%,100%': { transform: 'translate3d(0,0,0)' }, '50%': { transform: 'translate3d(0,-10px,0)' } },
        'ring-fill': { from: { strokeDashoffset: 'var(--ring-from)' }, to: { strokeDashoffset: 'var(--ring-to)' } },
        'loader-x': { from: { transform: 'translateX(-100%)' }, to: { transform: 'translateX(300%)' } },
      },
      animation: {
        'fade-up': 'fade-up .35s cubic-bezier(.2,.7,.2,1) both',
        'sheet-up': 'sheet-up .28s cubic-bezier(.2,.7,.2,1) both',
        drift: 'drift 9s ease-in-out infinite',
        'loader-x': 'loader-x 1.1s ease-in-out infinite',
      },
    },
  },
  plugins: [],
};
