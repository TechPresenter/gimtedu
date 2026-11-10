import { useCallback, useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import clsx from 'clsx';
import { Check, Copy, ExternalLink, RefreshCw, ShieldCheck } from 'lucide-react';
import { Alert, Button, Skeleton } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { copyText } from './clipboard';
import { OtpCodeInput } from './CodeInput';
import { QrCode } from './QrCode';

/** POST /auth/2fa/setup and POST /profile/2fa/setup. The secret is only ever shown during enrolment. */
export interface TwoFactorSetupData {
  secret: string;
  otpauth_uri: string;
  issuer: string;
  account: string;
}

interface Props<T> {
  /** Starts enrolment and returns a new secret: 'auth/2fa/setup' (sign-in) or 'profile/2fa/setup'. */
  setupPath: string;
  /** Confirms the first code: 'auth/2fa/setup/confirm' or 'profile/2fa/enable'. */
  confirmPath: string;
  /** Called with the confirm response (it carries the recovery codes). */
  onEnrolled: (data: T, message: string) => void;
  /** Return true when the caller handled the error itself (e.g. an expired sign-in sends the user back to the password). */
  onFatal?: (err: ApiError) => boolean;
  /** stacked = one column (sign-in card), split = QR on the left (modal). */
  layout?: 'stacked' | 'split';
  submitLabel?: string;
  /** Extra content under the form (e.g. "Back to sign in"). */
  footer?: ReactNode;
}

/** Manual-entry key in groups of four, as authenticator apps show it. */
const groupKey = (k: string) => (k.replace(/\s+/g, '').match(/.{1,4}/g) ?? []).join(' ');

function Step({ n, title, children, done }: { n: number; title: ReactNode; children?: ReactNode; done?: boolean }) {
  return (
    <li className="relative flex gap-3">
      <span
        className={clsx(
          'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
          done ? 'bg-emerald-600 text-white' : 'bg-brand-800 text-white dark:bg-brand-500',
        )}
        aria-hidden
      >
        {done ? <Check className="h-4 w-4" /> : n}
      </span>
      <div className="min-w-0 flex-1 pt-0.5">
        <p className="text-sm font-semibold text-slate-900 dark:text-white">{title}</p>
        {children && <div className="mt-1 text-sm text-slate-600 dark:text-slate-300">{children}</div>}
      </div>
    </li>
  );
}

/**
 * Authenticator-app enrolment: install an app, scan the QR code (or type the key), confirm with the first 6-digit code.
 * Used by the sign-in "setup" step and by My Profile › Security.
 */
export function TwoFactorSetup<T = unknown>({ setupPath, confirmPath, onEnrolled, onFatal, layout = 'stacked', submitLabel = 'Verify and turn on', footer }: Props<T>) {
  const uid = useId();
  const codeId = `${uid}-code`;
  const hintId = `${uid}-hint`;
  const [setup, setSetup] = useState<TwoFactorSetupData | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const mounted = useRef(true);
  const started = useRef(false);
  const copyTimer = useRef<number>();

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      window.clearTimeout(copyTimer.current);
    };
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    setCode('');
    setCodeError(null);
    setFormError(null);
    try {
      const res = await api.post<TwoFactorSetupData>(setupPath);
      if (mounted.current) setSetup(res.data);
    } catch (e) {
      const err = e as ApiError;
      if (!mounted.current || onFatal?.(err)) return;
      setLoadError(err.message);
    } finally {
      if (mounted.current) setLoading(false);
    }
  }, [setupPath, onFatal]);

  // One secret per mount (StrictMode runs effects twice in development; a second call would replace the first secret).
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    void load();
  }, [load]);

  const submit = async (value = code) => {
    if (busy) return;
    if (value.length !== 6) {
      setCodeError('Enter the 6-digit code shown in your authenticator app.');
      inputRef.current?.focus();
      return;
    }
    setBusy(true);
    setCodeError(null);
    setFormError(null);
    try {
      const res = await api.post<T>(confirmPath, { code: value });
      if (mounted.current) onEnrolled(res.data, res.message);
    } catch (e) {
      const err = e as ApiError;
      if (!mounted.current || onFatal?.(err)) return;
      if (err.status === 422) {
        setCodeError(err.errors?.code || err.message || 'That code is not correct. Try the newest code in your app.');
        window.setTimeout(() => inputRef.current?.focus(), 0);
      } else {
        setFormError(err.message);
      }
    } finally {
      if (mounted.current) setBusy(false);
    }
  };

  const copyKey = async () => {
    if (!setup || !(await copyText(setup.secret))) return;
    setCopied(true);
    window.clearTimeout(copyTimer.current);
    copyTimer.current = window.setTimeout(() => mounted.current && setCopied(false), 2000);
  };

  if (loadError) {
    return (
      <div className="space-y-4">
        <Alert variant="error" title="Could not start the setup" action={<Button size="sm" variant="secondary" icon={RefreshCw} onClick={() => void load()}>Retry</Button>}>
          {loadError}
        </Alert>
        {footer}
      </div>
    );
  }

  const split = layout === 'split';
  const qrBlock = (
    <div className={clsx('flex flex-col items-center gap-3', split ? 'sm:items-stretch' : '')}>
      {loading || !setup ? (
        <Skeleton className={clsx('aspect-square rounded-2xl', split ? 'w-48 sm:w-full' : 'w-44')} />
      ) : (
        <QrCode value={setup.otpauth_uri} label={`QR code to add ${setup.issuer} (${setup.account}) to an authenticator app`} className={split ? 'w-48 sm:w-full' : 'w-44'} />
      )}
      {setup && (
        <a
          href={setup.otpauth_uri}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline sm:hidden dark:text-brand-300"
        >
          <ExternalLink className="h-3.5 w-3.5" aria-hidden /> On this phone? Open in your authenticator app
        </a>
      )}
    </div>
  );

  const keyBlock = (
    <div className="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/60">
      <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Can&apos;t scan it? Enter this setup key</p>
      {loading || !setup ? (
        <Skeleton className="mt-2 h-6 w-full" />
      ) : (
        <>
          <div className="mt-1.5 flex items-center gap-2">
            <code className="min-w-0 flex-1 select-all break-normal font-mono text-sm font-semibold tracking-wider text-slate-900 dark:text-white" aria-label={`Setup key ${setup.secret.split('').join(' ')}`}>
              {groupKey(setup.secret)}
            </code>
            <button
              type="button"
              onClick={copyKey}
              className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 text-xs font-medium text-slate-600 transition hover:border-brand-300 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
              aria-label={copied ? 'Setup key copied' : 'Copy setup key'}
            >
              {copied ? <Check className="h-3.5 w-3.5 text-emerald-600" aria-hidden /> : <Copy className="h-3.5 w-3.5" aria-hidden />}
              {copied ? 'Copied' : 'Copy'}
            </button>
          </div>
          <p className="mt-1.5 text-[11px] leading-snug text-slate-500 dark:text-slate-400">
            Account <span className="font-medium text-slate-700 dark:text-slate-200">{setup.account}</span> · issuer {setup.issuer} · type Time-based
          </p>
        </>
      )}
    </div>
  );

  const form = (
    <form
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        void submit();
      }}
      noValidate
      className="mt-2 space-y-3"
    >
      <label htmlFor={codeId} className="sr-only">
        6-digit code from your authenticator app
      </label>
      <OtpCodeInput
        ref={inputRef}
        id={codeId}
        value={code}
        onChange={(v) => {
          setCode(v);
          if (codeError) setCodeError(null);
        }}
        onComplete={(v) => void submit(v)}
        invalid={!!codeError}
        disabled={loading || !setup}
        describedBy={codeError ? hintId : undefined}
      />
      {codeError && (
        <div id={hintId} role="alert" className="space-y-1">
          <p className="form-error !mt-0">{codeError}</p>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Codes change every 30 seconds; make sure your phone sets its time automatically.{' '}
            <button type="button" className="link" onClick={() => void load()}>
              Start again with a new key
            </button>
          </p>
        </div>
      )}
      {formError && <Alert variant="error">{formError}</Alert>}
      <Button type="submit" className="w-full" icon={ShieldCheck} loading={busy} disabled={loading || !setup}>
        {submitLabel}
      </Button>
    </form>
  );

  return (
    <div className="space-y-5">
      {split ? (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-[13rem_1fr]">
          <div className="space-y-3">
            {qrBlock}
            {keyBlock}
          </div>
          <ol className="space-y-5">
            <Step n={1} title="Install an authenticator app">
              Google Authenticator or Microsoft Authenticator on your phone (Authy and other TOTP apps also work).
            </Step>
            <Step n={2} title="Add your account">
              In the app tap <strong>+</strong>, choose <em>Scan a QR code</em> and point the camera at the code, or type the setup key.
            </Step>
            <Step n={3} title="Enter the 6-digit code">
              The app now shows a code for {setup?.issuer ?? 'your institute'} that changes every 30 seconds.
              {form}
            </Step>
          </ol>
        </div>
      ) : (
        <ol className="space-y-5">
          <Step n={1} title="Install an authenticator app">
            Google Authenticator or Microsoft Authenticator (Authy and other TOTP apps also work).
          </Step>
          <Step n={2} title="Scan this QR code">
            In the app tap <strong>+</strong> and choose <em>Scan a QR code</em>.
            <div className="mt-3 space-y-3">
              {qrBlock}
              {keyBlock}
            </div>
          </Step>
          <Step n={3} title="Enter the 6-digit code">
            Type the code the app shows for {setup?.issuer ?? 'your institute'}.
            {form}
          </Step>
        </ol>
      )}
      {footer}
    </div>
  );
}
