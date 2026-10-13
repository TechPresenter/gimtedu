import { useQueryClient } from '@tanstack/react-query';
import type { TtSlot } from '../types';

/** Static Tailwind class sets for subject colour coding (kept literal so the JIT picks them up). */
export const SUBJECT_TONES = [
  { card: 'bg-blue-50 border-blue-500 dark:bg-blue-500/10', text: 'text-blue-900 dark:text-blue-100', dot: 'bg-blue-500', chip: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200' },
  { card: 'bg-emerald-50 border-emerald-500 dark:bg-emerald-500/10', text: 'text-emerald-900 dark:text-emerald-100', dot: 'bg-emerald-500', chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' },
  { card: 'bg-violet-50 border-violet-500 dark:bg-violet-500/10', text: 'text-violet-900 dark:text-violet-100', dot: 'bg-violet-500', chip: 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200' },
  { card: 'bg-amber-50 border-amber-500 dark:bg-amber-500/10', text: 'text-amber-900 dark:text-amber-100', dot: 'bg-amber-500', chip: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200' },
  { card: 'bg-rose-50 border-rose-500 dark:bg-rose-500/10', text: 'text-rose-900 dark:text-rose-100', dot: 'bg-rose-500', chip: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200' },
  { card: 'bg-cyan-50 border-cyan-500 dark:bg-cyan-500/10', text: 'text-cyan-900 dark:text-cyan-100', dot: 'bg-cyan-500', chip: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/20 dark:text-cyan-200' },
  { card: 'bg-orange-50 border-orange-500 dark:bg-orange-500/10', text: 'text-orange-900 dark:text-orange-100', dot: 'bg-orange-500', chip: 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200' },
  { card: 'bg-teal-50 border-teal-500 dark:bg-teal-500/10', text: 'text-teal-900 dark:text-teal-100', dot: 'bg-teal-500', chip: 'bg-teal-100 text-teal-800 dark:bg-teal-500/20 dark:text-teal-200' },
  { card: 'bg-indigo-50 border-indigo-500 dark:bg-indigo-500/10', text: 'text-indigo-900 dark:text-indigo-100', dot: 'bg-indigo-500', chip: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-200' },
  { card: 'bg-lime-50 border-lime-600 dark:bg-lime-500/10', text: 'text-lime-900 dark:text-lime-100', dot: 'bg-lime-600', chip: 'bg-lime-100 text-lime-800 dark:bg-lime-500/20 dark:text-lime-200' },
] as const;

export type SubjectTone = (typeof SUBJECT_TONES)[number];

/** Stable tone for a subject id (or an explicit index from the section's subject order). */
export function toneFor(subjectId: number, order?: Map<number, number>): SubjectTone {
  const i = order?.get(subjectId) ?? subjectId;
  return SUBJECT_TONES[Math.abs(i) % SUBJECT_TONES.length];
}

/** "09:00" -> "09:00 AM" */
export function hm(t: string | null | undefined): string {
  if (!t) return '';
  const [h, m] = t.split(':').map(Number);
  return `${String(((h + 11) % 12) + 1).padStart(2, '0')}:${String(m ?? 0).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
}

export function slotRange(s: Pick<TtSlot, 'start_time' | 'end_time'>): string {
  return `${hm(s.start_time)} – ${hm(s.end_time)}`;
}

/** ISO weekday 1..7 for today. */
export function todayDow(): number {
  const d = new Date().getDay();
  return d === 0 ? 7 : d;
}

/** The slot running now, or the next one today. */
export function currentSlotId(slots: TtSlot[]): number | null {
  const now = new Date();
  const mins = now.getHours() * 60 + now.getMinutes();
  const toMin = (t: string) => {
    const [h, m] = t.split(':').map(Number);
    return h * 60 + (m || 0);
  };
  const teaching = slots.filter((s) => !s.is_break);
  const running = teaching.find((s) => toMin(s.start_time) <= mins && toMin(s.end_time) > mins);
  if (running) return running.id;
  return (teaching.find((s) => toMin(s.start_time) > mins) ?? teaching[0])?.id ?? null;
}

/** Invalidate every timetable query (grids, summary, cells, daily view, CRUD lists). */
export function useTimetableInvalidate() {
  const qc = useQueryClient();
  return () =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['tt'] }),
      qc.invalidateQueries({ queryKey: ['crud', 'timetables'] }),
      qc.invalidateQueries({ queryKey: ['crud', 'sections'] }),
      qc.invalidateQueries({ queryKey: ['acad-overview'] }),
    ]);
}

export const TYPE_LABEL: Record<string, string> = { lecture: 'Lecture', lab: 'Lab', tutorial: 'Tutorial', seminar: 'Seminar' };
