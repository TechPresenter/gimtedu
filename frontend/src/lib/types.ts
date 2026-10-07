/** Shared API types. */

export interface AppInfo {
  name: string;
  version: string;
  institute_name: string;
  short_name: string;
  tagline: string;
  logo: string;
  logo_white: string;
  logo_icon: string;
  currency_symbol: string;
  date_format: string;
  base_path: string;
  site_url: string;
  session_timeout: number;
  password_policy: { min_length: number; uppercase: boolean; number: boolean; special: boolean };
}

export interface SessionUser {
  id: number;
  name: string;
  username: string;
  email: string;
  phone: string | null;
  avatar: string | null;
  designation: string | null;
  roles: string | null;
  theme: 'light' | 'dark' | 'system';
  faculty_id: number | null;
  last_login_at: string | null;
  last_login_ip: string | null;
  must_change_password: boolean;
}

export interface AcademicSession {
  id: number;
  name: string;
  is_current: boolean;
  status: string;
}

export interface SessionPayload {
  authenticated: boolean;
  csrf_token: string;
  app: AppInfo;
  user?: SessionUser;
  is_super?: boolean;
  permissions?: Record<string, string[]>;
  sessions?: AcademicSession[];
  current_session_id?: number | null;
}

export interface Paginated<T> {
  rows: T[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  from?: number;
  to?: number;
  summary?: Record<string, unknown> | null;
}

export interface Option {
  value: string | number;
  label: string;
  sub?: string;
  color?: string;
}

export type Row = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
