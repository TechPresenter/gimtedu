/** Shared types + helpers for the Faculty & Staff (HR) module. */
import type { Tone } from '@/components/ui';
import { formatNumber } from '@/lib/format';

export type EmployeeType = 'faculty' | 'staff';

export const EMPLOYEE_LABEL: Record<EmployeeType, { singular: string; plural: string; base: string }> = {
  faculty: { singular: 'Faculty member', plural: 'Faculty', base: '/faculty' },
  staff: { singular: 'Staff member', plural: 'Staff', base: '/staff' },
};

export const employeeRef = (type: EmployeeType, id: number | string) => `${type === 'faculty' ? 'f' : 's'}-${id}`;

export function parseRef(ref: string | null | undefined): { type: EmployeeType; id: number } | null {
  const m = /^([fs])-(\d+)$/.exec(String(ref ?? ''));
  return m ? { type: m[1] === 'f' ? 'faculty' : 'staff', id: Number(m[2]) } : null;
}

export const profilePath = (type: EmployeeType, id: number | string) => `${EMPLOYEE_LABEL[type].base}/${id}`;

/** Leave type colour names (leave_types.color) -> UI tone. */
export function leaveTone(color: string | null | undefined): Tone {
  const allowed: Tone[] = ['blue', 'navy', 'green', 'orange', 'amber', 'purple', 'pink', 'red', 'cyan', 'slate'];
  return (allowed as string[]).includes(String(color)) ? (color as Tone) : 'slate';
}

/** Chip classes per tone for leave pills (calendar, lists). */
export const leaveChip: Record<Tone, string> = {
  blue: 'bg-blue-50 text-blue-800 ring-blue-600/20 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-400/25',
  navy: 'bg-brand-50 text-brand-800 ring-brand-700/20 dark:bg-brand-500/15 dark:text-brand-100 dark:ring-brand-400/25',
  green: 'bg-emerald-50 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-400/25',
  orange: 'bg-orange-50 text-orange-800 ring-orange-600/20 dark:bg-orange-500/15 dark:text-orange-200 dark:ring-orange-400/25',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/25 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-400/25',
  purple: 'bg-violet-50 text-violet-800 ring-violet-600/20 dark:bg-violet-500/15 dark:text-violet-200 dark:ring-violet-400/25',
  pink: 'bg-rose-50 text-rose-800 ring-rose-600/20 dark:bg-rose-500/15 dark:text-rose-200 dark:ring-rose-400/25',
  red: 'bg-red-50 text-red-800 ring-red-600/20 dark:bg-red-500/15 dark:text-red-200 dark:ring-red-400/25',
  cyan: 'bg-cyan-50 text-cyan-800 ring-cyan-600/20 dark:bg-cyan-500/15 dark:text-cyan-200 dark:ring-cyan-400/25',
  slate: 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-500/25',
};

export function daysLabel(days: number | string | null | undefined): string {
  const n = Number(days) || 0;
  return `${formatNumber(n, n % 1 ? 1 : 0)} day${n === 1 ? '' : 's'}`;
}

export interface LeaveType {
  id: number;
  code: string;
  name: string;
  annual_quota: number;
  applies_to: 'all' | 'faculty' | 'staff';
  gender: 'male' | 'female' | null;
  is_paid: boolean;
  max_consecutive: number | null;
  color: string | null;
  description: string | null;
  sort_order: number;
  status: 'active' | 'inactive';
  applications?: number;
  session_days?: number;
}

export interface BalanceRow {
  code: string;
  name: string;
  color: string | null;
  quota: number;
  used: number;
  pending: number;
  available: number | null;
  is_paid: boolean;
  unlimited: boolean;
}

export interface SessionWindow {
  id: number | null;
  name: string;
  start: string;
  end: string;
}

export interface NewAccount {
  user_id: number;
  username: string;
  password: string;
  email: string;
  emailed: boolean;
  role: string;
}

export interface Person {
  id: number;
  employee_id: string;
  user_id: number | null;
  title?: string | null;
  first_name: string;
  last_name: string | null;
  full_name: string;
  photo: string | null;
  gender: string | null;
  dob: string | null;
  designation: string;
  department_id: number | null;
  department_name: string | null;
  department_code: string | null;
  qualification: string | null;
  specialization?: string | null;
  experience_years?: string | number | null;
  email: string | null;
  phone: string | null;
  alternate_phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  joining_date: string | null;
  employment_type: string;
  salary: string | number | null;
  bank_name: string | null;
  bank_account: string | null;
  bank_ifsc: string | null;
  pan_no: string | null;
  bank_account_masked: string | null;
  pan_masked: string | null;
  bio?: string | null;
  research_interests?: string | null;
  publications_count?: number | null;
  linkedin_url?: string | null;
  show_on_website?: number | boolean;
  category?: string;
  section?: string | null;
  status: string;
  employee_type: EmployeeType;
  ref: string;
  is_hod_of?: string | null;
  updated_at?: string | null;
  created_at?: string | null;
}

export interface ProfilePayload {
  person: Person;
  stats: {
    experience_years: number | null;
    tenure_years: number | null;
    leave_taken: number;
    pending_leaves: number;
    documents: number;
    documents_verified: number;
    documents_pending: number;
    attendance_percent: number | null;
    subjects?: number;
    weekly_periods?: number;
    sections_class_teacher?: number;
  };
  session: SessionWindow;
  on_leave_today: { id: number; leave_type: string; leave_type_name: string; from_date: string; to_date: string; days: string } | null;
  upcoming_leaves: { id: number; leave_type: string; leave_type_name: string; from_date: string; to_date: string; days: string; status: string }[];
  account: { id: number; username: string; email: string; status: string; last_login_at: string | null; must_change_password: number; roles: string | null } | null;
  can: { edit: boolean; delete: boolean; approve: boolean; create: boolean; sensitive: boolean };
  lop: { month: string; label: string; days: number }[];
}

export interface ActivityRow {
  id: number;
  action: string;
  module: string;
  description: string;
  status: string;
  created_at: string;
  user_name: string | null;
  time_ago: string;
}

export const STAFF_CATEGORIES: Record<string, string> = {
  administration: 'Administration', accounts: 'Accounts', library: 'Library', hostel: 'Hostel', transport: 'Transport',
  maintenance: 'Maintenance', security: 'Security', it: 'IT & Systems', laboratory: 'Laboratory', other: 'Other',
};

export const EMPLOYEE_STATUSES = [
  { value: 'active', label: 'Active' },
  { value: 'on_leave', label: 'On long leave' },
  { value: 'resigned', label: 'Resigned' },
  { value: 'retired', label: 'Retired' },
  { value: 'inactive', label: 'Inactive' },
];

/** Monday-first weekday labels. */
export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Calendar cells (Monday first) for a YYYY-MM month: null = padding. */
export function monthGrid(month: string): (string | null)[] {
  const [y, m] = month.split('-').map(Number);
  const first = new Date(y, m - 1, 1);
  const daysIn = new Date(y, m, 0).getDate();
  const pad = (first.getDay() + 6) % 7;
  const cells: (string | null)[] = Array.from({ length: pad }, () => null);
  for (let d = 1; d <= daysIn; d++) cells.push(`${month}-${String(d).padStart(2, '0')}`);
  while (cells.length % 7) cells.push(null);
  return cells;
}

export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export function monthLabel(month: string): string {
  const [y, m] = month.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

export const currentMonth = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

/** Day of week (0 = Sunday) for YYYY-MM-DD without timezone drift. */
export function dow(date: string): number {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).getDay();
}

export const STAFF_CATEGORY_CODES: Record<string, string> = {
  administration: 'ADM', accounts: 'ACC', library: 'LIB', hostel: 'HST', transport: 'TRN', maintenance: 'MNT', security: 'SEC', it: 'IT', laboratory: 'LAB', other: 'OTH',
};

/** Keeps the selected tab of a horizontally scrolling tab strip visible (mobile). */
export function scrollActiveTabIntoView(container: HTMLElement | null) {
  const el = container?.querySelector<HTMLElement>('[role="tab"][aria-selected="true"]');
  const strip = el?.parentElement;
  if (!el || !strip || strip.scrollWidth <= strip.clientWidth) return;
  strip.scrollTo({ left: Math.max(0, el.offsetLeft - (strip.clientWidth - el.clientWidth) / 2), behavior: 'smooth' });
}
