import type { WaTemplateButton, WaTemplatePreviewData, WaVariable, WaHeaderType, WaTemplateCategory, WaTemplateVariables } from '../shared';

/** Campaign types — mirror app/services/whatsapp_campaigns.php (wa_cmp_*). */
export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'paused' | 'completed' | 'cancelled' | 'failed';
export type AudienceSource = 'filters' | 'csv' | 'mixed';
export type MapSource = 'contact' | 'csv' | 'literal';

export interface CampaignAudience {
  programs: number[];
  stages: string[];
  admission_status: string[];
  payment_status: string[];
  cities: string[];
  states: string[];
  batches: string[];
  tags: number[];
  contact_types: string[];
  segment_id: number | null;
  /** Always true: every campaign, any template category, goes only to contacts with a WhatsApp opt-in. */
  opt_in_only: boolean;
}

export interface VarMapEntry {
  source: MapSource;
  value: string;
  fallback: string;
}
export type VariableMap = Record<string, VarMapEntry>;

export interface CampaignProgress {
  processed: number;
  percent: number;
  eligible: number;
  delivery_rate: number | null;
  read_rate: number | null;
  reply_rate: number | null;
  failure_rate: number | null;
}

export interface CampaignTemplateRef {
  id: number;
  name: string | null;
  display_name: string | null;
  category: WaTemplateCategory | null;
  language: string | null;
  status: string | null;
}

export interface Campaign {
  id: number;
  name: string;
  type: string;
  type_label: string;
  description: string | null;
  template_id: number | null;
  template_name: string | null;
  audience: CampaignAudience;
  audience_source: AudienceSource;
  variable_map?: VariableMap;
  scheduled_at: string | null;
  rate_per_minute: number | null;
  respect_business_hours: number;
  status: CampaignStatus;
  total_recipients: number;
  queued_count: number;
  sent_count: number;
  delivered_count: number;
  read_count: number;
  failed_count: number;
  replied_count: number;
  skipped_count: number;
  interakt_campaign_id: string | null;
  started_at: string | null;
  paused_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  last_error: string | null;
  created_by: number | null;
  created_by_name: string | null;
  created_at: string;
  updated_at: string | null;
  updated_by?: number | null;
  progress: CampaignProgress;
  template: CampaignTemplateRef | null;
}

export interface PageMeta {
  total: number;
  page: number;
  per_page: number;
  pages: number;
  from?: number;
  to?: number;
}

export interface CampaignSummary {
  total: number;
  by_status: Record<CampaignStatus, number>;
  active: number;
  sent_30d: number;
  delivered_30d: number;
  read_30d: number;
  failed_30d: number;
  replied_30d: number;
  skipped_30d: number;
  audience_30d: number;
  delivery_rate: number | null;
  read_rate: number | null;
  reply_rate: number | null;
  next_scheduled: { id: number; name: string; scheduled_at: string } | null;
}

export interface CampaignListResponse {
  data: Campaign[];
  meta: PageMeta;
  summary: CampaignSummary;
}

export interface TemplateSlot {
  key: string;
  section: 'header' | 'body' | 'button';
  pos: number;
  label: string;
  var_key: string;
  sample: string;
  kind: 'text' | 'media' | 'url' | 'code';
}

export interface CampaignTemplate {
  id: number;
  name: string;
  display_name: string | null;
  description?: string | null;
  category: WaTemplateCategory;
  language: string;
  header_type: WaHeaderType;
  header_text: string | null;
  header_media_url: string | null;
  body: string;
  footer: string | null;
  buttons: WaTemplateButton[];
  variables: WaTemplateVariables | Record<string, never>;
  usage_count?: number;
  last_used_at?: string | null;
  status?: string;
  slots: TemplateSlot[];
}

export interface FacetOption {
  value: string | number;
  label: string;
  sub?: string | null;
  color?: string;
}

export interface CampaignMeta {
  types: { value: string; label: string; category: WaTemplateCategory; description: string }[];
  statuses: { value: CampaignStatus; label: string }[];
  skip_reasons: { value: string; label: string }[];
  templates: CampaignTemplate[];
  facets: {
    types: FacetOption[];
    stages: FacetOption[];
    programs: FacetOption[];
    cities: FacetOption[];
    states: FacetOption[];
    batches: FacetOption[];
    admission_statuses: FacetOption[];
    payment_statuses: FacetOption[];
    tags: { id: number; name: string; color: string | null }[];
  };
  segments: { id: number; name: string; description: string | null; contacts_count: number; opted_in_count: number }[];
  catalog: WaVariable[];
  /** Catalog keys resolved per recipient (contact / student / application / institute). */
  contact_keys: string[];
  mode: 'test' | 'live';
  rate_per_minute: number;
  business_hours: Record<string, [string, string] | null>;
  in_business_hours: boolean;
  max_audience: number;
  max_upload_rows: number;
  reply_window_hours: number;
  /** Recipient reports may be exported (export permission + Settings › Security "who can export"). */
  can_export: boolean;
  /** Phone numbers are masked for this user (Settings › Security). */
  phones_masked: boolean;
  /** The server can read / write .xlsx (PHP zip extension). */
  xlsx: boolean;
}

export interface UploadSummary {
  file_name: string;
  headers: string[];
  phone_column: number | null;
  name_column: number | null;
  consent_column: number | null;
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  duplicate_rows: number;
  consent_rows: number;
  sample: string[][];
  invalid_sample: { row: number; value: string; name?: string; reason: string }[];
  duplicate_sample: { row: number; phone: string; phone_display?: string; name?: string; first_row?: number }[];
  uploaded_at: string;
  updated_at: string | null;
}

export interface AudiencePreview {
  total: number;
  eligible: number;
  skipped: number;
  breakdown: { reason: string; label: string; count: number }[];
  by_type: { type: string; label: string; count: number }[];
  by_source: { filter: number; csv: number };
  sample: { name: string | null; phone: string | null; phone_display: string; contact_id: number | null; contact_type: string | null; source: 'filter' | 'csv' }[];
  category: WaTemplateCategory;
  template_chosen: boolean;
  opt_in_only: boolean;
  /** The saved segment was deleted: the filter part of the audience matches nobody. */
  segment_missing: boolean;
  capped: boolean;
  max: number;
  rate_per_minute: number;
  estimated_minutes: number;
  csv: UploadSummary | null;
}

export interface PersonalizeSample {
  recipient: { name: string | null; phone: string | null; phone_display: string | null; contact_type: string | null; source: string };
  values: Record<string, string>;
  missing: string[];
  preview: WaTemplatePreviewData;
}

export interface PersonalizePreview {
  samples: PersonalizeSample[];
  is_sample: boolean;
  errors: Record<string, string>;
  slots: TemplateSlot[];
}

export interface Readiness {
  ok: boolean;
  errors: { template?: string; audience?: string; personalise?: string; variables?: Record<string, string> };
  eligible: number;
  skipped: number;
  total: number;
  estimated_minutes: number;
  category: WaTemplateCategory;
}

export interface TimelineEntry {
  at: string;
  kind: string;
  title: string;
  by: string | null;
  detail: string | null;
}

export interface CampaignDetail extends Campaign {
  variable_map: VariableMap;
  template_full: (CampaignTemplate & { status: string }) | null;
  upload: UploadSummary | null;
  status_counts: { pending: number; queued: number };
  activity: { labels: string[]; buckets: string[]; granularity: '10min' | 'hour' | 'day'; series: Record<'sent' | 'delivered' | 'read' | 'failed' | 'replied', number[]> } | null;
  reasons: { failed: { reason: string; code: string | null; count: number }[]; skipped: { reason: string; label: string; count: number }[] };
  timeline: TimelineEntry[];
  rate_effective: number;
  in_business_hours: boolean;
  mode: 'test' | 'live';
  eta_minutes: number | null;
  readiness?: Readiness;
  cost: number | null;
}

export type RecipientStatus = 'pending' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped';

export interface CampaignRecipient {
  id: number;
  campaign_id: number;
  contact_id: number | null;
  phone: string;
  phone_display: string;
  valid_phone: boolean;
  name: string | null;
  source: 'filter' | 'csv';
  contact_type: string | null;
  status: RecipientStatus;
  error: string | null;
  skip_reason: string | null;
  attempts: number;
  queued_at: string | null;
  replied_at: string | null;
  message_id: number | null;
  created_at: string;
  message_status: string | null;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  failed_at: string | null;
  error_code: string | null;
  cost: string | null;
  conversation_id: number | null;
}

export interface RecipientCounts {
  all: number;
  pending: number;
  queued: number;
  sent: number;
  delivered: number;
  read: number;
  failed: number;
  skipped: number;
  replied: number;
}

export interface RecipientsResponse {
  data: CampaignRecipient[];
  meta: PageMeta;
  counts: RecipientCounts;
}

export interface TestSendResult {
  message_id: number;
  status: string;
  error: string | null;
  phone: string;
  mode: 'test' | 'live';
  preview: WaTemplatePreviewData;
}
