/** Template designer / library types (API: /api/whatsapp/templates/*, app/services/whatsapp_templates.php). */
import type { WaHeaderType, WaMode, WaTemplateCategory, WaTemplateStatus, WaVariable } from '../shared';

export type TplButtonType = 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER' | 'COPY_CODE' | 'OTP';
export type TplButtonGroup = 'none' | 'cta' | 'quick_reply';

export interface TplButton {
  type: TplButtonType;
  text: string;
  url?: string;
  phone_number?: string;
  example?: string;
  payload?: string;
  otp_type?: string;
}

export interface TplVarRef {
  pos: number;
  key: string;
  sample?: string;
}

export interface TplVariables {
  header: TplVarRef[];
  body: TplVarRef[];
  buttons: Record<string, { key: string; sample?: string }>;
}

export interface TplOtp {
  code_expiration_minutes: number;
  add_security_recommendation: boolean;
  otp_type: 'COPY_CODE' | 'ONE_TAP';
  button_text: string;
  package_name?: string;
  signature_hash?: string;
  autofill_text?: string;
}

export interface TplRow {
  id: number;
  name: string;
  display_name: string | null;
  description: string | null;
  category: WaTemplateCategory;
  language: string;
  header_type: WaHeaderType;
  header_text: string | null;
  header_media_url: string | null;
  header_media_name: string | null;
  body: string;
  footer: string | null;
  buttons: TplButton[];
  variables: TplVariables;
  otp_settings: TplOtp | null;
  status: WaTemplateStatus;
  interakt_id: string | null;
  wa_template_id: string | null;
  rejection_reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  synced_at: string | null;
  usage_count: number;
  last_used_at: string | null;
  is_system: number;
  parent_id: number | null;
  archived_from: string | null;
  archived_at: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string | null;
  editable?: boolean;
  created_by_name?: string | null;
  updated_by_name?: string | null;
  /** Meta changed the category on review (copied in by the Interakt sync); set while the template still has `to`. */
  recategorised?: TplRecategorised | null;
}

export interface TplRecategorised {
  from: WaTemplateCategory;
  to: WaTemplateCategory;
  at: string;
}

export interface TplStats {
  total: number;
  sent: number;
  delivered: number;
  read: number;
  failed: number;
  queued: number;
  /** every button click (quick reply + website / call), from Interakt message_api_clicked webhooks */
  clicks: number;
  /** messages with at least one click */
  clicked: number;
  /** messages the contact answered within 24 hours */
  replies: number;
  cost: number;
  last_used_at: string | null;
}

export interface TplUsageCounts {
  events: number;
  workflows: number;
  followups: number;
  campaigns: number;
  total: number;
}

export interface TplListItem extends TplRow {
  stats: TplStats;
  usage: TplUsageCounts;
}

export interface TplList {
  items: TplListItem[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

export interface TplSummary {
  total: number;
  by_status: Record<WaTemplateStatus, number>;
  by_category: Partial<Record<WaTemplateCategory, number>>;
  by_language: Record<string, number>;
  messages_30d: { total: number; sent: number; delivered: number; read: number; failed: number };
  top: { id: number; name: string; label: string; sent: number; read: number }[];
  mode: WaMode;
  configured: boolean;
  last_sync: string | null;
}

export interface TplMediaSpec {
  label: string;
  max_mb: number;
  ext: string[];
  mime: string[];
  accept: string;
}

export interface TplLimits {
  name: number;
  display_name: number;
  header_text: number;
  body: number;
  footer: number;
  button_text: number;
  buttons_total: number;
  quick_replies: number;
  url_buttons: number;
  phone_buttons: number;
  copy_code_buttons: number;
  url: number;
  copy_code: number;
  payload: number;
  sample: number;
  otp_expiry_min: number;
  otp_expiry_max: number;
  media: Record<'image' | 'video' | 'document', TplMediaSpec>;
}

export interface TplMeta {
  categories: Record<WaTemplateCategory, { label: string; description: string; hint: string }>;
  languages: Record<string, { label: string; interakt: string }>;
  statuses: Record<WaTemplateStatus, string>;
  limits: TplLimits;
  catalog: WaVariable[];
  mode: WaMode;
  configured: boolean;
  default_language: string;
  business_name: string;
  business_number: string;
  can_simulate: boolean;
  last_sync: string | null;
}

export type TplIssueLevel = 'error' | 'submit' | 'warning';

export interface TplIssue {
  field: string;
  level: TplIssueLevel;
  message: string;
}

export interface TplNamed {
  header_text: string;
  body: string;
  buttons: TplButton[];
  button_type: TplButtonGroup | 'otp';
  samples: Record<string, string>;
}

export interface TplUsage {
  events: { id: number; code: string; name: string; is_active: number }[];
  workflows: { id: number; name: string; status: string }[];
  followups: { id: number; stage_key: string; name: string; is_active: number }[];
  campaigns: { id: number; name: string; status: string; scheduled_at: string | null }[];
}

export interface TplDetail {
  template: TplRow;
  named: TplNamed;
  issues: TplIssue[];
  stats: TplStats;
  stats_30d: TplStats;
  trend: { labels: string[]; sent: number[]; delivered: number[]; read: number[]; failed: number[] };
  usage: TplUsage;
  versions: { id: number; name: string; status: WaTemplateStatus; created_at: string }[];
}

export interface TplValidation {
  issues: TplIssue[];
  compiled: { name: string; header_text: string | null; body: string; buttons: TplButton[]; variables: TplVariables };
  can_save: boolean;
  can_submit: boolean;
}

/** Designer form state (named form — {{student_name}} — compiled to positional by the server). */
export interface DesignerState {
  name: string;
  display_name: string;
  description: string;
  category: WaTemplateCategory;
  language: string;
  header_type: WaHeaderType;
  header_text: string;
  header_media_url: string;
  header_media_name: string;
  body: string;
  footer: string;
  button_type: TplButtonGroup;
  buttons: TplButton[];
  samples: Record<string, string>;
  otp: TplOtp;
}

export interface TplSaveResult {
  id: number;
  issues: TplIssue[];
  template: TplRow;
  mapping_only?: boolean;
}

export interface TplRemote {
  remote_id: string;
  name: string;
  display_name: string | null;
  category: WaTemplateCategory;
  language: string;
  status: WaTemplateStatus;
  header_type: WaHeaderType;
  body: string;
  buttons: TplButton[];
  variables: number;
  created_at: string | null;
}
