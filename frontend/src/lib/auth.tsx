import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, setCsrfToken } from './api';
import type { SessionPayload } from './types';

export type PermissionAction = 'view' | 'create' | 'edit' | 'delete' | 'export' | 'import' | 'approve' | 'publish' | 'manage';

interface AuthContextValue {
  session: SessionPayload | null;
  loading: boolean;
  /** Permission check; super admins always pass and 'manage' implies every action. */
  can: (module: string, action?: PermissionAction) => boolean;
  canAny: (modules: string[], action?: PermissionAction) => boolean;
  refresh: () => Promise<SessionPayload | null>;
  login: (identifier: string, password: string, remember: boolean) => Promise<string>;
  logout: () => Promise<void>;
  setAcademicSession: (id: number) => Promise<void>;
  /** Shallow-update session (e.g. after profile edit). */
  patch: (p: Partial<SessionPayload>) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<SessionPayload | null>(null);
  const [loading, setLoading] = useState(true);

  const apply = useCallback((s: SessionPayload) => {
    setCsrfToken(s.csrf_token);
    setSession(s);
    return s;
  }, []);

  const refresh = useCallback(async () => {
    try {
      const s = await api.get<SessionPayload>('auth/session');
      return apply(s);
    } catch {
      return null;
    } finally {
      setLoading(false);
    }
  }, [apply]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  // Session expired on the server: mark unauthenticated so routes redirect to login.
  useEffect(() => {
    const onExpired = () => setSession((s) => (s ? { ...s, authenticated: false, user: undefined } : s));
    window.addEventListener('auth:expired', onExpired);
    return () => window.removeEventListener('auth:expired', onExpired);
  }, []);

  const can = useCallback(
    (module: string, action: PermissionAction = 'view') => {
      if (!session?.authenticated) return false;
      if (session.is_super) return true;
      const actions = session.permissions?.[module] ?? [];
      return actions.includes(action) || actions.includes('manage');
    },
    [session],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      loading,
      can,
      canAny: (modules, action = 'view') => modules.some((m) => can(m, action)),
      refresh,
      login: async (identifier, password, remember) => {
        const res = await api.post<SessionPayload>('auth/login', { identifier, password, remember });
        apply(res.data);
        return res.message;
      },
      logout: async () => {
        try {
          const res = await api.post<{ csrf_token: string }>('auth/logout');
          setCsrfToken(res.data?.csrf_token);
        } finally {
          setSession((s) => (s ? { ...s, authenticated: false, user: undefined, permissions: {} } : s));
        }
      },
      setAcademicSession: async (id: number) => {
        await api.post('auth/prefs', { academic_session_id: id });
        setSession((s) => (s ? { ...s, current_session_id: id } : s));
      },
      patch: (p) => setSession((s) => (s ? { ...s, ...p } : s)),
    }),
    [session, loading, can, refresh, apply],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}

/** Shortcut for the current academic session object. */
export function useAcademicSession() {
  const { session } = useAuth();
  const id = session?.current_session_id ?? null;
  return { id, session: session?.sessions?.find((s) => s.id === id) ?? null, all: session?.sessions ?? [] };
}
