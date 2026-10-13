import type { Row } from '@/lib/types';

export interface EventStats {
  total: number;
  registered: number;
  attended: number;
  cancelled: number;
  website: number;
  last7: number;
  active: number;
  by_type: { type: string; label: string; count: number }[];
}

export interface EventDetail {
  event: Row;
  stats: EventStats;
  reminders: { sent: number; last_at: string | null };
  pending: number;
}

export interface EventSummary {
  total: number;
  upcoming: number;
  ongoing: number;
  past: number;
  periods: { upcoming: number; ongoing: number; past: number };
  this_month: number;
  open_registration: number;
  registrations: number;
  attended: number;
  registrations_30d: number;
  attendance_rate: number | null;
  next: Row[];
  by_category: { key: string; label: string; events: number; registrations: number }[];
  trend: { labels: string[]; values: number[] };
}
