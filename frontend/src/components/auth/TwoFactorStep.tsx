import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { ArrowLeft, KeyRound, LifeBuoy, ShieldCheck, Smartphone } from 'lucide-react';
import { Alert, Button } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import type { TwoFactorChallenge } from '@/lib/auth';
import type { SessionPayload } from '@/lib/types';
import { isRecoveryCodeComplete, OtpCodeInput, RecoveryCodeInput } from './CodeInput';

/** POST /auth/2fa/verify answers with the normal session payload (+ recovery_codes_left after a recovery code). */
export type TwoFactorVerifyResult = SessionPayload & { recovery_codes_left?: number };

interface Props {
  challenge: TwoFactorChallenge;
  /** The code was accepted: the server has signed the user in. */
  onSuccess: (payload: TwoFactorVerifyResult, message: string) => void;
  /** The pending sign-in is gone (expired, or too many wrong codes): back to the password form with this message. */
  onRestart: (message: string) => void;
  /** "Back to sign in" (the pending sign-in has been cancelled on the server). */
  onCancel: () => void;
}

/** Second sign-in step: a 6-digit code from the authenticator app, or a one-time recovery code. */
export function TwoFactorStep({ challenge, onSuccess, onRestart, onCancel }: Props) {
  const uid = useId();
  const [method, setMethod] = useState<'totp' | 'recovery'>('totp');
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  const allowRecovery = !challenge.methods || challenge.methods.includes('recovery');
  const codeId = `${uid}-code`;
  const errId = `${uid}-err`;

  const switchMethod = (m: 'totp' | 'recovery') => {
    setMethod(m);
    setCode('');
    setCodeError(null);
    setFormError(null);
    window.setTimeout(() => inputRef.current?.focus(), 0);
  };

  const submit = async (value = code) => {
    if (busy) return;
    const complete = method === 'totp' ? /^\d{6}$/.test(value) : isRecoveryCodeComplete(value);
    if (!complete) {
      setCodeError(method === 'totp' ? 'Enter the 6-digit code from your authenticator app.' : 'Enter one of your recovery codes, like ABCD-EF23.');
      inputRef.current?.focus();
      return;
    }
    setBusy(true);
    setCodeError(null);
    setFormError(null);
    try {
      const res = await api.post<TwoFactorVerifyResult>('auth/2fa/verify', { code: value, method });
      if (mounted.current) onSuccess(res.data, res.message);
    } catch (e) {
      if (!mounted.current) return;
      const err = e as ApiError;
      if (err.status === 401 || err.status === 429) {
        onRestart(err.message || (err.status === 429 ? 'Too many wrong codes. Sign in again.' : 'Your sign-in expired. Enter your password again.'));
        return;
      }
      if (err.status === 422) {
        setCodeError(err.errors?.code || err.message || 'That code is not correct.');
        window.setTimeout(() => inputRef.current?.focus(), 0);
      } else {
        setFormError(err.message);
      }
    } finally {
      if (mounted.current) setBusy(false);
    }
  };

  const back = async () => {
    setLeaving(true);
    try {
      await api.post('auth/2fa/cancel');
    } catch {
      /* the pending sign-in expires on its own */
    } finally {
      if (mounted.current) setLeaving(false);
      onCancel();
    }
  };

  return (
    <div>
      <div className="mb-5 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 dark:border-slate-800 dark:bg-slate-900/60">
        <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
          {method === 'totp' ? <Smartphone className="h-5 w-5" aria-hidden /> : <LifeBuoy className="h-5 w-5" aria-hidden />}
        </span>
        <p className="text-sm text-slate-600 dark:text-slate-300">
          {method === 'totp' ? (
            <>
              Open <strong className="font-semibold text-slate-800 dark:text-white">Google Authenticator</strong>, Microsoft Authenticator or your authenticator app and enter the current code
              {challenge.account ? (
                <>
                  {' '}
                  for <span className="font-medium text-slate-800 dark:text-white">{challenge.account}</span>
                </>
              ) : null}
              .
            </>
          ) : (
            <>Enter one of the recovery codes you saved when you turned on two-factor authentication. Each code works only once.</>
          )}
        </p>
      </div>

      {formError && (
        <Alert variant="error" className="mb-4">
          {formError}
        </Alert>
      )}

      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          void submit();
        }}
        noValidate
        className="space-y-4"
      >
        <div>
          <label htmlFor={codeId} className="form-label">
            {method === 'totp' ? 'Authentication code' : 'Recovery code'}
          </label>
          {method === 'totp' ? (
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
              disabled={leaving}
              autoFocus
              describedBy={codeError ? errId : undefined}
            />
          ) : (
            <RecoveryCodeInput
              ref={inputRef}
              id={codeId}
              value={code}
              onChange={(v) => {
                setCode(v);
                if (codeError) setCodeError(null);
              }}
              invalid={!!codeError}
              disabled={leaving}
              autoFocus
              describedBy={codeError ? errId : undefined}
            />
          )}
          {codeError ? (
            <p id={errId} className="form-error" role="alert">
              {codeError}
            </p>
          ) : (
            <p className="form-hint">{method === 'totp' ? 'The code changes every 30 seconds. It is checked as soon as you type the sixth digit.' : 'Letters and digits, with or without the dash.'}</p>
          )}
        </div>
        <Button type="submit" className="w-full" size="lg" icon={ShieldCheck} loading={busy} disabled={leaving}>
          Verify
        </Button>
      </form>

      <div className="mt-5 flex flex-col items-center gap-3 text-sm">
        {allowRecovery && (
          <button type="button" className="link inline-flex items-center gap-1.5" onClick={() => switchMethod(method === 'totp' ? 'recovery' : 'totp')}>
            {method === 'totp' ? <LifeBuoy className="h-4 w-4" aria-hidden /> : <KeyRound className="h-4 w-4" aria-hidden />}
            {method === 'totp' ? 'Use a recovery code instead' : 'Use your authenticator app instead'}
          </button>
        )}
        <Button variant="ghost" size="sm" icon={ArrowLeft} onClick={back} loading={leaving}>
          Back to sign in
        </Button>
      </div>
    </div>
  );
}
