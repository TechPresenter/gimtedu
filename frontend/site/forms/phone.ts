/**
 * International phone numbers for the public forms (unit forms_platform).
 *
 * libphonenumber-js ("max" metadata: per-country number patterns, so validation is precise) is loaded lazily — only
 * when a visitor first interacts with a form that has a phone field — and cached for the page. Until it has loaded the
 * inputs still work: values are sent as typed and the server (app/services/phone.php, same metadata) validates and
 * normalises to E.164. Messages are identical on both sides.
 */
type PhoneLib = typeof import('libphonenumber-js/max');

let libPromise: Promise<PhoneLib> | null = null;
let lib: PhoneLib | null = null;
const listeners = new Set<() => void>();

/** Start loading libphonenumber-js (idempotent). Resolves with the module. */
export function loadPhoneLib(): Promise<PhoneLib> {
  if (!libPromise) {
    libPromise = import('libphonenumber-js/max').then((m) => {
      lib = m;
      listeners.forEach((fn) => fn());
      return m;
    });
    libPromise.catch(() => {
      libPromise = null; // offline / chunk error: allow a retry on the next interaction
    });
  }
  return libPromise;
}

/** The loaded module, or null while it is still loading. */
export const phoneLib = (): PhoneLib | null => lib;

/** Subscribe to "library loaded" (returns an unsubscribe function). */
export function onPhoneLibLoaded(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export const DEFAULT_COUNTRY = 'IN';

/** Countries listed first in the selector (India, then the most common origins of applicants) — mirrors phone_preferred_countries(). */
export const PREFERRED: [string, string][] = [
  ['IN', '91'], ['NP', '977'], ['BD', '880'], ['BT', '975'], ['LK', '94'], ['AE', '971'], ['SA', '966'], ['QA', '974'],
  ['KW', '965'], ['OM', '968'], ['BH', '973'], ['US', '1'], ['GB', '44'], ['CA', '1'], ['AU', '61'], ['SG', '65'],
];

/** Flag emoji from an ISO 3166-1 alpha-2 code (regional indicator symbols). */
export function flagEmoji(iso: string): string {
  if (!/^[A-Za-z]{2}$/.test(iso)) return '🌐';
  return String.fromCodePoint(...iso.toUpperCase().split('').map((c) => 0x1f1e6 + c.charCodeAt(0) - 65));
}

let flagSupport: boolean | null = null;

/**
 * Can this device draw flag emoji? (Windows shows the two letters instead — the selectors then show a neat ISO-code
 * badge.) Checked once by drawing 🇮🇳 on a tiny canvas and looking for colour.
 */
export function supportsFlags(): boolean {
  if (flagSupport !== null) return flagSupport;
  flagSupport = false;
  try {
    const c = document.createElement('canvas');
    c.width = c.height = 16;
    const ctx = c.getContext('2d', { willReadFrequently: true } as CanvasRenderingContext2DSettings);
    if (ctx) {
      ctx.textBaseline = 'top';
      ctx.font = '14px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif';
      ctx.fillText(flagEmoji('IN'), 0, 0);
      const d = ctx.getImageData(0, 0, 16, 16).data;
      for (let i = 0; i < d.length; i += 4) {
        if (d[i + 3] > 0 && (Math.abs(d[i] - d[i + 1]) > 30 || Math.abs(d[i + 1] - d[i + 2]) > 30)) {
          flagSupport = true;
          break;
        }
      }
    }
  } catch {
    flagSupport = false;
  }
  return flagSupport;
}

let displayNames: Intl.DisplayNames | null | undefined;
const FALLBACK_NAMES: Record<string, string> = { IN: 'India', NP: 'Nepal', BD: 'Bangladesh', BT: 'Bhutan', LK: 'Sri Lanka', AE: 'United Arab Emirates', SA: 'Saudi Arabia', QA: 'Qatar', KW: 'Kuwait', OM: 'Oman', BH: 'Bahrain', US: 'United States', GB: 'United Kingdom', CA: 'Canada', AU: 'Australia', SG: 'Singapore' };

/**
 * English country name in plain letters (" & " -> " and ", brackets removed), same as phone_country_name() on the
 * server: "St. Kitts and Nevis", "Myanmar".
 */
export function countryName(iso: string): string {
  const code = iso.toUpperCase();
  if (displayNames === undefined) {
    try {
      displayNames = new Intl.DisplayNames(['en'], { type: 'region' });
    } catch {
      displayNames = null;
    }
  }
  let name = FALLBACK_NAMES[code] ?? code;
  try {
    name = displayNames?.of(code) ?? name;
  } catch {
    /* unknown code */
  }
  return name.replace(/ & /g, ' and ').replace(/[’‘]/g, "'").replace(/\s*\([^)]*\)/g, '').trim();
}

/** Calling code ("91") of a country; uses the library when loaded, else the preferred list. */
export function callingCode(iso: string): string {
  const l = lib;
  if (l) {
    try {
      return l.getCountryCallingCode(iso as Parameters<PhoneLib['getCountryCallingCode']>[0]);
    } catch {
      /* not a phone region */
    }
  }
  return PREFERRED.find(([c]) => c === iso)?.[1] ?? '';
}

export interface CountryItem {
  iso: string;
  dial: string;
  name: string;
  /** lowercase accent-free search text: name, ISO code and calling code */
  search: string;
}

const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
let allCountries: CountryItem[] | null = null;

/** Every phone region, sorted by name (needs the library; null until it has loaded). */
export function countryList(): CountryItem[] | null {
  if (allCountries) return allCountries;
  const l = lib;
  if (!l) return null;
  allCountries = l
    .getCountries()
    .map((iso) => {
      const dial = l.getCountryCallingCode(iso);
      const name = countryName(iso);
      return { iso, dial, name, search: fold(`${name} ${iso} +${dial} ${dial}`) };
    })
    .sort((a, b) => fold(a.name).localeCompare(fold(b.name)));
  return allCountries;
}

/** Preferred countries as items (available before the library loads). */
export function preferredList(): CountryItem[] {
  return PREFERRED.map(([iso, dial]) => {
    const name = countryName(iso);
    return { iso, dial, name, search: fold(`${name} ${iso} +${dial} ${dial}`) };
  });
}

/** Filter countries by a query (name, ISO code or calling code). */
export function filterCountries(list: CountryItem[], q: string): CountryItem[] {
  const query = fold(q.trim()).replace(/^\+/, '');
  if (!query) return list;
  const starts = list.filter((c) => fold(c.name).startsWith(query) || c.dial.startsWith(query) || c.iso.toLowerCase() === query);
  const rest = list.filter((c) => !starts.includes(c) && c.search.includes(query));
  return [...starts, ...rest];
}

export interface PhoneState {
  /** E.164 when the number parses ("+919876543210"), else "+<dial><digits>" or '' when empty */
  value: string;
  /** detected / selected ISO2 country */
  country: string;
  valid: boolean;
}

/**
 * Value to submit for a national number typed with a selected country (or an international number starting with
 * "+"). Uses libphonenumber when loaded; otherwise "+<calling code><digits>" (the server strips a trunk 0).
 */
export function toE164(text: string, country: string): PhoneState {
  const raw = text.trim();
  const digits = raw.replace(/\D/g, '');
  if (!digits) return { value: '', country, valid: false };
  const l = lib;
  if (l) {
    const p = l.parsePhoneNumberFromString(raw, raw.startsWith('+') ? undefined : (country as never));
    if (p) return { value: p.number, country: p.country ?? country, valid: p.isValid() };
  }
  if (raw.startsWith('+')) return { value: `+${digits}`, country, valid: digits.length >= 8 && digits.length <= 15 };
  const dial = callingCode(country);
  return { value: dial ? `+${dial}${digits}` : digits, country, valid: digits.length >= 6 && digits.length <= 14 };
}

/** National display text for a stored value ("+919876543210" -> {country: 'IN', text: '98765 43210'}). */
export function fromValue(value: string, fallbackCountry: string): { country: string; text: string } {
  const v = (value || '').trim();
  if (!v) return { country: fallbackCountry, text: '' };
  const l = lib;
  if (l) {
    const p = l.parsePhoneNumberFromString(v, v.startsWith('+') ? undefined : (fallbackCountry as never));
    // national digits grouped like the international format, without the trunk 0 (the selector shows the +code)
    if (p && p.country) return { country: p.country, text: p.formatInternational().replace(/^\+\d+\s*/, '') };
    if (p) return { country: fallbackCountry, text: p.formatInternational() };
  }
  // no library yet: show Indian numbers without the +91, anything else as typed
  const d = v.replace(/\D/g, '');
  if (fallbackCountry === 'IN' && /^(?:\+?91)?[6-9]\d{9}$/.test(v.replace(/[\s-]/g, ''))) return { country: 'IN', text: d.slice(-10) };
  return { country: fallbackCountry, text: v };
}

/** As-you-type national formatting (only when the caret is at the end, so editing in the middle is never disturbed). */
export function formatAsYouType(text: string, country: string): string {
  const l = lib;
  if (!l || !text) return text;
  try {
    if (text.trim().startsWith('+')) return new l.AsYouType().input(text);
    const digits = text.replace(/\D/g, '');
    // a typed trunk prefix ("098…") keeps the national format; otherwise group like the international format
    if (digits.startsWith('0')) return new l.AsYouType(country as never).input(text);
    const dial = callingCode(country);
    if (!dial) return text;
    return new l.AsYouType().input(`+${dial}${digits}`).replace(new RegExp(`^\\+${dial}\\s?`), '');
  } catch {
    return text;
  }
}

/** Readable international form of a stored value ("+919876543210" -> "+91 98765 43210"); unchanged when it does not parse. */
export function formatDisplay(value: string | undefined | null): string {
  const v = (value || '').trim();
  const l = lib;
  if (!v || !l) return v;
  const p = l.parsePhoneNumberFromString(v);
  return p ? p.formatInternational() : v;
}

/** Placeholder for the number input ("98765 43210" for India), '' elsewhere. */
export function examplePlaceholder(country: string): string {
  if (country === 'IN') return '98765 43210';
  return '';
}

/** Main region of calling codes shared by several regions (libphonenumber order; others have a single region). */
const SHARED_MAIN: Record<string, string> = { 1: 'US', 7: 'RU', 39: 'IT', 44: 'GB', 47: 'NO', 61: 'AU', 212: 'MA', 262: 'RE', 290: 'SH', 358: 'FI', 590: 'GP', 599: 'CW' };

/** Region a calling code belongs to ("44" -> "GB"), null when unknown or the library is not loaded. */
export function mainCountryFor(callingCodeValue: string | undefined): string | null {
  if (!callingCodeValue) return null;
  if (SHARED_MAIN[callingCodeValue]) return SHARED_MAIN[callingCodeValue];
  const l = lib;
  if (!l) return PREFERRED.find(([, d]) => d === callingCodeValue)?.[0] ?? null;
  return l.getCountries().find((c) => l.getCountryCallingCode(c) === callingCodeValue) ?? null;
}

/** "Enter a valid phone number for India (+91)." — identical to phone_invalid_message() on the server. */
export function invalidMessage(country: string | null | undefined): string {
  const dial = country ? callingCode(country) : '';
  return country && dial ? `Enter a valid phone number for ${countryName(country)} (+${dial}).` : 'Enter a valid phone number, including the country code.';
}

/**
 * Validation message for a phone value (E.164 or as typed) — null when valid or empty and not required.
 * Without the library only the length is checked here (the server always checks the full rules).
 */
export function phoneError(value: string, opts: { required?: boolean; label?: string; country?: string } = {}): string | null {
  const v = (value || '').trim();
  if (!v || v.replace(/\D/g, '') === '') return opts.required ? `${opts.label || 'Phone number'} is required.` : null;
  if (/[A-Za-z]/.test(v)) return invalidMessage(null);
  const l = lib;
  const country = opts.country || DEFAULT_COUNTRY;
  if (l) {
    const p = l.parsePhoneNumberFromString(v, v.startsWith('+') ? undefined : (country as never));
    if (p && p.isValid()) return null;
    return invalidMessage(p ? p.country ?? mainCountryFor(p.countryCallingCode) : v.startsWith('+') ? null : country);
  }
  const digits = v.replace(/\D/g, '');
  return digits.length < 7 || digits.length > 15 ? invalidMessage(v.startsWith('+') ? null : country) : null;
}
