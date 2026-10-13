/**
 * Small helpers shared by the homepage islands (CourseFinder, ScholarshipAssistant, StudentLifeSlider, CampusExplorer,
 * AcademicCalendar, SmartEnquiry). Kept dependency-free so each island chunk stays small.
 */
import { staticMode } from '../../core/dom';

export interface Option {
  value: string;
  label: string;
  count?: number;
}

/** Context handed to the Smart Enquiry form when another widget asks for a counsellor callback. */
export interface EnquiryIntent {
  level?: string;
  program?: string;
  note?: string;
}

declare global {
  interface Window {
    __gimtEnquiryIntent?: EnquiryIntent;
  }
}

export const ENQUIRY_EVENT = 'gimt:enquiry';

/**
 * Open the on-page Smart Enquiry form (url "#enquiry") with optional context — scrolls to it and pre-fills it, even if
 * the island mounts later (the intent waits on window.__gimtEnquiryIntent). Any other url is simply navigated to.
 */
export function openEnquiry(url: string, intent: EnquiryIntent = {}): void {
  if (url.startsWith('#')) {
    const target = document.getElementById(url.slice(1));
    if (target) {
      window.__gimtEnquiryIntent = intent;
      window.dispatchEvent(new CustomEvent<EnquiryIntent>(ENQUIRY_EVENT, { detail: intent }));
      target.scrollIntoView({ behavior: staticMode() ? 'auto' : 'smooth', block: 'start' });
      return;
    }
  }
  window.location.href = url;
}

/** Indian-format currency: 120000 → ₹1,20,000 */
export function inr(n: number, symbol = '₹'): string {
  return symbol + Math.round(n).toLocaleString('en-IN');
}

/** Inner HTML of a slot item's root element (slot html is the child's outerHTML). */
export function slotInner(html: string): string {
  const t = document.createElement('template');
  t.innerHTML = html;
  return (t.content.firstElementChild as HTMLElement | null)?.innerHTML ?? html;
}

/** Normalised text for search matching. */
export const norm = (s: string): string =>
  s
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^\w\s+]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

/* ------------------------------------------------------------------ dates (YYYY-MM-DD strings, timezone-safe) */

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
export const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

export function parseYmd(s: string): Date {
  const [y, m, d] = s.split('-').map((v) => parseInt(v, 10));
  return new Date(Date.UTC(y, (m || 1) - 1, d || 1));
}

export function ymd(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function addDays(s: string, n: number): string {
  const d = parseYmd(s);
  d.setUTCDate(d.getUTCDate() + n);
  return ymd(d);
}

/** "2026-10" → number of days */
export function daysInMonth(ym: string): number {
  const [y, m] = ym.split('-').map((v) => parseInt(v, 10));
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

export function addMonths(ym: string, n: number): string {
  const [y, m] = ym.split('-').map((v) => parseInt(v, 10));
  const d = new Date(Date.UTC(y, m - 1 + n, 1));
  return d.toISOString().slice(0, 7);
}

/** Monday-based weekday index 0–6. */
export function weekdayIndex(s: string): number {
  return (parseYmd(s).getUTCDay() + 6) % 7;
}

export function formatDay(s: string, opts: { weekday?: boolean; year?: boolean } = {}): string {
  const d = parseYmd(s);
  return `${opts.weekday ? WEEKDAYS[(d.getUTCDay() + 6) % 7].slice(0, 3) + ', ' : ''}${d.getUTCDate()} ${MONTHS[d.getUTCMonth()].slice(0, 3)}${opts.year ? ' ' + d.getUTCFullYear() : ''}`;
}
