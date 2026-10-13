/** Types of GET /api/dashboard (app/services/dashboard.php). Every block is optional: it is only present when the user may view it. */

export interface Trend {
  value: string;
  dir: 'up' | 'down' | 'flat';
  label?: string;
  positive?: boolean;
}

/** 6-point trend drawn as a sparkline on the KPI card. */
export interface Spark {
  points: number[];
  labels: string[];
  caption: string;
  format: 'number' | 'money' | 'percent';
}

export interface Kpi {
  value: number | null;
  trend?: Trend | null;
  hint?: string | null;
  date?: string | null;
  is_today?: boolean;
  spark?: Spark;
}

export type KpiKey =
  | 'total_students' | 'active_students' | 'new_admissions' | 'pending_applications' | 'active_programs' | 'faculty' | 'staff' | 'fee_collected'
  | 'pending_dues' | 'attendance_today' | 'enquiries_open' | 'upcoming_exams' | 'placed_students' | 'alumni' | 'library_books' | 'transport_vehicles';

export interface MonthPoint {
  month: string;
  label: string;
  full: string;
}

export interface AttendanceAgg {
  from: string;
  to: string;
  sheets: number;
  total: number;
  present: number;
  late: number;
  absent: number;
  leave: number;
  percent: number | null;
  label: string;
}

export interface LabelValue {
  label: string;
  value: number;
}

export interface DashboardCharts {
  admissions_trend?: (MonthPoint & { applications: number; admissions: number })[];
  fee_collection?: { months: (MonthPoint & { collected: number; target: number })[]; collected: number; billed: number };
  attendance?: { day: AttendanceAgg; week: AttendanceAgg; month: AttendanceAgg };
  distribution?: { category: LabelValue[]; level: LabelValue[] };
  student_growth?: { label: string; new: number; total: number }[];
  departments?: (LabelValue & { code: string })[];
  gender?: LabelValue[];
  exam_performance?: { scope: 'published' | 'computed'; students: number; pass_percent: number | null; programs: { label: string; students: number; pass_percent: number; avg_percent: number }[] };
  placement?: {
    scope: 'session' | 'all'; offers: number; placed: number; companies: number; drives: number; highest: number | null; average: number | null;
    programs: { label: string; placed: number; avg_lpa: number }[]; top_companies: { label: string; offers: number }[];
  };
}

export interface ActivityItem {
  id: number;
  action: string;
  module: string;
  module_label: string;
  record_id: string | null;
  description: string;
  user: string;
  avatar: string | null;
  created_at: string;
}

export type UpcomingCategory = 'event' | 'exam' | 'result' | 'finance' | 'admission' | 'placement' | 'holiday';

export interface UpcomingItem {
  date: string;
  time?: string;
  title: string;
  subtitle: string;
  category: UpcomingCategory;
  url: string;
}

export interface ProgramCount {
  id: number;
  label: string;
  name: string;
  category: string | null;
  value: number;
}

export interface DashboardLists {
  activities?: ActivityItem[];
  upcoming?: UpcomingItem[];
  top_programs?: { admitted: ProgramCount[]; applications: ProgramCount[] | null };
  recent_admissions?: { id: number; application_no: string; name: string; photo: string | null; stage: string; source: string | null; program: string | null; created_at: string }[];
  recent_enquiries?: { id: number; name: string; phone: string | null; city: string | null; source: string | null; status: string; priority: string | null; program: string | null; created_at: string }[];
  recent_payments?: { id: number; receipt_no: string; amount: number; mode: string; payment_date: string; status: string; created_at: string; student_id: number | null; name: string; photo: string | null; program: string | null }[];
  recent_notices?: { id: number; title: string; category: string; audience: string; status: string; is_pinned: boolean; date: string }[];
}

export interface SystemStatus {
  server: { status: string; php: string; software: string; os: string; load: number | null; time: string; timezone: string; app_version: string };
  database: { status: string; version: string | null; size: number | null; tables: number | null };
  backup: { last_at: string | null; filename: string | null; type: string | null; size: number | null; age_days: number | null; status: 'ok' | 'stale' | 'missing'; last_failed_at: string | null; schedule: string };
  storage: { uploads: number; upload_files: number; storage: number; used: number; disk_total: number | null; disk_free: number | null; disk_percent: number | null; measured_at: string };
}

/** Hero rings (selected session) and the "Today at GIMT" strip (always live). Every part is permission-aware. */
export interface HeroData {
  rings: {
    attendance?: { percent: number | null; date: string | null; is_today: boolean; total: number; present: number; late: number; absent: number; leave: number };
    fees?: { percent: number | null; collected: number; billed: number; balance: number; invoices: number };
    admissions?: { percent: number | null; admitted: number; intake: number; pipeline: number | null };
  };
  today: {
    classes?: { total: number; running: number; done: number; next_at: string | null; next_count: number; holiday: string | null };
    exams?: { papers: number; exams: number; running: number; next_at: string | null };
    events?: { count: number; title: string | null; time: string | null };
    dues_week?: { invoices: number; amount: number; until: string };
    collected?: { amount: number; receipts: number };
    enquiries?: { new: number; followups: number };
  };
  date: string;
  time: string;
}

export interface DashboardData {
  session: {
    id: number | null; name: string; start_date: string; end_date: string; is_current: boolean; admissions_open: boolean; previous: string | null;
    /** false for a session without admissions, students or invoices yet (older payloads omit it). */
    has_data?: boolean;
    current_id?: number | null;
    current_name?: string | null;
  };
  kpis: Partial<Record<KpiKey, Kpi>>;
  charts: DashboardCharts;
  lists: DashboardLists;
  system: SystemStatus | null;
  hero?: HeroData | null;
  generated_at: string;
  cached: boolean;
}
