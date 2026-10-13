import { useCallback, useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Clock3, LogOut } from 'lucide-react';
import { Button, Modal } from '@/components/ui';
import { api } from '@/lib/api';
import type { Area } from '../lib/config';
import { sessionKey, useLogout } from '../lib/session';

const PING_EVERY_MS = 60_000;
const WARN_BEFORE_S = 60;

/**
 * Idle-timeout handling for a signed-in area. The server ends a session after `timeout` seconds without a request;
 * while the user is active this keeps it alive with a light ping (max once a minute). After a quiet period it warns
 * 60 s before the server would expire the session, then re-checks the session (a "remember me" cookie may sign the
 * user straight back in) and otherwise sends them to the sign-in screen with an "expired" notice.
 */
export function SessionWatcher({ area, timeout }: { area: Area; timeout: number }) {
  const qc = useQueryClient();
  const logout = useLogout(area);
  const lastTouch = useRef(Date.now());
  const [left, setLeft] = useState<number | null>(null);
  const pinging = useRef(false);
  const expired = useRef(false);

  const ping = useCallback(async () => {
    if (pinging.current) return;
    pinging.current = true;
    try {
      await api.post(area === 'student' ? 'portal/student/ping' : 'auth/ping');
      lastTouch.current = Date.now();
      setLeft(null);
    } catch {
      // 401 / 403 -> let the session endpoint decide what happens next
      void qc.invalidateQueries({ queryKey: sessionKey(area) });
    } finally {
      pinging.current = false;
    }
  }, [area, qc]);

  useEffect(() => {
    const onActivity = () => {
      if (expired.current) return;
      if (Date.now() - lastTouch.current > PING_EVERY_MS) void ping();
    };
    const events: (keyof WindowEventMap)[] = ['pointerdown', 'keydown', 'touchstart', 'wheel'];
    events.forEach((e) => window.addEventListener(e, onActivity, { passive: true }));
    const tick = window.setInterval(() => {
      const remaining = Math.round(timeout - (Date.now() - lastTouch.current) / 1000);
      if (remaining <= 0) {
        if (!expired.current) {
          expired.current = true;
          setLeft(null);
          // The server session is gone (or was renewed by a remember-me cookie) - the session endpoint knows.
          void qc.invalidateQueries({ queryKey: sessionKey(area) }).then(() => {
            expired.current = false;
            lastTouch.current = Date.now();
          });
        }
        return;
      }
      setLeft(remaining <= WARN_BEFORE_S ? remaining : null);
    }, 1000);
    return () => {
      events.forEach((e) => window.removeEventListener(e, onActivity));
      window.clearInterval(tick);
    };
  }, [area, ping, qc, timeout]);

  return (
    <Modal
      open={left !== null}
      onClose={() => void ping()}
      size="sm"
      title="Are you still there?"
      description="For your security you will be signed out soon."
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
          <Clock3 className="h-5 w-5" aria-hidden />
        </span>
      }
      footer={
        <>
          <Button variant="secondary" icon={LogOut} onClick={() => void logout()}>
            Sign out
          </Button>
          <Button onClick={() => void ping()} autoFocus>
            Stay signed in
          </Button>
        </>
      }
    >
      <p className="text-sm text-slate-600 dark:text-slate-300">
        You have been inactive for a while. Your session ends in{' '}
        <strong className="tabular-nums text-slate-900 dark:text-white" aria-live="polite">
          {left ?? 0} second{left === 1 ? '' : 's'}
        </strong>
        .
      </p>
    </Modal>
  );
}
