import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowRight, Clock3, Info, LockKeyhole, UserRound } from 'lucide-react';
import { Alert, Button, Checkbox, Field } from '@/components/ui';
import { api } from '@/lib/api';
import { areaPath, type Area } from '../lib/config';
import { authErrorTone, errorMessage, fieldErrors } from '../lib/errors';
import { useApplySession, useSession } from '../lib/session';
import type { AnySession } from '../lib/types';
import { AuthHeading, AuthLink } from './AuthLayout';
import { PasswordInput } from './PasswordFields';

interface LoginFormProps {
  area: Area;
  title: string;
  subtitle: ReactNode;
  identifierLabel: string;
  identifierPlaceholder: string;
  identifierHint?: ReactNode;
  /** extra content under the submit button (activation link etc.) */
  below?: ReactNode;
  /** message shown when the portal is switched off */
  disabled?: boolean;
}

/** Sign-in form shared by the student and faculty portals (POST /api/portal/{area}/login). */
export function LoginForm({ area, title, subtitle, identifierLabel, identifierPlaceholder, identifierHint, below, disabled }: LoginFormProps) {
  const navigate = useNavigate();
  const location = useLocation();
  const [params] = useSearchParams();
  const applySession = useApplySession();
  const { data: session } = useSession(area);
  const [identifier, setIdentifier] = useState(() => {
    try {
      return localStorage.getItem(`gimt.portal.${area}.id`) ?? '';
    } catch {
      return '';
    }
  });
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<{ message: string; tone: 'warning' | 'error' } | null>(null);
  const idRef = useRef<HTMLInputElement>(null);
  const pwRef = useRef<HTMLInputElement>(null);

  const state = (location.state ?? {}) as { expired?: boolean; reset?: boolean };
  const expired = state.expired || session?.expired;
  const notFaculty = area === 'faculty' && session?.area === 'faculty' && session.not_faculty;

  useEffect(() => {
    (identifier ? pwRef : idRef).current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!identifier.trim()) local.identifier = `Enter your ${identifierLabel.toLowerCase()}.`;
    if (!password) local.password = 'Enter your password.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length) {
      (local.identifier ? idRef : pwRef).current?.focus();
      return;
    }
    setBusy(true);
    try {
      const res = await api.post<AnySession>(`portal/${area}/login`, { identifier: identifier.trim(), password, remember });
      try {
        // remember the ID only on a device the user marked as personal
        if (remember) localStorage.setItem(`gimt.portal.${area}.id`, identifier.trim());
        else localStorage.removeItem(`gimt.portal.${area}.id`);
      } catch {
        /* private mode */
      }
      applySession(area, res.data);
      const next = params.get('next') ?? '';
      navigate(next.startsWith(`/${area}`) && !next.startsWith('//') ? next : areaPath(area), { replace: true });
    } catch (err) {
      setErrors(fieldErrors(err));
      setError({ message: errorMessage(err), tone: authErrorTone(err) });
      setPassword('');
      pwRef.current?.focus();
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <AuthHeading icon={LockKeyhole} title={title} subtitle={subtitle} />
      <div className="space-y-3" aria-live="polite">
        {disabled && (
          <Alert variant="warning" title="Temporarily unavailable">
            Sign-in to this portal is switched off for maintenance. Please try again later.
          </Alert>
        )}
        {state.reset && !error && <Alert variant="success">Your password has been reset. Sign in with your new password.</Alert>}
        {expired && !error && !state.reset && (
          <Alert variant="info" title="Session expired">
            For your security you were signed out after a period of inactivity. Please sign in again.
          </Alert>
        )}
        {notFaculty && !error && (
          <Alert variant="warning">
            You are signed in to the admin panel with an account that is not linked to a faculty profile. Sign in with your faculty account to continue.
          </Alert>
        )}
        {error && (
          <Alert variant={error.tone} title={error.tone === 'warning' ? 'Sign-in paused' : undefined}>
            {error.message}
          </Alert>
        )}
      </div>
      <form onSubmit={submit} noValidate className="mt-5 space-y-5">
        <Field label={identifierLabel} htmlFor={`${area}-identifier`} error={errors.identifier} hint={identifierHint}>
          <div className="relative">
            <UserRound className="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              ref={idRef}
              id={`${area}-identifier`}
              name="username"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck={false}
              inputMode={area === 'faculty' ? 'email' : 'text'}
              value={identifier}
              onChange={(e) => setIdentifier(e.target.value)}
              placeholder={identifierPlaceholder}
              aria-invalid={!!errors.identifier || undefined}
              className={`form-input form-input-lg pl-11 ${errors.identifier ? 'form-input-error' : ''}`}
              maxLength={190}
              disabled={disabled}
            />
          </div>
        </Field>
        <Field
          label="Password"
          htmlFor={`${area}-password`}
          error={errors.password}
          aside={
            <AuthLink to={areaPath(area, 'forgot')} className="text-[13px]">
              Forgot password?
            </AuthLink>
          }
        >
          <PasswordInput ref={pwRef} id={`${area}-password`} name="password" autoComplete="current-password" large value={password} onChange={(e) => setPassword(e.target.value)} invalid={!!errors.password} maxLength={200} disabled={disabled} />
        </Field>
        <Checkbox
          checked={remember}
          onChange={(e) => setRemember(e.target.checked)}
          label="Keep me signed in on this device"
          description="Only on your personal phone or computer — never on a shared or lab system."
        />
        <Button type="submit" size="lg" loading={busy} iconRight={ArrowRight} className="w-full" disabled={disabled}>
          {busy ? 'Signing in…' : 'Sign in'}
        </Button>
      </form>
      {below}
      <p className="mt-6 flex items-start gap-2 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500 dark:bg-slate-900 dark:text-slate-400">
        <Clock3 className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
        <span>
          After {session?.app ? Math.round(session.app.session_timeout / 60) : 30} minutes of inactivity you are signed out automatically. Repeated wrong passwords lock the account for a few minutes.
        </span>
      </p>
    </>
  );
}

/** Small note row used under auth forms. */
export function AuthNote({ children }: { children: ReactNode }) {
  return (
    <p className="mt-4 flex items-start gap-2 text-[13px] text-slate-500 dark:text-slate-400">
      <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden />
      <span>{children}</span>
    </p>
  );
}
