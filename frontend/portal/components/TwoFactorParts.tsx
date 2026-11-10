import { forwardRef, useEffect, useRef, useState, type ChangeEvent } from 'react';
import clsx from 'clsx';
import { Check, Copy, QrCode } from 'lucide-react';
import { appUrl } from '../lib/config';

/* ------------------------------------------------------------------ Code input (6-digit TOTP or XXXX-XXXX recovery code) */

export type CodeMode = 'totp' | 'recovery';

/** Keeps only what a code of this kind can contain ("123 456" -> "123456", "ab2c-d3ef" -> "AB2C-D3EF"). */
export function normalizeCode(raw: string, mode: CodeMode): string {
  if (mode === 'totp') return raw.replace(/\D/g, '').slice(0, 6);
  const chars = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
  return chars.length > 4 ? `${chars.slice(0, 4)}-${chars.slice(4)}` : chars;
}

export function isCompleteCode(code: string, mode: CodeMode): boolean {
  return mode === 'totp' ? /^\d{6}$/.test(code) : /^[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(code);
}

interface CodeInputProps {
  id: string;
  mode: CodeMode;
  value: string;
  onChange: (value: string) => void;
  invalid?: boolean;
  disabled?: boolean;
  describedBy?: string;
  /** called once the code is complete after typing / pasting (used to submit a 6-digit code straight away) */
  onComplete?: (value: string) => void;
}

/** Large single-field code input: numeric keypad + one-time-code autofill for TOTP, auto-formatted recovery codes. */
export const CodeInput = forwardRef<HTMLInputElement, CodeInputProps>(function CodeInput({ id, mode, value, onChange, invalid, disabled, describedBy, onComplete }, ref) {
  const handle = (e: ChangeEvent<HTMLInputElement>) => {
    const next = normalizeCode(e.target.value, mode);
    onChange(next);
    if (onComplete && next !== value && isCompleteCode(next, mode)) onComplete(next);
  };
  return (
    <input
      ref={ref}
      id={id}
      name={mode === 'totp' ? 'otp' : 'recovery-code'}
      type="text"
      value={value}
      onChange={handle}
      disabled={disabled}
      inputMode={mode === 'totp' ? 'numeric' : 'text'}
      pattern={mode === 'totp' ? '[0-9]*' : undefined}
      autoComplete={mode === 'totp' ? 'one-time-code' : 'off'}
      autoCapitalize={mode === 'totp' ? 'off' : 'characters'}
      autoCorrect="off"
      spellCheck={false}
      maxLength={mode === 'totp' ? 6 : 9}
      placeholder={mode === 'totp' ? '000000' : 'XXXX-XXXX'}
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      className={clsx(
        'form-input form-input-lg text-center font-mono text-2xl font-semibold tracking-[0.35em] placeholder:tracking-[0.35em] placeholder:text-slate-300 dark:placeholder:text-slate-600',
        mode === 'recovery' && 'text-xl tracking-[0.2em] placeholder:tracking-[0.2em]',
        invalid && 'form-input-error',
      )}
    />
  );
});

/* ------------------------------------------------------------------ QR code (same-origin assets/vendor/qrcode.js) */

type QrFactory = (type: number, level: string) => { addData: (d: string) => void; make: () => void; createSvgTag: (o: { cellSize: number; margin: number; scalable: boolean }) => string };
let qrLoader: Promise<QrFactory | null> | null = null;

function loadQr(): Promise<QrFactory | null> {
  const w = window as unknown as { qrcode?: QrFactory };
  if (w.qrcode) return Promise.resolve(w.qrcode);
  if (!qrLoader) {
    qrLoader = new Promise((resolve) => {
      const s = document.createElement('script');
      s.src = appUrl('assets/vendor/qrcode.js');
      s.async = true;
      s.onload = () => resolve(w.qrcode ?? null);
      s.onerror = () => {
        qrLoader = null;
        resolve(null);
      };
      document.head.appendChild(s);
    });
  }
  return qrLoader;
}

/** otpauth:// QR code on a white tile (scannable in dark mode too). */
export function TotpQr({ uri, className }: { uri: string; className?: string }) {
  const [svg, setSvg] = useState('');
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    let alive = true;
    setSvg('');
    setFailed(false);
    void loadQr().then((qr) => {
      if (!alive) return;
      if (!qr) return setFailed(true);
      try {
        const q = qr(0, 'M');
        q.addData(uri);
        q.make();
        setSvg(q.createSvgTag({ cellSize: 4, margin: 2, scalable: true }));
      } catch {
        setFailed(true);
      }
    });
    return () => {
      alive = false;
    };
  }, [uri]);

  const box = clsx('h-44 w-44 shrink-0 rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700', className);
  if (failed) {
    return (
      <div className={clsx(box, 'flex flex-col items-center justify-center gap-2 p-4 text-center text-xs text-slate-500')} role="note">
        <QrCode className="h-8 w-8 text-slate-300" aria-hidden />
        The QR code could not load. Enter the setup key below instead.
      </div>
    );
  }
  if (!svg) return <div className={clsx(box, 'skeleton')} aria-hidden />;
  return (
    <div
      className={clsx(box, '[&>svg]:block [&>svg]:h-full [&>svg]:w-full')}
      role="img"
      aria-label="QR code to add your account to an authenticator app"
      dangerouslySetInnerHTML={{ __html: svg }}
    />
  );
}

/* ------------------------------------------------------------------ Clipboard / download helpers */

export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    /* fall back below */
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
    document.body.removeChild(ta);
    return ok;
  } catch {
    return false;
  }
}

/** Saves a client-generated text file (no network request). */
export function downloadText(filename: string, text: string): void {
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Copy button with a short "Copied" confirmation (announced to screen readers). */
export function CopyButton({ text, label = 'Copy', copiedLabel = 'Copied', className, size = 'sm' }: { text: string; label?: string; copiedLabel?: string; className?: string; size?: 'sm' | 'md' }) {
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
  const timer = useRef<number>();
  useEffect(() => () => window.clearTimeout(timer.current), []);
  const run = async () => {
    const ok = await copyText(text);
    setState(ok ? 'copied' : 'failed');
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setState('idle'), 2200);
  };
  return (
    <button type="button" onClick={() => void run()} className={clsx('btn btn-secondary', size === 'sm' && 'btn-sm', className)}>
      {state === 'copied' ? <Check className="h-4 w-4 text-emerald-600 dark:text-emerald-400" aria-hidden /> : <Copy className="h-4 w-4" aria-hidden />}
      <span aria-live="polite">{state === 'copied' ? copiedLabel : state === 'failed' ? 'Copy failed — select the text instead' : label}</span>
    </button>
  );
}

/** "JBSWY3DPEHPK3PXP" -> "JBSW Y3DP EHPK 3PXP" */
export function groupSecret(secret: string): string {
  return secret.replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ');
}

/* ------------------------------------------------------------------ Countdown */

/** Seconds left until a server-side deadline; calls onExpire once when it reaches 0. Pass null to stop. */
export function useCountdown(seconds: number | null, onExpire: () => void): number {
  const [deadline] = useState(() => (seconds ? Date.now() + seconds * 1000 : 0));
  const [left, setLeft] = useState(seconds ?? 0);
  const expire = useRef(onExpire);
  expire.current = onExpire;
  const active = seconds !== null && deadline > 0;
  useEffect(() => {
    if (!active) return;
    let fired = false;
    const tick = () => {
      const s = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      setLeft(s);
      if (s === 0 && !fired) {
        fired = true;
        expire.current();
      }
    };
    tick();
    const t = window.setInterval(tick, 1000);
    return () => window.clearInterval(t);
  }, [active, deadline]);
  return left;
}

export function formatClock(s: number): string {
  const m = Math.floor(s / 60);
  return `${m}:${String(s % 60).padStart(2, '0')}`;
}
