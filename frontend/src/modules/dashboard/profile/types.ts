/** Types of GET /api/profile (api/routes/profile.php). */

export interface ProfileUser {
  id: number;
  name: string;
  username: string;
  email: string;
  phone: string | null;
  designation: string | null;
  avatar: string | null;
  department: string | null;
  theme: 'light' | 'dark' | 'system';
  status: string;
  roles: { id: number; name: string; slug: string; color: string | null; is_super: boolean }[];
  created_at: string;
  last_login_at: string | null;
  last_login_ip: string | null;
  password_changed_at: string | null;
  two_factor_enabled: boolean;
  must_change_password: boolean;
}

export interface LoginRow {
  id: number;
  status: string;
  reason: string | null;
  ip_address: string | null;
  browser: string;
  created_at: string;
}

export interface ActivityRow {
  id: number;
  action: string;
  module: string;
  module_label: string;
  description: string | null;
  status: string;
  ip_address: string | null;
  created_at: string;
}

export interface DeviceSession {
  id: number;
  ip_address: string | null;
  browser: string;
  created_at: string;
  expires_at: string;
  current: boolean;
}

export interface SecurityCheck {
  key: string;
  label: string;
  ok: boolean;
  info?: boolean;
  detail: string;
}

export interface ProfileData {
  user: ProfileUser;
  security: {
    score: number;
    checks: SecurityCheck[];
    password_age_days: number | null;
    logins_30d: number;
    failed_30d: number;
    distinct_ips_30d: number;
    last_failed: { ip_address: string | null; browser: string; created_at: string } | null;
    session_timeout_minutes: number;
    current_ip: string;
    current_browser: string;
    signed_in_at: string | null;
    previous_login: { at: string | null; ip: string | null } | null;
  };
  stats: { actions_total: number; actions_30d: number; unread_notifications: number; unread_messages: number };
  logins: LoginRow[];
  activity: ActivityRow[];
  sessions: DeviceSession[];
}

export interface PageOf<T> {
  rows: T[];
  total: number;
  page: number;
  pages: number;
  per_page: number;
}
