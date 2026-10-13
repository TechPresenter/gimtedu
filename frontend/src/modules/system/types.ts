/** Types shared by the System administration screens. */

export interface RoleRef {
  id: number;
  name: string;
  color: string | null;
  is_super: boolean;
}

export interface UserRow {
  id: number;
  name: string;
  username: string;
  email: string;
  phone: string | null;
  avatar: string | null;
  designation: string | null;
  department_id: number | null;
  department_name: string | null;
  status: 'active' | 'inactive';
  failed_attempts: number;
  locked_until: string | null;
  last_failed_login_at: string | null;
  last_login_at: string | null;
  last_login_ip: string | null;
  password_changed_at: string | null;
  must_change_password: boolean;
  two_factor_enabled: number;
  created_at: string;
  created_by_name: string | null;
  role_names: string | null;
  roles: string | null;
  role_list: RoleRef[];
  is_super: boolean;
  is_locked: boolean;
  is_self: boolean;
}

export interface UserStats {
  total: number;
  active: number;
  inactive: number;
  locked: number;
  never: number;
  today: number;
  week: number;
  must_change: number;
  roles: (RoleRef & { users: number })[];
}

export interface LoginEvent {
  id: number;
  status: string;
  reason: string | null;
  ip_address: string | null;
  browser: string;
  created_at: string;
}

export interface ActivityItem {
  id: number;
  user_id?: number | null;
  user_name?: string | null;
  user_email?: string | null;
  user_avatar?: string | null;
  action: string;
  module: string;
  module_label: string;
  record_id: string | null;
  description: string | null;
  status: string;
  ip_address: string | null;
  browser?: string;
  created_at: string;
  has_meta?: boolean;
}

export interface TokenRow {
  id: number;
  user_id?: number;
  user_name?: string;
  user_email?: string;
  user_avatar?: string | null;
  ip_address: string | null;
  browser: string;
  created_at: string;
  expires_at: string;
  current?: boolean;
}

export interface UserDetail {
  user: UserRow;
  logins: LoginEvent[];
  activity: ActivityItem[];
  tokens: TokenRow[];
  stats: { logins: number; failed: number; ips: number; actions: number };
  permissions_count: number | null;
  can_manage: boolean;
}

export interface MatrixModule {
  key: string;
  label: string;
  actions: string[];
}

export interface MatrixGroup {
  group: string;
  modules: MatrixModule[];
}

export interface MatrixRole {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  color: string | null;
  is_system: boolean;
  is_super: boolean;
  users_count: number;
  permissions: string[];
  members: { id: number; name: string; avatar: string | null }[];
  created_at: string;
}

export interface MatrixPayload {
  groups: MatrixGroup[];
  actions: Record<string, { label: string; description: string }>;
  roles: MatrixRole[];
  total_permissions: number;
  can: { create: boolean; edit: boolean; delete: boolean };
}

export interface BackupRow {
  id: number;
  filename: string;
  type: 'database' | 'files';
  source: string;
  size_bytes: number;
  checksum: string | null;
  status: 'running' | 'completed' | 'failed';
  notes: string | null;
  error: string | null;
  meta: { tables?: number; rows?: number; files?: number; bytes?: number; duration_ms?: number; table_rows?: Record<string, number>; server?: string; database?: string } | null;
  created_by_name?: string | null;
  restored_by_name?: string | null;
  restored_at: string | null;
  restore_count: number;
  created_at: string;
  file_exists: boolean;
}
