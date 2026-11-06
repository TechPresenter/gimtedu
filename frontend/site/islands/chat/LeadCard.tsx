import clsx from 'clsx';
import { LoaderCircle, PhoneCall, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { CaptchaNote, EmailSuggestion } from '../../forms/FormExtras';
import { PhoneInput } from '../../forms/PhoneInput';
import { emailError, normalizeEmail, suggestEmail } from '../../forms/email';
import { DEFAULT_COUNTRY, loadPhoneLib, phoneError } from '../../forms/phone';
import { captchaAnswer, fetchSecurity, preloadCaptcha, pushLead, type FormSecurity } from '../../forms/security';
import { postJson } from './api';
import type { ChatConfig } from './types';

const NAME = /^\p{L}[\p{L}\p{M} .'-]*$/u;

interface Props {
  cfg: ChatConfig;
  conversationId: string | null;
  prefillPhone?: string;
  onDone: (message: string) => void;
  onDismiss: () => void;
  autoFocus?: boolean;
}

/**
 * Inline callback form in the chat (name, international phone with India preselected, optional email + program) →
 * POST /api/chat/lead (→ pf_submit_enquiry(): E.164 phone, 10-minute dedupe). Carries the one-time form token and the
 * captcha answer when one is configured; pushes the dataLayer conversion on success.
 */
export default function LeadCard({ cfg, conversationId, prefillPhone, onDone, onDismiss, autoFocus }: Props) {
  const uid = useId();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState(() => {
    const d = (prefillPhone || '').replace(/[^\d+]/g, '');
    if (!d) return '';
    if (d.startsWith('+')) return d;
    const digits = d.replace(/\D/g, '');
    return digits.length === 12 && digits.startsWith('91') ? `+${digits}` : digits.length === 10 ? `+91${digits}` : d;
  });
  const [phoneCountry, setPhoneCountry] = useState(DEFAULT_COUNTRY);
  const [email, setEmail] = useState('');
  const [emailHint, setEmailHint] = useState<string | null>(null);
  const [program, setProgram] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const honeypot = useRef<HTMLInputElement>(null);
  const first = useRef<HTMLInputElement>(null);
  const security = useRef<FormSecurity | null>(null);
  const [captcha, setCaptcha] = useState<FormSecurity['captcha']>(null);
  const captchaHolder = useRef<HTMLDivElement | null>(null);

  // the card opens when the visitor asks for a callback: get a form token and the phone library right away
  useEffect(() => {
    if (autoFocus) first.current?.focus({ preventScroll: true });
    void loadPhoneLib().catch(() => undefined);
    void fetchSecurity(cfg.endpoints.csrf).then((sec) => {
      if (!sec) return;
      security.current = sec;
      setCaptcha(sec.captcha);
      preloadCaptcha(sec.captcha);
    });
  }, [autoFocus, cfg.endpoints.csrf]);

  const validate = () => {
    const e: Record<string, string> = {};
    const n = name.trim();
    if (n.length < 2) e.name = 'Please enter your name.';
    else if (!NAME.test(n)) e.name = 'Name may only contain letters, spaces, dots and hyphens.';
    const pe = phoneError(phone, { required: true, label: 'Mobile number', country: phoneCountry });
    if (pe) e.phone = pe;
    const ee = emailError(email);
    if (ee) e.email = ee;
    return e;
  };

  const send = async (sec: FormSecurity | null) => {
    if (sec) await new Promise((r) => window.setTimeout(r, Math.max(0, sec.minSeconds * 1000 + 250 - (Date.now() - sec.at))));
    let captchaToken = '';
    if (sec?.captcha) {
      try {
        captchaToken = await captchaAnswer(sec.captcha, 'chatbot-lead', captchaHolder.current);
      } catch {
        return { ok: false, message: 'The security check could not be completed. Please try again.', errors: { captcha: 'Please try again.' } as Record<string, string>, status: 422 };
      }
    }
    return postJson<{ ok: boolean }>(cfg, cfg.endpoints.lead, {
      conversation_id: conversationId,
      name: name.trim(),
      phone,
      phone_country: phoneCountry,
      email: normalizeEmail(email),
      program_id: program,
      website: honeypot.current?.value ?? '',
      _ft: sec?.token ?? '',
      captcha_token: captchaToken,
    });
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
    let sec = security.current ?? (await fetchSecurity(cfg.endpoints.csrf));
    let res = await send(sec);
    const ft = (res.errors as Record<string, string> | undefined)?._ft ?? '';
    if (!res.ok && (res.status === 419 || ft)) {
      // expired / too-fast token: get a fresh one and try once more
      sec = (await fetchSecurity(cfg.endpoints.csrf)) ?? sec;
      res = await send(sec);
    }
    setBusy(false);
    if (res.ok) {
      security.current = null;
      pushLead('chatbot_lead');
      onDone(res.message || 'Thank you! Our admission counsellor will call you shortly.');
      return;
    }
    security.current = sec;
    const fe = { ...((res.errors as Record<string, string> | undefined) ?? {}) };
    delete fe._ft;
    if (fe.program_id) fe.program = fe.program_id;
    setErrors(fe);
    setMessage(Object.keys(fe).length ? '' : res.message);
  };

  const field = 'rounded-xl border bg-white px-3 py-2 text-[14px] text-slate-900 placeholder:text-slate-400 transition focus:outline-none focus:ring-2 focus:ring-accent-500/40';
  const err = (k: string) => (errors[k] ? <p id={`${uid}-${k}-err`} className="mt-1 text-[12px] font-medium text-red-700">{errors[k]}</p> : null);
  const aria = (k: string) => ({ 'aria-invalid': errors[k] ? true : undefined, 'aria-describedby': errors[k] ? `${uid}-${k}-err` : undefined });

  return (
    <form onSubmit={submit} noValidate className="relative rounded-2xl border border-accent-200 bg-gradient-to-b from-accent-50 to-white p-3.5 shadow-sm" aria-labelledby={`${uid}-title`} aria-busy={busy}>
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
          <input ref={first} id={`${uid}-name`} value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" maxLength={150} required aria-required="true" disabled={busy} className={clsx(field, 'w-full', errors.name ? 'border-red-400' : 'border-slate-200')} {...aria('name')} />
          {err('name')}
        </div>
        <div>
          <label htmlFor={`${uid}-phone`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Mobile number <span className="text-red-700" aria-hidden="true">*</span></label>
          <PhoneInput
            id={`${uid}-phone`}
            label="Mobile number"
            value={phone}
            required
            disabled={busy}
            invalid={!!errors.phone}
            describedBy={errors.phone ? `${uid}-phone-err` : undefined}
            className={clsx('!rounded-xl !shadow-none', errors.phone ? '!border-red-400' : '!border-slate-200')}
            inputClassName="!py-2 text-[14px]"
            onChange={(val, info) => {
              setPhone(val);
              setPhoneCountry(info.country || phoneCountry);
              if (errors.phone) setErrors((p) => ({ ...p, phone: '' }));
            }}
          />
          {err('phone')}
        </div>
        <div>
          <label htmlFor={`${uid}-email`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Email <span className="font-normal text-slate-500">(optional)</span></label>
          <input
            id={`${uid}-email`}
            type="email"
            inputMode="email"
            autoCapitalize="off"
            spellCheck={false}
            value={email}
            disabled={busy}
            onChange={(e) => setEmail(e.target.value)}
            onBlur={() => {
              const norm = normalizeEmail(email);
              if (norm !== email) setEmail(norm);
              setEmailHint(suggestEmail(norm));
            }}
            autoComplete="email"
            maxLength={190}
            className={clsx(field, 'w-full', errors.email ? 'border-red-400' : 'border-slate-200')}
            {...aria('email')}
          />
          <EmailSuggestion
            suggestion={emailHint}
            onAccept={(s) => {
              setEmail(s);
              setEmailHint(null);
            }}
          />
          {err('email')}
        </div>
        {cfg.programs.length > 0 && (
          <div>
            <label htmlFor={`${uid}-program`} className="mb-1 block text-[12.5px] font-semibold text-slate-700">Program of interest <span className="font-normal text-slate-500">(optional)</span></label>
            <select id={`${uid}-program`} value={program} disabled={busy} onChange={(e) => setProgram(e.target.value)} className={clsx(field, 'w-full', errors.program ? 'border-red-400' : 'border-slate-200')} {...aria('program')}>
              <option value="">Not sure yet</option>
              {cfg.programs.map((p) => (
                <option key={p.value} value={p.value}>{p.label}</option>
              ))}
            </select>
            {err('program')}
          </div>
        )}
        <input ref={honeypot} type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="absolute -left-[9999px] h-px w-px opacity-0" />
        <CaptchaNote captcha={captcha} holderRef={(el) => (captchaHolder.current = el)} />
      </div>
      {(message || errors.captcha) && <p role="alert" className="mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-[12.5px] font-medium text-red-800">{message || errors.captcha}</p>}
      <button type="submit" disabled={busy} className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-full bg-accent-600 px-4 py-2.5 text-[14px] font-semibold text-white shadow-flame transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 disabled:opacity-70" aria-busy={busy}>
        {busy && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
        {busy ? 'Sending…' : 'Call me back'}
      </button>
    </form>
  );
}
