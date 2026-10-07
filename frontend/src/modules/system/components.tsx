import { useMemo, useState, type ReactNode } from 'react';
import clsx from 'clsx';
import {
  Activity, ArchiveRestore, BadgeCheck, Ban, Check, CircleAlert, CircleCheck, CircleX, Copy, Download, Eye, EyeOff, Info, KeyRound, LockOpen, LogIn, LogOut,
  Megaphone, Pencil, Plus, Send, ShieldCheck, ShieldX, Trash2, Upload, Wand2, type LucideIcon,
} from 'lucide-react';
import { Badge, Input, toneClasses, useToast, type Tone } from '@/components/ui';
import type { BadgeColor } from '@/lib/status';
import { useAuth } from '@/lib/auth';
import { labelize } from '@/lib/format';
import type { RoleRef } from './types';

/* ---------------------------------------------------------------- Roles */
const roleBadgeColor: Record<string, BadgeColor> = {
  red: 'red', blue: 'blue', navy: 'navy', green: 'green', cyan: 'cyan', purple: 'purple', amber: 'amber', orange: 'amber', pink: 'purple', slate: 'slate',
};
const roleDot: Record<string, string> = {
  red: 'bg-red-500', blue: 'bg-blue-600', navy: 'bg-brand-800', green: 'bg-emerald-500', cyan: 'bg-cyan-500', purple: 'bg-violet-500', amber: 'bg-amber-500',
  orange: 'bg-orange-500', pink: 'bg-rose-500', slate: 'bg-slate-400',
};

export const roleColor = (c: string | null | undefined): BadgeColor => roleBadgeColor[c ?? ''] ?? 'slate';
export const roleDotClass = (c: string | null | undefined) => roleDot[c ?? ''] ?? 'bg-slate-400';

export function RoleBadges({ roles, max = 3 }: { roles: RoleRef[]; max?: number }) {
  if (!roles.length) return <span className="text-xs text-slate-400">No role</span>;
  return (
    <div className="flex flex-wrap gap-1">
      {roles.slice(0, max).map((r) => (
        <Badge key={r.id} color={roleColor(r.color)} className="!px-2">
          {r.is_super && <ShieldCheck className="h-3 w-3" aria-hidden />}
          {r.name}
        </Badge>
      ))}
      {roles.length > max && <Badge color="slate">+{roles.length - max}</Badge>}
    </div>
  );
}

/* ---------------------------------------------------------------- Audit actions */
const actionMap: Record<string, { icon: LucideIcon; tone: Tone }> = {
  create: { icon: Plus, tone: 'green' },
  update: { icon: Pencil, tone: 'blue' },
  delete: { icon: Trash2, tone: 'red' },
  purge: { icon: Trash2, tone: 'red' },
  login: { icon: LogIn, tone: 'cyan' },
  logout: { icon: LogOut, tone: 'slate' },
  export: { icon: Download, tone: 'purple' },
  download: { icon: Download, tone: 'purple' },
  import: { icon: Upload, tone: 'purple' },
  approve: { icon: BadgeCheck, tone: 'green' },
  publish: { icon: Megaphone, tone: 'navy' },
  restore: { icon: ArchiveRestore, tone: 'amber' },
  denied: { icon: ShieldX, tone: 'red' },
  password_reset: { icon: KeyRound, tone: 'amber' },
  password_reset_link: { icon: KeyRound, tone: 'amber' },
  password_reset_requested: { icon: KeyRound, tone: 'amber' },
  unlock: { icon: LockOpen, tone: 'amber' },
  revoke: { icon: Ban, tone: 'red' },
  view: { icon: Eye, tone: 'slate' },
  verify: { icon: ShieldCheck, tone: 'green' },
  test_email: { icon: Send, tone: 'cyan' },
};

export function actionMeta(action: string, status?: string): { icon: LucideIcon; tone: Tone } {
  if (status && status !== 'success') return { icon: actionMap[action]?.icon ?? CircleAlert, tone: 'red' };
  return actionMap[action] ?? { icon: Activity, tone: 'slate' };
}

const toneBadge: Record<Tone, BadgeColor> = {
  blue: 'blue', navy: 'navy', green: 'green', orange: 'amber', amber: 'amber', purple: 'purple', pink: 'purple', red: 'red', cyan: 'cyan', slate: 'slate',
};

export function ActionBadge({ action, status }: { action: string; status?: string }) {
  const m = actionMeta(action, status);
  const Icon = m.icon;
  return (
    <Badge color={toneBadge[m.tone]} className="!px-2">
      <Icon className="h-3 w-3" aria-hidden />
      {labelize(action)}
    </Badge>
  );
}

export function ActionIcon({ action, status, size = 'md' }: { action: string; status?: string; size?: 'sm' | 'md' }) {
  const m = actionMeta(action, status);
  const Icon = m.icon;
  return (
    <span className={clsx('inline-flex shrink-0 items-center justify-center rounded-full', toneClasses[m.tone].icon, size === 'sm' ? 'h-7 w-7' : 'h-8 w-8')}>
      <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'} aria-hidden />
    </span>
  );
}

/* ---------------------------------------------------------------- Check states */
export type CheckStatus = 'pass' | 'warn' | 'fail' | 'info';
export const checkMeta: Record<CheckStatus, { icon: LucideIcon; tone: Tone; label: string }> = {
  pass: { icon: CircleCheck, tone: 'green', label: 'Pass' },
  warn: { icon: CircleAlert, tone: 'amber', label: 'Warning' },
  fail: { icon: CircleX, tone: 'red', label: 'Action needed' },
  info: { icon: Info, tone: 'blue', label: 'Info' },
};

/* ---------------------------------------------------------------- Misc */
export function CopyButton({ value, label = 'Copy', className }: { value: string; label?: string; className?: string }) {
  const toast = useToast();
  const [done, setDone] = useState(false);
  return (
    <button
      type="button"
      className={clsx('inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10', className)}
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(value);
          setDone(true);
          setTimeout(() => setDone(false), 1600);
        } catch {
          toast.error('Copy failed — select the text and copy it manually.');
        }
      }}
    >
      {done ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
      {done ? 'Copied' : label}
    </button>
  );
}

/** Pretty JSON with light syntax colouring. */
export function JsonView({ data }: { data: unknown }) {
  const html = useMemo(() => {
    const json = JSON.stringify(data, null, 2) ?? '';
    const esc = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return esc.replace(/("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g, (m) => {
      let cls = 'text-amber-600 dark:text-amber-300';
      if (m.startsWith('"')) cls = m.endsWith(':') ? 'text-brand-700 dark:text-brand-300' : 'text-emerald-700 dark:text-emerald-300';
      else if (/true|false|null/.test(m)) cls = 'text-violet-600 dark:text-violet-300';
      return `<span class="${cls}">${m}</span>`;
    });
  }, [data]);
  return (
    <pre className="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-slate-50 p-3 font-mono text-xs leading-relaxed text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
      <code dangerouslySetInnerHTML={{ __html: html }} />
    </pre>
  );
}

/** Simple label/value list used in drawers. */
export function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5 text-sm">
      <span className="shrink-0 text-slate-500 dark:text-slate-400">{label}</span>
      <span className="min-w-0 text-right font-medium text-slate-800 dark:text-slate-100">{children ?? <span className="text-slate-400">—</span>}</span>
    </div>
  );
}

/* ---------------------------------------------------------------- Password with policy */
export interface PasswordPolicy {
  min_length: number;
  uppercase: boolean;
  number: boolean;
  special: boolean;
}

export function usePasswordPolicy(): PasswordPolicy {
  const { session } = useAuth();
  return session?.app.password_policy ?? { min_length: 8, uppercase: true, number: true, special: true };
}

export function passwordChecks(pw: string, p: PasswordPolicy) {
  return [
    { label: `At least ${p.min_length} characters`, ok: pw.length >= p.min_length, required: true },
    { label: 'An uppercase letter', ok: /[A-Z]/.test(pw), required: p.uppercase },
    { label: 'A number', ok: /\d/.test(pw), required: p.number },
    { label: 'A special character', ok: /[^A-Za-z0-9]/.test(pw), required: p.special },
  ].filter((c) => c.required);
}

export function generatePassword(p: PasswordPolicy): string {
  const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '@#$%&*!?'];
  const len = Math.max(12, p.min_length + 2);
  const rand = (n: number) => {
    const a = new Uint32Array(1);
    crypto.getRandomValues(a);
    return a[0] % n;
  };
  const chars = sets.map((s) => s[rand(s.length)]);
  const all = sets.join('');
  while (chars.length < len) chars.push(all[rand(all.length)]);
  for (let i = chars.length - 1; i > 0; i--) {
    const j = rand(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
}

interface PasswordInputProps {
  id: string;
  value: string;
  onChange: (v: string) => void;
  invalid?: boolean;
  placeholder?: string;
  /** Show generate + copy helpers */
  generator?: boolean;
  /** Show the strength meter and policy checklist (only when there is a value) */
  meter?: boolean;
  autoComplete?: string;
}

/** Password input with show/hide, optional generator and a live policy checklist. */
export function PasswordInput({ id, value, onChange, invalid, placeholder, generator = true, meter = true, autoComplete = 'new-password' }: PasswordInputProps) {
  const policy = usePasswordPolicy();
  const [show, setShow] = useState(false);
  const checks = passwordChecks(value, policy);
  const passed = checks.filter((c) => c.ok).length;
  const strength = value ? Math.min(4, Math.round((passed / checks.length) * 3) + (value.length >= policy.min_length + 4 ? 1 : 0)) : 0;
  const bar = ['bg-red-500', 'bg-red-500', 'bg-amber-500', 'bg-blue-600', 'bg-emerald-500'][strength];
  const word = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'][strength];
  return (
    <div>
      <div className="relative">
        <Input id={id} type={show ? 'text' : 'password'} value={value} onChange={(e) => onChange(e.target.value)} invalid={invalid} placeholder={placeholder} autoComplete={autoComplete} className="pr-20 font-mono" />
        <div className="absolute inset-y-0 right-1.5 flex items-center gap-0.5">
          {generator && (
            <button
              type="button"
              className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-brand-700 dark:hover:bg-slate-800"
              title="Generate a strong password"
              aria-label="Generate a strong password"
              onClick={() => {
                onChange(generatePassword(policy));
                setShow(true);
              }}
            >
              <Wand2 className="h-4 w-4" />
            </button>
          )}
          <button type="button" className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" onClick={() => setShow((s) => !s)} aria-label={show ? 'Hide password' : 'Show password'} title={show ? 'Hide password' : 'Show password'}>
            {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </div>
      </div>
      {meter && value && (
        <div className="mt-2 space-y-2 motion-safe:animate-fade-in">
          <div className="flex items-center gap-2">
            <div className="flex flex-1 gap-1" aria-hidden>
              {[1, 2, 3, 4].map((i) => (
                <span key={i} className={clsx('h-1.5 flex-1 rounded-full transition-colors duration-300', i <= strength ? bar : 'bg-slate-200 dark:bg-slate-700')} />
              ))}
            </div>
            <span className="w-16 text-right text-xs font-medium text-slate-500" aria-live="polite">{word}</span>
            {generator && show && <CopyButton value={value} />}
          </div>
          <ul className="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
            {checks.map((c) => (
              <li key={c.label} className={clsx('flex items-center gap-1.5 text-xs transition-colors', c.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500')}>
                {c.ok ? <CircleCheck className="h-3.5 w-3.5" /> : <span className="mx-[3px] h-2 w-2 rounded-full border border-current" />}
                {c.label}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

/** Input that must match a phrase (e.g. RESTORE) before a dangerous action is enabled. */
export function TypeToConfirm({ phrase, value, onChange, error }: { phrase: string; value: string; onChange: (v: string) => void; error?: string }) {
  return (
    <div>
      <label htmlFor="type-to-confirm" className="form-label">
        Type <code className="rounded bg-red-50 px-1.5 py-0.5 font-mono text-xs font-bold text-red-700 dark:bg-red-500/10 dark:text-red-300">{phrase}</code> to confirm
      </label>
      <Input id="type-to-confirm" value={value} onChange={(e) => onChange(e.target.value)} autoComplete="off" spellCheck={false} invalid={!!error} className="font-mono tracking-widest" placeholder={phrase} />
      {error && <p className="form-error">{error}</p>}
    </div>
  );
}
