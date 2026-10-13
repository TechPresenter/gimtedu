/** API payload types for the examination unit (api/routes/exams.php, results.php, marksheets.php). */

export type ExamStatus = 'draft' | 'scheduled' | 'ongoing' | 'marks_entry' | 'verified' | 'published' | 'cancelled';
export type ResultStatus = 'PASS' | 'BACKLOG' | 'FAIL' | 'ABSENT' | 'WITHHELD';

export interface DatesheetRow {
  id: number;
  exam_id: number;
  subject_id: number;
  exam_date: string;
  start_time: string;
  end_time: string;
  classroom_id: number | null;
  notes: string | null;
  exam_name: string;
  exam_status: ExamStatus;
  type_name: string;
  type_code: string;
  program_short: string | null;
  semester_no: number | null;
  subject_code: string;
  subject_name: string;
  subject_type: string;
  room_code: string | null;
  room_name: string | null;
  chief_invigilator: string | null;
  invigilators_count: number;
  students_count: number;
}

export interface DatesheetPayload {
  rows: DatesheetRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  summary?: { papers: number; today: number; this_week: number; upcoming: number; rooms: number; unassigned_rooms: number; next_date: string | null; today_date: string };
}

export interface ExamSummary {
  total: number;
  upcoming: number;
  ongoing: number;
  completed: number;
  results_pending: number;
  published: number;
  papers_next_14: number;
  upcoming_papers: DatesheetRow[];
  marks_pending_subjects: number;
  session_id: number | null;
  by_type: { label: string; count: number }[];
  by_status: { status: string; count: number }[];
}

export interface ExamRecord {
  id: number;
  name: string;
  status: ExamStatus;
  display_status: ExamStatus;
  exam_type_id: number;
  academic_session_id: number;
  program_id: number | null;
  semester_no: number | null;
  start_date: string | null;
  end_date: string | null;
  result_date: string | null;
  min_attendance_percent: string | null;
  instructions: string | null;
  verified_at: string | null;
  published_at: string | null;
  type_name: string;
  type_code: string;
  is_final: number;
  program_short: string | null;
  program_name: string | null;
  session_name: string | null;
  cohort: string;
  verified_by_name: string | null;
  published_by_name: string | null;
  created_by_name: string | null;
  created_at: string;
}

export interface SubjectProgress {
  subject_id: number;
  code: string;
  name: string;
  type: string;
  credits: number;
  max_total: number;
  pass_marks: number;
  students: number;
  entered: number;
  absent: number;
  passed: number;
  submitted: number;
  average: number | null;
  highest: number | null;
  pass_percent: number | null;
  percent: number;
  last_entry: string | null;
  exam_date: string | null;
  state: 'not_started' | 'in_progress' | 'complete' | 'submitted';
  can_enter?: boolean;
}

export interface WorkflowStep {
  key: string;
  label: string;
  done: boolean;
  detail: string;
}

export interface ResultCounts {
  total: number;
  pass: number;
  backlog: number;
  fail: number;
  absent: number;
  withheld: number;
  published: number;
  appeared: number;
  pass_percent: number | null;
  avg_percent: number | null;
  top_percent: number | null;
  avg_sgpa: number | null;
}

export interface ExamOverview {
  exam: ExamRecord;
  steps: WorkflowStep[];
  current_step: string;
  counts: { subjects: number; scheduled: number; allocated: number; eligible: number; ineligible: number; marks_expected: number; marks_entered: number; marks_percent: number; pending_candidates: number };
  results: ResultCounts;
  progress: SubjectProgress[];
  next_paper: { exam_date: string; start_time: string; end_time: string; subject_name: string; subject_code: string; room_code: string | null } | null;
  timeline: { action: string; description: string; created_at: string; user_name: string | null }[];
  min_attendance: number;
  marksheets: number;
  permissions: { edit: boolean; override: boolean; marks: boolean; verify: boolean; publish: boolean };
  sections: { id: number; name: string; students: number }[];
}

export interface ExamStudentRow {
  student_id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  section_name: string | null;
  allocated: boolean;
  is_eligible: boolean;
  ineligibility_reason: string | null;
  attendance_percent: number | null;
  fee_due: number;
  is_override: boolean;
  override_reason: string | null;
  overridden_at?: string | null;
  overridden_by_name?: string | null;
  hall_ticket_no: string | null;
  seat_no: string | null;
  room_code: string | null;
  marks_count: number;
}

export interface ExamStudentsPayload {
  view: 'allocated' | 'pending';
  rows: ExamStudentRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  summary: { allocated: number; eligible: number; ineligible: number; overridden: number; admit_cards: number; pending: number; candidates: number; min_attendance: number; fee_block: boolean };
}

export interface MarkRow {
  student_id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  section_name: string | null;
  mark_id: number | null;
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
  is_pass: number;
}

export interface MarksSheet {
  exam: { id: number; name: string; status: ExamStatus; display_status: ExamStatus; cohort: string; session_name: string | null };
  subject: {
    id: number; code: string; name: string; type: string; credits: number; max_internal: number; max_external: number; max_practical: number; max_total: number; pass_marks: number;
    schedule: { exam_date: string; start_time: string; end_time: string; room_code: string | null } | null;
  };
  section_id: number | null;
  sections: { id: number; name: string; students: number }[];
  rows: MarkRow[];
  stats: { students: number; entered: number; absent: number; passed: number; submitted: number; average: number | null; pass_percent: number | null };
  grades: GradeBand[];
  can_edit: boolean;
  can_submit: boolean;
  lock_reason: string | null;
}

export interface MarksExamOption {
  id: number;
  name: string;
  status: ExamStatus;
  display_status: ExamStatus;
  start_date: string | null;
  end_date: string | null;
  semester_no: number | null;
  type_name: string;
  cohort: string;
  subjects: number;
  students: number;
  entered: number;
  percent: number;
  locked: boolean;
}

export interface ResultsExamRow {
  id: number;
  name: string;
  status: ExamStatus;
  program_id: number | null;
  semester_no: number | null;
  start_date: string | null;
  end_date: string | null;
  result_date: string | null;
  published_at: string | null;
  type_name: string;
  is_final: number;
  program_short: string | null;
  session_name: string | null;
  cohort: string;
  total: number;
  pass: number;
  failed: number;
  appeared: number;
  withheld: number;
  avg_percent: number | null;
  avg_sgpa: number | null;
  top_percent: number | null;
  pass_percent: number | null;
  marksheets: number;
}

export interface ResultsOverview {
  exam: {
    id: number; name: string; status: ExamStatus; display_status: ExamStatus; cohort: string; session_name: string | null; type_name: string; is_final: boolean;
    program_id: number | null; semester_no: number | null; academic_session_id: number; published_at: string | null; published_by_name: string | null; verified_at: string | null;
    result_date: string | null; start_date: string | null; end_date: string | null;
  };
  counts: ResultCounts;
  toppers: { id: number; student_id: number; name: string; student_uid: string; photo: string | null; section_name: string | null; percentage: number; sgpa: number | null; cgpa: number | null; class_rank: number; total_marks: number; max_marks: number }[];
  grade_distribution: { grade: string; count: number }[];
  bands: { label: string; count: number }[];
  subjects: (SubjectProgress & { lowest: number | null; average_percent: number | null; failed: number; grades: Record<string, number> })[];
  marksheets: number;
  missing_marks: string[];
  sections: { id: number; name: string; students: number }[];
  permissions: { withhold: boolean; publish: boolean; verify: boolean; marksheets: boolean; export: boolean };
}

export interface SessionResults {
  session_id: number;
  programs: { label: string; total: number; pass: number; appeared: number; avg_sgpa: number | null; avg_percent: number | null; pass_percent: number }[];
  status: Record<string, number>;
  trend: { label: string; pass_percent: number; avg_sgpa: number | null }[];
  toppers: { id: number; exam_id: number; name: string; student_uid: string; photo: string | null; program_short: string | null; semester_no: number; sgpa: number | null; cgpa: number | null; percentage: number; exam_name: string }[];
  toppers_basis: 'sgpa' | 'percentage';
  pending: { id: number; name: string; status: ExamStatus; cohort: string; end_date: string | null }[];
}

export interface ResultDetail {
  result: {
    id: number; exam_id: number; student_id: number; student_name: string; student_uid: string; roll_no: string | null; enrollment_no: string | null; photo: string | null;
    father_name: string | null; section_name: string | null; exam_name: string; exam_status: ExamStatus; type_name: string; is_final: number; program_name: string | null;
    program_short: string | null; session_name: string | null; semester_no: number | null; total_marks: number | null; max_marks: number | null; percentage: number | null;
    credits_registered: number | null; credits_earned: number | null; sgpa: number | null; cgpa: number | null; result_status: ResultStatus; backlog_count: number; class_rank: number | null;
    division: string | null; withheld_reason: string | null; withheld_at: string | null; withheld_by_name: string | null; is_published: boolean; published_at: string | null;
    marksheet_id: number | null; marksheet_no: string | null; marksheet_status: string | null;
  };
  subjects: {
    code: string; name: string; type: string; credits: number; max_internal: number; max_external: number; max_practical: number; pass_marks: number; max_total: number;
    internal_marks: number | null; external_marks: number | null; practical_marks: number | null; total_marks: number | null; grade: string | null; grade_point: number | null;
    is_absent: boolean; is_pass: boolean | null; remarks: string | null;
  }[];
  history: { id: number; exam_id: number; exam_name: string; type_name: string; is_final: number; semester_no: number; session_name: string; percentage: number | null; sgpa: number | null; cgpa: number | null; result_status: ResultStatus; backlog_count: number; class_rank: number | null; result_date: string | null; marksheet_no: string | null }[];
  can_withhold: boolean;
}

export interface MarksheetSummary {
  kpis: { total: number; issued: number; revoked: number; not_printed: number; prints: number; this_month: number; pending: number };
  pending: { id: number; name: string; published_at: string | null; result_date: string | null; is_final: number; cohort: string; session_name: string | null; pending: number; issued: number; withheld: number }[];
  recent: { d: string; n: number }[];
  can_generate: boolean;
  can_revoke: boolean;
}

export interface AttendanceSheet {
  schedule: { id: number; exam_id: number; exam_date: string; start_time: string; end_time: string; subject_code: string; subject_name: string; room_code: string | null; room_name: string | null };
  rows: { student_id: number; name: string; student_uid: string; roll_no: string | null; photo: string | null; seat_no: string | null; status: string | null; remarks: string | null }[];
  counts: { present: number; absent: number; malpractice: number; unmarked: number };
  can_mark: boolean;
  can_edit: boolean;
}
