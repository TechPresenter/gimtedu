/** Response shapes of the student area endpoints (api/routes/portal/student.php). */

export interface GpaPoint {
  semester: number;
  label: string;
  sgpa: number | null;
  cgpa: number | null;
  percentage: number | null;
  result_status: string | null;
}

export interface FeePart {
  due_date: string | null;
  amount: number;
  invoice_id: number;
  invoice_no: string;
  title: string;
  installment_no: number | null;
  overdue: boolean;
  days_left: number | null;
}

export interface StudentOverview {
  gpa_trend: GpaPoint[];
  fees: { next_due: FeePart | null; overdue_amount: number; overdue_count: number; oldest_overdue: string | null };
  library: { member: boolean; issued: number; overdue: number; unpaid_fines: number; loans: { id: number; title: string; issue_date: string; due_date: string; days_left: number | null }[] };
  admit_cards: { exam_id: number; name: string; start_date: string | null; end_date: string | null; hall_ticket_no: string | null; eligible: boolean; reason: string | null; available: boolean; days_left: number | null }[];
  hostel: { hostel: string; room_no: string; bed_no: string | null } | null;
  transport: { route: string; stop: string | null; pickup_time: string | null; vehicle_no: string | null } | null;
  counts: { open_tickets: number; pending_certificates: number; rejected_documents: number; open_hostel_complaints: number };
}

/* ------------------------------------------------------------------ timetable */
export interface TtDay {
  no: number;
  name: string;
  short: string;
  date: string;
  is_today: boolean;
  holiday: string | null;
  event: string | null;
  weekly_off: boolean;
}
export interface TtSlot {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  is_break: boolean;
}
export interface TtEntry {
  id: number;
  day: number;
  slot_id: number;
  type: string;
  notes: string | null;
  subject_id: number;
  subject_code: string;
  subject: string;
  subject_type: string;
  faculty: string | null;
  room_code: string | null;
  room: string | null;
}
export interface TtSubject {
  id: number;
  code: string;
  name: string;
  type: string;
  faculty: string[];
  periods: number;
}
export interface StudentTimetable {
  session: string | null;
  section: { name: string; class_teacher: string | null; class_teacher_email: string | null; home_room: string | null } | null;
  program: string | null;
  semester: number;
  days: TtDay[];
  slots: TtSlot[];
  entries: TtEntry[];
  subjects: TtSubject[];
  today: number;
  now: string;
  week_start: string;
  periods_per_week: number;
  unpublished: boolean;
}

/* ------------------------------------------------------------------ attendance */
export type DayStatus = 'present' | 'absent' | 'late' | 'leave' | 'partial' | 'holiday' | 'off' | 'future' | 'none';
export interface AttRecord {
  status: string;
  subject_code: string | null;
  subject: string | null;
  slot: string | null;
  start_time: string | null;
  remarks: string | null;
}
export interface AttDay {
  date: string;
  status: DayStatus;
  counts: { present: number; absent: number; late: number; leave: number; half_day: number };
  total: number;
  holiday: string | null;
  records: AttRecord[];
}
export interface AttSubject {
  id: number;
  code: string;
  name: string;
  held: number;
  present: number;
  absent: number;
  late: number;
  leave: number;
  half_day: number;
  attended: number;
  percent: number | null;
  needed: number;
  can_miss: number;
  faculty: string | null;
  last_date: string | null;
}
export interface StudentAttendance {
  session: string | null;
  threshold: number;
  late_counts_as_present: boolean;
  overall: { percent: number | null; held: number; attended: number; present: number; absent: number; late: number; leave: number; half_day: number; needed: number; can_miss: number };
  subjects: AttSubject[];
  month: { value: string; label: string; held: number; attended: number; percent: number | null; days: AttDay[]; first_dow: number };
  months: { value: string; label: string }[];
  trend: { month: string; label: string; held: number; percent: number | null }[];
  absences: { date: string; subject_code: string | null; subject: string | null; start_time: string | null }[];
}

/* ------------------------------------------------------------------ exams */
export interface ExamPaper {
  id: number;
  date: string;
  start_time: string | null;
  end_time: string | null;
  code: string;
  name: string;
  type: string;
  room_code: string | null;
  room: string | null;
  days_left: number | null;
}
export interface StudentExam {
  id: number;
  name: string;
  type: string;
  is_final: boolean;
  semester: number | null;
  session: string | null;
  start_date: string | null;
  end_date: string | null;
  result_date: string | null;
  status: string;
  phase: 'upcoming' | 'ongoing' | 'completed';
  days_left: number | null;
  instructions: string | null;
  allocation: { eligible: boolean; reason: string | null; hall_ticket_no: string | null; seat_no: string | null; room: string | null; attendance_percent: number | null; fee_due: number | null } | null;
  admit_card: { available: boolean; reason: string | null };
  result_id: number | null;
  papers: ExamPaper[];
}
export interface StudentExams {
  upcoming: StudentExam[];
  past: StudentExam[];
  next_paper: (ExamPaper & { exam_id: number; exam: string }) | null;
  today: string;
}

/* ------------------------------------------------------------------ results */
export interface ResultItem {
  id: number;
  exam_id: number;
  exam_name: string;
  type: string;
  is_final: boolean;
  semester: number | null;
  session: string | null;
  withheld: boolean;
  total_marks: number | null;
  max_marks: number | null;
  percentage: number | null;
  sgpa: number | null;
  cgpa: number | null;
  credits_registered: number | null;
  credits_earned: number | null;
  result_status: string;
  backlog_count: number;
  class_rank: number | null;
  division: string | null;
  result_date: string | null;
  marksheet: { no: string; issued_at: string } | null;
  printable: boolean;
}
export interface SubjectMark {
  code: string;
  name: string;
  type: string;
  credits: number | null;
  internal: number | null;
  max_internal: number;
  external: number | null;
  max_external: number;
  practical: number | null;
  max_practical: number;
  total: number | null;
  max_total: number;
  pass_marks: number;
  grade: string | null;
  grade_point: number | null;
  absent: boolean;
  pass: boolean | null;
}
export interface ResultDetail extends ResultItem {
  subjects: SubjectMark[];
  grade_scale: { grade: string; min: number; max: number; grade_point: number }[];
}
export interface StudentResults {
  summary: {
    cgpa: number | null;
    sgpa: number | null;
    semester: number | null;
    result_status: string | null;
    credits_earned: number;
    backlogs: number;
    best_sgpa: number | null;
    semesters_completed: number;
    total_semesters: number;
    division: string | null;
  };
  trend: GpaPoint[];
  results: ResultItem[];
}

/* ------------------------------------------------------------------ fees */
export interface Installment {
  no: number;
  amount: number;
  paid: number;
  due_date: string;
  status: string;
}
export interface Invoice {
  id: number;
  invoice_no: string;
  title: string;
  semester: number | null;
  session: string | null;
  gross: number;
  discount: number;
  scholarship: number;
  fine: number;
  net: number;
  paid: number;
  balance: number;
  overdue: number;
  due_date: string | null;
  days_left: number | null;
  status: string;
  open: boolean;
  late_fee_due: number;
  created_at: string;
  cancelled_reason: string | null;
  installments: Installment[];
  items: { head: string; amount: number }[];
}
export interface Payment {
  id: number;
  receipt_no: string;
  amount: number;
  fine: number;
  total: number;
  date: string;
  mode: string;
  mode_label: string;
  reference_no: string | null;
  status: string;
  purpose: string | null;
  invoices: string | null;
  void_reason: string | null;
  printable: boolean;
}
export interface PayOptions {
  gateway: { provider: string | null; configured: boolean; mode: string; online_enabled: boolean; available: boolean };
  modes: { key: string; label: string }[];
  bank: Partial<Record<'account_name' | 'bank_name' | 'account_no' | 'ifsc' | 'branch', string>> | null;
  bank_complete: boolean;
  upi_id: string | null;
  office: { hours: string; phone: string; email: string; address: string };
  late_fee_per_day: number;
}
export interface StudentFees {
  totals: { invoices: number; net: number; paid: number; balance: number; overdue: number; concessions: number; fine: number };
  next_due: FeePart | null;
  schedule: FeePart[];
  invoices: Invoice[];
  payments: Payment[];
  concessions: { name: string; type: string; amount: number; reason: string | null; date: string; invoice_no: string | null }[];
  pay: PayOptions;
}
export interface InvoiceDetail {
  id: number;
  invoice_no: string;
  title: string;
  semester_no: number | null;
  session_name: string | null;
  structure_name: string | null;
  gross_amount: number;
  discount_amount: number;
  scholarship_amount: number;
  fine_amount: number;
  net_amount: number;
  paid_amount: number;
  balance_amount: number;
  overdue_amount: number;
  due_date: string | null;
  status: string;
  late_fee_due: number;
  late_fee_rate: { per_day: number; max: number | null };
  created_at: string;
  cancelled_reason: string | null;
  items: { head: string; code: string; amount: number }[];
  installments: Installment[];
  concessions: { name: string; amount: number; reason: string | null; status: string }[];
  payments: { id: number; receipt_no: string; date: string; mode: string; reference_no: string | null; status: string; amount: number }[];
}

/* ------------------------------------------------------------------ library */
export interface Loan {
  id: number;
  title: string;
  authors: string | null;
  isbn: string | null;
  category: string | null;
  accession_no: string | null;
  cover: string | null;
  issue_date: string;
  due_date: string;
  return_date: string | null;
  renew_count: number;
  status: string;
  state: string;
  days_overdue: number;
  accrued_fine: number;
  fine_amount: number;
  days_left: number | null;
  renewable: boolean;
}
export interface StudentLibrary {
  member: { membership_no: string; max_books: number; loan_days: number; valid_until: string | null; status: string; since: string; expired: boolean } | null;
  rules: { fine_per_day: number; grace_days: number; max_renewals: number; loan_days: number; max_books: number };
  current: Loan[];
  history: Loan[];
  fines: { id: number; book: string | null; amount: number; reason: string; days_overdue: number | null; status: string; paid_at: string | null; receipt_no: string | null; waive_reason: string | null; date: string }[];
  stats: { issued: number; overdue: number; due_soon: number; total: number; unpaid_fines: number; accrued: number };
}

/* ------------------------------------------------------------------ hostel / transport */
export interface HostelComplaint {
  id: number;
  complaint_no: string;
  category: string;
  category_label: string;
  subject: string;
  description: string | null;
  priority: string;
  status: string;
  assigned: boolean;
  resolution: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  created_at: string;
  location: string;
}
export interface StudentHostel {
  allocation: {
    allocation_no: string;
    hostel: string;
    hostel_code: string;
    hostel_type: string;
    block: string | null;
    room_no: string;
    room_type: string;
    floor: string;
    capacity: number;
    is_ac: boolean;
    bed_no: string | null;
    allocated_on: string;
    valid_until: string | null;
    session: string | null;
    fee_amount: number | null;
    mess_fee: number | null;
    warden: string | null;
    warden_phone: string | null;
    email: string | null;
    address: string | null;
    visiting_hours: string | null;
    amenities: string[];
    room_amenities: string[];
  } | null;
  roommates: { first_name: string; program: string; semester: number; bed_no: string | null }[];
  complaints: HostelComplaint[];
  history: { allocation_no: string; allocated_on: string; vacated_on: string | null; status: string; hostel_name: string; room_no: string }[];
  categories: Record<string, string>;
  priorities: Record<string, string>;
}
export interface StudentTransport {
  allocation: {
    route: string;
    route_code: string | null;
    start_point: string | null;
    end_point: string | null;
    distance_km: number | null;
    morning_departure: string | null;
    evening_departure: string | null;
    stop: string | null;
    landmark: string | null;
    pickup_time: string | null;
    drop_time: string | null;
    start_date: string | null;
    end_date: string | null;
    fee_amount: number | null;
    session: string | null;
  } | null;
  vehicle: { vehicle_no: string; type: string; model: string | null; capacity: number | null; status: string; driver: string | null; driver_phone: string | null; conductor: string | null; conductor_phone: string | null } | null;
  stops: { id: number; name: string; landmark: string | null; sequence: number; pickup_time: string | null; drop_time: string | null; mine: boolean }[];
}

/* ------------------------------------------------------------------ certificates */
export interface CertificateItem {
  id: number;
  certificate_no: string;
  type: string;
  type_label: string;
  title: string;
  purpose: string | null;
  issue_date: string;
  valid_until: string | null;
  status: string;
  status_label: string;
  requested_at: string;
  verify_url?: string;
  security_code?: string | null;
  revoked_reason?: string | null;
  reason?: string | null;
  decided_at?: string | null;
}
export interface StudentCertificates {
  issued: CertificateItem[];
  requests: CertificateItem[];
  requestable: { type: string; label: string; description: string; purpose_required: boolean; available: boolean; reason: string | null }[];
  purposes: string[];
  verify_page: string;
}

/* ------------------------------------------------------------------ placement */
export interface Drive {
  id: number;
  title: string;
  role: string | null;
  job_type: string;
  job_type_label: string;
  company: string;
  logo: string | null;
  industry: string | null;
  website: string | null;
  headquarters: string | null;
  description: string | null;
  openings: number | null;
  package_min: number | null;
  package_max: number | null;
  stipend: number | null;
  location: string | null;
  drive_date: string | null;
  deadline: string | null;
  days_left: number | null;
  venue: string | null;
  mode: string;
  status: string;
  rounds: string[];
  criteria: { programs: string[]; min_cgpa: number | null; max_backlogs: number | null; min_percentage: number | null; batch_year: number | null; notes: string | null };
  eligible: boolean;
  reason: string | null;
  can_apply: boolean;
  application: { id: number; status: string; current_round: string | null; applied_at: string } | null;
}
export interface Application {
  id: number;
  drive_id: number;
  status: string;
  status_label: string;
  current_round: string | null;
  applied_at: string;
  updated_at: string | null;
  company: string;
  logo: string | null;
  title: string;
  role: string | null;
  drive_date: string | null;
  drive_status: string;
  rounds: string[];
  package_min: number | null;
  package_max: number | null;
  interviews: { round: string; scheduled_at: string | null; mode: string | null; result: string }[];
  can_withdraw: boolean;
}
export interface Offer {
  id: number;
  company: string;
  logo: string | null;
  role: string | null;
  package_lpa: number | null;
  offer_date: string | null;
  joining_date: string | null;
  location: string | null;
  status: string;
  status_label: string;
  offer_letter: string | null;
}
export interface StudentPlacement {
  profile: { cgpa: number | null; backlogs: number; previous_percentage: number | null; final_year: boolean; batch_end: number | null; status: string };
  drives: Drive[];
  applications: Application[];
  offers: Offer[];
}

/* ------------------------------------------------------------------ documents / tickets */
export interface StudentDocument {
  id: number;
  type: string;
  type_label: string;
  title: string;
  file_name: string | null;
  mime: string | null;
  size: number | null;
  status: string;
  remarks: string | null;
  verified_at: string | null;
  uploaded_at: string;
}
export interface StudentDocuments {
  documents: StudentDocument[];
  stats: { total: number; verified: number; pending: number; rejected: number };
  missing: string[];
}

export interface Ticket {
  id: number;
  ticket_no: string;
  type: string;
  type_label: string;
  category: string;
  category_label: string;
  subject: string;
  priority: string;
  status: string;
  anonymous: boolean;
  rating: number | null;
  created_at: string;
  updated_at: string | null;
  resolved_at: string | null;
  has_resolution: boolean;
  sla_hours: number;
}
export interface TicketDetail extends Ticket {
  message: string;
  resolution: string | null;
  started_at: string | null;
  closed_at: string | null;
  assigned: string | null;
  can_rate: boolean;
  can_reopen: boolean;
  timeline: { type: string; title: string; at: string }[];
}
export interface StudentTickets {
  tickets: Ticket[];
  counts: { open: number; in_progress: number; resolved: number; closed: number };
  types: Record<string, string>;
  categories: Record<string, string>;
}
