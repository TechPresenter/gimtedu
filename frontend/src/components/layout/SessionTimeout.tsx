import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Clock } from 'lucide-react';
import { Button, Modal } from '@/components/ui';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';

/** Warns the user one minute before the idle session expires and lets them extend it. */
export function SessionTimeout() {
  const { session, logout } = useAuth();
  const navigate = useNavigate();
  const timeout = (session?.app.session_timeout ?? 1800) * 1000;
  const last = useRef(Date.now());
  const [warn, setWarn] = useState(false);
  const [left, setLeft] = useState(60);

  useEffect(() => {
    const bump = () => {
      last.current = Date.now();
    };
    // Any API call extends the server session; user input marks activity locally.
    const events = ['mousedown', 'keydown', 'touchstart', 'scroll'];
    let lastPing = Date.now();
    const onActivity = () => {
      bump();
      // Keep the server session alive while the user is actively working without API calls.
      if (Date.now() - lastPing > Math.min(timeout / 3, 5 * 60 * 1000)) {
        lastPing = Date.now();
        void api.post('auth/ping').catch(() => undefined);
      }
    };
    events.forEach((e) => window.addEventListener(e, onActivity, { passive: true }));
    const t = setInterval(() => {
      const idle = Date.now() - last.current;
      const remaining = Math.round((timeout - idle) / 1000);
      if (remaining <= 0) {
        clearInterval(t);
        void logout().then(() => navigate('/login?timeout=1', { replace: true }));
      } else if (remaining <= 60) {
        setWarn(true);
        setLeft(remaining);
      }
    }, 1000);
    return () => {
      events.forEach((e) => window.removeEventListener(e, onActivity));
      clearInterval(t);
    };
  }, [timeout, logout, navigate]);

  const stay = async () => {
    await api.post('auth/ping').catch(() => undefined);
    last.current = Date.now();
    setWarn(false);
  };

  return (
    <Modal
      open={warn}
      onClose={stay}
      size="sm"
      title="Your session is about to expire"
      icon={<span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-amber-100 text-amber-600"><Clock className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={() => void logout().then(() => navigate('/login', { replace: true }))}>
            Sign out
          </Button>
          <Button onClick={stay}>Stay signed in</Button>
        </>
      }
    >
      <p className="text-sm text-slate-600 dark:text-slate-300">
        For your security you will be signed out in <strong className="tabular-nums">{left}</strong> seconds due to inactivity.
      </p>
    </Modal>
  );
}
