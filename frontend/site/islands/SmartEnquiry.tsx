import clsx from 'clsx';
import { ArrowLeft, ArrowRight, Check, CircleAlert, Download, LoaderCircle, PhoneCall } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { postForm } from '../core/widgets';
import { useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';
import { ENQUIRY_EVENT, type EnquiryIntent, type Option } from './home/shared';

interface ProgramOption {
  value: string;
  label: string;
  level: string;
}

interface TimeOption {
  value: string;
  label: string;
  hint?: string;
}

export interface SmartEnquiryProps extends IslandBaseProps {
  /** POST /api/public/enquiry (api/routes/public.php) — FormData + X-CSRF-Token, api_ok / api_error JSON. */
  endpoint: string;
  levels: Option[];
  programs: ProgramOption[];
  times: TimeOption[];
  officeHours?: string;
  consent: string;
  privacyUrl?: string | null;
  /** Extra hidden fields (source, form, utm_*). */
  hidden?: Record<string, string>;
  applyUrl: string;
  brochureUrl?: string | null;
  phone?: string;
}

interface Values {
  level: string;
  program: string;
  name: string;
  phone: string;
  email: string;
  city: string;
  time: string;
  consent: boolean;
}

const EMPTY: Values = { level: '', program: '', name: '', phone: '', email: '', city: '', time: '', consent: false };
const STEPS = ['Interest', 'Contact', 'Callback'];
const FIELD_STEP: Record<string, number> = { program: 0, program_id: 0, level: 0, name: 1, phone: 1, email: 1, city: 2, callback_time: 2, consent: 2, message: 2 };
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const NAME = /^\p{L}[\p{L}\p{M} .'-]*$/u;

/** Indian mobile: keep the 10 significant digits (drops +91 / 0 prefixes). */
const mobileDigits = (v: string) => {
  let d = v.replace(/\D/g, '');
  if (d.length === 12 && d.startsWith('91')) d = d.slice(2);
  if (d.length === 11 && d.startsWith('0')) d = d.slice(1);
  return d;
};

/**
 * Smart Enquiry: compact 3-step lead form — 1) interest (level + program) 2) name, +91 mobile, email 3) city, preferred
 * callback time, consent — with per-step inline validation mirroring the server, a progress indicator, honeypot, CSRF
 * (postForm) and a success state with next steps. Posts to /api/public/enquiry with source/form/utm fields plus
 * level, callback_time and a composed message. Other widgets can pre-fill it via openEnquiry() (islands/home/shared.ts).
 */
export default function SmartEnquiry({ endpoint, levels, programs, times, officeHours, consent, privacyUrl, hidden = {}, applyUrl, brochureUrl, phone }: SmartEnquiryProps) {
  const uid = useId();
  const isStatic = useStaticMode();
  const [step, setStep] = useState(0);
  const [v, setV] = useState<Values>(EMPTY);
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<'idle' | 'sending' | 'success' | 'error'>('idle');
  const [message, setMessage] = useState('');
  const [website, setWebsite] = useState('');
  const stepRef = useRef<HTMLDivElement>(null);
  const moved = useRef(false);

  // pre-fill from other widgets (Course Finder, Scholarship Assistant)
  useEffect(() => {
    const apply = (intent?: EnquiryIntent) => {
      if (!intent) return;
      setV((prev) => ({
        ...prev,
        level: intent.level && levels.some((l) => l.value === intent.level) ? intent.level : prev.level,
        program: intent.program && programs.some((p) => p.value === intent.program) ? intent.program : intent.level ? '' : prev.program,
      }));
      setNote(intent.note || '');
      setStatus('idle');
      setStep(0);
      window.setTimeout(() => stepRef.current?.querySelector<HTMLElement>('input, select')?.focus({ preventScroll: true }), isStatic ? 0 : 450);
    };
    apply(window.__gimtEnquiryIntent);
    window.__gimtEnquiryIntent = undefined;
    const on = (e: Event) => {
      apply((e as CustomEvent<EnquiryIntent>).detail);
      window.__gimtEnquiryIntent = undefined;
    };
    window.addEventListener(ENQUIRY_EVENT, on);
    return () => window.removeEventListener(ENQUIRY_EVENT, on);
  }, [levels, programs, isStatic]);

  useEffect(() => {
    if (!moved.current) return;
    stepRef.current?.querySelector<HTMLElement>('input:not([type="hidden"]), select, button')?.focus({ preventScroll: true });
  }, [step]);

  const set = <K extends keyof Values>(k: K, value: Values[K]) => {
    setV((prev) => ({ ...prev, [k]: value, ...(k === 'level' ? { program: '' } : {}) }));
    if (errors[k as string]) setErrors((prev) => ({ ...prev, [k]: '' }));
  };

  const validate = (s: number): Record<string, string> => {
    const e: Record<string, string> = {};
    if (s === 0 && !v.level && !v.program) e.level = 'Choose what you would like to study.';
    if (s === 1) {
      const name = v.name.trim();
      if (!name) e.name = 'Full Name is required.';
      else if (name.length < 2) e.name = 'Full Name must be at least 2 characters.';
      else if (!NAME.test(name)) e.name = 'Full Name may only contain letters, spaces, dots and hyphens.';
      const d = mobileDigits(v.phone);
      if (!v.phone.trim()) e.phone = 'Mobile number is required.';
      else if (d.length !== 10) e.phone = 'Enter a valid 10-digit mobile number.';
      if (v.email.trim() && !EMAIL.test(v.email.trim())) e.email = 'Enter a valid email address.';
    }
    if (s === 2) {
      if (v.city.length > 80) e.city = 'City may not be longer than 80 characters.';
      if (!v.consent) e.consent = 'Please accept to continue.';
    }
    return e;
  };

  const next = () => {
    const e = validate(step);
    setErrors(e);
    if (Object.keys(e).length) {
      stepRef.current?.querySelector<HTMLElement>(`[name="${Object.keys(e)[0]}"]`)?.focus();
      return;
    }
    moved.current = true;
    setStep((s) => Math.min(2, s + 1));
  };

  const submit = async (ev: FormEvent) => {
    ev.preventDefault();
    if (step < 2) return next();
    const all = { ...validate(0), ...validate(1), ...validate(2) };
    setErrors(all);
    if (Object.keys(all).length) {
      moved.current = true;
      setStep(FIELD_STEP[Object.keys(all)[0]] ?? 2);
      return;
    }
    const program = programs.find((p) => p.value === v.program);
    const level = levels.find((l) => l.value === v.level);
    const time = times.find((t) => t.value === v.time);
    const fd = new FormData();
    Object.entries(hidden).forEach(([k, val]) => fd.append(k, val));
    fd.append('website', website);
    fd.append('name', v.name.trim());
    fd.append('phone', `+91 ${mobileDigits(v.phone)}`);
    if (v.email.trim()) fd.append('email', v.email.trim());
    if (v.city.trim()) fd.append('city', v.city.trim());
    fd.append('program', program ? program.value : level ? `${level.label} program (undecided)` : '');
    fd.append('level', level?.label ?? '');
    if (time) fd.append('callback_time', `${time.label}${time.hint ? ` (${time.hint})` : ''}`);
    fd.append('consent', '1');
    fd.append(
      'message',
      [
        `Homepage smart enquiry — interested in ${program ? program.label : level ? `${level.label} programs (not decided yet)` : 'a program'}.`,
        time ? `Preferred callback: ${time.label}${time.hint ? ` (${time.hint})` : ''}.` : '',
        note ? `${note}.` : '',
      ]
        .filter(Boolean)
        .join(' '),
    );
    setStatus('sending');
    setMessage('');
    const res = await postForm(endpoint, fd);
    if (res.ok) {
      setStatus('success');
      setMessage(res.message || 'Thank you! Our admission counsellor will call you shortly.');
      return;
    }
    setStatus('error');
    setMessage(res.message || 'Unable to submit right now. Please try again.');
    if (res.errors && Object.keys(res.errors).length) {
      const mapped: Record<string, string> = {};
      Object.entries(res.errors).forEach(([k, msg]) => (mapped[k === 'program_id' ? 'program' : k] = msg));
      setErrors(mapped);
      moved.current = true;
      setStep(Math.min(...Object.keys(mapped).map((k) => FIELD_STEP[k] ?? 2)));
    }
  };

  const err = (k: string) =>
    errors[k] ? (
      <p id={`${uid}-${k}-err`} className="form-error">
        {errors[k]}
      </p>
    ) : null;
  const aria = (k: string) => ({ 'aria-invalid': errors[k] ? true : undefined, 'aria-describedby': errors[k] ? `${uid}-${k}-err` : undefined });
  const input = 'h-12 w-full rounded-xl border bg-white px-4 text-[14.5px] text-brand-900 placeholder:text-slate-400 transition focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10';
  const sending = status === 'sending';
  const firstName = v.name.trim().split(/\s+/)[0] || '';
  const chosen = programs.find((p) => p.value === v.program);

  if (status === 'success') {
    return (
      <div className="smart-enquiry flex min-h-[470px] flex-col rounded-[30px] bg-white p-6 text-slate-600 shadow-[0_40px_80px_-40px_rgba(0,0,0,.55)] sm:p-8" role="status">
        <svg className="success-check h-16 w-16" viewBox="0 0 52 52" aria-hidden="true">
          <circle cx="26" cy="26" r="24" />
          <path d="M15 27 l7 7 l15-15" />
        </svg>
        <h3 className="mt-5 font-display text-[1.5rem] font-extrabold leading-tight text-brand-900">{firstName ? `Thank you, ${firstName}!` : 'Thank you!'}</h3>
        <p className="mt-2 text-[15px] leading-relaxed">{message}</p>
        <p className="mt-6 text-[12.5px] font-bold uppercase tracking-[0.12em] text-accent-700">What happens next</p>
        <ol className="mt-3 space-y-3">
          {[
            `A counsellor calls you${v.time && v.time !== 'anytime' ? ` in the ${times.find((t) => t.value === v.time)?.label.toLowerCase()}` : ''}${officeHours ? ` (${officeHours})` : ''}.`,
            'Get the fee details, scholarships and eligibility for your program.',
            'Apply online in about ten minutes — save and continue any time.',
          ].map((t, i) => (
            <li key={i} className="flex items-start gap-3 text-[14.5px] text-brand-900">
              <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-600 text-[12px] font-bold text-white">{i + 1}</span>
              {t}
            </li>
          ))}
        </ol>
        <div className="mt-auto flex flex-wrap gap-3 pt-7">
          <a href={chosen ? `${applyUrl}?program=${encodeURIComponent(chosen.value)}` : applyUrl} className="btn btn-apply">
            Start your application
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
          </a>
          {brochureUrl && (
            <a href={brochureUrl} className="btn btn-brochure" target="_blank" rel="noopener">
              <Download className="h-4 w-4" aria-hidden="true" />
              Download brochure<span className="sr-only"> (opens in a new tab)</span>
            </a>
          )}
        </div>
      </div>
    );
  }

  return (
    <form className="smart-enquiry flex min-h-[470px] flex-col rounded-[30px] bg-white p-6 text-slate-600 shadow-[0_40px_80px_-40px_rgba(0,0,0,.55)] sm:p-8" onSubmit={submit} noValidate aria-busy={sending}>
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-[12px] font-bold uppercase tracking-[0.14em] text-accent-700">Free counselling</p>
          <h3 className="mt-1 font-display text-[1.45rem] font-extrabold leading-tight text-brand-900">Get a call from an admission counsellor</h3>
        </div>
        {phone && (
          <a href={`tel:${phone.replace(/[^0-9+]/g, '')}`} className="hidden shrink-0 items-center gap-2 rounded-full bg-accent-50 px-3 py-2 text-[13px] font-semibold text-accent-700 ring-1 ring-inset ring-accent-200 transition hover:bg-accent-100 sm:inline-flex">
            <PhoneCall className="h-4 w-4" aria-hidden="true" />
            <span className="sr-only">Call </span>
            {phone}
          </a>
        )}
      </div>

      <ol className="mt-6 grid grid-cols-3 gap-2" aria-label="Progress">
        {STEPS.map((label, i) => (
          <li key={label} className="min-w-0" aria-current={i === step ? 'step' : undefined}>
            <span className="block h-1.5 overflow-hidden rounded-full bg-warm" aria-hidden="true">
              <span className="block h-full origin-left rounded-full bg-accent-600 transition-transform duration-500" style={{ transform: `scaleX(${i < step ? 1 : i === step ? 0.5 : 0})` }} />
            </span>
            <span className={clsx('mt-2 flex items-center gap-1.5 truncate text-[12.5px] font-semibold', i <= step ? 'text-brand-900' : 'text-slate-400')}>
              {i < step ? <Check className="h-3.5 w-3.5 text-accent-600" strokeWidth={3} aria-hidden="true" /> : <span className="tabular-nums">{i + 1}.</span>}
              {label}
              <span className="sr-only">{i < step ? ' (done)' : i === step ? ' (current step)' : ''}</span>
            </span>
          </li>
        ))}
      </ol>

      <input type="text" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />

      <div ref={stepRef} key={step} className={clsx('mt-6 flex-1', !isStatic && 'animate-fade-up')}>
        {step === 0 && (
          <fieldset>
            <legend className="font-display text-[16.5px] font-bold text-brand-900">What would you like to study?</legend>
            <div className="mt-3 grid grid-cols-2 gap-2.5" role="radiogroup" aria-label="Level of study" {...aria('level')}>
              {levels.map((l) => {
                const on = v.level === l.value;
                return (
                  <label
                    key={l.value}
                    className={clsx(
                      'flex cursor-pointer items-center justify-center gap-2 rounded-2xl border px-3 py-3 text-center text-[14px] font-semibold transition focus-within:ring-2 focus-within:ring-accent-500 focus-within:ring-offset-2',
                      on ? 'border-brand-900 bg-brand-900 text-white' : 'border-warm-2 text-brand-900 hover:border-[#E2DAD0] hover:bg-[#FAF8F5]',
                    )}
                  >
                    <input type="radio" name="level" value={l.value} checked={on} onChange={() => set('level', l.value)} className="sr-only" />
                    {on && <Check className="h-4 w-4" strokeWidth={3} aria-hidden="true" />}
                    {l.label}
                  </label>
                );
              })}
            </div>
            {err('level')}
            <label className="mt-4 block">
              <span className="form-label">Program (optional)</span>
              <select name="program" value={v.program} onChange={(e) => set('program', e.target.value)} className={clsx(input, errors.program ? 'border-red-400' : 'border-warm-2')} {...aria('program')}>
                <option value="">{v.level ? 'Not decided yet' : 'Choose a level first, or pick a program'}</option>
                {programs
                  .filter((p) => !v.level || p.level === v.level)
                  .map((p) => (
                    <option key={p.value} value={p.value}>
                      {p.label}
                    </option>
                  ))}
              </select>
            </label>
            {err('program')}
            {note && <p className="mt-3 rounded-xl bg-[#FAF8F5] px-3 py-2 text-[13px] text-slate-600 ring-1 ring-inset ring-warm-2">Regarding: {note}</p>}
          </fieldset>
        )}

        {step === 1 && (
          <fieldset className="grid gap-3.5">
            <legend className="mb-1 font-display text-[16.5px] font-bold text-brand-900">How can we reach you?</legend>
            <div>
              <label htmlFor={`${uid}-name`} className="form-label">
                Full name<span className="form-required"> *</span>
              </label>
              <input id={`${uid}-name`} name="name" value={v.name} onChange={(e) => set('name', e.target.value)} autoComplete="name" maxLength={150} className={clsx(input, errors.name ? 'border-red-400' : 'border-warm-2')} {...aria('name')} />
              {err('name')}
            </div>
            <div>
              <label htmlFor={`${uid}-phone`} className="form-label">
                Mobile number<span className="form-required"> *</span>
              </label>
              <div className={clsx('flex h-12 items-center overflow-hidden rounded-xl border bg-white transition focus-within:border-brand-400 focus-within:ring-4 focus-within:ring-accent-500/10', errors.phone ? 'border-red-400' : 'border-warm-2')}>
                <span className="flex h-full items-center border-r border-warm-2 bg-[#FAF8F5] px-3.5 text-[14.5px] font-semibold text-brand-900" aria-hidden="true">
                  +91
                </span>
                <input
                  id={`${uid}-phone`}
                  name="phone"
                  type="tel"
                  inputMode="numeric"
                  autoComplete="tel-national"
                  value={v.phone}
                  onChange={(e) => set('phone', e.target.value.replace(/[^\d\s+-]/g, '').slice(0, 16))}
                  placeholder="10-digit mobile number"
                  className="h-full min-w-0 flex-1 border-0 bg-transparent px-3.5 text-[14.5px] text-brand-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                  {...aria('phone')}
                />
              </div>
              {err('phone')}
            </div>
            <div>
              <label htmlFor={`${uid}-email`} className="form-label">
                Email <span className="font-normal text-slate-400">(optional)</span>
              </label>
              <input id={`${uid}-email`} name="email" type="email" value={v.email} onChange={(e) => set('email', e.target.value)} autoComplete="email" maxLength={190} className={clsx(input, errors.email ? 'border-red-400' : 'border-warm-2')} {...aria('email')} />
              {err('email')}
            </div>
          </fieldset>
        )}

        {step === 2 && (
          <fieldset className="grid gap-4">
            <legend className="mb-1 font-display text-[16.5px] font-bold text-brand-900">When should we call?</legend>
            <div>
              <label htmlFor={`${uid}-city`} className="form-label">
                City <span className="font-normal text-slate-400">(optional)</span>
              </label>
              <input id={`${uid}-city`} name="city" value={v.city} onChange={(e) => set('city', e.target.value)} autoComplete="address-level2" maxLength={80} className={clsx(input, errors.city ? 'border-red-400' : 'border-warm-2')} {...aria('city')} />
              {err('city')}
            </div>
            <div role="radiogroup" aria-label="Preferred callback time">
              <span className="form-label">Preferred callback time</span>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {times.map((t) => {
                  const on = v.time === t.value;
                  return (
                    <label
                      key={t.value}
                      className={clsx(
                        'flex cursor-pointer flex-col items-center rounded-2xl border px-2 py-2.5 text-center transition focus-within:ring-2 focus-within:ring-accent-500 focus-within:ring-offset-2',
                        on ? 'border-brand-900 bg-brand-900 text-white' : 'border-warm-2 text-brand-900 hover:border-[#E2DAD0] hover:bg-[#FAF8F5]',
                      )}
                    >
                      <input type="radio" name="callback_time" value={t.value} checked={on} onChange={() => set('time', t.value)} className="sr-only" />
                      <span className="text-[13.5px] font-bold">{t.label}</span>
                      {t.hint && <span className={clsx('text-[11.5px]', on ? 'text-white/70' : 'text-slate-500')}>{t.hint}</span>}
                    </label>
                  );
                })}
              </div>
            </div>
            <div>
              <label className="flex items-start gap-2.5 text-[13px] leading-relaxed text-slate-600">
                <input type="checkbox" name="consent" checked={v.consent} onChange={(e) => set('consent', e.target.checked)} className={clsx('form-check mt-0.5', errors.consent && 'is-invalid')} {...aria('consent')} />
                <span>
                  {consent}
                  {privacyUrl && (
                    <>
                      {' '}
                      <a href={privacyUrl} className="font-semibold text-accent-700 underline underline-offset-2">
                        Privacy policy
                      </a>
                    </>
                  )}
                </span>
              </label>
              {err('consent')}
            </div>
          </fieldset>
        )}
      </div>

      {status === 'error' && message && (
        <p className="form-alert mt-4" role="alert">
          <CircleAlert className="h-4 w-4 shrink-0" aria-hidden="true" />
          {message}
        </p>
      )}

      <div className="mt-6 flex items-center justify-between gap-3">
        {step > 0 ? (
          <button
            type="button"
            className="btn btn-ghost"
            onClick={() => {
              moved.current = true;
              setStep((s) => Math.max(0, s - 1));
            }}
            disabled={sending}
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Back
          </button>
        ) : (
          <span className="text-[12.5px] text-slate-500">Takes under a minute</span>
        )}
        {step < 2 ? (
          <button type="button" className="btn btn-explore" onClick={next}>
            Continue
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
          </button>
        ) : (
          <button type="submit" className="btn btn-apply btn-shine" disabled={sending}>
            {sending ? (
              <>
                <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />
                Sending…
              </>
            ) : (
              <>
                Request a Callback
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
              </>
            )}
          </button>
        )}
      </div>
    </form>
  );
}
