import { useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { CircleCheck, CircleX, Eye, EyeOff, Info, KeyRound, LaptopMinimal, LogIn, LogOut, ShieldCheck } from 'lucide-react';
import { Badge, Button, Card, CardHeader, DataTable, EmptyState, Field, Input, Pagination, StatusBadge, Tabs, useConfirm, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { DeviceSession, LoginRow, PageOf, ProfileData } from './types';

export function SecurityTab({ data, onChanged }: { data: ProfileData; onChanged: () => void }) {
  return (
    <div className="space-y-6">
      <PasswordCard onChanged={onChanged} passwordAge={data.security.password_age_days} />
      <ChecklistCard data={data} />
      <DevicesCard sessions={data.sessions} onChanged={onChanged} />
      <LoginHistoryCard />
    </div>
  );
}

/* ------------------------------------------------------------ Change password */
function PasswordCard({ onChanged, passwordAge }: { onChanged: () => void; passwordAge: number | null }) {
  const { session } = useAuth();
  const toast = useToast();
  const policy = session?.app.password_policy ?? { min_length: 8, uppercase: true, number: true, special: true };
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [show, setShow] = useState(false);
  const [saving, setSaving] = useState(false);
  const pw = form.password;
  const rules = [
    { ok: pw.length >= policy.min_length, label: `At least ${policy.min_length} characters` },
    policy.uppercase && { ok: /[A-Z]/.test(pw), label: 'An uppercase letter' },
    policy.number && { ok: /\d/.test(pw), label: 'A number' },
    policy.special && { ok: /[^A-Za-z0-9]/.test(pw), label: 'A special character' },
    { ok: pw !== '' && pw === form.password_confirmation, label: 'Both new passwords match' },
  ].filter(Boolean) as { ok: boolean; label: string }[];

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!form.current_password) local.current_password = 'Enter your current password.';
    if (!pw) local.password = 'Enter a new password.';
    else if (rules.slice(0, -1).some((r) => !r.ok)) local.password = 'The new password does not meet the password policy.';
    if (pw && pw !== form.password_confirmation) local.password_confirmation = 'Passwords do not match.';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    setSaving(true);
    try {
      const res = await api.post('auth/password', form);
      setForm({ current_password: '', password: '', password_confirmation: '' });
      setErrors({});
      toast.success(res.message || 'Password updated successfully.');
      onChanged();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };

  const field = (k: keyof typeof form, label: string, auto: string) => (
    <Field label={label} required htmlFor={`pw-${k}`} error={errors[k]}>
      <Input
        id={`pw-${k}`}
        type={show ? 'text' : 'password'}
        value={form[k]}
        onChange={(e) => {
          setForm((f) => ({ ...f, [k]: e.target.value }));
          setErrors((er) => ({ ...er, [k]: '' }));
        }}
        invalid={!!errors[k]}
        autoComplete={auto}
      />
    </Field>
  );

  return (
    <Card>
      <CardHeader
        title="Change password"
        subtitle={passwordAge === null ? 'Choose a strong password you do not use elsewhere.' : `Last changed ${passwordAge === 0 ? 'today' : `${passwordAge} day${passwordAge === 1 ? '' : 's'} ago`}. Changing it signs out your remembered devices.`}
        icon={KeyRound}
        actions={
          <button type="button" onClick={() => setShow((s) => !s)} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-pressed={show}>
            {show ? <EyeOff className="h-3.5 w-3.5" aria-hidden /> : <Eye className="h-3.5 w-3.5" aria-hidden />} {show ? 'Hide' : 'Show'} passwords
          </button>
        }
      />
      <form onSubmit={submit} noValidate className="card-body">
        <div className="grid gap-6 md:grid-cols-5">
          <div className="space-y-4 md:col-span-3">
            {field('current_password', 'Current password', 'current-password')}
            {field('password', 'New password', 'new-password')}
            {field('password_confirmation', 'Confirm new password', 'new-password')}
          </div>
          <div className="md:col-span-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Password policy</p>
            <ul className="mt-2 space-y-2" aria-live="polite">
              {rules.map((r) => (
                <li key={r.label} className={clsx('flex items-center gap-2 text-sm', r.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400')}>
                  {r.ok ? <CircleCheck className="h-4 w-4 shrink-0" aria-hidden /> : <span className="h-4 w-4 shrink-0 rounded-full border-2 border-slate-300 dark:border-slate-600" aria-hidden />}
                  {r.label}
                  <span className="sr-only">{r.ok ? '(met)' : '(not met)'}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
        <div className="mt-5 flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
          <Button type="submit" icon={KeyRound} loading={saving}>
            Update password
          </Button>
        </div>
      </form>
    </Card>
  );
}

/* ------------------------------------------------------------ Security checklist */
function ChecklistCard({ data }: { data: ProfileData }) {
  const s = data.security;
  return (
    <Card>
      <CardHeader title="Security summary" subtitle={`This session: ${s.current_browser} · ${s.current_ip}${s.signed_in_at ? ` · signed in ${timeAgo(s.signed_in_at)}` : ''} · idle timeout ${s.session_timeout_minutes} min`} icon={ShieldCheck} actions={<Badge color={s.score >= 80 ? 'green' : s.score >= 50 ? 'amber' : 'red'}>{s.score}% secure</Badge>} />
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {s.checks.map((c) => (
          <li key={c.key} className="flex items-start gap-3 px-5 py-3.5">
            {c.ok ? (
              <CircleCheck className="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" aria-hidden />
            ) : c.info ? (
              <Info className="mt-0.5 h-5 w-5 shrink-0 text-slate-400" aria-hidden />
            ) : (
              <CircleX className="mt-0.5 h-5 w-5 shrink-0 text-amber-500" aria-hidden />
            )}
            <div className="min-w-0">
              <p className="text-sm font-medium text-slate-800 dark:text-slate-100">
                {c.label}
                <span className="sr-only">{c.ok ? ' — passed' : c.info ? ' — information' : ' — needs attention'}</span>
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{c.detail}</p>
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/* ------------------------------------------------------------ Remembered devices */
function DevicesCard({ sessions, onChanged }: { sessions: DeviceSession[]; onChanged: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [busy, setBusy] = useState<number | 'all' | null>(null);
  const others = sessions.filter((s) => !s.current).length;

  const revoke = async (s: DeviceSession) => {
    const ok = await confirm({ title: 'Sign out this device?', message: <>The remembered sign-in on <strong>{s.browser}</strong> ({s.ip_address ?? 'unknown IP'}) will stop working. Active sessions end at their next request after the idle timeout.</>, confirmText: 'Sign out device', danger: true });
    if (!ok) return;
    setBusy(s.id);
    try {
      const res = await api.del(`profile/sessions/${s.id}`);
      toast.success(res.message || 'Device signed out.');
      onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const revokeAll = async () => {
    const ok = await confirm({ title: 'Sign out all other devices?', message: 'Every other browser where you chose "Remember me" will have to sign in again.', confirmText: 'Sign out others', danger: true });
    if (!ok) return;
    setBusy('all');
    try {
      const res = await api.del('profile/sessions');
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card>
      <CardHeader
        title="Remembered devices"
        subtitle='Browsers where you signed in with "Remember me".'
        icon={LaptopMinimal}
        actions={others > 0 ? <Button size="sm" variant="secondary" icon={LogOut} loading={busy === 'all'} onClick={revokeAll}>Sign out others</Button> : undefined}
      />
      {sessions.length === 0 ? (
        <EmptyState icon={LaptopMinimal} title="No remembered devices" description={'Tick "Remember me" when signing in to stay signed in on a trusted device.'} className="!py-8" />
      ) : (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {sessions.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
              <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                <LaptopMinimal className="h-4 w-4" aria-hidden />
              </span>
              <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100">
                  {s.browser}
                  {s.current && <Badge color="green">This browser</Badge>}
                </p>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {s.ip_address ?? 'Unknown IP'} · signed in {formatDateTime(s.created_at)} · expires {formatDateTime(s.expires_at)}
                </p>
              </div>
              <Button size="sm" variant="ghost" className="!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-500/10" loading={busy === s.id} onClick={() => revoke(s)}>
                Sign out
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/* ------------------------------------------------------------ Login history */
function LoginHistoryCard() {
  const [status, setStatus] = useState<'all' | 'success' | 'failed'>('all');
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useApi<PageOf<LoginRow>>(['profile-logins', status, page], 'profile/logins', { status: status === 'all' ? undefined : status, page, per_page: 10 }, { placeholderData: (p) => p });
  const columns: Column<LoginRow>[] = [
    { key: 'created_at', header: 'Date & time', render: (r) => <span className="whitespace-nowrap">{formatDateTime(r.created_at)}<span className="block text-xs text-slate-400">{timeAgo(r.created_at)}</span></span> },
    { key: 'status', header: 'Result', render: (r) => <span className="flex flex-col items-start gap-0.5"><StatusBadge status={r.status} />{r.reason && <span className="text-xs text-slate-400">{r.reason}</span>}</span> },
    { key: 'browser', header: 'Device', render: (r) => r.browser },
    { key: 'ip_address', header: 'IP address', render: (r) => <span className="font-mono text-xs">{r.ip_address ?? '—'}</span> },
  ];
  return (
    <Card className="overflow-hidden">
      <CardHeader
        title="Login history"
        subtitle="Every sign-in, sign-out and failed attempt on your account."
        icon={LogIn}
        actions={<Tabs variant="pills" value={status} onChange={(v) => { setStatus(v as typeof status); setPage(1); }} tabs={[{ key: 'all', label: 'All' }, { key: 'success', label: 'Successful' }, { key: 'failed', label: 'Failed' }]} />}
      />
      {error ? (
        <p className="px-5 py-6 text-sm text-red-600">{(error as ApiError).message}</p>
      ) : (
        <>
          <DataTable columns={columns} rows={data?.rows ?? []} loading={isLoading} dense skeletonRows={5} caption="Login history" empty={<EmptyState icon={LogIn} title="No sign-ins found" className="!py-8" />} />
          {data && data.total > data.per_page && <Pagination page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} className="border-t border-slate-100 dark:border-slate-800" />}
        </>
      )}
    </Card>
  );
}
