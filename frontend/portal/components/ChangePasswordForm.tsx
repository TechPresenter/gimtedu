import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, KeyRound, ShieldCheck } from 'lucide-react';
import { Alert, Button, Field } from '@/components/ui';
import { api } from '@/lib/api';
import type { Area } from '../lib/config';
import { errorMessage, fieldErrors } from '../lib/errors';
import { sessionKey, useApplySession } from '../lib/session';
import type { AnySession, PasswordPolicy } from '../lib/types';
import { PasswordInput, PasswordRules, policyChecks } from './PasswordFields';

interface Props {
  area: Area;
  policy: PasswordPolicy;
  /** called after the password was changed (session already refreshed) */
  onDone?: () => void;
  submitLabel?: string;
}

/**
 * Change password (current + new + confirm) with the live policy checklist.
 * Students: POST /api/portal/student/password (returns the fresh session payload).
 * Faculty:  POST /api/auth/password (shared admin endpoint), then the faculty session is reloaded.
 */
export function ChangePasswordForm({ area, policy, onDone, submitLabel = 'Update password' }: Props) {
  const qc = useQueryClient();
  const applySession = useApplySession();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!current) local.current_password = 'Enter your current password.';
    const failed = policyChecks(password, policy).find((c) => !c.ok);
    if (!password) local.password = 'Choose a new password.';
    else if (failed) local.password = `Your new password needs: ${failed.label.toLowerCase()}.`;
    if (password && confirm !== password) local.password_confirmation = 'Passwords do not match.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length) return;
    setBusy(true);
    try {
      const body = { current_password: current, password, password_confirmation: confirm };
      if (area === 'student') {
        const res = await api.post<AnySession>('portal/student/password', body);
        applySession('student', res.data);
        setDone(res.message || 'Your password has been updated.');
      } else {
        const res = await api.post('auth/password', body);
        await qc.invalidateQueries({ queryKey: sessionKey('faculty') });
        setDone(res.message || 'Your password has been updated.');
      }
      setCurrent('');
      setPassword('');
      setConfirm('');
      onDone?.();
    } catch (err) {
      setErrors(fieldErrors(err));
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-5">
      {done && (
        <Alert variant="success" title="Password updated">
          {done} Other devices signed in with your old password have been signed out.
        </Alert>
      )}
      {error && !done && <Alert variant="error">{error}</Alert>}
      <Field label="Current password" htmlFor="cp-current" required error={errors.current_password}>
        <PasswordInput id="cp-current" autoComplete="current-password" value={current} onChange={(e) => { setCurrent(e.target.value); setDone(null); }} invalid={!!errors.current_password} />
      </Field>
      <Field label="New password" htmlFor="cp-new" required error={errors.password}>
        <PasswordInput id="cp-new" autoComplete="new-password" value={password} onChange={(e) => { setPassword(e.target.value); setDone(null); }} invalid={!!errors.password} aria-describedby="cp-rules" />
      </Field>
      <div id="cp-rules">
        <PasswordRules password={password} policy={policy} />
      </div>
      <Field label="Confirm new password" htmlFor="cp-confirm" required error={errors.password_confirmation}>
        <PasswordInput id="cp-confirm" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} invalid={!!errors.password_confirmation} />
      </Field>
      {confirm && confirm === password && !errors.password_confirmation && (
        <p className="-mt-3 flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
          <CheckCircle2 className="h-3.5 w-3.5" aria-hidden /> Passwords match
        </p>
      )}
      <div className="flex flex-col-reverse gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
        <p className="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
          <ShieldCheck className="h-4 w-4 text-emerald-600" aria-hidden /> Never share your password with anyone.
        </p>
        <Button type="submit" loading={busy} icon={KeyRound} className="w-full sm:w-auto">
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}
