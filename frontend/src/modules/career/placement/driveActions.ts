import { CalendarCheck2, CalendarX2, CirclePlay, Megaphone, type LucideIcon } from 'lucide-react';

/** Quick status transitions offered for a drive (PATCH via the CRUD endpoint). */
export function driveTransitions(status: string): { status: string; label: string; icon: LucideIcon; danger?: boolean; confirm?: string }[] {
  const out: { status: string; label: string; icon: LucideIcon; danger?: boolean; confirm?: string }[] = [];
  if (status === 'upcoming') out.push({ status: 'open', label: 'Open registrations', icon: Megaphone });
  if (status === 'upcoming' || status === 'open') out.push({ status: 'ongoing', label: 'Mark drive ongoing', icon: CirclePlay });
  if (status === 'ongoing' || status === 'open') out.push({ status: 'completed', label: 'Mark completed', icon: CalendarCheck2, confirm: 'Mark this drive completed? Registrations close and pending students stay in their current round.' });
  if (status === 'cancelled') out.push({ status: 'upcoming', label: 'Restore drive', icon: CirclePlay });
  if (status !== 'cancelled' && status !== 'completed') out.push({ status: 'cancelled', label: 'Cancel drive', icon: CalendarX2, danger: true, confirm: 'Cancel this drive? Registered students stay on record; you can restore the drive later.' });
  return out;
}
