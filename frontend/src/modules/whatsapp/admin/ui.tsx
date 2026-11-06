import { useState, type ReactNode } from 'react';
import clsx from 'clsx';
import { CalendarRange, Check, Copy, Eye, EyeOff } from 'lucide-react';
import { Button, Input, useToast } from '@/components/ui';
import { isoDate } from '@/lib/format';
import type { Period } from './lib';

/** Small building blocks shared by the WhatsApp dashboard, reports, integrations, API, team and settings pages. */

export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    /* fall through */
  }
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  } catch {
    return false;
  }
}

/** Copy button with a check-mark confirmation. */
export function CopyButton({ text, label = 'Copy', size = 'sm', className }: { text: string; label?: string; size?: 'xs' | 'sm'; className?: string }) {
  const toast = useToast();
  const [done, setDone] = useState(false);
  return (
    <Button
      type="button"
      size={size}
      variant="secondary"
      icon={done ? Check : Copy}
      className={className}
      onClick={async () => {
        const ok = await copyText(text);
        if (ok) {
          setDone(true);
          window.setTimeout(() => setDone(false), 1600);
        } else toast.error('Copy failed — select the text and copy it manually.');
      }}
    >
      {done ? 'Copied' : label}
    </Button>
  );
}

/** Read-only value with a copy button (URLs, commands). `secret` hides the value behind an eye toggle. */
export function CopyField({ value, label, secret, mono = true }: { value: string; label?: string; secret?: boolean; mono?: boolean }) {
  const [show, setShow] = useState(!secret);
  return (
    <div className="flex min-w-0 items-stretch gap-2">
      <div
        className={clsx(
          'flex min-w-0 flex-1 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200',
          mono && 'font-mono text-[12.5px]',
        )}
      >
        <span className="min-w-0 flex-1 truncate" title={show ? value : undefined} aria-label={label}>
          {show ? value : '•'.repeat(Math.min(40, Math.max(12, value.length)))}
        </span>
        {secret && (
          <button
            type="button"
            className="ml-2 shrink-0 rounded p-0.5 text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:hover:text-slate-200"
            onClick={() => setShow((s) => !s)}
            aria-label={show ? 'Hide value' : 'Show value'}
          >
            {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        )}
      </div>
      <CopyButton text={value} />
    </div>
  );
}

/** Dark code block with a copy button. */
export function CodeBlock({ code, language, className }: { code: string; language?: string; className?: string }) {
  return (
    <div className={clsx('relative overflow-hidden rounded-xl border border-slate-800 bg-slate-950 dark:border-slate-700', className)}>
      <div className="flex items-center justify-between border-b border-white/10 px-3 py-1.5">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{language ?? 'code'}</span>
        <CopyButton text={code} size="xs" className="!border-white/10 !bg-white/5 !text-slate-200 hover:!bg-white/10" />
      </div>
      <pre className="max-h-[28rem] overflow-auto p-3 text-[12.5px] leading-relaxed text-slate-100">
        <code>{code}</code>
      </pre>
    </div>
  );
}

/** Pretty JSON viewer (payloads, requests). */
export function JsonView({ value, className }: { value: unknown; className?: string }) {
  let text: string;
  if (typeof value === 'string') {
    try {
      text = JSON.stringify(JSON.parse(value), null, 2);
    } catch {
      text = value;
    }
  } else text = JSON.stringify(value ?? null, null, 2);
  return <CodeBlock code={text} language="json" className={className} />;
}

/** Coloured dot for health states. */
export function HealthDot({ state, pulse }: { state: 'ok' | 'warning' | 'error' | 'off'; pulse?: boolean }) {
  const c = { ok: 'bg-emerald-500', warning: 'bg-amber-500', error: 'bg-red-500', off: 'bg-slate-400' }[state];
  return (
    <span className="relative inline-flex h-2.5 w-2.5 shrink-0" aria-hidden>
      {pulse && state !== 'off' && <span className={clsx('absolute inline-flex h-full w-full rounded-full opacity-60 motion-safe:animate-ping', c)} />}
      <span className={clsx('relative inline-flex h-2.5 w-2.5 rounded-full', c)} />
    </span>
  );
}

/** Label / value row used inside cards. */
export function KV({ label, children, className }: { label: ReactNode; children: ReactNode; className?: string }) {
  return (
    <div className={clsx('flex items-start justify-between gap-3 py-2 text-sm', className)}>
      <dt className="shrink-0 text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="min-w-0 text-right font-medium text-slate-800 dark:text-slate-100">{children}</dd>
    </div>
  );
}

const PERIODS: { key: Period; label: string }[] = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
  { key: 'custom', label: 'Custom' },
];

/** Daily / Weekly / Monthly / Custom switch with a date range for custom. */
export function PeriodPicker({
  period,
  from,
  to,
  onChange,
  className,
}: {
  period: Period;
  from?: string;
  to?: string;
  onChange: (v: { period: Period; from?: string; to?: string }) => void;
  className?: string;
}) {
  const today = isoDate();
  const [f, setF] = useState(from || isoDate(new Date(Date.now() - 29 * 86400000)));
  const [t, setT] = useState(to || today);
  const invalid = !f || !t || f > t;
  return (
    <div className={clsx('flex flex-wrap items-center gap-2', className)}>
      <div className="pill-tabs" role="tablist" aria-label="Period">
        {PERIODS.map((p) => (
          <button
            key={p.key}
            type="button"
            role="tab"
            aria-selected={period === p.key}
            className={clsx('pill-tab', period === p.key && 'pill-tab-active')}
            onClick={() => (p.key === 'custom' ? onChange({ period: 'custom', from: f, to: t }) : onChange({ period: p.key }))}
          >
            {p.label}
          </button>
        ))}
      </div>
      {period === 'custom' && (
        <div className="flex flex-wrap items-center gap-2">
          <CalendarRange className="hidden h-4 w-4 text-slate-400 sm:block" aria-hidden />
          <Input type="date" inputSize="sm" value={f} max={today} onChange={(e) => setF(e.target.value)} aria-label="From date" className="w-[9.5rem]" />
          <span className="text-xs text-slate-400">to</span>
          <Input type="date" inputSize="sm" value={t} max={today} onChange={(e) => setT(e.target.value)} aria-label="To date" className="w-[9.5rem]" />
          <Button size="sm" variant="secondary" disabled={invalid || (f === from && t === to)} onClick={() => onChange({ period: 'custom', from: f, to: t })}>
            Apply
          </Button>
        </div>
      )}
    </div>
  );
}

/** Section heading inside cards / tabs. */
export function SectionTitle({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <h3 className="font-display text-base font-semibold text-slate-900 dark:text-white">{title}</h3>
        {description && <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** Mini horizontal meter (rates in tables). */
export function RateBar({ value, tone = 'green' }: { value: number; tone?: 'green' | 'blue' | 'orange' | 'red' }) {
  const bar = { green: 'bg-emerald-500', blue: 'bg-blue-600', orange: 'bg-accent-500', red: 'bg-red-500' }[tone];
  const v = Math.max(0, Math.min(100, value));
  return (
    <span className="inline-flex items-center gap-2">
      <span className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-slate-200 sm:inline-block dark:bg-slate-700" aria-hidden>
        <span className={clsx('block h-full rounded-full', bar)} style={{ width: `${v}%` }} />
      </span>
      <span className="tabular-nums">{value.toFixed(value % 1 ? 1 : 0)}%</span>
    </span>
  );
}
