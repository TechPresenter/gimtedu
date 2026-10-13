import { forwardRef, useState, type InputHTMLAttributes } from 'react';
import clsx from 'clsx';
import { Check, Eye, EyeOff, X } from 'lucide-react';
import type { PasswordPolicy } from '../lib/types';

interface PasswordInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  invalid?: boolean;
  large?: boolean;
}

/** Password input with an accessible show / hide toggle. */
export const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(function PasswordInput({ invalid, large, className, ...rest }, ref) {
  const [show, setShow] = useState(false);
  return (
    <div className="relative">
      <input
        ref={ref}
        type={show ? 'text' : 'password'}
        aria-invalid={invalid || undefined}
        className={clsx('form-input pr-12', large && 'form-input-lg', invalid && 'form-input-error', className)}
        {...rest}
      />
      <button
        type="button"
        onClick={() => setShow((s) => !s)}
        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-xl text-slate-400 transition hover:text-slate-700 focus-visible:text-slate-700 dark:hover:text-white"
        aria-label={show ? 'Hide password' : 'Show password'}
        aria-pressed={show}
        title={show ? 'Hide password' : 'Show password'}
      >
        {show ? <EyeOff className="h-[18px] w-[18px]" /> : <Eye className="h-[18px] w-[18px]" />}
      </button>
    </div>
  );
});

export interface PolicyCheck {
  key: string;
  label: string;
  ok: boolean;
}

export function policyChecks(password: string, policy: PasswordPolicy): PolicyCheck[] {
  const checks: PolicyCheck[] = [{ key: 'length', label: `At least ${policy.min_length} characters`, ok: password.length >= policy.min_length }];
  if (policy.uppercase) checks.push({ key: 'upper', label: 'One uppercase letter', ok: /[A-Z]/.test(password) });
  if (policy.number) checks.push({ key: 'number', label: 'One number', ok: /\d/.test(password) });
  if (policy.special) checks.push({ key: 'special', label: 'One special character', ok: /[^A-Za-z0-9]/.test(password) });
  return checks;
}

/** 0-4 strength score (policy coverage + length bonus). */
export function passwordScore(password: string, policy: PasswordPolicy): number {
  if (!password) return 0;
  const checks = policyChecks(password, policy);
  const passed = checks.filter((c) => c.ok).length / checks.length;
  let score = Math.round(passed * 3);
  if (passed === 1 && password.length >= Math.max(12, policy.min_length + 4)) score = 4;
  return Math.max(1, score);
}

const LEVELS = [
  { label: '', bar: 'bg-slate-200 dark:bg-slate-700', text: 'text-slate-400' },
  { label: 'Weak', bar: 'bg-red-500', text: 'text-red-600 dark:text-red-400' },
  { label: 'Fair', bar: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400' },
  { label: 'Good', bar: 'bg-emerald-500', text: 'text-emerald-600 dark:text-emerald-400' },
  { label: 'Strong', bar: 'bg-emerald-600', text: 'text-emerald-700 dark:text-emerald-300' },
];

/** Live password policy checklist + strength meter. */
export function PasswordRules({ password, policy, className }: { password: string; policy: PasswordPolicy; className?: string }) {
  const checks = policyChecks(password, policy);
  const score = passwordScore(password, policy);
  const level = LEVELS[score];
  return (
    <div className={clsx('rounded-xl border border-slate-200/80 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-900/60', className)} aria-live="polite">
      <div className="flex items-center gap-3">
        <div className="grid flex-1 grid-cols-4 gap-1.5" aria-hidden>
          {[1, 2, 3, 4].map((i) => (
            <span key={i} className={clsx('h-1.5 rounded-full transition-colors duration-300', i <= score ? level.bar : 'bg-slate-200 dark:bg-slate-700')} />
          ))}
        </div>
        <span className={clsx('w-14 text-right text-xs font-semibold', level.text)}>{password ? level.label : ''}</span>
      </div>
      <ul className="mt-2.5 grid gap-1.5 sm:grid-cols-2">
        {checks.map((c) => (
          <li key={c.key} className={clsx('flex items-center gap-1.5 text-xs', c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-500 dark:text-slate-400')}>
            <span className={clsx('inline-flex h-4 w-4 items-center justify-center rounded-full', c.ok ? 'bg-emerald-100 dark:bg-emerald-500/20' : 'bg-slate-200/70 dark:bg-slate-800')}>
              {c.ok ? <Check className="h-3 w-3" aria-hidden /> : <X className="h-3 w-3" aria-hidden />}
            </span>
            {c.label}
            <span className="sr-only">{c.ok ? '(met)' : '(not met)'}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
