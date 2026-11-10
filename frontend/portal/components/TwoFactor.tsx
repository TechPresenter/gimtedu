import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { ArrowLeft, ArrowRight, Clock3, Download, KeyRound, LifeBuoy, RefreshCw, ShieldCheck, ShieldPlus, Smartphone } from 'lucide-react';
import { Alert, Button, Checkbox, Field, Skeleton, useToast } from '@/components/ui';
import { api, setCsrfToken } from '@/lib/api';
import { errorMessage, errorStatus, fieldErrors } from '../lib/errors';
import type { FacultySession } from '../lib/types';
import { AuthHeading } from './AuthLayout';
import { CodeInput, CopyButton, TotpQr, downloadText, formatClock, groupSecret, isCompleteCode, useCountdown, type CodeMode } from './TwoFactorParts';

/**
 * Second sign-in step of the faculty portal (TOTP two-factor authentication).
 *
 * POST /api/portal/faculty/login answers {two_factor: {step, methods, expires_in, account}} instead of a session when the
 * account has 2FA (step "verify") or the institute requires it and the account is not enrolled yet (step "setup").
 *   verify -> POST /api/auth/2fa/verify {code, method: totp|recovery}
 *   setup  -> POST /api/auth/2fa/setup (secret + otpauth URI) -> POST /api/auth/2fa/setup/confirm {code} (+ recovery codes)
 *   back   -> POST /api/auth/2fa/cancel
 * Both finish with the admin session payload; the faculty portal payload is then loaded from /api/portal/faculty/session.
 */

export interface TwoFactorChallenge {
  step: 'verify' | 'setup';
  methods?: CodeMode[];
  expires_in: number;
  /** masked email, e.g. a***n@gimt.ac.in */
  account?: string;
}

/** data.two_factor of a sign-in response, or null for a normal session payload. */
export function readTwoFactorChallenge(data: unknown): TwoFactorChallenge | null {
  const tf = (data as { two_factor?: Partial<TwoFactorChallenge> } | null)?.two_factor;
  if (!tf || (tf.step !== 'verify' && tf.step !== 'setup')) return null;
  return {
    step: tf.step,
    methods: Array.isArray(tf.methods) && tf.methods.length ? tf.methods : ['totp', 'recovery'],
    expires_in: Number(tf.expires_in) > 0 ? Number(tf.expires_in) : tf.step === 'setup' ? 600 : 300,
    account: typeof tf.account === 'string' ? tf.account : undefined,
  };
}

export interface AuthNotice {
  message: string;
  tone: 'warning' | 'error' | 'info';
}

interface SetupInfo {
  secret: string;
  otpauth_uri: string;
  issuer: string;
  account: string;
}

/** Parts of the /api/auth/* session payload this flow reads. */
interface AuthPayload {
  csrf_token?: string;
  is_super?: boolean;
  permissions?: Record<string, string[]>;
  recovery_codes_left?: number;
  recovery_codes?: string[];
}

const EXPIRED = 'Your sign-in expired. Enter your password again.';
const NO_ACCESS = 'This account does not have Faculty Portal access. Please contact the administrator.';
const NOT_FACULTY = 'This account is not linked to an active faculty profile. Administrators and staff sign in at the admin panel.';

class SignInError extends Error {}

async function signOutQuietly() {
  try {
    const res = await api.post<{ csrf_token?: string }>('portal/faculty/logout');
    setCsrfToken(res.data?.csrf_token);
  } catch {
    /* already signed out */
  }
}

/** After a successful second factor: same checks as POST /portal/faculty/login, then the faculty portal payload. */
async function loadFacultySession(payload: AuthPayload | null | undefined): Promise<FacultySession> {
  if (payload?.csrf_token) setCsrfToken(payload.csrf_token);
  if (payload?.permissions && !payload.is_super && !(payload.permissions.faculty_portal ?? []).includes('view')) {
    await signOutQuietly();
    throw new SignInError(NO_ACCESS);
  }
  const s = await api.get<FacultySession>('portal/faculty/session');
  setCsrfToken(s.csrf_token);
  if (s.authenticated && s.faculty) return s;
  if (s.not_faculty) {
    await signOutQuietly();
    throw new SignInError(NOT_FACULTY);
  }
  throw new SignInError(EXPIRED);
}

/** Errors that end the pending sign-in (back to the password form). */
function endsSignIn(e: unknown): AuthNotice | null {
  const status = errorStatus(e);
  if (status === 401) return { message: errorMessage(e, EXPIRED), tone: 'warning' };
  if (status === 429 || status === 423) return { message: errorMessage(e), tone: 'warning' };
  if (status === 403) return { message: errorMessage(e), tone: 'error' };
  if (e instanceof SignInError) return { message: e.message, tone: 'error' };
  return null;
}

interface TwoFactorSignInProps {
  challenge: TwoFactorChallenge;
  /** server message of the sign-in response (shown under the heading) */
  message?: string;
  /** leave the second step: back to the password form, optionally with a notice */
  onBack: (notice?: AuthNotice) => void;
  /** signed in: store the session and enter the portal */
  onSignedIn: (session: FacultySession) => void;
}

export function TwoFactorSignIn({ challenge, message, onBack, onSignedIn }: TwoFactorSignInProps) {
  const [phase, setPhase] = useState<'verify' | 'setup' | 'codes'>(challenge.step);
  const [codes, setCodes] = useState<{ list: string[]; issuer: string; account: string } | null>(null);
  const leaving = useRef(false);

  const leave = useCallback(
    (notice?: AuthNotice, cancel = true) => {
      if (leaving.current) return;
      leaving.current = true;
      if (cancel) void api.post('auth/2fa/cancel').catch(() => undefined);
      onBack(notice);
    },
    [onBack],
  );

  // The pending sign-in only lives for expires_in seconds on the server (stops once enrolled).
  const left = useCountdown(phase === 'codes' ? null : challenge.expires_in, () => leave({ message: EXPIRED, tone: 'warning' }));

  const timer = (
    <p className="mt-6 flex items-center justify-center gap-1.5 text-xs text-slate-500 dark:text-slate-400" role="timer">
      <Clock3 className="h-3.5 w-3.5" aria-hidden />
      This step expires in {formatClock(left)}
    </p>
  );

  if (phase === 'codes' && codes) {
    return <RecoveryCodesStep codes={codes.list} issuer={codes.issuer} account={codes.account} onBack={(n) => leave(n, false)} onSignedIn={onSignedIn} />;
  }
  if (phase === 'setup') {
    return (
      <>
        <SetupStep
          challenge={challenge}
          message={message}
          onBack={leave}
          onEnrolled={(list, info) => {
            setCodes({ list, issuer: info.issuer, account: info.account });
            setPhase('codes');
          }}
        />
        {timer}
      </>
    );
  }
  return (
    <>
      <VerifyStep challenge={challenge} message={message} onBack={leave} onSignedIn={onSignedIn} />
      {timer}
    </>
  );
}

/* ------------------------------------------------------------------ Verify (enrolled accounts) */

function VerifyStep({ challenge, message, onBack, onSignedIn }: { challenge: TwoFactorChallenge; message?: string; onBack: (n?: AuthNotice, cancel?: boolean) => void; onSignedIn: (s: FacultySession) => void }) {
  const toast = useToast();
  const canRecover = (challenge.methods ?? []).includes('recovery');
  const [method, setMethod] = useState<CodeMode>('totp');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();
  const inputRef = useRef<HTMLInputElement>(null);
  const busyRef = useRef(false);

  useEffect(() => {
    inputRef.current?.focus();
  }, [method]);

  const submit = async (value = code) => {
    if (busyRef.current) return;
    setError(null);
    if (!isCompleteCode(value, method)) {
      setFieldError(method === 'totp' ? 'Enter the 6-digit code shown in your authenticator app.' : 'Enter a recovery code in the format XXXX-XXXX.');
      inputRef.current?.focus();
      return;
    }
    setFieldError(undefined);
    busyRef.current = true;
    setBusy(true);
    try {
      const res = await api.post<AuthPayload>('auth/2fa/verify', { code: value, method });
      const session = await loadFacultySession(res.data);
      const left = res.data?.recovery_codes_left;
      if (method === 'recovery' && typeof left === 'number') {
        const text = left === 0 ? 'That was your last recovery code. Generate new codes from your profile in the admin panel.' : `You used a recovery code. ${left} ${left === 1 ? 'code is' : 'codes are'} left.`;
        if (left <= 3) toast.warning(text, 'Recovery code used');
        else toast.info(text, 'Recovery code used');
      }
      onSignedIn(session);
    } catch (e) {
      const end = endsSignIn(e);
      if (end) return onBack(end, false);
      const inline = fieldErrors(e).code ?? (errorStatus(e) === 422 ? errorMessage(e) : undefined);
      setFieldError(inline);
      setError(inline ? null : errorMessage(e));
      setCode('');
      inputRef.current?.focus();
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    void submit();
  };

  const switchMethod = () => {
    setMethod((m) => (m === 'totp' ? 'recovery' : 'totp'));
    setCode('');
    setFieldError(undefined);
    setError(null);
  };

  const account = challenge.account ? <strong className="font-semibold text-slate-700 dark:text-slate-200">{challenge.account}</strong> : 'your account';
  return (
    <div>
      <AuthHeading
        icon={method === 'totp' ? ShieldCheck : KeyRound}
        title={method === 'totp' ? 'Two-factor authentication' : 'Use a recovery code'}
        subtitle={
          method === 'totp' ? (
            <>
              {message || 'Enter the 6-digit code from your authenticator app.'} <span className="block text-sm">Signing in as {account}.</span>
            </>
          ) : (
            'Enter one of the recovery codes you saved when you set up two-factor authentication. Each code works only once.'
          )
        }
      />
      <div aria-live="polite">
        {error && (
          <Alert variant="error" className="mb-4">
            {error}
          </Alert>
        )}
      </div>
      <form onSubmit={onSubmit} noValidate className="space-y-5">
        <Field
          label={method === 'totp' ? 'Authentication code' : 'Recovery code'}
          htmlFor="tfa-code"
          error={fieldError}
          hint={method === 'totp' ? 'Open Google Authenticator, Microsoft Authenticator or Authy — the code changes every 30 seconds.' : undefined}
        >
          <CodeInput
            ref={inputRef}
            id="tfa-code"
            mode={method}
            value={code}
            onChange={(v) => {
              setCode(v);
              if (fieldError) setFieldError(undefined);
            }}
            onComplete={method === 'totp' ? (v) => void submit(v) : undefined}
            invalid={!!fieldError}
            disabled={busy}
          />
        </Field>
        <Button type="submit" size="lg" loading={busy} iconRight={ArrowRight} className="w-full">
          {busy ? 'Verifying…' : 'Verify and sign in'}
        </Button>
      </form>
      <div className="mt-5 flex flex-col-reverse items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between">
        <Button variant="ghost" icon={ArrowLeft} onClick={() => onBack()} disabled={busy}>
          Back to sign in
        </Button>
        {canRecover && (
          <button type="button" onClick={switchMethod} className="link rounded-lg px-2 py-2 text-center text-sm" disabled={busy}>
            {method === 'totp' ? 'Use a recovery code instead' : 'Use your authenticator app instead'}
          </button>
        )}
      </div>
      <p className="mt-5 flex items-start gap-2 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500 dark:bg-slate-900 dark:text-slate-400">
        <LifeBuoy className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
        <span>Lost your phone and your recovery codes? Contact the administration office — they can reset two-factor authentication on your account so you can set it up again.</span>
      </p>
    </div>
  );
}

/* ------------------------------------------------------------------ Setup (institute requires 2FA, account not enrolled) */

function SetupStep({ challenge, message, onBack, onEnrolled }: { challenge: TwoFactorChallenge; message?: string; onBack: (n?: AuthNotice, cancel?: boolean) => void; onEnrolled: (codes: string[], info: SetupInfo) => void }) {
  const [info, setInfo] = useState<SetupInfo | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();
  const inputRef = useRef<HTMLInputElement>(null);
  const started = useRef(false);
  const busyRef = useRef(false);

  const load = useCallback(async () => {
    setLoadError(null);
    setInfo(null);
    try {
      const res = await api.post<SetupInfo>('auth/2fa/setup');
      if (!res.data?.secret || !res.data.otpauth_uri) throw new Error('The setup key could not be created. Please try again.');
      setInfo(res.data);
    } catch (e) {
      const end = endsSignIn(e);
      if (end) return onBack(end, false);
      setLoadError(errorMessage(e));
    }
  }, [onBack]);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    void load();
  }, [load]);

  const submit = async (value = code) => {
    if (busyRef.current || !info) return;
    setError(null);
    if (!isCompleteCode(value, 'totp')) {
      setFieldError('Enter the 6-digit code shown in your authenticator app.');
      inputRef.current?.focus();
      return;
    }
    setFieldError(undefined);
    busyRef.current = true;
    setBusy(true);
    try {
      const res = await api.post<AuthPayload>('auth/2fa/setup/confirm', { code: value });
      if (res.data?.csrf_token) setCsrfToken(res.data.csrf_token);
      onEnrolled(Array.isArray(res.data?.recovery_codes) ? res.data.recovery_codes : [], info);
    } catch (e) {
      const end = endsSignIn(e);
      if (end) return onBack(end, false);
      const inline = fieldErrors(e).code ?? (errorStatus(e) === 422 ? errorMessage(e) : undefined);
      setFieldError(inline);
      setError(inline ? null : errorMessage(e));
      setCode('');
      inputRef.current?.focus();
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    void submit();
  };

  return (
    <div>
      <AuthHeading icon={ShieldPlus} title="Set up two-factor authentication" subtitle={message || 'Your institute requires two-factor authentication. Set it up to continue.'} />
      <div aria-live="polite">
        {error && (
          <Alert variant="error" className="mb-4">
            {error}
          </Alert>
        )}
      </div>

      <ol className="space-y-6">
        <SetupItem n={1} title="Install an authenticator app">
          Use <strong className="font-semibold text-slate-700 dark:text-slate-200">Google Authenticator</strong>, Microsoft Authenticator or Authy on your phone.
        </SetupItem>

        <SetupItem n={2} title="Scan this QR code with the app">
          {loadError ? (
            <Alert
              variant="error"
              className="mt-3"
              action={
                <button type="button" onClick={() => void load()} className="btn btn-secondary btn-sm shrink-0">
                  <RefreshCw className="h-4 w-4" aria-hidden /> Retry
                </button>
              }
            >
              {loadError}
            </Alert>
          ) : !info ? (
            <div className="mt-3 flex flex-col items-center gap-3 sm:flex-row sm:items-start" aria-busy="true" aria-label="Preparing your setup key">
              <Skeleton className="h-44 w-44 rounded-2xl" />
              <div className="w-full flex-1 space-y-2">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-4 w-1/2" />
              </div>
            </div>
          ) : (
            <div className="mt-3">
              <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                <TotpQr uri={info.otpauth_uri} />
                <div className="w-full min-w-0 flex-1 space-y-2 text-[13px]">
                  <p>
                    Tap <strong className="font-semibold text-slate-700 dark:text-slate-200">+</strong> in the app, choose <em>Scan a QR code</em> and point the camera at the code.
                  </p>
                  <dl className="space-y-1 rounded-xl bg-slate-50 px-3 py-2.5 text-xs dark:bg-slate-900">
                    <div className="flex gap-2">
                      <dt className="w-14 shrink-0 text-slate-400">Issuer</dt>
                      <dd className="min-w-0 truncate font-medium text-slate-700 dark:text-slate-200">{info.issuer}</dd>
                    </div>
                    <div className="flex gap-2">
                      <dt className="w-14 shrink-0 text-slate-400">Account</dt>
                      <dd className="min-w-0 break-all font-medium text-slate-700 dark:text-slate-200">{info.account || challenge.account}</dd>
                    </div>
                  </dl>
                  <a href={info.otpauth_uri} className="btn btn-soft btn-sm w-full sm:hidden">
                    <Smartphone className="h-4 w-4" aria-hidden /> Open in authenticator app
                  </a>
                </div>
              </div>
              <details className="group mt-3 rounded-xl border border-slate-200 bg-white open:shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <summary className="cursor-pointer select-none rounded-xl px-3.5 py-2.5 text-[13px] font-semibold text-slate-700 marker:text-slate-400 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800/60">
                  Can&apos;t scan it? Enter a setup key instead
                </summary>
                <div className="space-y-2.5 border-t border-slate-100 px-3.5 py-3 text-xs dark:border-slate-800">
                  <p>
                    In the app choose <em>Enter a setup key</em>, type the key below and select <em>Time based</em>.
                  </p>
                  <p className="select-all break-all rounded-lg bg-slate-50 px-3 py-2 text-center font-mono text-[15px] font-semibold tracking-wider text-slate-900 dark:bg-slate-800 dark:text-white" aria-label={`Setup key ${info.secret.split('').join(' ')}`}>
                    {groupSecret(info.secret)}
                  </p>
                  <CopyButton text={info.secret} label="Copy key" copiedLabel="Key copied" className="w-full" />
                </div>
              </details>
            </div>
          )}
        </SetupItem>

        <SetupItem n={3} title="Enter the 6-digit code from the app">
          <form onSubmit={onSubmit} noValidate className="mt-3 space-y-4">
            <Field label="Authentication code" htmlFor="tfa-setup-code" error={fieldError}>
              <CodeInput
                ref={inputRef}
                id="tfa-setup-code"
                mode="totp"
                value={code}
                onChange={(v) => {
                  setCode(v);
                  if (fieldError) setFieldError(undefined);
                }}
                onComplete={(v) => void submit(v)}
                invalid={!!fieldError}
                disabled={busy || !info}
              />
            </Field>
            <Button type="submit" size="lg" loading={busy} icon={ShieldCheck} className="w-full" disabled={!info}>
              {busy ? 'Verifying…' : 'Verify and turn on'}
            </Button>
          </form>
        </SetupItem>
      </ol>

      <div className="mt-5">
        <Button variant="ghost" icon={ArrowLeft} onClick={() => onBack()} disabled={busy} className="w-full sm:w-auto">
          Back to sign in
        </Button>
      </div>
    </div>
  );
}

function SetupItem({ n, title, children }: { n: number; title: string; children: ReactNode }) {
  return (
    <li className="relative flex gap-3.5">
      <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-900 font-display text-[13px] font-bold text-white dark:bg-brand-600" aria-hidden>
        {n}
      </span>
      <div className="min-w-0 flex-1 pt-0.5 text-sm text-slate-500 dark:text-slate-400">
        <h3 className="font-display text-[15px] font-bold text-slate-900 dark:text-white">
          <span className="sr-only">Step {n}: </span>
          {title}
        </h3>
        <div className="mt-1">{children}</div>
      </div>
    </li>
  );
}

/* ------------------------------------------------------------------ Recovery codes (shown once, right after enrolment) */

function RecoveryCodesStep({ codes, issuer, account, onBack, onSignedIn }: { codes: string[]; issuer: string; account: string; onBack: (n?: AuthNotice) => void; onSignedIn: (s: FacultySession) => void }) {
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const headingRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    headingRef.current?.focus();
  }, []);

  const text = [
    `${issuer || 'GIMT'} — two-factor authentication recovery codes`,
    account ? `Account: ${account}` : '',
    `Generated: ${new Date().toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}`,
    '',
    ...codes,
    '',
    'Each code signs you in once if you cannot use your authenticator app. Keep them private.',
  ]
    .filter((l, i) => l !== '' || i > 2)
    .join('\n');
  const fileName = `${(issuer || 'gimt').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'gimt'}-recovery-codes.txt`;

  const finish = async () => {
    setBusy(true);
    setError(null);
    try {
      onSignedIn(await loadFacultySession(null));
    } catch (e) {
      const end = endsSignIn(e);
      if (end) return onBack(end);
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <div ref={headingRef} tabIndex={-1} className="rounded-2xl focus:outline-none">
        <AuthHeading
          icon={KeyRound}
          tone="navy"
          title="Save your recovery codes"
          subtitle="If you lose your phone, each of these codes signs you in once. They are shown only now — keep them somewhere safe, such as a password manager or a printed copy."
        />
      </div>
      <Alert variant="success" className="mb-4">
        Two-factor authentication is now on for your account.
      </Alert>
      {codes.length > 0 ? (
        <ol className="grid grid-cols-2 gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3 sm:p-4 dark:border-slate-800 dark:bg-slate-900" aria-label="Recovery codes">
          {codes.map((c) => (
            <li key={c} className="select-all rounded-lg bg-white px-2 py-2 text-center font-mono text-[15px] font-semibold tracking-wider text-slate-900 ring-1 ring-slate-200/80 dark:bg-slate-800 dark:text-white dark:ring-slate-700">
              {c}
            </li>
          ))}
        </ol>
      ) : (
        <Alert variant="warning">Your recovery codes could not be shown. You can generate new ones from My Profile › Security in the admin panel.</Alert>
      )}
      {codes.length > 0 && (
        <div className="mt-3 grid grid-cols-2 gap-2">
          <CopyButton text={text} label="Copy codes" copiedLabel="Codes copied" size="md" />
          <button type="button" onClick={() => downloadText(fileName, text)} className="btn btn-secondary">
            <Download className="h-4 w-4" aria-hidden /> Download
          </button>
        </div>
      )}
      <div aria-live="polite">
        {error && (
          <Alert variant="error" className="mt-4">
            {error}
          </Alert>
        )}
      </div>
      <Checkbox className="mt-5" checked={saved} onChange={(e) => setSaved(e.target.checked)} label="I have saved these codes" description="You will not be able to see them again." />
      <Button size="lg" iconRight={ArrowRight} className="mt-5 w-full" disabled={!saved && codes.length > 0} loading={busy} onClick={() => void finish()}>
        Continue to the portal
      </Button>
    </div>
  );
}
