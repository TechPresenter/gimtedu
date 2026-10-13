import type { ReactNode } from 'react';
import clsx from 'clsx';
import { CheckCircle2, KeyRound, LogIn, LogOut, Monitor, ShieldAlert, ShieldCheck, Smartphone, Sparkles, XCircle } from 'lucide-react';
import { Button } from '@/components/ui';
import { formatDateTime, labelize, timeAgo } from '@/lib/format';
import type { LoginRow } from '../lib/types';
import { ThemeSwitch } from './UserMenu';
import { Section } from './Ui';

const EVENT: Record<string, { icon: typeof LogIn; tone: string; label: string }> = {
  success: { icon: LogIn, tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300', label: 'Signed in' },
  failed: { icon: XCircle, tone: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300', label: 'Failed sign-in' },
  locked: { icon: ShieldAlert, tone: 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300', label: 'Account locked' },
  logout: { icon: LogOut, tone: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400', label: 'Signed out' },
  activated: { icon: Sparkles, tone: 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300', label: 'Account activated' },
  reset: { icon: KeyRound, tone: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200', label: 'Password changed' },
  blocked: { icon: ShieldAlert, tone: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300', label: 'Blocked' },
};

/** Recent sign-in activity of the signed-in account. */
export function LoginHistory({ rows }: { rows: LoginRow[] }) {
  if (!rows.length) return <p className="py-4 text-center text-sm text-slate-500">No sign-in activity yet.</p>;
  return (
    <ul className="space-y-1">
      {rows.map((r, i) => {
        const ev = EVENT[r.status] ?? { icon: LogIn, tone: 'bg-slate-100 text-slate-500', label: labelize(r.status) };
        const mobile = /android|iphone|ios|ipad/i.test(r.device);
        return (
          <li key={i} className="flex items-start gap-3 rounded-xl px-1.5 py-2">
            <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', ev.tone)}>
              <ev.icon className="h-4 w-4" aria-hidden />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-[13px] font-semibold text-slate-800 dark:text-slate-100">
                {ev.label}
                {r.reason && r.status !== 'success' && <span className="font-normal text-slate-500 dark:text-slate-400"> · {r.reason}</span>}
              </p>
              <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                <span className="inline-flex items-center gap-1">
                  {mobile ? <Smartphone className="h-3 w-3" aria-hidden /> : <Monitor className="h-3 w-3" aria-hidden />}
                  {r.device}
                </span>
                {r.ip_address && <span>IP {r.ip_address}</span>}
              </p>
            </div>
            <time className="shrink-0 text-[11px] text-slate-400" dateTime={r.created_at} title={formatDateTime(r.created_at)}>
              {timeAgo(r.created_at)}
            </time>
          </li>
        );
      })}
    </ul>
  );
}

/** Account & security card (username, password age, last sign-in) with the change-password shortcut. */
export function SecurityCard({ items, passwordTo, note }: { items: { label: string; value: ReactNode }[]; passwordTo: string; note?: ReactNode }) {
  return (
    <Section title="Account & security" icon={ShieldCheck}>
      <dl className="divide-y divide-slate-100 dark:divide-slate-800">
        {items.map((it) => (
          <div key={it.label} className="flex items-center justify-between gap-4 py-2.5 first:pt-0">
            <dt className="text-[13px] text-slate-500 dark:text-slate-400">{it.label}</dt>
            <dd className="text-right text-[13px] font-semibold text-slate-800 dark:text-slate-100">{it.value || <span className="text-slate-400">—</span>}</dd>
          </div>
        ))}
      </dl>
      {note && (
        <p className="mt-3 flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
          <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          {note}
        </p>
      )}
      <Button to={passwordTo} variant="secondary" icon={KeyRound} className="mt-4 w-full">
        Change password
      </Button>
    </Section>
  );
}

export function AppearanceCard() {
  return (
    <Section title="Appearance" subtitle="Saved for your account on this portal">
      <ThemeSwitch />
    </Section>
  );
}
