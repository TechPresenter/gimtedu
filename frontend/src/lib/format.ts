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
