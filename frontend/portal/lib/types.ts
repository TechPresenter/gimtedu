import type { Area } from './config';

export interface PasswordPolicy {
  min_length: number;
  uppercase: boolean;
  number: boolean;
  special: boolean;
}

/** Branding + configuration shared by both portals (GET /portal/{area}/session -> app). */
export interface PortalApp {
  institute_name: string;
  short_name: string;
  tagline: string;
  logo: string;
  logo_white: string;
  base_path: string;
  site_url: string;
  admin_url: string;
  support_email: string;
  support_phone: string;
  academic_session: string | null;
  session_timeout: number;
  password_policy: PasswordPolicy;
  features: { student_portal: boolean; faculty_portal: boolean; self_activation: boolean };
}

export interface StudentInfo {
  id: number;
  student_uid: string;
  name: string;
  first_name: string;
  photo: string | null;
  email: string | null;
  mobile: string | null;
  gender: string | null;
  program: string;
  program_short: string;
  program_code: string;
  department: string | null;
  semester: number;
  section: string | null;
  batch: string | null;
  roll_no: string | null;
  status: string;
}

export interface StudentAccount {
  username: string;
  must_change_password: boolean;
  theme: 'light' | 'dark' | 'system' | null;
  activated_at: string | null;
  password_changed_at: string | null;
  last_login_at: string | null;
  last_login_ip: string | null;
}

export interface FacultyInfo {
  id: number;
  employee_id: string;
  name: string;
  first_name: string;
  title: string | null;
  photo: string | null;
  designation: string;
  department: string | null;
  email: string;
  phone: string | null;
  status: string;
}

export interface FacultyAccount {
  username: string;
  email: string;
  must_change_password: boolean;
  theme: 'light' | 'dark' | 'system' | null;
  last_login_at: string | null;
  last_login_ip: string | null;
  password_changed_at: string | null;
  roles: string | null;
}

interface SessionBase {
  authenticated: boolean;
  csrf_token: string;
  app: PortalApp;
  expired?: boolean;
  /** set client-side after an explicit sign-out (no "session expired" notice) */
  signed_out?: boolean;
  expires_in?: number;
}

export interface StudentSession extends SessionBase {
  area: 'student';
  student?: StudentInfo;
  account?: StudentAccount;
}

export interface FacultySession extends SessionBase {
  area: 'faculty';
  not_faculty?: boolean;
  faculty?: FacultyInfo;
  account?: FacultyAccount;
}

export type AreaSession<A extends Area> = A extends 'student' ? StudentSession : FacultySession;
export type AnySession = StudentSession | FacultySession;

export interface NoticeItem {
  id: number;
  title: string;
  category: string | null;
  audience: string;
  date: string;
  expiry_date: string | null;
  is_pinned: boolean;
  excerpt: string;
  attachment: string | null;
  url: string | null;
  html?: string;
}

export interface LoginRow {
  status: string;
  reason: string | null;
  ip_address: string | null;
  device: string;
  created_at: string;
}
