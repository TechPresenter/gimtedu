/** Types of the admin Portal Access screen (/api/portal-access/*). */

export interface PaginationInfo {
  total: number;
  page: number;
  per_page: number;
  pages: number;
  from: number;
  to: number;
}

export interface Listing<T> {
  rows: T[];
  pagination: PaginationInfo;
}

export interface PortalSettings {
  portal_student_enabled: boolean;
  portal_faculty_enabled: boolean;
  portal_self_activation: boolean;
}

export interface PortalSummary {
  students: { total: number; accounts: number; without: number; activated: number; self_activated: number; disabled: number; locked: number; must_change: number; never: number; recent: number };
  faculty: { total: number; linked: number; without: number; active: number; locked: number; must_change: number; recent: number };
  today: { student_logins: number; faculty_logins: number; failed: number };
  urls: { student: string; faculty: string };
  settings: PortalSettings;
}

export type StudentState = 'none' | 'pending' | 'active' | 'must_change' | 'locked' | 'disabled';
export type FacultyState = 'none' | 'unlinked' | 'inactive' | 'locked' | 'must_change' | 'active';

export interface StudentRow {
  id: number;
  student_uid: string;
  name: string;
  photo: string | null;
  email: string | null;
  mobile: string | null;
  student_status: string;
  program: string;
  semester: number;
  section: string | null;
  account_id: number | null;
  username: string | null;
  state: StudentState;
  failed_attempts: number;
  locked_until: string | null;
  last_login_at: string | null;
  last_login_ip: string | null;
  login_count: number;
  activated_at: string | null;
  activation_method: string | null;
  account_created_at: string | null;
}

export interface FacultyRow {
  id: number;
  employee_id: string;
  name: string;
  photo: string | null;
  designation: string | null;
  email: string | null;
  phone: string | null;
  faculty_status: string;
  department: string | null;
  user_id: number | null;
  username: string | null;
  login_email: string | null;
  state: FacultyState;
  locked_until: string | null;
  last_login_at: string | null;
  last_login_ip: string | null;
}

export interface LoginActivityRow {
  key: string;
  type: 'student' | 'faculty';
  id: number;
  created_at: string;
  status: string;
  reason: string | null;
  ip_address: string | null;
  identifier: string | null;
  person_id: number | null;
  name: string | null;
  ref: string | null;
  device: string;
}

/** One issued credential (shown once). */
export interface IssuedCredential {
  name: string;
  login: string;
  /** secondary login hint (e.g. email for faculty) */
  alt?: string | null;
  password: string;
  emailed: boolean;
  email?: string | null;
}
