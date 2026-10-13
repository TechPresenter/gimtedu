import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { CircleCheck, KeyRound, Mail, ShieldAlert } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Avatar, Button, Field, Modal, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { CopyButton, PasswordInput, passwordChecks, usePasswordPolicy } from '../components';
import type { UserRow } from '../types';

type Mode = 'temporary' | 'link';
interface Result {
  mode: Mode;
  password?: string | null;
  must_change?: boolean;
  email?: string;
  logged?: boolean;
}

/** Reset a user's password: set a temporary password (optionally generated) or email a reset link. */
export function ResetPasswordModal({ user, onClose }: { user: Pick<UserRow, 'id' | 'name' | 'email' | 'avatar' | 'status'> | null; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const policy = usePasswordPolicy();
  const [mode, setMode] = useState<Mode>('temporary');
  const [password, setPassword] = useState('');
  const [mustChange, setMustChange] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string>('');
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<(Result & { message: string }) | null>(null);

  useEffect(() => {
    if (user) {
      setMode('temporary');
      setPassword('');
      setMustChange(true);
      setError(null);
      setFieldError('');
      setResult(null);
    }
  }, [user]);

  const submit = async () => {
    if (!user) return;
    if (mode === 'temporary' && password && passwordChecks(password, policy).some((c) => !c.ok)) {
      setFieldError('The password does not meet the policy. Leave it blank to generate one.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await api.post<Result>(`users/${user.id}/reset-password`, mode === 'temporary' ? { mode, password, must_change: mustChange } : { mode });
      setResult({ ...res.data, message: res.message });
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['crud', 'users'] });
      void qc.invalidateQueries({ queryKey: ['user-detail', user.id] });
    } catch (e) {
      const ex = e as ApiError;
      setFieldError(ex.errors?.password ?? '');
      setError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  const options: { key: Mode; icon: typeof KeyRound; title: string; text: string }[] = [
    { key: 'temporary', icon: KeyRound, title: 'Set a temporary password', text: 'You share it with the user securely. Remembered devices are signed out.' },
    { key: 'link', icon: Mail, title: 'Email a reset link', text: `A one-time link valid for 60 minutes is sent to ${user?.email ?? 'the user'}.` },
  ];

  return (
    <Modal
      open={!!user}
      onClose={onClose}
      static={saving}
      size="md"
      title="Reset password"
      description={user ? `For ${user.name}` : undefined}
      footer={
        result ? (
          <Button onClick={onClose}>Done</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button onClick={submit} loading={saving} icon={mode === 'link' ? Mail : KeyRound}>
              {mode === 'link' ? 'Send reset link' : password ? 'Set password' : 'Generate & set password'}
            </Button>
          </>
        )
      }
    >
      {user && result ? (
        <div className="space-y-4 motion-safe:animate-slide-up">
          <div className="flex items-center gap-3 rounded-xl bg-emerald-50 p-4 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
            <CircleCheck className="h-6 w-6 shrink-0" />
            <p className="text-sm">{result.message}</p>
          </div>
          {result.password && (
            <div>
              <p className="form-label">Temporary password</p>
              <div className="flex items-center justify-between gap-3 rounded-xl border border-dashed border-brand-300 bg-brand-50/60 px-4 py-3 dark:border-brand-500/40 dark:bg-brand-500/10">
                <code className="select-all break-all font-mono text-lg font-bold tracking-wide text-brand-900 dark:text-white">{result.password}</code>
                <CopyButton value={result.password} />
              </div>
              <p className="mt-2 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300">
                <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0" /> This password is shown only once. Share it through a secure channel{result.must_change ? '; the user must change it after signing in.' : '.'}
              </p>
            </div>
          )}
        </div>
      ) : user ? (
        <div className="space-y-4">
          <div className="flex items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
            <Avatar name={user.name} src={user.avatar} />
            <div className="min-w-0 text-sm">
              <p className="truncate font-semibold text-slate-900 dark:text-white">{user.name}</p>
              <p className="truncate text-xs text-slate-500">{user.email}</p>
            </div>
          </div>
          {error && <Alert variant="error">{error}</Alert>}
          <div className="grid gap-2" role="radiogroup" aria-label="Reset method">
            {options.map((o) => (
              <button
                key={o.key}
                type="button"
                role="radio"
                aria-checked={mode === o.key}
                onClick={() => setMode(o.key)}
                className={clsx(
                  'flex items-start gap-3 rounded-xl border p-3 text-left transition',
                  mode === o.key ? 'border-brand-500 bg-brand-50/60 ring-2 ring-brand-500/15 dark:bg-brand-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700',
                )}
              >
                <span className={clsx('kpi-icon !h-9 !w-9', mode === o.key ? 'bg-brand-800 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                  <o.icon className="h-4 w-4" />
                </span>
                <span>
                  <span className="block text-sm font-semibold text-slate-900 dark:text-white">{o.title}</span>
                  <span className="block text-xs text-slate-500 dark:text-slate-400">{o.text}</span>
                </span>
              </button>
            ))}
          </div>
          {mode === 'temporary' ? (
            <div className="space-y-4 motion-safe:animate-fade-in">
              <Field label="New password" htmlFor="reset-pw" error={fieldError} hint="Leave blank to generate a strong password automatically.">
                <PasswordInput id="reset-pw" value={password} onChange={(v) => { setPassword(v); setFieldError(''); }} invalid={!!fieldError} placeholder="Auto-generate" />
              </Field>
              <Toggle checked={mustChange} onChange={setMustChange} label="Require a password change at next sign-in" description="Recommended for temporary passwords." />
            </div>
          ) : (
            user.status !== 'active' && <Alert variant="warning">This account is inactive. Activate it before sending a reset link.</Alert>
          )}
        </div>
      ) : null}
    </Modal>
  );
}
