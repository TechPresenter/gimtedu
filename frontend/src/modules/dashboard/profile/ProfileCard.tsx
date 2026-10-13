import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { Bell, Building2, Camera, CalendarDays, Clock3, Mail, Phone, ShieldCheck, Trash2, UserRound } from 'lucide-react';
import { Avatar, Badge, Card, ProgressBar, Spinner, useConfirm, useToast } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { formatDate, formatDateTime, formatNumber, humanFileSize } from '@/lib/format';
import type { ProfileData, ProfileUser } from './types';

const MAX_PHOTO = 5 * 1024 * 1024;

/** Left column: photo (upload / remove), identity, roles, quick stats and the security score. */
export function ProfileCard({ data, onUpdated }: { data: ProfileData; onUpdated: (u: ProfileUser) => void }) {
  const u = data.user;
  const toast = useToast();
  const confirm = useConfirm();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  const upload = async (file: File | undefined) => {
    if (!file) return;
    if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) {
      toast.error('Choose a JPG, PNG, WebP or GIF image.');
      return;
    }
    if (file.size > MAX_PHOTO) {
      toast.error(`The photo must be smaller than ${humanFileSize(MAX_PHOTO)}.`);
      return;
    }
    setBusy(true);
    try {
      const res = await api.post<{ user: ProfileUser }>('profile', toFormData({ avatar: file }));
      onUpdated(res.data.user);
      toast.success('Profile photo updated.');
    } catch (e) {
      const err = e as ApiError;
      toast.error(err.errors?.avatar ?? err.message);
    } finally {
      setBusy(false);
      if (input.current) input.current.value = '';
    }
  };

  const remove = async () => {
    if (!(await confirm({ title: 'Remove profile photo?', message: 'Your initials will be shown instead.', confirmText: 'Remove photo', danger: true }))) return;
    setBusy(true);
    try {
      const res = await api.post<{ user: ProfileUser }>('profile', { remove_avatar: 1 });
      onUpdated(res.data.user);
      toast.success('Profile photo removed.');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const score = data.security.score;
  const scoreTone = score >= 80 ? 'green' : score >= 50 ? 'amber' : 'red';
  const pending = data.security.checks.filter((c) => !c.ok && !c.info).length;

  return (
    <Card className="overflow-hidden">
      <div className="relative h-24 bg-gradient-to-r from-brand-900 via-brand-800 to-brand-600">
        <div className="absolute inset-0 opacity-20 [background-image:radial-gradient(circle_at_1px_1px,white_1px,transparent_0)] [background-size:16px_16px]" aria-hidden />
      </div>
      <div className="px-5 pb-5">
        <div className="-mt-12 flex items-end justify-between gap-3">
          <div className="relative">
            <Avatar name={u.name} src={u.avatar} size="xl" className="!h-24 !w-24 text-2xl ring-4 ring-white dark:ring-slate-900" />
            <button
              type="button"
              onClick={() => input.current?.click()}
              disabled={busy}
              className="absolute bottom-0 right-0 inline-flex h-9 w-9 items-center justify-center rounded-full bg-accent-600 text-white shadow-soft ring-4 ring-white transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-accent-300 disabled:opacity-70 dark:ring-slate-900"
              aria-label="Change profile photo"
              title="Change profile photo"
            >
              {busy ? <Spinner className="h-4 w-4" /> : <Camera className="h-4 w-4" aria-hidden />}
            </button>
            <input ref={input} type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => upload(e.target.files?.[0])} />
          </div>
          {u.avatar && (
            <button type="button" onClick={remove} disabled={busy} className="mb-1 inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10">
              <Trash2 className="h-3.5 w-3.5" aria-hidden /> Remove photo
            </button>
          )}
        </div>
        <h2 className="mt-3 font-display text-xl font-bold text-slate-900 dark:text-white">{u.name}</h2>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {u.designation || 'Team member'} · @{u.username}
        </p>
        <div className="mt-3 flex flex-wrap gap-1.5">
          {u.roles.map((r) => (
            <Badge key={r.id} color={r.is_super ? 'red' : 'navy'}>
              {r.is_super && <ShieldCheck className="h-3 w-3" aria-hidden />}
              {r.name}
            </Badge>
          ))}
          {u.status !== 'active' && <Badge color="amber">{u.status}</Badge>}
        </div>

        <dl className="mt-5 space-y-2.5 text-sm">
          {[
            { icon: Mail, label: 'Email', value: u.email },
            { icon: Phone, label: 'Phone', value: u.phone || 'Not added' },
            ...(u.department ? [{ icon: Building2, label: 'Department', value: u.department }] : []),
            { icon: CalendarDays, label: 'Member since', value: formatDate(u.created_at) },
            { icon: Clock3, label: 'Last sign-in', value: data.security.previous_login?.at ? `${formatDateTime(data.security.previous_login.at)}${data.security.previous_login.ip ? ` · ${data.security.previous_login.ip}` : ''}` : 'First sign-in' },
          ].map((row) => (
            <div key={row.label} className="flex items-start gap-3">
              <row.icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden />
              <dt className="sr-only">{row.label}</dt>
              <dd className="min-w-0 break-words text-slate-700 dark:text-slate-200">{row.value}</dd>
            </div>
          ))}
        </dl>

        <div className="mt-5 grid grid-cols-2 gap-3">
          <Link to="/profile?tab=activity" className="rounded-xl bg-slate-50 p-3 transition hover:-translate-y-0.5 hover:shadow-soft dark:bg-slate-800/60">
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Actions · 30 days</p>
            <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{formatNumber(data.stats.actions_30d)}</p>
          </Link>
          <Link to="/profile?tab=security" className="rounded-xl bg-slate-50 p-3 transition hover:-translate-y-0.5 hover:shadow-soft dark:bg-slate-800/60">
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Sign-ins · 30 days</p>
            <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{formatNumber(data.security.logins_30d)}</p>
          </Link>
          <Link to="/notifications?filter=unread" className="rounded-xl bg-slate-50 p-3 transition hover:-translate-y-0.5 hover:shadow-soft dark:bg-slate-800/60">
            <p className="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"><Bell className="h-3 w-3" aria-hidden /> Alerts</p>
            <p className="font-display text-lg font-bold text-slate-900 dark:text-white">
              {formatNumber(data.stats.unread_notifications)} <span className="text-xs font-medium text-slate-500 dark:text-slate-400">unread</span>
            </p>
          </Link>
          <Link to="/messages" className="rounded-xl bg-slate-50 p-3 transition hover:-translate-y-0.5 hover:shadow-soft dark:bg-slate-800/60">
            <p className="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"><Mail className="h-3 w-3" aria-hidden /> Messages</p>
            <p className="font-display text-lg font-bold text-slate-900 dark:text-white">
              {formatNumber(data.stats.unread_messages)} <span className="text-xs font-medium text-slate-500 dark:text-slate-400">unread</span>
            </p>
          </Link>
        </div>

        <Link to="/profile?tab=security" className="mt-5 block rounded-xl border border-slate-100 p-4 transition hover:border-slate-200 hover:shadow-soft dark:border-slate-800 dark:hover:border-slate-700">
          <div className="flex items-center justify-between gap-2">
            <p className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <UserRound className="h-4 w-4 text-slate-400" aria-hidden /> Account security
            </p>
            <span className={clsx('text-sm font-bold tabular-nums', scoreTone === 'green' ? 'text-emerald-600' : scoreTone === 'amber' ? 'text-amber-600' : 'text-red-600')}>{score}%</span>
          </div>
          <ProgressBar value={score} tone={scoreTone} className="mt-2" label="Account security score" />
          <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
            {pending ? `${pending} recommendation${pending === 1 ? '' : 's'} to review` : 'All security recommendations are met'}
          </p>
        </Link>
      </div>
    </Card>
  );
}
