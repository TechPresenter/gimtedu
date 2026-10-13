import { createContext, useCallback, useContext, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, setCsrfToken } from '@/lib/api';
import type { Area } from './config';
import type { AnySession, AreaSession, FacultySession, StudentSession } from './types';

/**
 * Portal sessions. Each area has its own boot endpoint (GET /api/portal/{area}/session) cached under
 * ['portal-session', area]. Login / activation / logout responses replace the cached payload directly.
 */

export const sessionKey = (area: Area) => ['portal-session', area] as const;

export function useSession<A extends Area>(area: A) {
  return useQuery<AreaSession<A>>({
    queryKey: sessionKey(area),
    queryFn: async () => {
      const s = await api.get<AreaSession<A>>(`portal/${area}/session`);
      setCsrfToken(s.csrf_token);
      return s;
    },
    staleTime: Infinity,
    retry: 1,
  });
}

/** Store a fresh session payload (after login / activation / password change). */
export function useApplySession() {
  const qc = useQueryClient();
  return useCallback(
    (area: Area, s: AnySession) => {
      setCsrfToken(s.csrf_token);
      qc.setQueryData(sessionKey(area), s);
      // a sign-in replaces any other identity held by this browser session
      const other: Area = area === 'student' ? 'faculty' : 'student';
      void qc.invalidateQueries({ queryKey: sessionKey(other) });
    },
    [qc],
  );
}

/** Mark the area signed out locally: 'expired' (server session timed out) or 'logout' (user signed out). */
export function useMarkSignedOut() {
  const qc = useQueryClient();
  return useCallback(
    (area: Area, reason: 'expired' | 'logout' = 'expired') => {
      qc.setQueryData<AnySession | undefined>(sessionKey(area), (old) =>
        old
          ? ({ ...old, authenticated: false, expired: reason === 'expired', signed_out: reason === 'logout', student: undefined, faculty: undefined, account: undefined } as unknown as AnySession)
          : old,
      );
      qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'portal-session' });
    },
    [qc],
  );
}

export function useLogout(area: Area) {
  const markSignedOut = useMarkSignedOut();
  return useCallback(async () => {
    try {
      const res = await api.post<{ csrf_token: string }>(`portal/${area}/logout`);
      setCsrfToken(res.data?.csrf_token);
    } finally {
      markSignedOut(area, 'logout');
    }
  }, [area, markSignedOut]);
}

/* ------------------------------------------------------------------ Signed-in area context */

export interface PortalIdentity {
  name: string;
  firstName: string;
  photo: string | null;
  /** second line under the name (Student ID · program / designation · department) */
  sub: string;
  idLabel: string;
}

interface AreaCtx {
  area: Area;
  session: AnySession;
  identity: PortalIdentity;
}

const Ctx = createContext<AreaCtx | null>(null);

export function identityOf(session: AnySession): PortalIdentity {
  if (session.area === 'student' && session.student) {
    const s = session.student;
    return {
      name: s.name,
      firstName: s.first_name,
      photo: s.photo,
      sub: `${s.program_short} · Sem ${s.semester}${s.section ? ` · Sec ${s.section}` : ''}`,
      idLabel: s.student_uid,
    };
  }
  if (session.area === 'faculty' && session.faculty) {
    const f = session.faculty;
    return { name: f.name, firstName: f.first_name, photo: f.photo, sub: [f.designation, f.department].filter(Boolean).join(' · '), idLabel: f.employee_id };
  }
  return { name: '', firstName: '', photo: null, sub: '', idLabel: '' };
}

export function AreaProvider({ area, session, children }: { area: Area; session: AnySession; children: ReactNode }) {
  return <Ctx.Provider value={{ area, session, identity: identityOf(session) }}>{children}</Ctx.Provider>;
}

/** Signed-in area context (only inside the authenticated layouts). */
export function usePortal(): AreaCtx {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('usePortal must be used inside an authenticated portal area');
  return ctx;
}

export function useStudentSession(): StudentSession {
  const { session } = usePortal();
  if (session.area !== 'student') throw new Error('Not a student session');
  return session;
}

export function useFacultySession(): FacultySession {
  const { session } = usePortal();
  if (session.area !== 'faculty') throw new Error('Not a faculty session');
  return session;
}
