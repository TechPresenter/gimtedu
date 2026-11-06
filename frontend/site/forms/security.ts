/**
 * Spam / duplicate protection + conversion tracking for the public form islands (server side: pf_guard() and
 * pf_token_*() in app/services/public_forms.php).
 *
 *  - One-time form token "_ft": issued with the page (prop) or fetched from GET /api/public/form-token next to the
 *    form endpoint. The server refuses tokens younger than `minSeconds` (bots submit instantly) and answers a reused
 *    token with the first result (double clicks / retries never create a second row).
 *  - Captcha (reCAPTCHA v3, Cloudflare Turnstile or hCaptcha — Admin › SEO › Integrations): the provider script is
 *    loaded only when the visitor starts filling a form, never on page load, and only when a provider is configured.
 *  - postSecure(): FormData POST with CSRF header, token + captcha answer, automatic single retry for an expired token
 *    / session (419) or a too-fast submission.
 *  - pushLead(): window.dataLayer conversion event {event: 'generate_lead', form} (consent is handled by the
 *    cookie-consent / Consent Mode layer of the SEO unit; no personal data is pushed).
 */
import { csrfToken } from '../core/dom';

export interface CaptchaConfig {
  provider: 'recaptcha_v3' | 'turnstile' | 'hcaptcha';
  siteKey: string;
}

export interface FormSecurity {
  token: string;
  captcha: CaptchaConfig | null;
  /** when this token was issued / received (ms) */
  at: number;
  minSeconds: number;
}

export interface SecureResult<T = unknown> {
  ok: boolean;
  status: number;
  message: string;
  data?: T;
  errors?: Record<string, string>;
}

/** GET endpoint for fresh tokens, next to the POST endpoint ("/api/public/enquiry" -> "/api/public/form-token"). */
export function tokenUrl(endpoint: string): string {
  const clean = endpoint.split('?')[0].replace(/\/+$/, '');
  return clean.replace(/\/[^/]*$/, '/form-token');
}

/**
 * Security from server-rendered props (token issued with the page). Call it when the island mounts: the token is at
 * least as old as the mount, so timing it from here is conservative (the server clock decides).
 */
export function initialSecurity(token?: string | null, captcha?: CaptchaConfig | null): FormSecurity | null {
  return token ? { token, captcha: captcha ?? null, at: Date.now(), minSeconds: 2 } : null;
}

/** Fetch a fresh token (+ CSRF, captcha config). Updates the page's CSRF meta tag. */
export async function fetchSecurity(endpoint: string): Promise<FormSecurity | null> {
  try {
    const res = await fetch(tokenUrl(endpoint), { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
    const json = (await res.json()) as { ok?: boolean; data?: { token?: string; csrf?: string; captcha?: CaptchaConfig | null; minSeconds?: number } };
    const d = json?.data;
    if (!json?.ok || !d?.token) return null;
    if (d.csrf) {
      const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
      if (meta) meta.content = d.csrf;
    }
    return { token: d.token, captcha: d.captcha ?? null, at: Date.now(), minSeconds: d.minSeconds ?? 2 };
  } catch {
    return null;
  }
}

const wait = (ms: number) => new Promise<void>((r) => window.setTimeout(r, Math.max(0, ms)));

/* ------------------------------------------------------------------ captcha */

type Grecaptcha = { ready: (cb: () => void) => void; execute: (key: string, o: { action: string }) => Promise<string> };
type Turnstile = {
  render: (el: HTMLElement, o: Record<string, unknown>) => string;
  execute: (el: HTMLElement | string) => void;
  remove: (id: string) => void;
};
type Hcaptcha = { render: (el: HTMLElement, o: Record<string, unknown>) => string; execute: (id: string, o: { async: true }) => Promise<{ response: string }>; remove?: (id: string) => void };

const scripts = new Map<string, Promise<void>>();

function loadScript(src: string): Promise<void> {
  let p = scripts.get(src);
  if (!p) {
    p = new Promise<void>((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.defer = true;
      s.onload = () => resolve();
      s.onerror = () => {
        scripts.delete(src);
        reject(new Error('captcha script failed'));
      };
      document.head.appendChild(s);
    });
    scripts.set(src, p);
  }
  return p;
}

function scriptFor(c: CaptchaConfig): string {
  if (c.provider === 'recaptcha_v3') return `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(c.siteKey)}`;
  if (c.provider === 'turnstile') return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
  return 'https://js.hcaptcha.com/1/api.js?render=explicit&recaptchacompat=off';
}

/** Start downloading the captcha script (call on first interaction with a form). */
export function preloadCaptcha(c: CaptchaConfig | null | undefined): void {
  if (!c) return;
  if (c.provider === 'recaptcha_v3' && !document.getElementById('gimt-recaptcha-badge')) {
    // the badge is replaced by the notice rendered under the form (Google allows this when the notice is shown)
    const st = document.createElement('style');
    st.id = 'gimt-recaptcha-badge';
    st.textContent = '.grecaptcha-badge{visibility:hidden!important}';
    document.head.appendChild(st);
  }
  void loadScript(scriptFor(c)).catch(() => undefined);
}

/** reCAPTCHA v3 action name — same as pf_captcha_action() on the server. */
export const captchaAction = (form: string) => form.toLowerCase().replace(/[^a-z0-9_]/g, '_') || 'submit';

/**
 * Get a captcha answer. `holder` hosts the Turnstile / hCaptcha widget (shown only if the provider needs an
 * interaction). Resolves '' when no captcha is configured; rejects when the check could not be completed.
 */
export async function captchaAnswer(c: CaptchaConfig | null | undefined, form: string, holder?: HTMLElement | null): Promise<string> {
  if (!c) return '';
  await loadScript(scriptFor(c));
  const w = window as unknown as { grecaptcha?: Grecaptcha; turnstile?: Turnstile; hcaptcha?: Hcaptcha };
  if (c.provider === 'recaptcha_v3') {
    const g = w.grecaptcha;
    if (!g) throw new Error('reCAPTCHA unavailable');
    await new Promise<void>((r) => g.ready(r));
    return g.execute(c.siteKey, { action: captchaAction(form) });
  }
  const box = document.createElement('div');
  (holder ?? document.body).appendChild(box);
  try {
    if (c.provider === 'turnstile') {
      const t = w.turnstile;
      if (!t) throw new Error('Turnstile unavailable');
      return await new Promise<string>((resolve, reject) => {
        const id = t.render(box, {
          sitekey: c.siteKey,
          action: captchaAction(form),
          appearance: 'interaction-only',
          execution: 'execute',
          callback: (token: string) => {
            resolve(token);
            window.setTimeout(() => t.remove(id), 0);
          },
          'error-callback': () => reject(new Error('Turnstile error')),
          'timeout-callback': () => reject(new Error('Turnstile timeout')),
        });
        t.execute(box);
      });
    }
    const h = w.hcaptcha;
    if (!h) throw new Error('hCaptcha unavailable');
    const id = h.render(box, { sitekey: c.siteKey, size: 'invisible' });
    const res = await h.execute(id, { async: true });
    h.remove?.(id);
    return res.response;
  } finally {
    window.setTimeout(() => box.remove(), 1000);
  }
}

/* ------------------------------------------------------------------ submit */

function send<T>(url: string, body: FormData): Promise<SecureResult<T>> {
  return fetch(url, {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
  })
    .then(async (res) => {
      const json = (await res.json().catch(() => null)) as { ok?: boolean; message?: string; data?: T; errors?: Record<string, string> } | null;
      if (json && typeof json.ok === 'boolean') return { ok: json.ok, status: res.status, message: json.message ?? '', data: json.data, errors: json.errors && !Array.isArray(json.errors) ? json.errors : {} };
      return { ok: false, status: res.status, message: res.status === 404 ? 'This form is not available yet. Please try again later.' : 'Unexpected server response. Please try again.' };
    })
    .catch(() => ({ ok: false, status: 0, message: 'Unable to submit right now. Please check your connection and try again.' }));
}

export interface SecureOptions {
  endpoint: string;
  /** form key for captcha actions / analytics ("enquiry", "contact" …) */
  form: string;
  /** current security (token from props or an earlier fetch) — refreshed automatically when missing / expired */
  security: FormSecurity | null;
  /** element that may host a Turnstile / hCaptcha challenge */
  captchaHolder?: HTMLElement | null;
  /** custom transport (e.g. XHR with upload progress); defaults to fetch */
  transport?: (body: FormData) => Promise<SecureResult>;
}

/**
 * POST with token + captcha answer, retrying once when the server reports an expired token / session (419) or a
 * too-fast submission (waits the remaining seconds). Returns the result and the security object that was used
 * (callers drop it after a success — tokens are single-use).
 */
export async function postSecure<T = unknown>(body: FormData, o: SecureOptions): Promise<SecureResult<T> & { security: FormSecurity | null }> {
  let sec = o.security ?? (await fetchSecurity(o.endpoint));
  const transport = (o.transport as ((b: FormData) => Promise<SecureResult<T>>) | undefined) ?? ((b: FormData) => send<T>(o.endpoint, b));
  const attempt = async (): Promise<SecureResult<T>> => {
    if (sec) {
      // never send sooner than the server's minimum fill time (e.g. right after a fresh token was fetched)
      await wait(sec.minSeconds * 1000 + 250 - (Date.now() - sec.at));
      body.set('_ft', sec.token);
    }
    if (sec?.captcha) {
      try {
        body.set('captcha_token', await captchaAnswer(sec.captcha, o.form, o.captchaHolder));
      } catch {
        return { ok: false, status: 422, message: 'The security check could not be completed. Please try again.', errors: { captcha: 'Please try the security check again.' } };
      }
    }
    return transport(body);
  };
  let res = await attempt();
  const ft = res.errors?._ft ?? '';
  if (!res.ok && (res.status === 419 || ft.startsWith('fast'))) {
    if (ft.startsWith('fast') && sec) {
      sec = { ...sec, at: Date.now() - (sec.minSeconds - Number(ft.split(':')[1] || sec.minSeconds)) * 1000 };
    } else {
      sec = (await fetchSecurity(o.endpoint)) ?? sec;
    }
    res = await attempt();
  }
  if (res.errors) delete res.errors._ft;
  return { ...res, security: sec };
}

/**
 * Conversion event for Google Tag Manager / GA4 (contract with the SEO unit's consent layer). Also announces the lead on
 * window as `gimt:lead` ({form}) — core/popup.ts then stops the automatic enquiry popup for 30 days.
 */
export function pushLead(form: string, extra: Record<string, string | number | boolean> = {}): void {
  try {
    const w = window as unknown as { dataLayer?: unknown[] };
    w.dataLayer = w.dataLayer || [];
    w.dataLayer.push({ event: 'generate_lead', form, ...extra });
  } catch {
    /* never block the success state */
  }
  try {
    window.dispatchEvent(new CustomEvent('gimt:lead', { detail: { form } }));
  } catch {
    /* ignore */
  }
}
