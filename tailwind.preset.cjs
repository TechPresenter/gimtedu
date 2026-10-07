/**
 * Shared Tailwind theme for the admin SPA (frontend/) and the public website (assets/css).
 * GIMT brand: navy primary, green accent, blue/cyan secondary.
 */
module.exports = {
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#EFF4FB', 100: '#DBE6F6', 200: '#B8CDEC', 300: '#89AADD', 400: '#5480C8',
          500: '#2F5FB0', 600: '#1F4A94', 700: '#183C7A', 800: '#133266', 900: '#0B2A5B', 950: '#071A3B',
        },
        accent: {
          50: '#EDFAF1', 100: '#D2F2DC', 200: '#A8E4BB', 300: '#72CF92', 400: '#45B86D',
          500: '#2EA454', 600: '#22943F', 700: '#1C7634', 800: '#1A5E2D', 900: '#164D27',
        },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 2px rgba(16,24,40,.04), 0 4px 16px -4px rgba(16,24,40,.08)',
        soft: '0 10px 30px -12px rgba(11,42,91,.25)',
        pop: '0 20px 50px -12px rgba(11,42,91,.35)',
      },
      borderRadius: {
        '4xl': '2rem',
      },
      fontSize: {
        '2xs': ['0.6875rem', { lineHeight: '1rem' }],
      },
      keyframes: {
        'fade-in': { from: { opacity: '0' }, to: { opacity: '1' } },
        'slide-up': { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        shimmer: { '100%': { transform: 'translateX(100%)' } },
      },
      animation: {
        'fade-in': 'fade-in .2s ease-out',
        'slide-up': 'slide-up .25s ease-out',
      },
    },
  },
};
