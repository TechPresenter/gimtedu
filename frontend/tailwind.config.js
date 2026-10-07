import preset from '../tailwind.preset.cjs';

/** @type {import('tailwindcss').Config} */
export default {
  presets: [preset],
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  plugins: [],
};
