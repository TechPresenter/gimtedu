/**
 * Email helpers for the public forms — mirror email_normalize() / email_is_valid() in app/services/phone.php
 * (trim, strip spaces and "mailto:", lowercase, internationalised domain -> ASCII / punycode, strict format) plus
 * typo suggestions for common providers ("gmial.com" -> "gmail.com"), shown as "Did you mean …?" (client only).
 */

/** Internationalised domain -> ASCII via the URL parser (punycode), unchanged when already ASCII or invalid. */
function domainToAscii(domain: string): string {
  if (!/[^\x20-\x7e]/.test(domain)) return domain;
  try {
    return new URL(`http://${domain}/`).hostname || domain;
  } catch {
    return domain;
  }
}

/** Normalised address ('' for empty input). Does not validate. */
export function normalizeEmail(value: string): string {
  let e = (value || '').replace(/\s+/g, '');
  e = e.replace(/^["'<]+|[>"']+$/g, '').replace(/^mailto:/i, '');
  if (!e) return '';
  const at = e.lastIndexOf('@');
  if (at < 0) return e.toLowerCase();
  const local = e.slice(0, at).toLowerCase();
  const domain = e
    .slice(at + 1)
    .replace(/[。．｡]/g, '.')
    .toLowerCase()
    .replace(/\.+$/, '');
  return `${local}@${domainToAscii(domain)}`;
}

const LOCAL = /^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*$/;
const LABEL = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;
const TLD = /^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/;

/** Strict format check of a normalised address (same rules as the server). */
export function isValidEmail(value: string): boolean {
  const e = normalizeEmail(value);
  if (!e || e.length > 254 || e.split('@').length !== 2) return false;
  const [local, domain] = e.split('@');
  if (!local || local.length > 64 || !LOCAL.test(local) || domain.length > 253) return false;
  const labels = domain.split('.');
  if (labels.length < 2 || labels.some((l) => !l || l.length > 63 || !LABEL.test(l))) return false;
  return TLD.test(labels[labels.length - 1]);
}

/** Validation message (null when valid / empty and optional). */
export function emailError(value: string, opts: { required?: boolean; label?: string } = {}): string | null {
  if (!normalizeEmail(value)) return opts.required ? `${opts.label || 'Email address'} is required.` : null;
  return isValidEmail(value) ? null : 'Enter a valid email address.';
}

const DOMAINS = [
  'gmail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.in', 'hotmail.com', 'outlook.com', 'outlook.in', 'live.com', 'live.in', 'icloud.com', 'me.com',
  'rediffmail.com', 'ymail.com', 'aol.com', 'msn.com', 'protonmail.com', 'proton.me', 'zoho.com', 'zohomail.in', 'gmx.com', 'mail.com',
];
const TLDS = ['com', 'in', 'co.in', 'org', 'net', 'edu', 'ac.in', 'gov.in', 'co.uk', 'ae', 'me', 'io'];
/** Mistyped TLDs that are not real TLDs themselves (safe to correct on any domain). */
const TLD_TYPOS: Record<string, string> = { con: 'com', cmo: 'com', ocm: 'com', vom: 'com', xom: 'com', comm: 'com', coom: 'com', cpm: 'com', 'co.inn': 'co.in', 'con.in': 'co.in', inn: 'in' };
/** Extra corrections only for the big providers ("gmail.co", "yahoo.om" — .co / .om are real TLDs elsewhere). */
const PROVIDER_TLD_TYPOS: Record<string, string> = { ...TLD_TYPOS, co: 'com', om: 'com', cm: 'com', ni: 'in' };

/** Damerau-Levenshtein distance (optimal string alignment) — small strings only. */
function distance(a: string, b: string): number {
  const d: number[][] = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) {
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
    }
  }
  return d[a.length][b.length];
}

/**
 * Suggested correction for a likely typo in the domain ("rahul@gmial.com" -> "rahul@gmail.com",
 * "x@yahoo.con" -> "x@yahoo.com"), or null. Institutional domains that are not close to a provider are left alone.
 */
export function suggestEmail(value: string): string | null {
  const e = normalizeEmail(value);
  const at = e.lastIndexOf('@');
  if (at < 1) return null;
  const local = e.slice(0, at);
  const domain = e.slice(at + 1);
  if (!domain || DOMAINS.includes(domain)) return null;
  // whole-domain match against the common providers — only when the provider name itself is mistyped ("gmial.com");
  // the same name with another ending is a real regional domain ("yahoo.co.uk", "hotmail.fr") or a TLD typo (below)
  let best: string | null = null;
  let bestScore = 3;
  const name0 = domain.split('.')[0];
  for (const d of DOMAINS) {
    if (d.split('.')[0] === name0) continue;
    const score = distance(domain, d);
    if (score < bestScore && score <= (d.length > 9 ? 2 : 1)) {
      best = d;
      bestScore = score;
    }
  }
  // provider name without a TLD ("gmail") or with a mistyped one ("gmail.co")
  if (!best) {
    const name = domain.split('.')[0];
    const match = DOMAINS.find((d) => d.split('.')[0] === name);
    if (match && domain !== match && (domain === name || PROVIDER_TLD_TYPOS[domain.slice(name.length + 1)])) best = match;
  }
  // TLD typo on any domain ("college.con" -> "college.com")
  if (!best) {
    const dot = domain.indexOf('.');
    const tld = dot > 0 ? domain.slice(dot + 1) : '';
    if (tld && TLD_TYPOS[tld] && !TLDS.includes(tld)) best = `${domain.slice(0, dot)}.${TLD_TYPOS[tld]}`;
  }
  return best && best !== domain ? `${local}@${best}` : null;
}
