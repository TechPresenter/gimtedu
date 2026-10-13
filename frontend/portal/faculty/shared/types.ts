/** Response types of the faculty-area endpoints (api/routes/portal/faculty.php). */

export type AttStatus = 'present' | 'absent' | 'late' | 'leave';

export interface SheetCounts {
  present: number;
  absent: number;
  late: number;
  leave: number;
  half_day: number;
  total: number;
  percent: number | null;
}

/* ------------------------------------------------------------------ Timetable */
export interface TimeSlot {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  is_break: boolean;
}

export interface TimetableDay {
  dow: number;
  name: string;
  short: string;
  date: string;
  is_today: boolean;
  holiday: string | null;
  event: string | null;
  weekly_off: boolean;
}

export type PeriodState = 'marked' | 'pending' | 'now' | 'upcoming' | 'holiday' | 'off';

export interface TimetablePeriod {
  id: number;
  day_of_week: number;
  time_slot_id: number;
  type: string;
  section_id: number;
  subject_id: number;
  semester_no: number;
  notes: string | null;
  slot: string;
  start_time: string;
  end_time: string;
  subject_code: string;
  subject: string;
  program: string;
  section: string;
  room_code: string | null;
  room: string | null;
  building: string | null;
  date: string | null;
  state: PeriodState;
  counts: SheetCounts | null;
}

export interface TimetableData {
  session: string | null;
  week: { start: string; end: string; is_current: boolean; prev: string; next: string };
  today: string;
  now: string;
  slots: TimeSlot[];
  days: TimetableDay[];
  periods: TimetablePeriod[];
  summary: { periods: number; hours: number; subjects: number; sections: number; by_type: Record<string, number>; busiest_day: string | null; busiest_count: number };
}

/* ------------------------------------------------------------------ Classes */
export interface FacultyClassRow {
  key: string;
  section_id: number;
  subject_id: number;
  subject_code: string;
  subject: string;
  subject_type: string;
  credits: number;
  program: string;
  program_name: string;
  department: string | null;
  semester_no: number;
  section: string;
  label: string;
  students: number;
  periods_per_week: number;
  days: number[];
  held: number;
  attendance_percent: number | null;
  last_marked: string | null;
  is_class_teacher: boolean;
  assigned: boolean;
  in_timetable: boolean;
}

export interface ClassesData {
  classes: FacultyClassRow[];
  summary: {
    classes: number;
    students: number;
    periods_per_week: number;
    attendance_percent: number | null;
    min_percent: number;
    class_teacher_of: { section_id: number; label: string; students: number }[];
  };
}

export interface RosterStudent {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  gender: string | null;
  photo: string | null;
  email: string | null;
  mobile: string | null;
  percent: number | null;
  held: number;
  subject_percent: number | null;
  subject_held: number;
  below: boolean;
  overall_below: boolean;
  trail: (AttStatus | 'half_day' | null)[];
}

export interface RegisterRow {
  id: number;
  date: string;
  slot: string | null;
  start_time: string | null;
  time_slot_id: number | null;
  counts: SheetCounts;
  is_locked: boolean;
  remarks: string | null;
}

export interface RosterData {
  class: {
    section_id: number;
    subject_id: number;
    subject_code: string;
    subject: string;
    subject_type: string;
    credits: number;
    program: string;
    program_name: string;
    semester_no: number;
    section: string;
    label: string;
    class_teacher: string | null;
    is_class_teacher: boolean;
  };
  stats: { students: number; held: number; average: number | null; below: number; min_percent: number; last_marked: string | null };
  trail_dates: { date: string; slot: string | null }[];
  students: RosterStudent[];
  registers: RegisterRow[];
  schedule: { day_of_week: number; type: string; slot: string; start_time: string; end_time: string; room_code: string | null; room: string | null }[];
  next_exam: { exam_id: number; exam: string; exam_date: string; start_time: string; end_time: string } | null;
}

/* ------------------------------------------------------------------ Attendance */
export interface SheetSummary {
  id: number;
  is_locked: boolean;
  counts: SheetCounts;
  updated_at: string | null;
  taken_by: string | null;
}

export interface DayPeriod {
  timetable_id: number | null;
  section_id: number;
  subject_id: number;
  time_slot_id: number | null;
  slot: string;
  start_time: string | null;
  end_time: string | null;
  type: string;
  subject_code: string;
  subject: string;
  program: string;
  semester_no: number;
  section: string;
  room_code: string | null;
  room: string | null;
  students: number;
  time_state: 'done' | 'now' | 'upcoming';
  sheet: SheetSummary | null;
}

export interface AttendanceDayData {
  date: string;
  day: { date: string; day_name: string; label: string; holiday: { title: string; type: string } | null; weekly_off: boolean; future: boolean; blocked: string | null; warning: string | null; working: boolean };
  window: { earliest: string; latest: string; days: number; open: boolean; message: string | null };
  periods: DayPeriod[];
  extra: DayPeriod[];
  classes: { section_id: number; subject_id: number; label: string; subject_code: string; subject: string; students: number }[];
  slots: { id: number; name: string; start_time: string; end_time: string; label: string }[];
  statuses: Record<AttStatus, string>;
}

export interface SheetStudent {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  gender: string | null;
  moved: boolean;
  status: AttStatus | null;
  remarks: string | null;
  percent: number | null;
  held: number;
  subject_percent: number | null;
  below: boolean;
}

export interface AttendanceSheetData {
  section: { id: number; name: string; semester_no: number; program: string; label: string; class_teacher: string | null; students: number };
  subject: { id: number; code: string; name: string; type: string };
  slot: { id: number; name: string; start_time: string; end_time: string } | null;
  date: string;
  day: AttendanceDayData['day'];
  sheet: { id: number; method: string; remarks: string | null; is_locked: boolean; faculty_id: number | null; created_at: string; updated_at: string | null; taken_by_name: string | null; faculty_name: string | null } | null;
  students: SheetStudent[];
  min_percent: number;
  can_save: boolean;
  read_only_reason: string | null;
  period: { id: number; room_code: string | null; type: string } | null;
  window: { earliest: string; days: number };
  statuses: Record<AttStatus, string>;
}

export interface SaveAttendanceResult {
  id: number;
  created: boolean;
  changed: number;
  counts: SheetCounts | null;
  label: string;
  new_defaulters: { id: number; name: string; percent: number }[];
}

export interface HistoryRow {
  id: number;
  date: string;
  section_id: number;
  subject_id: number;
  time_slot_id: number | null;
  slot: string | null;
  start_time: string | null;
  subject_code: string;
  subject: string;
  class: string;
  counts: SheetCounts;
  is_locked: boolean;
  remarks: string | null;
  updated_at: string | null;
  taken_by: string | null;
  faculty: string | null;
  editable: boolean;
}

export interface HistoryData {
  items: HistoryRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  stats: { registers: number; percent: number | null };
  edit_days: number;
  earliest: string;
}

export interface MyAttendanceData {
  month: string;
  start: string;
  end: string;
  days: Record<string, { status: string; in_time: string | null; out_time: string | null; remarks: string | null }>;
  leave_days: Record<string, string>;
  holidays: Record<string, string>;
  weekly_offs: number[];
  summary: { present: number; absent: number; late: number; leave: number; half_day: number; marked: number; expected: number; percent: number | null };
  trend: { month: string; label: string; percent: number | null; marked: number }[];
  has_any: boolean;
  prev: string;
  next: string | null;
}

/* ------------------------------------------------------------------ Marks */
export type PaperState = 'not_started' | 'in_progress' | 'complete' | 'submitted' | 'upcoming' | 'verified' | 'published' | 'cancelled';

export interface MarksPaper {
  subject_id: number;
  subject_code: string;
  subject: string;
  subject_type: string;
  max_internal: number;
  max_external: number;
  max_practical: number;
  max_total: number;
  pass_marks: number;
  exam_date: string | null;
  start_time: string | null;
  sections: { id: number; name: string; students: number }[];
  students: number;
  entered: number;
  submitted: number;
  absent: number;
  state: PaperState;
  open: boolean;
  locked: boolean;
}

export interface MarksExam {
  id: number;
  name: string;
  status: string;
  display_status: string;
  type: string;
  cohort: string;
  start_date: string | null;
  end_date: string | null;
  is_final: boolean;
  locked: boolean;
  papers: MarksPaper[];
}

export interface MarksListData {
  exams: MarksExam[];
  kpis: { open: number; pending_students: number; submitted: number; locked: number; upcoming: number };
  can_enter: boolean;
}

export interface MarksRow {
  student_id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  section_name: string | null;
  internal_marks: number | null;
  external_marks: number | null;
  practical_marks: number | null;
  total_marks: number | null;
  grade: string | null;
  grade_point: number | null;
  is_absent: boolean;
  is_pass: boolean | null;
  status: string | null;
  remarks: string | null;
  updated_at: string | null;
  attendance_status: string | null;
  entered: boolean;
}

export interface GradeBand {
  grade: string;
  min: number;
  max: number;
  grade_point: number;
  is_pass: boolean;
}

export interface MarksSheetData {
  exam: { id: number; name: string; status: string; display_status: string; cohort: string; session_name: string | null; type: string; start_date: string | null; end_date: string | null };
  subject: {
    id: number;
    code: string;
    name: string;
    type: string;
    credits: number;
    max_internal: number;
    max_external: number;
    max_practical: number;
    max_total: number;
    pass_marks: number;
    schedule: { exam_date: string; start_time: string; end_time: string; room_code: string | null } | null;
  };
  section_id: number | null;
  sections: { id: number; name: string; students: number }[];
  rows: MarksRow[];
  stats: { students: number; entered: number; absent: number; passed: number; submitted: number; average: number | null; highest: number | null; pass_percent: number | null };
  grades: GradeBand[];
  can_edit: boolean;
  lock_reason: string | null;
  lock_kind: string | null;
}

export interface SaveMarksResult {
  saved: number;
  deleted: number;
  submitted: boolean;
  submitted_rows: number;
  submit_error: string | null;
  sheet: MarksSheetData;
}

/* ------------------------------------------------------------------ Leave */
export interface LeaveBalance {
  code: string;
  name: string;
  color: string | null;
  quota: number;
  used: number;
  pending: number;
  available: number | null;
  is_paid: boolean;
  unlimited: boolean;
  /** gender-specific type (maternity / paternity) - not part of the regular balance */
  special?: boolean;
}

export interface LeaveType {
  code: string;
  name: string;
  quota: number;
  is_paid: boolean;
  max_consecutive: number | null;
  color: string | null;
  description: string | null;
}

export interface LeaveRow {
  id: number;
  leave_type: string;
  leave_type_name: string;
  color: string | null;
  from_date: string;
  to_date: string;
  days: number;
  is_half_day: boolean;
  reason: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'cancelled';
  remarks: string | null;
  approved_by: string | null;
  approved_at: string | null;
  applied_at: string;
  cancelled_at: string | null;
  is_paid: boolean;
  can_cancel: boolean;
  phase: 'past' | 'current' | 'upcoming';
}

export interface LeaveData {
  session: { name: string; start: string; end: string };
  balance: LeaveBalance[];
  types: LeaveType[];
  items: LeaveRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  counts: { pending: number; approved: number; rejected: number; cancelled: number };
  taken: number;
  on_leave_today: LeaveRow | null;
  upcoming: LeaveRow[];
  holidays: { title: string; holiday_date: string; end_date: string | null; type: string }[];
  weekly_offs: number[];
  backdate_days: number;
}

export interface LeavePreview {
  days: { days: number; calendar_days: number; weekly_offs: number; holidays: { date: string; title: string }[] } | null;
  errors: Record<string, string>;
  balance: LeaveBalance | null;
}

/* ------------------------------------------------------------------ Documents, payroll, corrections */
export interface DocumentRow {
  id: number;
  doc_type: string;
  doc_type_label: string;
  title: string;
  original_name: string | null;
  mime: string | null;
  size: number | null;
  size_label: string | null;
  status: 'pending' | 'verified' | 'rejected';
  remarks: string | null;
  expiry_date: string | null;
  expired: boolean;
  uploaded_at: string;
  uploaded_by: string | null;
  verified_by: string | null;
  verified_at: string | null;
  mine: boolean;
  can_delete: boolean;
}

export interface DocumentsData {
  items: DocumentRow[];
  counts: { total: number; verified: number; pending: number; rejected: number };
  types: Record<string, string>;
  max_bytes: number;
  accept: string;
}

export interface PayrollData {
  employee_id: string | null;
  designation: string | null;
  department: string | null;
  employment_type: string | null;
  joining_date: string | null;
  tenure_years: number | null;
  salary: number | null;
  annual: number | null;
  bank: { name: string | null; account: string | null; ifsc: string | null };
  pan: string | null;
  components: null;
  session: { name: string; start: string; end: string };
  leave: { paid: number; unpaid: number };
  lop: { month: string; label: string; days: number }[];
}

export interface CorrectionRow {
  id: number;
  field: string | null;
  label: string | null;
  requested: string | null;
  note: string | null;
  created_at: string;
}

export interface CorrectionsData {
  items: CorrectionRow[];
  fields: Record<string, string>;
}
