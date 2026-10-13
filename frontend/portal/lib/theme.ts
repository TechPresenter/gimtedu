import { useCallback } from 'react';
import { api } from '@/lib/api';
import { useTheme } from '@/lib/theme';
import type { Area } from './config';

export type ThemeChoice = 'light' | 'dark' | 'system';

/**
 * Theme switcher for the portal. Uses the shared ThemeProvider (so charts and the UI kit follow it) but never
 * lets it call the admin-only /api/auth/prefs for students: the choice is saved through the area's own endpoint.
 */
export function usePortalTheme(area: Area | null) {
  const { theme, resolved, setTheme } = useTheme();
  const choose = useCallback(
    (t: ThemeChoice) => {
      setTheme(t, false);
      if (!area) return;
      const path = area === 'student' ? 'portal/student/prefs' : 'auth/prefs';
      void api.post(path, { theme: t }).catch(() => undefined);
    },
    [area, setTheme],
  );
  return { theme: theme as ThemeChoice, resolved, choose };
}
