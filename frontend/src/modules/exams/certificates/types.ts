/** Types for the Certificates unit (API: api/routes/certificates.php). */
import type { Tone } from '@/components/ui';

export type CertStatus = 'issued' | 'pending' | 'revoked' | 'rejected' | 'expired';

export interface CertTypeDef {
  key: string;
  label: string;
  short: string;
  code: string;
  icon: string;
  tone: Tone;
  description: string;
  fields: string[];
  required: string[];
  once: boolean;
  active_only: boolean;
  validity_days: number | null;
}

export interface CertFieldDef {
  key: string;
  label: string;
  type: 'text' | 'select' | 'date';
  maxlength?: number;
  placeholder?: string;
  suggestions?: string[];
  options?: string[];
  default?: string;
  help?: string;
}

export interface PlaceholderGroup {
  group: string;
  items: { key: string; label: string }[];
}

export interface CertTemplateOption {
  id: number;
  name: string;
  type: string;
  title: string;
  orientation: 'portrait' | 'landscape';
  border_style: string;
  accent_color: string;
  is_default: boolean;
  validity_days: number | null;
}

export interface CertSettings {
  approval_required: boolean;
  email_student: boolean;
  verify_rate_limit: number;
  prefix: string;
}

export interface CertConfig {
  types: CertTypeDef[];
  fields: Record<string, CertFieldDef>;
  placeholders: PlaceholderGroup[];
  border_styles: Record<string, string>;
  font_styles: Record<string, string>;
  templates: CertTemplateOption[];
  settings: CertSettings;
  status_labels: Record<CertStatus, string>;
  verify_base: string;
  can_approve: boolean;
}

export interface CertSummary {
  kpis: {
    valid_count: number;
    expired_count: number;
    pending_count: number;
    revoked_count: number;
    rejected_count: number;
    session_count: number;
    month_count: number;
    prev_month_count: number;
    printed_total: number;
    all_count: number;
    verifications_30d: number;
    verifications_trend: number | null;
    verifications_flagged: number;
    month_trend: number | null;
  };
  by_type: { type: string; label: string; tone: Tone; total: number; issued: number; pending: number }[];
  monthly: { month: string; label: string; issued: number; verifications: number }[];
  recent_verifications: {
    id: number;
    certificate_no: string;
    result: 'valid' | 'expired' | 'revoked' | 'not_found';
    source: 'web' | 'qr' | 'api';
    created_at: string;
    certificate_id: number | null;
    type: string | null;
    type_label: string | null;
    student_name: string | null;
  }[];
  settings: Omit<CertSettings, 'prefix'>;
}

export interface StudentCard {
  id: number;
  name: string;
  student_uid: string;
  photo: string | null;
  status: string;
  gender: string | null;
  program: string;
  program_short: string;
  semester: number;
  total_semesters: number;
  section: string | null;
  batch: string | null;
  father_name: string | null;
  has_email: boolean;
  cgpa: string;
  certificates: { id: number; certificate_no: string; type: string; type_label: string; status: string; effective_status: CertStatus; issue_date: string; valid_until: string | null }[];
}

export interface PreviewResult {
  html: string;
  orientation: 'portrait' | 'landscape';
  title: string;
  errors: Record<string, string>;
  warnings: string[];
  missing: { key: string; label: string }[];
  unknown: string[];
  field_errors: Record<string, string>;
  valid_until: string | null;
  template: { id: number; name: string };
}

export interface CertificateDetail {
  certificate: {
    id: number;
    certificate_no: string;
    type: string;
    type_label: string;
    template_id: number | null;
    student_id: number;
    title: string;
    purpose: string | null;
    issue_date: string;
    valid_until: string | null;
    status: Exclude<CertStatus, 'expired'>;
    effective_status: CertStatus;
    revoked_reason: string | null;
    remarks: string | null;
    issued_by_name: string | null;
    approved_by_name: string | null;
    revoked_by_name: string | null;
    approved_at: string | null;
    revoked_at: string | null;
    created_at: string;
    verified_count: number;
    printed_count: number;
    last_printed_at: string | null;
    last_verified_at: string | null;
    student_name: string;
    student_uid: string;
    student_photo: string | null;
    student_status: string;
    student_email: boolean;
    current_semester: number;
    program_short: string;
    program_name: string;
    section_name: string | null;
    template_name: string | null;
    session_name: string | null;
    security_code: string;
  };
  fields: { key: string; label: string; value: string }[];
  raw_fields: Record<string, string>;
  replaces: { id: number; certificate_no: string; status: string; issue_date: string } | null;
  replaced_by: { id: number; certificate_no: string; status: string; issue_date: string }[];
  verifications: { id: number; result: string; source: string; ip_address: string | null; created_at: string }[];
  verification_sources: Record<string, number>;
  events: { type: string; title: string; by: string | null; at: string; note: string | null }[];
  verify_url: string;
  orientation: 'portrait' | 'landscape';
  can: { approve: boolean; reject: boolean; revoke: boolean; reissue: boolean; email: boolean; delete: boolean; edit: boolean };
}

export interface BulkCandidate {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  status: string;
  current_semester: number;
  photo: string | null;
  program_short: string;
  section_name: string | null;
  existing_no: string | null;
  existing_status: string | null;
  eligible: boolean;
  reason: string | null;
  warning: string | null;
}

export interface TemplateRow {
  id: number;
  name: string;
  type: string;
  type_label: string;
  description: string | null;
  title: string;
  subtitle: string | null;
  body_html: string;
  orientation: 'portrait' | 'landscape';
  border_style: string;
  accent_color: string;
  font_style: string;
  signatory_name: string | null;
  signatory_designation: string | null;
  signatory2_name: string | null;
  signatory2_designation: string | null;
  show_photo: boolean;
  show_seal: boolean;
  show_qr: boolean;
  validity_days: number | null;
  is_default: boolean;
  status: 'active' | 'inactive';
  issued_count: number;
  pending_count: number;
  last_used: string | null;
  updated_at: string | null;
  updated_by_name: string | null;
}

export interface TemplatePreview {
  html: string;
  used: string[];
  unknown: string[];
  missing: string[];
  problems: string[];
  student: { id: number | null; name: string; uid: string; program: string };
  sample: boolean;
  orientation: 'portrait' | 'landscape';
  words: number;
}
