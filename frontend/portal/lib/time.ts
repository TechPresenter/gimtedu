/** Small date/time helpers for the portal screens (Asia/Kolkata wall-clock as delivered by the API). */

export function greeting(d: Date = new Date()): string {
  const h = d.getHours();
  if (h < 5) return 'Good evening';
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

/** "Tuesday, 13 October 2026" */
export function longDate(v: string | Date = new Date()): string {
  const d = typeof v === 'string' ? new Date(/^\d{4}-\d{2}-\d{2}$/.test(v) ? `${v}T00:00:00` : v.replace(' ', 'T')) : v;
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** { day: '07', month: 'Dec', weekday: 'Mon' } for date tiles. */
export function dateParts(v: string): { day: string; month: string; weekday: string } {
  const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(v) ? `${v}T00:00:00` : v.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return { day: '--', month: '', weekday: '' };
  return {
    day: String(d.getDate()).padStart(2, '0'),
    month: d.toLocaleDateString('en-GB', { month: 'short' }),
    weekday: d.toLocaleDateString('en-GB', { weekday: 'short' }),
  };
}

/** "in 5 days" / "tomorrow" / "today" */
export function daysLeftLabel(days: number): string {
  if (days <= 0) return 'Today';
  if (days === 1) return 'Tomorrow';
  return `In ${days} days`;
}

/** "09:00" from "09:00:00" (24h) — compact for timelines. */
export function hhmm(t: string | null | undefined): string {
  if (!t) return '';
  const [h, m] = t.split(':');
  const hour = Number(h);
  const suffix = hour >= 12 ? 'PM' : 'AM';
  return `${((hour + 11) % 12) + 1}:${m} ${suffix}`;
}
