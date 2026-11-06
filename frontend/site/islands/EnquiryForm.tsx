import clsx from 'clsx';
import { ArrowRight, CircleAlert, LoaderCircle } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FocusEvent, type FormEvent } from 'react';
import { CountryField, PostalField, StateField, countryIso } from '../forms/AddressFields';
import { CaptchaNote, EmailSuggestion } from '../forms/FormExtras';
import { PhoneInput } from '../forms/PhoneInput';
import { postalError } from '../forms/address';
import { emailError, normalizeEmail, suggestEmail } from '../forms/email';
import { DEFAULT_COUNTRY, countryName, loadPhoneLib, mainCountryFor, phoneError } from '../forms/phone';
import { fetchSecurity, initialSecurity, postSecure, preloadCaptcha, pushLead, type CaptchaConfig, type FormSecurity } from '../forms/security';
import { Icon } from '../lib/Icon';
import type { IslandBaseProps } from '../lib/types';

export interface FormField {
  name: string;
  label: string;
  /** country = searchable country select (submits the name + `<name>_code`), state = Indian states & UTs for India else
   *  free text, postal = postal / PIN code validated per country — state and postal follow `countryField` ("country"). */
  type?: 'text' | 'email' | 'tel' | 'number' | 'date' | 'select' | 'textarea' | 'checkbox' | 'country' | 'state' | 'postal';
  required?: boolean;
  placeholder?: string;
  /** select options; consecutive options with the same `group` render inside one <optgroup>. */
  options?: (string | { value: string; label: string; group?: string })[];
  /** 'half' fields sit side by side from the sm breakpoint. */
  col?: 'full' | 'half';
  minLength?: number;
  maxLength?: number;
  min?: number;
  max?: number;
  /** JS regex source (no slashes) + message, mirrored from a server `regex:` rule. Ignored for tel fields. */
  pattern?: string;
  patternMessage?: string;
  rows?: number;
  autocomplete?: string;
  help?: string;
  value?: string;
  /** tel: preselected country (ISO2), default India */
  country?: string;
  /** state / postal: name of the country field they follow (default "country"; India when the form has none) */
  countryField?: string;
  /** @deprecated legacy '+91' prefix — read as the tel field's default country */
  prefix?: string;
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
  /** label of the success-state button that shows the form again (default "Submit another enquiry") */
  resetLabel?: string;
  /** Extra hidden inputs (e.g. { source: 'website', form: 'counselling' }). */
  hidden?: Record<string, string>;
  /** Required consent checkbox label (name "consent"). */
  consent?: string;
  /** Message when the consent box is not ticked — default the server's wording for the form (contact) or a short
   *  generic one, so the browser and the server always say the same thing. */
  consentError?: string;
  /** light (white card) · dark (navy mesh) · glass · lead (campaign lead card) · plain (no card chrome). */
  theme?: 'light' | 'dark' | 'glass' | 'lead' | 'plain';
  compact?: boolean;
  /** Part of the title rendered in orange-red (lead card). */
  titleHighlight?: string;
  /** heading level of the form title (h2 when the form is the first section heading after the page H1) */
  titleTag?: 'h2' | 'h3';
  /** Small feature tags under the title (lead card), e.g. [{icon: 'wallet', label: 'Easy instalments'}]. */
  tags?: { icon: string; label: string }[];
  /** Copy utm_* / gclid / fbclid from the page URL into hidden fields (when not already provided). */
  captureUtm?: boolean;
  /** form key for analytics + captcha actions (contact | enquiry | event | alumni …) — set by enquiry_form() */
  formKey?: string | null;
  /** one-time form token issued with the page (enquiry_form()); fetched from /api/public/form-token when absent */
  formToken?: string | null;
  captcha?: CaptchaConfig | null;
  /** result of a no-JS post shown on load (enquiry_form() passes the session flash) */
  initial?: { ok: boolean; message: string; errors?: Record<string, string>; values?: Record<string, string> };
  /** form instance id of the server-rendered fallback (its hidden "_fid") — values typed there before the island mounted are kept */
  fid?: string | null;
}

const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];

/**
 * Values the visitor already typed into the server-rendered fallback form (found by its "_fid") and the field that had
 * focus. Read during the island's first render — the fallback DOM is replaced only when React commits — so nothing
 * typed before the island mounted is lost. Only changed values are taken (defaults come from the props anyway).
 */
function fallbackState(fid?: string | null): { values: Record<string, string>; focus: string | null } {
  const none = { values: {}, focus: null };
  if (!fid || typeof document === 'undefined' || !/^[a-z0-9-]+$/i.test(fid)) return none;
  const form = document.querySelector<HTMLInputElement>(`form input[type="hidden"][name="_fid"][value="${fid}"]`)?.form;
  if (!form) return none;
  const values: Record<string, string> = {};
  form.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea').forEach((el) => {
    if (!el.name || el.name === 'website' || el.name.startsWith('_') || (el instanceof HTMLInputElement && el.type === 'hidden')) return;
    if (el instanceof HTMLInputElement && el.type === 'checkbox') {
      if (el.checked !== el.defaultChecked) values[el.name] = el.checked ? '1' : '';
      return;
    }
    const initialValue = el instanceof HTMLSelectElement ? (Array.from(el.options).find((o) => o.defaultSelected)?.value ?? el.options[0]?.value ?? '') : el.defaultValue;
    if (el.value !== initialValue) values[el.name] = el.value;
  });
  const active = document.activeElement;
  const focus = active instanceof HTMLElement && form.contains(active) ? (active.getAttribute('name') || '').replace(/_country$/, '') || null : null;
  return { values, focus };
}

function titleNode(title: string, highlight?: string) {
  if (!highlight) return title;
  const i = title.toLowerCase().indexOf(highlight.toLowerCase());
  if (i < 0) return title;
  return (
    <>
      {title.slice(0, i)}
      <em>{title.slice(i, i + highlight.length)}</em>
      {title.slice(i + highlight.length)}
    </>
  );
}

/** Default country of a tel field: `country`, else a legacy "+<code>" prefix, else India. */
function telCountry(f: FormField): string {
  if (f.country) return f.country.toUpperCase();
  const cc = /^\+(\d{1,3})$/.exec((f.prefix || '').trim())?.[1];
  return (cc && mainCountryFor(cc)) || DEFAULT_COUNTRY;
}

/** Client-side rules mirroring the server (validate(), phone.php) with the same messages; the server stays authoritative. */
function check(f: FormField, raw: FormDataEntryValue | null, ctx: { country: (field?: string) => string; phoneCountry: (name: string) => string }): string | null {
  const v = typeof raw === 'string' ? raw.trim() : raw ? 'file' : '';
  if (f.type === 'checkbox') return f.required && !raw ? `${f.label} is required.` : null;
  if (f.type === 'tel') return phoneError(v, { required: f.required, label: f.label, country: ctx.phoneCountry(f.name) });
  if (f.type === 'email') return emailError(v, { required: f.required, label: f.label });
  if (f.type === 'postal') {
    const iso = ctx.country(f.countryField);
    return postalError(v, iso, { required: f.required, countryName: countryName(iso) });
  }
  if (!v) return f.required ? `${f.label} is required.` : null;
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

/** Select options as runs of the same `group` (an <optgroup> each; options without a group stay top-level). */
function groupOptions(options: NonNullable<FormField['options']>) {
  const out: { group?: string; items: { value: string; label: string }[] }[] = [];
  options.forEach((o) => {
    const opt = typeof o === 'string' ? { value: o, label: o } : o;
    const last = out[out.length - 1];
    if (last && last.group === opt.group) last.items.push(opt);
    else out.push({ group: opt.group, items: [opt] });
  });
  return out;
}

/**
 * AJAX enquiry / counselling / contact form: international phone (country selector, E.164), email normalisation +
 * typo suggestions, address fields (country / state / postal code), inline validation mirroring the server, CSRF,
 * one-time form token + optional captcha, honeypot, loading state (button + fields disabled), server field errors
 * (focus the first), animated success state + dataLayer conversion. Works with the api_ok/api_error JSON envelope.
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
  resetLabel = 'Submit another enquiry',
  hidden = {},
  consent,
  consentError,
  theme = 'light',
  compact = false,
  titleHighlight,
  titleTag = 'h3',
  tags = [],
  captureUtm = false,
  formKey,
  formToken,
  captcha,
  initial,
  fid,
}: EnquiryFormProps) {
  const utm: Record<string, string> = {};
  if (captureUtm) {
    const q = new URLSearchParams(window.location.search);
    UTM_KEYS.forEach((k) => {
      const v = q.get(k);
      if (v && !(k in hidden)) utm[k] = v.slice(0, 100);
    });
  }
  const hiddenAll = { ...hidden, ...utm };
  const uid = useId();
  const formRef = useRef<HTMLFormElement>(null);
  const successRef = useRef<HTMLDivElement>(null);
  const captchaHolder = useRef<HTMLDivElement | null>(null);
  const security = useRef<FormSecurity | null>(initialSecurity(formToken, captcha));
  const warmed = useRef(false);
  // starting values: a no-JS post that came back with errors, plus anything typed into the fallback before mounting
  const [fallback] = useState(() => fallbackState(fid));
  const [values0, setValues0] = useState<Record<string, string>>(() => ({ ...(initial && !initial.ok ? initial.values ?? {} : {}), ...fallback.values }));
  const [round, setRound] = useState(0);
  const [errors, setErrors] = useState<Record<string, string>>(() => (initial && !initial.ok ? { ...(initial.errors ?? {}) } : {}));
  const [status, setStatus] = useState<'idle' | 'sending' | 'success' | 'error'>(() => (initial ? (initial.ok ? 'success' : 'error') : 'idle'));
  const [message, setMessage] = useState(initial?.message ?? '');
  const [suggest, setSuggest] = useState<Record<string, string | null>>({});
  const dark = theme === 'dark' || theme === 'lead';
  const formName = formKey || 'enquiry';
  // same wording as the server (pf_submit_contact) / the no-JS fallback
  const consentMsg = consentError || (formKey === 'contact' ? 'Please accept the terms and privacy policy to send your message.' : 'Please tick the box to agree to be contacted.');

  // address state: country fields (ISO2), states, postal codes
  const fieldValue = (f: FormField) => values0[f.name] ?? f.value ?? '';
  const [countries, setCountries] = useState<Record<string, string>>(() =>
    Object.fromEntries(fields.filter((f) => f.type === 'country').map((f) => [f.name, countryIso(values0[`${f.name}_code`] || fieldValue(f) || 'IN')])),
  );
  const [addr, setAddr] = useState<Record<string, string>>(() => Object.fromEntries(fields.filter((f) => f.type === 'state' || f.type === 'postal').map((f) => [f.name, fieldValue(f)])));
  const countryOf = (field?: string) => countries[field || 'country'] ?? DEFAULT_COUNTRY;
  const phoneCountryOf = (name: string) => {
    const fd = formRef.current ? new FormData(formRef.current) : null;
    return String(fd?.get(`${name}_country`) || '') || telCountry(fields.find((x) => x.name === name) ?? { name, label: '' });
  };

  const all: FormField[] = consent ? [...fields, { name: 'consent', label: 'Consent', type: 'checkbox', required: true }] : fields;

  const focusField = (name: string) => {
    const form = formRef.current;
    if (!form) return;
    const el = form.querySelector<HTMLElement>(`#${CSS.escape(`${uid}-${name}`)}`) ?? form.querySelector<HTMLElement>(`[name="${CSS.escape(name)}"]:not([type="hidden"])`);
    el?.focus();
  };

  // a no-JS post that came back with errors: bring the first invalid field into focus; otherwise keep the focus where
  // the visitor was typing in the fallback form (caret at the end)
  useEffect(() => {
    if (initial && !initial.ok && initial.errors && Object.keys(initial.errors).length) focusField(Object.keys(initial.errors)[0]);
    else if (fallback.focus) {
      focusField(fallback.focus);
      const el = document.activeElement;
      if (el instanceof HTMLInputElement && ['text', 'tel', 'email', 'search', 'url'].includes(el.type)) {
        try {
          el.setSelectionRange(el.value.length, el.value.length);
        } catch {
          /* not supported for this input type */
        }
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // success: the (shorter) confirmation replaces the form — bring it into view when it ended up above the viewport
  useEffect(() => {
    if (status !== 'success' || !successRef.current) return;
    const el = successRef.current;
    const top = el.getBoundingClientRect().top;
    if (top < 0 || top > window.innerHeight - 80) {
      const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      el.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
    }
  }, [status]);

  const setFieldError = (name: string, err: string | null) =>
    setErrors((prev) => {
      if ((prev[name] ?? null) === err) return prev;
      const next = { ...prev };
      if (err) next[name] = err;
      else delete next[name];
      return next;
    });

  const validateField = (f: FormField) => {
    const form = formRef.current;
    if (!form) return;
    setFieldError(f.name, check(f, new FormData(form).get(f.name), { country: countryOf, phoneCountry: phoneCountryOf }));
  };

  /** First interaction: start loading the phone library and the captcha script (never on page load). */
  const warm = (_e?: FocusEvent) => {
    if (warmed.current) return;
    warmed.current = true;
    if (fields.some((f) => f.type === 'tel' || f.type === 'country')) void loadPhoneLib().catch(() => undefined);
    preloadCaptcha(security.current?.captcha ?? captcha);
  };

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (status === 'sending') return;
    const form = e.currentTarget;
    form.querySelectorAll<HTMLInputElement>('input[type="email"]').forEach((el) => (el.value = normalizeEmail(el.value)));
    const data = new FormData(form);
    const errs: Record<string, string> = {};
    all.forEach((f) => {
      const err = check(f, data.get(f.name), { country: countryOf, phoneCountry: phoneCountryOf });
      if (err) errs[f.name] = err;
    });
    setErrors(errs);
    if (Object.keys(errs).length) {
      setStatus('error');
      setMessage('Please fix the highlighted fields and try again.');
      focusField(Object.keys(errs)[0]);
      return;
    }
    setStatus('sending');
    setMessage('');
    const res = await postSecure(data, { endpoint, form: formName, security: security.current, captchaHolder: captchaHolder.current });
    security.current = res.ok ? null : res.security; // tokens are single-use
    if (res.ok) {
      setStatus('success');
      setMessage(res.message || successMessage);
      pushLead(formName);
      form.dispatchEvent(new CustomEvent('form:success', { bubbles: true, detail: res }));
      return;
    }
    setStatus('error');
    setMessage(res.message || 'Unable to submit right now. Please try again.');
    if (res.errors && Object.keys(res.errors).length) {
      setErrors(res.errors);
      const first = Object.keys(res.errors).find((k) => all.some((f) => f.name === k)) ?? Object.keys(res.errors)[0];
      window.setTimeout(() => focusField(first), 30);
    }
  };

  const sending = status === 'sending';
  const extraErrors = Object.entries(errors).filter(([k]) => !all.some((f) => f.name === k));

  return (
    <div className={clsx('enquiry', `enquiry-${theme}`, compact && 'enquiry-compact')}>
      {(title || icon) && (
        <div className={clsx('mb-5', dark && 'text-center')}>
          {icon && (
            <span className="enquiry-icon">
              <Icon name={icon} className="h-7 w-7" />
            </span>
          )}
          {title && (titleTag === 'h2' ? <h2 className="enquiry-title">{titleNode(title, titleHighlight)}</h2> : <h3 className="enquiry-title">{titleNode(title, titleHighlight)}</h3>)}
          {tags.length > 0 && (
            <p className="enquiry-tags">
              {tags.map((t) => (
                <span key={t.label}>
                  <Icon name={t.icon} className="" />
                  {t.label}
                </span>
              ))}
            </p>
          )}
          {subtitle && <p className="enquiry-subtitle">{subtitle}</p>}
        </div>
      )}

      {status === 'success' ? (
        <div className="enquiry-success" role="status" ref={successRef}>
          <svg className="success-check" viewBox="0 0 52 52" aria-hidden="true">
            <circle cx="26" cy="26" r="24" />
            <path d="M15 27 l7 7 l15-15" />
          </svg>
          <p className="mt-4 font-display text-xl font-bold">{successTitle}</p>
          <p className="mt-1 text-sm opacity-80">{message || successMessage}</p>
          <button
            type="button"
            className={clsx('btn btn-sm mt-5', dark ? 'btn-outline-light' : 'btn-secondary')}
            onClick={() => {
              setRound((r) => r + 1);
              setValues0({});
              setErrors({});
              setSuggest({});
              setMessage('');
              setStatus('idle');
              // tokens are single-use: get the next one now, so it is old enough by the time the form is sent again
              if (!security.current) {
                void fetchSecurity(endpoint).then((sec) => {
                  if (sec && !security.current) security.current = sec;
                });
              }
              window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('input:not([type="hidden"]):not([tabindex="-1"]), select, textarea')?.focus(), 30);
            }}
          >
            {resetLabel}
          </button>
        </div>
      ) : (
        <form key={round} ref={formRef} onSubmit={submit} onFocus={warm} noValidate className="grid grid-cols-2 gap-3.5" aria-busy={sending}>
          {Object.entries(hiddenAll).map(([k, v]) => (
            <input key={k} type="hidden" name={k} value={v} />
          ))}
          {/* a form that shows the consent box asks the server to require it too (422 "consent" without it) */}
          {consent && !('consent_required' in hiddenAll) && <input type="hidden" name="consent_required" value="1" />}
          <input type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
          {fields.map((f) => {
            const id = `${uid}-${f.name}`;
            const err = errors[f.name];
            const describedBy = [err && `${id}-err`, f.help && `${id}-help`, f.type === 'email' && suggest[f.name] && `${id}-sug`].filter(Boolean).join(' ') || undefined;
            const placeholder = f.placeholder ?? (compact ? `${f.label}${f.required ? ' *' : ''}` : undefined);
            const common = {
              id,
              name: f.name,
              required: f.required,
              'aria-required': f.required || undefined,
              disabled: sending,
              'aria-invalid': err ? true : undefined,
              'aria-describedby': describedBy,
              onBlur: () => validateField(f),
              onChange: () => err && validateField(f),
              className: clsx('form-input', err && 'is-invalid'),
              defaultValue: fieldValue(f) || undefined,
            };
            const fieldCountry = countryOf(f.countryField);
            return (
              <div key={f.name} className={clsx(f.col === 'half' ? 'col-span-2 sm:col-span-1' : 'col-span-2')}>
                {f.type === 'checkbox' ? (
                  <div className="flex items-start gap-2.5">
                    <input {...common} type="checkbox" value="1" defaultValue={undefined} defaultChecked={['1', 'on', 'true', 'yes'].includes(String(fieldValue(f)).toLowerCase())} className={clsx('form-check mt-0.5', err && 'is-invalid')} />
                    <label htmlFor={id} className="text-sm">
                      {f.label}
                      {f.required && <span className="form-required"> *</span>}
                    </label>
                  </div>
                ) : (
                  <>
                    <label htmlFor={id} className={clsx('form-label', compact && 'sr-only')}>
                      {f.label}
                      {f.required && <span className="form-required"> *</span>}
                    </label>
                    {f.type === 'select' ? (
                      <select {...common}>
                        <option value="">{f.placeholder || `Select ${f.label.toLowerCase()}`}</option>
                        {groupOptions(f.options || []).map((g, gi) =>
                          g.group ? (
                            <optgroup key={`g${gi}`} label={g.group}>
                              {g.items.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                  {opt.label}
                                </option>
                              ))}
                            </optgroup>
                          ) : (
                            g.items.map((opt) => (
                              <option key={opt.value} value={opt.value}>
                                {opt.label}
                              </option>
                            ))
                          ),
                        )}
                      </select>
                    ) : f.type === 'textarea' ? (
                      <textarea {...common} rows={f.rows ?? 4} placeholder={placeholder} maxLength={f.maxLength} />
                    ) : f.type === 'tel' ? (
                      <PhoneInput
                        id={id}
                        name={f.name}
                        label={f.label}
                        defaultValue={fieldValue(f)}
                        defaultCountry={(values0[`${f.name}_country`] || telCountry(f)).toUpperCase()}
                        required={f.required}
                        disabled={sending}
                        invalid={!!err}
                        describedBy={describedBy}
                        placeholder={compact ? placeholder : f.placeholder}
                        autoComplete={f.autocomplete && f.autocomplete !== 'tel-national' ? f.autocomplete : 'tel'}
                        inputClassName={clsx(compact && 'py-2.5', theme === 'lead' && 'text-[15px]')}
                        onChange={() => err && window.setTimeout(() => validateField(f), 0)}
                        onBlur={() => window.setTimeout(() => validateField(f), 0)}
                      />
                    ) : f.type === 'country' ? (
                      <CountryField
                        id={id}
                        name={f.name}
                        label={f.label}
                        iso={countries[f.name] ?? DEFAULT_COUNTRY}
                        invalid={!!err}
                        disabled={sending}
                        describedBy={describedBy}
                        onChange={(iso) => {
                          setCountries((p) => ({ ...p, [f.name]: iso }));
                          setFieldError(f.name, null);
                          // re-check dependent postal codes against the new country
                          fields.filter((x) => x.type === 'postal' && (x.countryField || 'country') === f.name).forEach((x) => setFieldError(x.name, null));
                        }}
                      />
                    ) : f.type === 'state' ? (
                      <StateField
                        id={id}
                        name={f.name}
                        country={fieldCountry}
                        value={addr[f.name] ?? ''}
                        required={f.required}
                        invalid={!!err}
                        disabled={sending}
                        describedBy={describedBy}
                        onChange={(v) => {
                          setAddr((p) => ({ ...p, [f.name]: v }));
                          if (err) setFieldError(f.name, v || !f.required ? null : `${f.label} is required.`);
                        }}
                        onBlur={() => validateField(f)}
                      />
                    ) : f.type === 'postal' ? (
                      <PostalField
                        id={id}
                        name={f.name}
                        country={fieldCountry}
                        value={addr[f.name] ?? ''}
                        required={f.required}
                        invalid={!!err}
                        disabled={sending}
                        describedBy={describedBy}
                        placeholder={compact ? placeholder : f.placeholder}
                        onChange={(v) => {
                          setAddr((p) => ({ ...p, [f.name]: v }));
                          if (err) window.setTimeout(() => validateField(f), 0);
                        }}
                        onBlur={() => validateField(f)}
                      />
                    ) : (
                      <input
                        {...common}
                        type={f.type ?? 'text'}
                        placeholder={placeholder}
                        autoComplete={f.autocomplete}
                        inputMode={f.type === 'email' ? 'email' : undefined}
                        autoCapitalize={f.type === 'email' ? 'off' : undefined}
                        spellCheck={f.type === 'email' ? false : undefined}
                        min={f.min}
                        max={f.max}
                        maxLength={f.maxLength}
                        onBlur={(ev) => {
                          if (f.type === 'email') {
                            const el = ev.currentTarget;
                            const norm = normalizeEmail(el.value);
                            if (norm !== el.value) el.value = norm;
                            setSuggest((p) => ({ ...p, [f.name]: suggestEmail(norm) }));
                          }
                          validateField(f);
                        }}
                      />
                    )}
                  </>
                )}
                {f.type === 'email' && (
                  <div id={`${id}-sug`}>
                    <EmailSuggestion
                      dark={dark}
                      suggestion={suggest[f.name] ?? null}
                      onAccept={(s) => {
                        const el = formRef.current?.querySelector<HTMLInputElement>(`#${CSS.escape(id)}`);
                        if (el) {
                          el.value = s;
                          el.focus();
                        }
                        setSuggest((p) => ({ ...p, [f.name]: null }));
                        validateField(f);
                      }}
                    />
                  </div>
                )}
                {f.help && !err && (
                  <p id={`${id}-help`} className="form-hint">
                    {f.help}
                  </p>
                )}
                {err && (
                  <p id={`${id}-err`} className="form-error">
                    {err}
                  </p>
                )}
              </div>
            );
          })}
          {consent && (
            <div className="col-span-2">
              <div className="flex items-start gap-2.5">
                <input
                  id={`${uid}-consent`}
                  name="consent"
                  type="checkbox"
                  value="1"
                  required
                  aria-required="true"
                  disabled={sending}
                  defaultChecked={['1', 'on'].includes(values0.consent ?? '')}
                  className={clsx('form-check mt-0.5', errors.consent && 'is-invalid')}
                  aria-invalid={errors.consent ? true : undefined}
                  aria-describedby={errors.consent ? `${uid}-consent-err` : undefined}
                  onChange={(ev) => ev.currentTarget.checked && setFieldError('consent', null)}
                />
                <label htmlFor={`${uid}-consent`} className="enquiry-consent text-sm">
                  {consent}
                  <span className="form-required"> *</span>
                </label>
              </div>
              {errors.consent && (
                <p id={`${uid}-consent-err`} className="form-error">
                  {errors.consent === 'Consent is required.' ? consentMsg : errors.consent}
                </p>
              )}
            </div>
          )}
          <CaptchaNote captcha={security.current?.captcha ?? captcha} dark={dark} holderRef={(el) => (captchaHolder.current = el)} />
          {status === 'error' && (message || extraErrors.length > 0) && (
            <div className="form-alert col-span-2 !items-start" role="alert">
              <CircleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              <div>
                {message}
                {extraErrors.length > 0 && (
                  <ul className="mt-1 list-disc pl-5 text-[13px] font-normal">
                    {extraErrors.map(([k, v]) => (
                      <li key={k}>{v}</li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          )}
          <div className="col-span-2 pt-1">
            <button type="submit" className="btn btn-accent btn-shine w-full" disabled={sending} aria-disabled={sending || undefined} data-magnetic="0.15">
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
