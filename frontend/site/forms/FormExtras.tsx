import clsx from 'clsx';
import { Lightbulb } from 'lucide-react';
import type { CaptchaConfig } from './security';

/** "Did you mean rahul@gmail.com?" — one-click correction of a likely email typo (polite live region). */
export function EmailSuggestion({ suggestion, onAccept, dark }: { suggestion: string | null; onAccept: (s: string) => void; dark?: boolean }) {
  return (
    <p className={clsx('mt-1.5 text-xs', !suggestion && 'sr-only', dark ? 'text-white/80' : 'text-slate-600')} aria-live="polite">
      {suggestion && (
        <>
          <Lightbulb className={clsx('mr-1 inline h-3.5 w-3.5 align-[-2px]', dark ? 'text-accent-300' : 'text-accent-600')} aria-hidden="true" />
          Did you mean{' '}
          <button type="button" className={clsx('font-semibold underline underline-offset-2', dark ? 'text-white' : 'text-accent-700 hover:text-accent-800')} onClick={() => onAccept(suggestion)}>
            {suggestion}
          </button>
          ?
        </>
      )}
    </p>
  );
}

/**
 * Notice required when the reCAPTCHA badge is hidden (Google's terms), plus the host element for a Turnstile /
 * hCaptcha challenge when one is needed. Renders nothing without a captcha.
 */
export function CaptchaNote({ captcha, holderRef, dark }: { captcha: CaptchaConfig | null | undefined; holderRef?: (el: HTMLDivElement | null) => void; dark?: boolean }) {
  if (!captcha) return null;
  const link = clsx('underline underline-offset-2', dark ? 'text-white/85' : 'text-slate-600');
  return (
    <div className="col-span-2">
      <div ref={holderRef} className="flex justify-center empty:hidden" />
      {captcha.provider === 'recaptcha_v3' && (
        <p className={clsx('text-[11px] leading-snug', dark ? 'text-white/60' : 'text-slate-500')}>
          This form is protected by reCAPTCHA and the Google{' '}
          <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" className={link}>
            Privacy Policy
          </a>{' '}
          and{' '}
          <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer" className={link}>
            Terms of Service
          </a>{' '}
          apply.
        </p>
      )}
    </div>
  );
}
