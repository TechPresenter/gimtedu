import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { api } from './api';

type Theme = 'light' | 'dark' | 'system';
interface ThemeCtx {
  theme: Theme;
  resolved: 'light' | 'dark';
  setTheme: (t: Theme, persist?: boolean) => void;
}

const Ctx = createContext<ThemeCtx>({ theme: 'light', resolved: 'light', setTheme: () => {} });

function systemDark() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function ThemeProvider({ children, initial }: { children: ReactNode; initial?: Theme }) {
  const [theme, setThemeState] = useState<Theme>(() => {
    try {
      return (localStorage.getItem('gimt.theme') as Theme) || initial || 'light';
    } catch {
      return initial || 'light';
    }
  });
  // follow the OS setting live while the theme is 'system'
  const [sysDark, setSysDark] = useState(systemDark);
  useEffect(() => {
    if (theme !== 'system') return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const on = () => setSysDark(mq.matches);
    on();
    mq.addEventListener?.('change', on);
    return () => mq.removeEventListener?.('change', on);
  }, [theme]);
  const resolved: 'light' | 'dark' = theme === 'system' ? (sysDark ? 'dark' : 'light') : theme;

  useEffect(() => {
    document.documentElement.classList.toggle('dark', resolved === 'dark');
    document.documentElement.style.colorScheme = resolved;
  }, [resolved]);

  const setTheme = useCallback((t: Theme, persist = true) => {
    setThemeState(t);
    try {
      localStorage.setItem('gimt.theme', t);
    } catch {
      /* ignore */
    }
    if (persist) void api.post('auth/prefs', { theme: t }).catch(() => undefined);
  }, []);

  return <Ctx.Provider value={{ theme, resolved, setTheme }}>{children}</Ctx.Provider>;
}

export const useTheme = () => useContext(Ctx);
