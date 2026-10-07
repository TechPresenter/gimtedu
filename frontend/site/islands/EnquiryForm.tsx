import clsx from 'clsx';
import { ArrowRight, CircleAlert, LoaderCircle } from 'lucide-react';
import { useId, useRef, useState, type FormEvent } from 'react';
import { postForm } from '../core/widgets';
import { Icon } from '../lib/Icon';
import type { IslandBaseProps } from '../lib/types';

export interface FormField {
  name: string;
  label: string;
  type?: 'text' | 'email' | 'tel' | 'number' | 'date' | 'select' | 'textarea' | 'checkbox';
  required?: boolean;
  placeholder?: string;
  options?: (string | { value: string; label: string })[];
  /** 'half' fields sit side by side from the sm breakpoint. */
  col?: 'full' | 'half';
  minLength?: number;
  maxLength?: number;
  min?: number;
  max?: number;
  /** JS regex source (no slashes) + message, mirrored from a server `regex:` rule. */
  pattern?: string;
  patternMessage?: string;
  rows?: number;
  autocomplete?: string;
  help?: string;
  value?: string;
}

export interface EnquiryFormProps extends IslandBaseProps {
  /** POST endpoint, e.g. "/api/public/enquiry" (api/routes/public.php). Receives FormData + X-CSRF-Token. */
  endpoint: string;
  fields: FormField[];
  title?: string;
  subtitle?: string;
  icon?: string;
  submitLabel?: string;
  successTitle?: string;
  successMessage?: string;
  /** Extra hidden inputs (e.g. { source: 'website', form: 'counselling' }). */
  hidden?: Record<string, string>;
  /** Required consent checkbox label (name "consent"). */
  consent?: string;
  theme?: 'light' | 'dark' | 'glass';
  compact?: boolean;
}

const PHONE = /^\+?[0-9\s\-()]{7,20}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** Client-side rules mirroring validate() in app/functions.php (same messages); the server stays authoritative. */
function check(f: FormField, raw: FormDataEntryValue | null): string | null {
  const v = typeof raw === 'string' ? raw.trim() : raw ? 'file' : '';
  if (f.type === 'checkbox') return f.required && !raw ? `${f.label} is required.` : null;
  if (!v) return f.required ? `${f.label} is required.` : null;
  if (f.type === 'email' && !EMAIL.test(v)) return 'Enter a valid email address.';
  if (f.type === 'tel' && !PHONE.test(v)) return 'Enter a valid phone number.';
  if (f.type === 'number') {
    const n = Number(v);
    if (!Number.isFinite(n)) return `${f.label} must be a number.`;
    if (f.min !== undefined && n < f.min) return `${f.label} must be at least ${f.min}.`;
    if (f.max !== undefined && n > f.max) return `${f.label} may not be greater than ${f.max}.`;
  }
  if (f.minLength && v.length < f.minLength) return `${f.label} must be at least ${f.minLength} characters.`;
  if (f.maxLength && v.length > f.maxLength) return `${f.label} may not be longer than ${f.maxLength} characters.`;
  if (f.pattern && !new RegExp(f.pattern).test(v)) return f.patternMessage || `${f.label} format is invalid.`;
  return null;
}

/**
 * AJAX enquiry / counselling / contact form: inline validation (mirrors server rules), CSRF header, honeypot,
 * loading spinner, server field errors, animated success state. Works with the api_ok/api_error JSON envelope.
 */
export default function EnquiryForm({
  endpoint,
  fields,
  title,
  subtitle,
  icon,
  submitLabel = 'Submit Enquiry',
  successTitle = 'Thank you!',
  successMessage = 'Your enquiry has been received. Our admission counsellor will contact you shortly.',
  hidden = {},
  consent,
  theme = 'light',
  compact = false,
}: EnquiryFormProps) {
  const uid = useId();
  const formRef = useRef<HTMLFormElement>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<'idle' | 'sending' | 'success' | 'error'>('idle');
  const [message, setMessage] = useState('');
  const all: FormField[] = consent ? [...fields, { name: 'consent', label: 'Consent', type: 'checkbox', required: true }] : fields;

  const validateField = (f: FormField) => {
    const form = formRef.current;
    if (!form) return;
    const err = check(f, new FormData(form).get(f.name));
    setErrors((prev) => {
      const next = { ...prev };
      if (err) next[f.name] = err;
      else delete next[f.name];
      return next;
    });
  };

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    const errs: Record<string, string> = {};
    all.forEach((f) => {
      const err = check(f, data.get(f.name));
      if (err) errs[f.name] = err;
    });
    setErrors(errs);
    if (Object.keys(errs).length) {
      setStatus('error');
      setMessage('Please fix the highlighted fields and try again.');
      form.querySelector<HTMLElement>(`[name="${CSS.escape(Object.keys(errs)[0])}"]`)?.focus();
      return;
    }
    setStatus('sending');
    setMessage('');
    const res = await postForm(endpoint, data);
    if (res.ok) {
      setStatus('success');
      setMessage(res.message || successMessage);
      form.reset();
      form.dispatchEvent(new CustomEvent('form:success', { bubbles: true, detail: res }));
      return;
    }
    setStatus('error');
    setMessage(res.message || 'Unable to submit right now. Please try again.');
    if (res.errors && Object.keys(res.errors).length) {
      setErrors(res.errors);
      form.querySelector<HTMLElement>(`[name="${CSS.escape(Object.keys(res.errors)[0])}"]`)?.focus();
    }
  };

  const sending = status === 'sending';

  return (
    <div className={clsx('enquiry', `enquiry-${theme}`, compact && 'enquiry-compact')}>
      {(title || icon) && (
        <div className={clsx('mb-5', theme === 'dark' && 'text-center')}>
          {icon && (
            <span className="enquiry-icon">
              <Icon name={icon} className="h-7 w-7" />
            </span>
          )}
          {title && <h3 className="enquiry-title">{title}</h3>}
          {subtitle && <p className="enquiry-subtitle">{subtitle}</p>}
        </div>
      )}

      {status === 'success' ? (
        <div className="enquiry-success" role="status">
          <svg className="success-check" viewBox="0 0 52 52" aria-hidden="true">
            <circle cx="26" cy="26" r="24" />
            <path d="M15 27 l7 7 l15-15" />
          </svg>
          <p className="mt-4 font-display text-xl font-bold">{successTitle}</p>
          <p className="mt-1 text-sm opacity-80">{message}</p>
          <button type="button" className={clsx('btn btn-sm mt-5', theme === 'dark' ? 'btn-outline-light' : 'btn-secondary')} onClick={() => setStatus('idle')}>
            Submit another enquiry
          </button>
        </div>
      ) : (
        <form ref={formRef} onSubmit={submit} noValidate className="grid grid-cols-2 gap-3.5" aria-busy={sending}>
          {Object.entries(hidden).map(([k, v]) => (
            <input key={k} type="hidden" name={k} value={v} />
          ))}
          <input type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
          {fields.map((f) => {
            const id = `${uid}-${f.name}`;
            const err = errors[f.name];
            const describedBy = [err && `${id}-err`, f.help && `${id}-help`].filter(Boolean).join(' ') || undefined;
            const common = {
              id,
              name: f.name,
              required: f.required,
              disabled: sending,
              'aria-invalid': err ? true : undefined,
              'aria-describedby': describedBy,
              onBlur: () => validateField(f),
              onChange: () => err && validateField(f),
              className: clsx('form-input', err && 'is-invalid'),
              defaultValue: f.value,
            };
            return (
              <div key={f.name} className={clsx(f.col === 'half' ? 'col-span-2 sm:col-span-1' : 'col-span-2', f.type === 'checkbox' && 'flex items-start gap-2.5')}>
                {f.type === 'checkbox' ? (
                  <>
                    <input {...common} type="checkbox" value="1" defaultValue={undefined} className={clsx('form-check mt-0.5', err && 'is-invalid')} />
                    <label htmlFor={id} className="text-sm">
                      {f.label}
                      {f.required && <span className="form-required"> *</span>}
                    </label>
                  </>
                ) : (
                  <>
                    <label htmlFor={id} className={clsx('form-label', compact && 'sr-only')}>
                      {f.label}
                      {f.required && <span className="form-required"> *</span>}
                    </label>
                    {f.type === 'select' ? (
                      <select {...common}>
                        <option value="">{f.placeholder || `Select ${f.label.toLowerCase()}`}</option>
                        {(f.options || []).map((o) => {
                          const opt = typeof o === 'string' ? { value: o, label: o } : o;
                          return (
                            <option key={opt.value} value={opt.value}>
                              {opt.label}
                            </option>
                          );
                        })}
                      </select>
                    ) : f.type === 'textarea' ? (
                      <textarea {...common} rows={f.rows ?? 4} placeholder={f.placeholder ?? (compact ? `${f.label}${f.required ? ' *' : ''}` : undefined)} maxLength={f.maxLength} />
                    ) : (
                      <input
                        {...common}
                        type={f.type ?? 'text'}
                        placeholder={f.placeholder ?? (compact ? `${f.label}${f.required ? ' *' : ''}` : undefined)}
                        autoComplete={f.autocomplete}
                        inputMode={f.type === 'tel' ? 'tel' : undefined}
                        min={f.min}
                        max={f.max}
                        maxLength={f.maxLength}
                      />
                    )}
                  </>
                )}
                {f.help && !err && (
                  <p id={`${id}-help`} className="form-hint">
                    {f.help}
                  </p>
                )}
                {err && (
                  <p id={`${id}-err`} className="form-error col-span-2">
                    {err}
                  </p>
                )}
              </div>
            );
          })}
          {consent && (
            <div className="col-span-2">
              <div className="flex items-start gap-2.5">
                <input id={`${uid}-consent`} name="consent" type="checkbox" value="1" disabled={sending} className={clsx('form-check mt-0.5', errors.consent && 'is-invalid')} aria-invalid={errors.consent ? true : undefined} />
                <label htmlFor={`${uid}-consent`} className="text-sm">
                  {consent}
                </label>
              </div>
              {errors.consent && <p className="form-error">Please accept to continue.</p>}
            </div>
          )}
          {status === 'error' && message && (
            <p className="form-alert col-span-2" role="alert">
              <CircleAlert className="h-4 w-4 shrink-0" aria-hidden="true" />
              {message}
            </p>
          )}
          <div className="col-span-2 pt-1">
            <button type="submit" className="btn btn-accent btn-shine w-full" disabled={sending} data-magnetic="0.15">
              {sending ? (
                <>
                  <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />
                  Sending…
                </>
              ) : (
                <>
                  {submitLabel}
                  <ArrowRight className="h-4 w-4" aria-hidden="true" />
                </>
              )}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
