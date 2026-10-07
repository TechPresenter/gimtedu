/** API shapes for the attendance module (see api/routes/attendance.php). */
import type { Option } from '@/lib/types';

export type StudentStatus = 'present' | 'absent' | 'late' | 'leave';
export type EmployeeStatus = StudentStatus | 'half_day';

export interface Counts {
  present: number;
  absent: number;
  late: number;
  leave: number;
  half_day: number;
  total: number;
  percent: number | null;
}

export interface Holiday {
  id: number;
  title: string;
  type: 'holiday' | 'vacation' | 'exam_break' | 'event' | string;
  holiday_date?: string;
  end_date?: string | null;
}

export interface DayInfo {
  date: string;
  day_name: string;
  label: string;
  holiday: Holiday | null;
  weekly_off: boolean;
  future: boolean;
  blocked: string | null;
  warning: string | null;
}

export interface SectionInfo {
  id: number;
  label: string;
  program_id: number;
  semester_no: number;
  academic_session_id: number | null;
  students_count: number;
  class_teacher_name: string | null;
  program_short: string;
}

export interface SectionOption extends Option {
  program_id: number;
  semester_no: number;
}

export interface Slot {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  label: string;
}

export interface OptionsPayload {
  sections: SectionOption[];
  restricted: boolean;
  slots: Slot[];
  min_percent: number;
  late_counts: boolean;
  weekly_off: number[];
  session: { id: number | null; name: string; start_date?: string; end_date?: string };
}

export interface MarkedSheet {
  id: number;
  subject_id: number;
  time_slot_id: number | null;
  is_locked: boolean;
  subject_code: string;
  subject_name: string;
  slot_name: string | null;
  start_time: string | null;
  counts: Counts | null;
  updated_at: string | null;
  created_at: string | null;
}

export interface PeriodOption {
  timetable_id: number;
  time_slot_id: number;
  subject_id: number;
  faculty_id: number | null;
  faculty_name: string | null;
  type: string;
  slot_name: string;
  start_time: string;
  end_time: string;
  subject_code: string;
  subject_name: string;
  sheet: MarkedSheet | null;
  allowed: boolean;
}

export interface SubjectOption {
  id: number;
  code: string;
  name: string;
  type: string;
  faculty_id: number | null;
  faculty_name: string | null;
  allowed: boolean;
}

export interface PeriodsPayload {
  section: SectionInfo;
  day: DayInfo;
  source: 'timetable' | 'subjects';
  periods: PeriodOption[];
  subjects: SubjectOption[];
  slots: Slot[];
  sheets: MarkedSheet[];
}

export interface RosterStudent {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  gender: string | null;
  moved: boolean;
  status: StudentStatus | null;
  remarks: string | null;
  percent: number | null;
  held: number;
  subject_percent: number | null;
  below: boolean;
}

export interface SheetMeta {
  id: number;
  method: string;
  remarks: string | null;
  is_locked: boolean;
  faculty_id: number | null;
  faculty_name: string | null;
  taken_by_name: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface SheetPayload {
  section: SectionInfo;
  subject: { id: number; code: string; name: string; type: string };
  slot: { id: number; name: string; start_time: string; end_time: string } | null;
  date: string;
  day: DayInfo;
  sheet: SheetMeta | null;
  default_faculty_id: number | null;
  default_faculty_name: string | null;
  students: RosterStudent[];
  min_percent: number;
  can_save: boolean;
  restricted: string | null;
}

export interface SaveSheetResult {
  id: number;
  created: boolean;
  changed: number;
  counts: Counts | null;
  label: string;
  new_defaulters: { id: number; name: string; percent: number }[];
}

export interface ScheduleItem {
  kind: 'period' | 'section';
  section_id: number;
  section_label: string;
  subject_id?: number;
  subject?: string;
  time_slot_id?: number;
  slot_name?: string;
  start_time?: string;
  end_time?: string;
  faculty_name: string | null;
  attendance_id?: number | null;
  counts?: Counts | null;
  students?: number;
  sessions?: number;
  percent?: number | null;
  absent?: number;
  last_marked?: string | null;
}

export interface DashboardPayload {
  date: string;
  day: DayInfo;
  min_percent: number;
  mine: boolean;
  is_faculty: boolean;
  kpis: {
    percent: number | null;
    students_marked: number;
    students_total: number;
    present: number;
    late: number;
    absent: number;
    leave: number;
    period_absences: number;
    marks: number;
    sessions_marked: number;
    sections_marked: number;
    sections_total: number;
    scheduled: number;
    scheduled_source: 'timetable' | 'sections';
    scheduled_marked: number;
    session_percent: number | null;
  };
  status_split: { present: number; late: number; absent: number; leave: number };
  trend: { date: string; label: string; percent: number | null; sessions: number }[];
  schedule: ScheduleItem[];
  low_attendance: { count: number; threshold: number; students: { id: number; name: string; student_uid: string; photo: string | null; class_label: string; percent: number; held: number }[] };
  recent: {
    id: number;
    attendance_date: string;
    section_id: number;
    subject_id: number;
    time_slot_id: number | null;
    section_label: string;
    subject_code: string;
    subject_name: string;
    slot_name: string | null;
    taken_by_name: string | null;
    method: string;
    updated_at: string | null;
    created_at: string;
    counts: Counts | null;
  }[];
  employees: { total: number; faculty: Record<EmployeeStatus, number>; staff: Record<EmployeeStatus, number> };
}

export interface CalendarDay {
  date: string;
  day: number;
  dow: number;
  dow_label: string;
  weekly_off: boolean;
  holiday: Holiday | null;
  future: boolean;
  sessions?: {
    id: number;
    subject_id: number;
    time_slot_id: number | null;
    subject: string;
    slot_name: string | null;
    start_time: string | null;
    faculty_name: string | null;
    is_locked: boolean;
    counts: Counts;
  }[];
  totals?: { present: number; absent: number; late: number; leave: number; total: number };
  percent?: number | null;
}

export interface CalendarPayload {
  section: SectionInfo;
  month: string;
  from: string;
  to: string;
  days: CalendarDay[];
  min_percent: number;
  summary: { working_days: number; days_marked: number; sessions: number; percent: number | null; best: { date: string; percent: number } | null; worst: { date: string; percent: number } | null };
}

export interface EmployeeRow {
  person_type: 'faculty' | 'staff';
  person_id: number;
  name: string;
  employee_id: string;
  photo: string | null;
  designation: string;
  department: string | null;
  department_id: number | null;
  status: EmployeeStatus | null;
  in_time: string | null;
  out_time: string | null;
  remarks: string | null;
  leave: { type: string; from: string; to: string } | null;
}

export interface EmployeeSheetPayload {
  date: string;
  day: DayInfo;
  sheets: Record<'faculty' | 'staff', { id: number; method: string; is_locked: boolean; updated_at: string | null; created_at: string; taken_by_name: string | null } | null>;
  people: EmployeeRow[];
  counts: Record<EmployeeStatus | 'unmarked', number>;
  total: number;
  late_after: string;
  can_save: boolean;
}

export interface RegisterPerson extends Omit<EmployeeRow, 'status' | 'in_time' | 'out_time' | 'remarks' | 'leave'> {
  cells: Record<string, EmployeeStatus>;
  totals: { present: number; absent: number; late: number; leave: number; half_day: number; marked: number; percent: number | null };
}

export interface EmployeeRegisterPayload {
  month: string;
  from: string;
  to: string;
  days: CalendarDay[];
  people: RegisterPerson[];
  daily: Record<string, Partial<Record<EmployeeStatus, number>>>;
  total: number;
  page: number;
  pages: number;
  per_page: number;
  summary: { present: number; absent: number; late: number; leave: number; half_day: number; marked: number; employees: number; percent: number | null };
}

export interface ReportFiltersEcho {
  from: string;
  to: string;
  department_id?: number;
  program_id?: number;
  semester?: number;
  section_id?: number;
  subject_id?: number;
  threshold: number;
  q: string;
}

export interface StudentReportRow {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  email: string | null;
  mobile: string | null;
  class_label: string | null;
  student_status: string;
  held: number;
  present: number;
  absent: number;
  late: number;
  leave: number;
  attended: number;
  percent: number | null;
  last_date: string | null;
  shortfall: number;
  last_alert: string | null;
}

export interface StudentReportPayload {
  rows: StudentReportRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  filters: ReportFiltersEcho;
  source: 'summary' | 'live';
  summary: { students: number; below: number; sessions: number; min_percent: number; threshold: number; percent: number | null; lowest: number | null };
}

export interface DepartmentReportPayload {
  rows: {
    id: number;
    name: string;
    code: string;
    sessions: number;
    students: number;
    held: number;
    present: number;
    absent: number;
    late: number;
    leave: number;
    percent: number | null;
    below: number;
    programs: { id: number; short_name: string; name: string; students: number; sessions: number; percent: number | null }[];
  }[];
  filters: ReportFiltersEcho;
  summary: { departments: number; students: number; sessions: number; below: number; percent: number | null; threshold: number };
}

export interface SubjectReportRow {
  key: string;
  subject_id: number;
  section_id: number;
  code: string;
  name: string;
  type: string;
  section_label: string;
  faculty_name: string | null;
  sessions: number;
  students: number;
  held: number;
  present: number;
  absent: number;
  late: number;
  leave: number;
  percent: number | null;
  below: number | null;
  last_date: string | null;
}

export interface SubjectReportPayload {
  rows: SubjectReportRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  filters: ReportFiltersEcho;
  summary: { subjects: number; sessions: number; percent: number | null; threshold: number; lowest: number | null; below_subjects: number };
}

export interface MonthlyCell {
  v: string;
  a: number;
  h: number;
}

export interface MonthlyPayload {
  section: SectionInfo;
  subject: { id: number; code: string; name: string } | null;
  month: string;
  from: string;
  to: string;
  days: CalendarDay[];
  sessions_per_day: Record<string, number>;
  students: { id: number; name: string; student_uid: string; roll_no: string | null; photo: string | null; cells: Record<string, MonthlyCell>; held: number; attended: number; percent: number | null }[];
  daily: Record<string, number | null>;
  min_percent: number;
  summary: { students: number; sessions: number; days: number; percent: number | null; below: number };
}

export interface StudentDetailPayload {
  student: { id: number; name: string; student_uid: string; roll_no: string | null; photo: string | null; email: string | null; mobile: string | null; status: string; class_label: string | null };
  filters: ReportFiltersEcho;
  min_percent: number;
  overall: { held: number; attended: number; percent: number | null; needed: number; absent: number; late: number; leave: number };
  subjects: { id: number; code: string; name: string; held: number; present: number; absent: number; late: number; leave: number; percent: number }[];
  monthly: { month: string; label: string; held: number; percent: number }[];
  last_alert: string | null;
}

export interface DevicePayload {
  configured: boolean;
  token_hint: string | null;
  endpoint: string;
  late_after: string;
  can_manage: boolean;
  token?: string;
  stats: { today: number; recorded_today: number; devices: number };
  recent: {
    id: number;
    device_id: string;
    method: string;
    identifier: string;
    person_type: string | null;
    person_name: string | null;
    punched_at: string;
    direction: string;
    result: string;
    record_status: string | null;
    message: string | null;
  }[];
}
