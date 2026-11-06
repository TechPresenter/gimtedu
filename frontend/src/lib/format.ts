/** Formatting helpers (Indian locale). */

const inr = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });
const inr2 = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function toNumber(v: unknown): number {
  if (typeof v === 'number') return v;
  if (v === null || v === undefined || v === '') return 0;
  const n = Number(String(v).replace(/,/g, ''));
  return Number.isFinite(n) ? n : 0;
}

export function formatNumber(v: unknown, decimals = 0): string {
  const n = toNumber(v);
  return decimals ? n.toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) : inr.format(n);
}

/** ₹1,20,000 */
export function formatMoney(v: unknown, decimals = 0): string {
  const n = toNumber(v);
  return `₹${decimals ? inr2.format(n) : inr.format(n)}`;
}

/** ₹28.4 L / ₹2.5 Cr / ₹45K */
export function formatMoneyShort(v: unknown): string {
  const n = toNumber(v);
  const abs = Math.abs(n);
  const trim = (x: number, d = 1) => x.toFixed(d).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
  if (abs >= 1e7) return `₹${trim(n / 1e7, 2)} Cr`;
  if (abs >= 1e5) return `₹${trim(n / 1e5)} L`;
  if (abs >= 1e3) return `₹${trim(n / 1e3)}K`;
  return `₹${inr.format(n)}`;
}

export function formatPercent(v: unknown, decimals = 0): string {
  return `${toNumber(v).toFixed(decimals)}%`;
}

function parseDate(v: unknown): Date | null {
  if (!v) return null;
  if (v instanceof Date) return v;
  const s = String(v);
  if (s.startsWith('0000')) return null;
  const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(s) ? `${s}T00:00:00` : s.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

/** 07 Oct 2026 */
export function formatDate(v: unknown): string {
  const d = parseDate(v);
  return d ? d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}

/** 07 Oct 2026, 04:15 PM */
export function formatDateTime(v: unknown): string {
  const d = parseDate(v);
  return d ? `${formatDate(d)}, ${d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}` : '—';
}

/** 09:30 AM from "09:30:00" */
export function formatTime(v: unknown): string {
  if (!v) return '—';
  const [h, m] = String(v).split(':').map(Number);
  if (Number.isNaN(h)) return String(v);
  const ampm = h >= 12 ? 'PM' : 'AM';
  return `${String(((h + 11) % 12) + 1).padStart(2, '0')}:${String(m ?? 0).padStart(2, '0')} ${ampm}`;
}

export function timeAgo(v: unknown): string {
  const d = parseDate(v);
  if (!d) return '—';
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return 'just now';
  const units: [number, string][] = [[31536000, 'year'], [2592000, 'month'], [604800, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'min']];
  for (const [secs, label] of units) {
    if (diff >= secs) {
      const n = Math.floor(diff / secs);
      return `${n} ${label}${n > 1 ? 's' : ''} ago`;
    }
  }
  return 'just now';
}

/** YYYY-MM-DD for <input type="date"> */
export function isoDate(d: Date = new Date()): string {
  const tz = d.getTimezoneOffset() * 60000;
  return new Date(d.getTime() - tz).toISOString().slice(0, 10);
}

/** "first_name" -> "First Name" */
export function labelize(key: string | null | undefined): string {
  if (!key) return '';
  return String(key).replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function initials(name: string | null | undefined): string {
  const parts = String(name ?? '').replace(/^(Dr|Prof|Mr|Ms|Mrs)\.?\s+/i, '').trim().split(/\s+/);
  return (parts.slice(0, 2).map((p) => p[0] ?? '').join('') || '?').toUpperCase();
}

export function fullName(r: { first_name?: string | null; middle_name?: string | null; last_name?: string | null }): string {
  return [r.first_name, r.middle_name, r.last_name].filter(Boolean).join(' ');
}

export function humanFileSize(bytes: number): string {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`;
}

export function pluralize(n: number, singular: string, plural?: string): string {
  return `${formatNumber(n)} ${n === 1 ? singular : plural ?? `${singular}s`}`;
}

/* ---------------------------------------------------------------- phone numbers */

/** Two-digit country calling codes (ITU E.164). Codes starting with 1 or 7 have one digit; every other code has three. */
const CC2 = new Set('20 27 30 31 32 33 34 36 39 40 41 43 44 45 46 47 48 49 51 52 53 54 55 56 57 58 60 61 62 63 64 65 66 81 82 84 86 90 91 92 93 94 95 98'.split(' '));
/** Indian metro STD codes with two digits (Delhi, Pune, Mumbai, Kolkata, Hyderabad, Chennai, Ahmedabad, Bengaluru). */
const IN_METRO = new Set(['11', '20', '22', '33', '40', '44', '79', '80']);

/** Split digits into groups of the given sizes; the remainder joins the last group. */
function groupDigits(d: string, sizes: number[]): string {
  const out: string[] = [];
  let i = 0;
  sizes.forEach((n, k) => {
    if (i >= d.length) return;
    const take = k === sizes.length - 1 ? d.length - i : n;
    out.push(d.slice(i, i + take));
    i += take;
  });
  return out.join(' ');
}

function nationalGroups(cc: string, n: string): string {
  const len = n.length;
  if (cc === '91' && len === 10) {
    if (/^[6-9]/.test(n)) return groupDigits(n, [5, 5]); // mobile: 99554 46477
    return IN_METRO.has(n.slice(0, 2)) ? groupDigits(n, [2, 4, 4]) : groupDigits(n, [3, 3, 4]); // landline with STD code
  }
  if (cc === '1' && len === 10) return groupDigits(n, [3, 3, 4]);
  if (cc === '44' && len === 10) return groupDigits(n, [4, 6]);
  if (len <= 4) return n;
  if (len <= 8) return groupDigits(n, [Math.ceil(len / 2), len]);
  if (len === 9) return groupDigits(n, [cc === '971' ? 2 : 3, 3, 3]);
  if (len === 10) return groupDigits(n, [3, 3, 4]);
  if (len === 11) return groupDigits(n, [3, 4, 4]);
  return groupDigits(n, [4, 4, 4]);
}

/** Digits of a phone number in international form ("919955446477"), or null when it cannot be read as one. */
function phoneDigits(v: unknown): string | null {
  const raw = String(v ?? '').trim();
  if (!raw || /[a-z]/i.test(raw.replace(/\b(ext|x)\.?\s*\d+$/i, ''))) return null;
  const digits = raw.replace(/\D/g, '');
  if (raw.startsWith('+')) return digits.length >= 7 ? digits : null;
  if (raw.startsWith('00') && digits.length >= 9) return digits.slice(2);
  // Numbers stored without a country code are Indian (the forms' default country)
  if (/^0?[6-9]\d{9}$/.test(digits)) return `91${digits.slice(-10)}`;
  if (/^91[6-9]\d{9}$/.test(digits)) return digits;
  return null;
}

/**
 * Readable international phone number: '+919955446477' / '+91 9955446477' / '9955446477' → '+91 99554 46477',
 * '+14155552671' → '+1 415 555 2671'. Values that are not phone numbers are returned as typed.
 */
export function formatPhone(v: unknown): string {
  const raw = String(v ?? '').trim();
  const d = phoneDigits(raw);
  if (!d) return raw;
  const cc = d[0] === '1' || d[0] === '7' ? d.slice(0, 1) : CC2.has(d.slice(0, 2)) ? d.slice(0, 2) : d.slice(0, 3);
  const national = d.slice(cc.length);
  return national ? `+${cc} ${nationalGroups(cc, national)}` : raw;
}

/** tel: link target in E.164 ('tel:+919955446477'); falls back to the digits as typed. */
export function telHref(v: unknown): string {
  const d = phoneDigits(v);
  return `tel:${d ? `+${d}` : String(v ?? '').replace(/[^\d+]/g, '')}`;
}

/** WhatsApp click-to-chat link (wa.me needs the full international number without '+'). */
export function whatsappHref(v: unknown, text?: string): string {
  const d = phoneDigits(v) ?? String(v ?? '').replace(/\D/g, '');
  return `https://wa.me/${d}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
}
