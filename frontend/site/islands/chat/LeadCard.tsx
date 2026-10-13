import clsx from 'clsx';
import { LoaderCircle, PhoneCall, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { postJson } from './api';
import type { ChatConfig } from './types';

const CODES = [
  ['+91', 'IN', 'India'], ['+977', 'NP', 'Nepal'], ['+880', 'BD', 'Bangladesh'], ['+94', 'LK', 'Sri Lanka'], ['+975', 'BT', 'Bhutan'], ['+971', 'AE', 'UAE'],
  ['+966', 'SA', 'Saudi Arabia'], ['+974', 'QA', 'Qatar'], ['+965', 'KW', 'Kuwait'], ['+968', 'OM', 'Oman'], ['+973', 'BH', 'Bahrain'], ['+1', 'US', 'USA / Canada'],
  ['+44', 'GB', 'United Kingdom'], ['+61', 'AU', 'Australia'], ['+65', 'SG', 'Singapore'], ['+60', 'MY', 'Malaysia'], ['+49', 'DE', 'Germany'],
] as const;
const NAME = /^\p{L}[\p{L}\p{M} .'-]*$/u;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

interface Props {
  cfg: ChatConfig;
  conversationId: string | null;
  prefillPhone?: string;
  onDone: (message: string) => void;
  onDismiss: () => void;
  autoFocus?: boolean;
}

/** Inline callback form in the chat (name, phone with +91 default, optional email + program) → POST /api/chat/lead. */
export default function LeadCard({ cfg, conversationId, prefillPhone, onDone, onDismiss, autoFocus }: Props) {
  const uid = useId();
  const [name, setName] = useState('');
  const [code, setCode] = useState('+91');
  const [phone, setPhone] = useState(() => {
    const d = (prefillPhone || '').replace(/\D/g, '');
    return d.length === 12 && d.startsWith('91') ? d.slice(2) : d.slice(-10);
  });
  const [email, setEmail] = useState('');
  const [program, setProgram] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const honeypot = useRef<HTMLInputElement>(null);
  const first = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (autoFocus) first.current?.focus({ preventScroll: true });
  }, [autoFocus]);

  const validate = () => {
    const e: Record<string, string> = {};
    const n = name.trim();
    const digits = phone.replace(/\D/g, '');
    if (n.length < 2) e.name = 'Please enter your name.';
    else if (!NAME.test(n)) e.name = 'Name may only contain letters, spaces, dots and hyphens.';
    if (!digits) e.phone = 'Please enter your mobile number.';
    else if (code === '+91' ? !/^[6-9]\d{9}$/.test(digits) : digits.length < 6 || digits.length > 14) e.phone = code === '+91' ? 'Enter a valid 10-digit mobile number.' : 'Enter a valid phone number.';
    if (email.trim() && !EMAIL.test(email.trim())) e.email = 'Enter a valid email address.';
    return e;
  };

  const submit = async (ev: FormEvent) => {
    ev.preventDefault();
    if (busy) return;
    const e = validate();
    setErrors(e);
    setMessage('');
    if (Object.keys(e).length) {
      document.getElementById(`${uid}-${Object.keys(e)[0]}`)?.focus();
      return;
    }
    setBusy(true);
    const res = await postJson<{ ok: boolean }>(cfg, cfg.endpoints.lead, {
      conversation_id: conversationId,
      name: name.trim(),
      phone: `${code} ${phone.replace(/\D/g, '')}`,
      email: email.trim(),
      program_id: program,
      website: honeypot.current?.value ?? '',
    });
    setBusy(false);
    if (res.ok) {
      onDone(res.message || 'Thank you! Our admission counsellor will call you shortly.');
      return;
    }
    const fe = { ...(res.errors ?? {}) };
    if (fe.program_id) fe.program = fe.program_id;
    setErrors(fe);
    setMessage(Object.keys(fe).length ? '' : res.message);
  };

  const field = 'rounded-xl border bg-white px-3 py-2 text-[14px] text-slate-900 placeholder:text-slate-400 transition focus:outline-none focus:ring-2 focus:ring-accent-500/40';
  const err = (k: string) => (errors[k] ? <p id={`${uid}-${k}-err`} className="mt-1 text-[12px] font-medium text-red-700">{errors[k]}</p> : null);
  const aria = (k: string) => ({ 'aria-invalid': errors[k] ? true : undefined, 'aria-describedby': errors[k] ? `${uid}-${k}-err` : undefined });

  return (
    <form onSubmit={submit} noValidate className="relative rounded-2xl border border-accent-200 bg-gradient-to-b from-accent-50 to-white p-3.5 shadow-sm" aria-labelledby={`${uid}-title`}>
      <button type="button" onClick={onDismiss} className="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-full text-slate-500 hover:bg-white hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500" aria-label="Close the callback form">
        <X className="h-4 w-4" aria-hidden="true" />
      </button>
      <p id={`${uid}-title`} className="flex items-center gap-2 pr-7 font-display text-[15px] font-bold text-brand-900">
        <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-accent-600 text-white"><PhoneCall className="h-3.5 w-3.5" aria-hidden="true" /></span>
        Request a callback
      </p>
      <p className="mt-1 text-[12.5px] leading-snug text-slate-600">A counsellor will call you{cfg.hours ? ` (${cfg.hours})` : ''}. We only use these details to contact you about admission.</p>
      <div className="mt-3 space-y-2.5">
        <div>
          <label htmlFor={`${uid}-name`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Full name <span className="text-red-700" aria-hidden="true">*</span></label>
          <input ref={first} id={`${uid}-name`} value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" maxLength={150} required className={clsx(field, 'w-full', errors.name ? 'border-red-400' : 'border-slate-200')} {...aria('name')} />
          {err('name')}
        </div>
        <div>
          <label htmlFor={`${uid}-phone`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Mobile number <span className="text-red-700" aria-hidden="true">*</span></label>
          <div className="flex gap-1.5">
            <select value={code} onChange={(e) => setCode(e.target.value)} aria-label="Country code" className={clsx(field, 'w-[92px] shrink-0 border-slate-200 px-2')}>
              {CODES.map(([c, iso, country]) => (
                <option key={c + iso} value={c} title={country} aria-label={`${country} ${c}`}>{c} {iso}</option>
              ))}
            </select>
            <input id={`${uid}-phone`} value={phone} onChange={(e) => setPhone(e.target.value.replace(/[^\d\s-]/g, ''))} inputMode="tel" autoComplete="tel-national" maxLength={16} placeholder={code === '+91' ? '98765 43210' : ''} required className={clsx(field, 'min-w-0 flex-1', errors.phone ? 'border-red-400' : 'border-slate-200')} {...aria('phone')} />
          </div>
          {err('phone')}
        </div>
        <div>
          <label htmlFor={`${uid}-email`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Email <span className="font-normal text-slate-500">(optional)</span></label>
          <input id={`${uid}-email`} type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" maxLength={190} className={clsx(field, 'w-full', errors.email ? 'border-red-400' : 'border-slate-200')} {...aria('email')} />
          {err('email')}
        </div>
        {cfg.programs.length > 0 && (
          <div>
            <label htmlFor={`${uid}-program`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Program of interest <span className="font-normal text-slate-500">(optional)</span></label>
            <select id={`${uid}-program`} value={program} onChange={(e) => setProgram(e.target.value)} className={clsx(field, 'w-full', errors.program ? 'border-red-400' : 'border-slate-200')} {...aria('program')}>
              <option value="">Not sure yet</option>
              {cfg.programs.map((p) => (
                <option key={p.value} value={p.value}>{p.label}</option>
              ))}
            </select>
            {err('program')}
          </div>
        )}
        <input ref={honeypot} type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="absolute -left-[9999px] h-px w-px opacity-0" />
      </div>
      {message && <p role="alert" className="mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-[12.5px] font-medium text-red-800">{message}</p>}
      <button type="submit" disabled={busy} className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-full bg-accent-600 px-4 py-2.5 text-[14px] font-semibold text-white shadow-flame transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 disabled:opacity-70" aria-busy={busy}>
        {busy && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
        {busy ? 'Sending…' : 'Call me back'}
      </button>
    </form>
  );
}
