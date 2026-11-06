import { useState, type ChangeEvent, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Check, Eye, EyeOff, KeyRound, LockKeyhole, LogOut, ShieldAlert, X } from 'lucide-react';
import { Alert, Avatar, Button, Field, Input, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDocumentTitle } from '@/lib/hooks';
import { AuthLayout } from '@/pages/auth/AuthLayout';

/**
 * Mandatory "Set a new password" screen. AdminLayout renders it instead of the application while
 * session.user.must_change_password is true (temporary password, "force change at next sign-in", or a password older
 * than Settings > Security > Password expiry). The server enforces the same rule: every API except auth/* answers
 * 403 {code: 'password_change_required'} until POST /api/auth/password succeeds. The URL is kept, so the page the user
 * asked for opens as soon as the new password is saved.
 */
export function ForcePasswordChange() {
  useDocumentTitle('Set a new password');
  const { session, refresh, logout } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const user = session?.user;
  const policy = session?.app.password_policy ?? { min_length: 8, uppercase: true, number: true, special: true };
  const expired = user?.password_change_reason === 'expired';

  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (k: keyof typeof form) => (e: ChangeEvent<HTMLInputElement>) => {
    setForm((f) => ({ ...f, [k]: e.target.value }));
    if (errors[k]) setErrors((all) => Object.fromEntries(Object.entries(all).filter(([key]) => key !== k)));
  };

  const p = form.password;
  const rules = [
    { ok: p.length >= policy.min_length, label: `At least ${policy.min_length} characters` },
    policy.uppercase && { ok: /[A-Z]/.test(p), label: 'An uppercase letter' },
    policy.number && { ok: /\d/.test(p), label: 'A number' },
    policy.special && { ok: /[^A-Za-z0-9]/.test(p), label: 'A special character' },
    { ok: p !== '' && p !== form.current_password, label: 'Different from the current one' },
  ].filter(Boolean) as { ok: boolean; label: string }[];

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    const local: Record<string, string> = {};
    if (!form.current_password) local.current_password = expired ? 'Enter your current password.' : 'Enter the password you signed in with.';
    if (!p) local.password = 'Choose a new password.';
    else if (rules.some((r) => !r.ok)) local.password = 'The new password does not meet every requirement below.';
    if (p && form.password_confirmation !== p) local.password_confirmation = 'Passwords do not match.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    try {
      const res = await api.post('auth/password', form);
      await refresh();
      // queries that failed with 403 while the change was pending load again
      void qc.invalidateQueries();
      toast.success(res.message || 'Password updated successfully.');
    } catch (err) {
      const ae = err as ApiError;
      setError(ae.message);
      setErrors(ae.errors ?? {});
    } finally {
      setBusy(false);
    }
  };

  const signOut = async () => {
    setLeaving(true);
    await logout().catch(() => undefined);
    navigate('/login', { replace: true });
  };

  const eye = (
    <button type="button" onClick={() => setShow((s) => !s)} className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label={show ? 'Hide passwords' : 'Show passwords'} aria-pressed={show}>
      {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
    </button>
  );

  return (
    <AuthLayout
      title={expired ? 'Your password has expired' : 'Set a new password'}
      subtitle={
        expired
          ? 'For your security, passwords must be changed regularly. Choose a new password to continue.'
          : 'Your account uses a temporary password or an administrator asked you to change it. Choose a new password to continue.'
      }
    >
      {user && (
        <div className="mb-5 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 dark:border-slate-800 dark:bg-slate-900">
          <Avatar name={user.name} src={user.avatar} size="sm" />
          <div className="min-w-0 text-sm leading-tight">
            <p className="truncate font-semibold text-slate-900 dark:text-white">{user.name}</p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{user.email}</p>
          </div>
          <ShieldAlert className="ml-auto h-5 w-5 shrink-0 text-amber-500" aria-hidden />
        </div>
      )}
      {error && <Alert variant="error" className="mb-5">{error}</Alert>}
      <form onSubmit={submit} className="space-y-5" noValidate>
        <Field label="Current password" htmlFor="fpc-current" required error={errors.current_password} hint={expired ? undefined : 'The temporary password you were given, or the one you just signed in with.'}>
          <div className="relative">
            <Input id="fpc-current" type={show ? 'text' : 'password'} autoComplete="current-password" autoFocus value={form.current_password} onChange={set('current_password')} prefix={<LockKeyhole className="h-4 w-4" />} invalid={!!errors.current_password} className="pr-11" />
            {eye}
          </div>
        </Field>
        <Field label="New password" htmlFor="fpc-new" required error={errors.password}>
          <Input id="fpc-new" type={show ? 'text' : 'password'} autoComplete="new-password" value={form.password} onChange={set('password')} prefix={<KeyRound className="h-4 w-4" />} invalid={!!errors.password} aria-describedby="fpc-rules" />
          <ul id="fpc-rules" className="mt-2 grid grid-cols-1 gap-1 text-xs sm:grid-cols-2" aria-label="Password requirements">
            {rules.map((r) => (
              <li key={r.label} className={clsx('flex items-center gap-1', r.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500')}>
                {r.ok ? <Check className="h-3.5 w-3.5 shrink-0" aria-hidden /> : <X className="h-3.5 w-3.5 shrink-0" aria-hidden />}
                <span>
                  {r.label}
                  <span className="sr-only">{r.ok ? ' (met)' : ' (not met yet)'}</span>
                </span>
              </li>
            ))}
          </ul>
        </Field>
        <Field label="Confirm new password" htmlFor="fpc-confirm" required error={errors.password_confirmation}>
          <Input id="fpc-confirm" type={show ? 'text' : 'password'} autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} prefix={<KeyRound className="h-4 w-4" />} invalid={!!errors.password_confirmation} />
        </Field>
        <Button type="submit" className="w-full" size="lg" loading={busy} icon={KeyRound}>
          Save password and continue
        </Button>
      </form>
      <div className="mt-6 flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
        <span className="min-w-0 flex-1">Devices that used “Keep me signed in” will have to sign in again.</span>
        <Button variant="ghost" size="sm" icon={LogOut} loading={leaving} onClick={() => void signOut()}>
          Sign out
        </Button>
      </div>
    </AuthLayout>
  );
}
