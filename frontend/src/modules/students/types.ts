/** API payload types for the students module (api/routes/students.php). */

export interface TreeDepartment { id: number; name: string; code: string }
export interface TreeProgram { id: number; department_id: number; name: string; short_name: string; code: string; level: string; total_semesters: number; status: string }
export interface TreeCourse { id: number; program_id: number; name: string; code: string }
export interface TreeBatch { id: number; program_id: number; name: string; start_year: number; end_year: number; status: string }
export interface TreeSession { id: number; name: string; is_current: boolean; start_date: string; end_date: string; status: string }
export interface TreeSection { id: number; program_id: number; semester_no: number; academic_session_id: number | null; batch_id: number | null; name: string; capacity: number; strength: number }

export interface AcademicTree {
  departments: TreeDepartment[];
  programs: TreeProgram[];
  courses: TreeCourse[];
  batches: TreeBatch[];
  sessions: TreeSession[];
  sections: TreeSection[];
  current_session_id: number | null;
}

export interface StudentStats {
  total: number;
  active: number;
  male: number;
  female: number;
  other: number;
  new_this_session: number;
  new_last_session: number;
  new_this_month: number;
  hostellers: number;
  transport_users: number;
  session: string | null;
  new_growth_percent: number | null;
  by_status: { status: string; label: string; count: number }[];
  by_program: { id: number; label: string; count: number }[];
  by_category: { label: string; count: number }[];
}

export interface DuplicateMatch { id: number; name: string; student_uid: string; status: string }
export type Duplicates = Partial<Record<'email' | 'mobile' | 'admission_no' | 'aadhaar_no' | 'student_uid' | 'roll_no', DuplicateMatch[]>>;

export interface ParentRow {
  id?: number;
  relation: 'father' | 'mother' | 'guardian' | 'other';
  name: string;
  phone: string | null;
  email: string | null;
  occupation: string | null;
  annual_income: string | number | null;
  address: string | null;
  is_emergency_contact: boolean;
}

export interface StudentProfileRow {
  id: number;
  student_uid: string;
  admission_no: string;
  roll_no: string | null;
  enrollment_no: string | null;
  first_name: string;
  middle_name: string | null;
  last_name: string | null;
  full_name: string;
  photo: string | null;
  gender: string;
  dob: string | null;
  age: number | null;
  blood_group: string | null;
  category: string | null;
  religion: string | null;
  nationality: string | null;
  aadhaar_masked: string | null;
  mobile: string;
  email: string | null;
  whatsapp: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  country: string | null;
  pincode: string | null;
  permanent_address: string | null;
  father_name: string | null;
  mother_name: string | null;
  guardian_name: string | null;
  guardian_relation: string | null;
  guardian_phone: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  department_id: number | null;
  program_id: number;
  course_id: number | null;
  batch_id: number | null;
  current_semester: number;
  section_id: number | null;
  academic_session_id: number | null;
  admission_date: string | null;
  admission_type: string | null;
  previous_qualification: string | null;
  previous_percentage: string | null;
  is_hosteller: number;
  uses_transport: number;
  status: string;
  status_reason: string | null;
  remarks: string | null;
  created_at: string;
  updated_at: string | null;
  program_full_name: string;
  program_name: string;
  program_code: string;
  total_semesters: number;
  program_level: string;
  department_name: string | null;
  course_name: string | null;
  batch_name: string | null;
  batch_start: number | null;
  batch_end: number | null;
  section_name: string | null;
  session_name: string | null;
  created_by_name: string | null;
  valid_until: string;
}

export interface AttendanceStats { total: number; present: number; absent: number; late: number; leave: number; half_day: number; attended: number; percent: number | null; threshold: number }
export interface FeeStats { invoices: number; net: number; paid: number; balance: number; overdue: number; discount: number; scholarship: number; fine: number; next_due: string | null }
export interface GpaStats { sgpa: number | null; cgpa: number | null; semester: number | null; result_status: string | null; backlogs: number; source: string | null }

export interface StudentProfile {
  student: StudentProfileRow;
  parents: ParentRow[];
  stats: {
    attendance: AttendanceStats | null;
    fees: FeeStats | null;
    gpa: GpaStats | null;
    library: { member: { id: number; membership_no: string; status: string } | null; issued: number; overdue: number; total: number; fines: number } | null;
    hostel: { hostel_name: string; hostel_code: string; room_no: string; room_type: string; floor_no: number; bed_no: string | null; allocated_on: string; warden_name: string | null; warden_phone: string | null } | null;
    transport: { route_name: string; route_code: string; stop_name: string | null; pickup_time: string | null; vehicle_no: string | null; start_date: string } | null;
    placement: { applications: number; offers: number; best_package: number | null } | null;
    documents: { total: number; verified: number; pending: number; rejected: number };
    certificates: number | null;
  };
  recent_activity: ActivityRow[];
  academic_history: { semester_no: number; status: string; sgpa: string | null; cgpa: string | null; session_name: string | null }[];
  print_views: Record<string, boolean>;
}

export interface ActivityRow { id: number; action: string; module: string; description: string; status?: string; created_at: string; user_name: string | null; user_avatar?: string | null }

export interface PagedRows<T> { rows: T[]; total: number; page: number; pages: number; per_page: number }

export interface IdCardRow {
  id: number;
  full_name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  gender: string;
  dob: string | null;
  blood_group: string | null;
  mobile: string;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  guardian_phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  current_semester: number;
  status: string;
  program_name: string;
  program_full_name: string;
  course_name: string | null;
  batch_name: string | null;
  section_name: string | null;
  valid_until: string;
}
export interface InstituteInfo { name: string; phone: string; email: string; website: string; address: string; logo: string; logo_white: string }

export interface PromotionStudent {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  gender: string;
  section_id: number | null;
  section_name: string | null;
  batch_name: string | null;
  attendance_percent: number | null;
  cgpa: number | null;
  result_status: string | null;
  backlogs: number;
  flags: string[];
  suggested_action: 'promote' | 'pass_out';
}
export interface PromotionPreview {
  program: { id: number; name: string; short_name: string; total_semesters: number };
  semester: number;
  section_id: number | null;
  session_id: number | null;
  target: { is_final: boolean; to_semester: number | null; to_session_id: number | null };
  target_sections: { id: number; name: string; capacity: number; strength: number; academic_session_id: number | null }[];
  students: PromotionStudent[];
  min_attendance: number;
}
export interface PromotionResult {
  reference_no: string;
  promoted: number;
  detained: number;
  passed_out: number;
  skipped: number;
  created_sections: string[];
  without_section: number;
  to_semester: number | null;
  to_session: string;
}
export interface PromotionHistoryRow {
  id: number;
  reference_no: string;
  program_id: number;
  program_name: string;
  from_semester: number;
  to_semester: number | null;
  section_name: string | null;
  from_session: string | null;
  to_session: string | null;
  promoted_count: number;
  detained_count: number;
  passed_out_count: number;
  skipped_count: number;
  remarks: string | null;
  created_by_name: string | null;
  created_at: string;
}
