import { forwardRef } from 'react';
import clsx from 'clsx';

/** Digits only, at most 6 ("123 456", "Code: 123456" and pasted text with spaces all work). */
export const cleanTotp = (v: string) => v.replace(/\D/g, '').slice(0, 6);

/** Recovery codes look like ABCD-EF23: upper-case letters and digits in two groups of four. */
export function formatRecoveryCode(v: string): string {
  const raw = v.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
  return raw.length > 4 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
}
export const isRecoveryCodeComplete = (v: string) => /^[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(v);

interface OtpProps {
  id: string;
  value: string;
  onChange: (v: string) => void;
  /** Called once the sixth digit is typed or a full code is pasted (auto-submit). */
  onComplete?: (code: string) => void;
  invalid?: boolean;
  disabled?: boolean;
  autoFocus?: boolean;
  describedBy?: string;
}

/**
 * One numeric field for a 6-digit authenticator code: numeric keypad on phones, SMS/one-time-code autofill, paste
 * friendly, auto-submits when complete.
 */
export const OtpCodeInput = forwardRef<HTMLInputElement, OtpProps>(function OtpCodeInput({ id, value, onChange, onComplete, invalid, disabled, autoFocus, describedBy }, ref) {
  return (
    <input
      ref={ref}
      id={id}
      name="one-time-code"
      type="text"
      inputMode="numeric"
      pattern="[0-9]*"
      autoComplete="one-time-code"
      autoCorrect="off"
      spellCheck={false}
      autoFocus={autoFocus}
      disabled={disabled}
      value={value}
      placeholder="000000"
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      onChange={(e) => {
        const next = cleanTotp(e.target.value);
        onChange(next);
        if (next.length === 6 && next !== value) onComplete?.(next);
      }}
      onFocus={(e) => e.currentTarget.select()}
      className={clsx(
        'form-input h-14 text-center font-mono text-2xl font-semibold tabular-nums tracking-[0.45em] pl-[calc(0.875rem_+_0.45em)] placeholder:tracking-[0.45em] placeholder:text-slate-300 dark:placeholder:text-slate-600',
        invalid && 'form-input-error',
      )}
    />
  );
});

interface RecoveryProps {
  id: string;
  value: string;
  onChange: (v: string) => void;
  invalid?: boolean;
  disabled?: boolean;
  autoFocus?: boolean;
  describedBy?: string;
}

/** Text field for a one-time recovery code (XXXX-XXXX), upper-cased and dashed while typing or pasting. */
export const RecoveryCodeInput = forwardRef<HTMLInputElement, RecoveryProps>(function RecoveryCodeInput({ id, value, onChange, invalid, disabled, autoFocus, describedBy }, ref) {
  return (
    <input
      ref={ref}
      id={id}
      type="text"
      autoComplete="off"
      autoCapitalize="characters"
      autoCorrect="off"
      spellCheck={false}
      autoFocus={autoFocus}
      disabled={disabled}
      value={value}
      placeholder="XXXX-XXXX"
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      onChange={(e) => onChange(formatRecoveryCode(e.target.value))}
      className={clsx('form-input h-14 text-center font-mono text-xl font-semibold uppercase tracking-[0.2em] placeholder:text-slate-300 dark:placeholder:text-slate-600', invalid && 'form-input-error')}
    />
  );
});
