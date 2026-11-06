/** Event Manager types (api/routes/whatsapp/events.php, app/services/whatsapp_events.php). */

export type WaEventOp = '=' | '!=' | '>' | '<' | '>=' | '<=' | 'contains' | 'not_contains' | 'in' | 'not_in' | 'empty' | 'not_empty';

export interface WaEventCondition {
  field: string;
  op: WaEventOp | string;
  value: string;
}

export interface WaEventSchedule {
  days?: number[];
  time?: string;
  minutes?: number;
}

export interface WaEvent {
  id: number;
  code: string;
  name: string;
  description: string | null;
  category: string;
  source: string;
  conditions: WaEventCondition[];
  template_id: number | null;
  recipient: string;
  recipient_field: string | null;
  /** PHP sends [] for an empty map */
  variable_map: Record<string, string> | [];
  delay_minutes: number;
  respect_business_hours: boolean;
  schedule_config: WaEventSchedule | [];
  is_active: boolean;
  is_system: boolean;
  sample_payload: Record<string, unknown> | [];
  last_executed_at: string | null;
  last_scan_at?: string | null;
  execution_count: number;
  created_at: string;
  updated_at: string | null;
  template_name?: string | null;
  template_display?: string | null;
  template_status?: string | null;
  template_category?: string | null;
  suggested_template?: { id: number; name: string; status: string } | null;
  template_convention: string;
  is_scheduled: boolean;
  fired_30d?: number;
  failed_30d?: number;
  messages_30d?: number;
  /** Active workflows started by the same code that also send a template (list: only present when there is one). */
  workflow_overlaps?: WaEventWorkflowOverlap[];
}

/** An active automation workflow triggered by the same event that sends WhatsApp templates (duplicate-message warning). */
export interface WaEventWorkflowOverlap {
  id: number;
  name: string;
  templates: string[];
  /** The workflow sends the event's own template (the contact would get the same message twice). */
  same_template: boolean;
}

/** Event returned by save / toggle / create: `warning` explains an overlap with an active workflow (never blocks). */
export interface WaEventSaved extends WaEvent {
  warning?: string | null;
}

export interface WaEventTemplateVar {
  key: string;
  part: 'header' | 'body' | 'button';
  pos: number;
  sample: string;
}

export interface WaEventDetail extends WaEvent {
  template: { id: number; name: string; display_name: string; status: string | null; category: string | null; body: string; linked: boolean } | null;
  template_variables: WaEventTemplateVar[];
  payload_keys: string[];
  schedule: WaEventSchedule | null;
  workflow_overlaps: WaEventWorkflowOverlap[];
}

export interface WaEventSummary {
  events: number;
  active: number;
  custom: number;
  no_template: number;
  fired_today: number;
  fired_7d: number;
  failed_7d: number;
  duplicates_7d: number;
  skipped_7d: number;
  messages_7d: number;
  last_scan: string | null;
}

/** A bulk event (one event per student / enquiry / ticket) expanded by the worker in the background. */
export interface WaEventFanout {
  id: number;
  event_code: string;
  codes: string[];
  id_key: string;
  total: number;
  processed: number;
  fired: number;
  duplicates: number;
  status: 'pending' | 'running' | 'done' | 'failed' | 'cancelled';
  error: string | null;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
  event_id: number | null;
  event_name: string | null;
  created_by_name: string | null;
}

export interface WaEventListResponse {
  events: WaEvent[];
  summary: WaEventSummary;
  categories: Record<string, string>;
  fanouts?: WaEventFanout[];
}

export interface WaEventTemplateOption {
  id: number;
  name: string;
  display_name: string;
  category: string;
  language: string;
  status: string;
  body: string;
  variables: WaEventTemplateVar[];
}

export interface WaEventOptions {
  categories: Record<string, string>;
  sources: Record<string, string>;
  recipients: Record<string, string>;
  operators: Record<string, string>;
  contact_fields: Record<string, string>;
  templates: WaEventTemplateOption[];
  variable_catalog: { key: string; label: string; sample: string }[];
  scheduled_codes: string[];
  ingest_url: string;
  track_url: string;
  can_see_tokens: boolean;
}

export interface WaEventOutcome {
  status: 'queued' | 'skipped' | 'failed' | null;
  reason: string | null;
  message_id: number | null;
  template: string | null;
  recipient: string | null;
  scheduled_at: string | null;
  stage: string | null;
  /** wa_send() result status: 'duplicate_recent' = the same template + text reached the contact minutes earlier, nothing new queued */
  send_status?: string | null;
  /** id of that earlier message (send_status duplicate_recent) */
  duplicate_of?: number | null;
  note?: string | null;
  /** list rows only: the earlier message (chat to open) */
  earlier_message?: WaEventEarlierMessage | null;
}

export interface WaEventEarlierMessage {
  id: number;
  conversation_id: number | null;
  status: string;
  sender_type: string;
  created_at: string;
}

export interface WaEventLog {
  id: number;
  event_code: string;
  source: string | null;
  status: 'received' | 'processed' | 'skipped' | 'duplicate' | 'failed';
  error: string | null;
  dedupe_key: string | null;
  contact_id: number | null;
  actions: unknown;
  created_at: string;
  processed_at: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  event_id: number | null;
  event_name: string | null;
  category?: string | null;
  outcome: WaEventOutcome | null;
}

export interface WaEventLogMessage {
  id: number;
  contact_id: number;
  type: string;
  template_name: string | null;
  status: string;
  error: string | null;
  body: string | null;
  created_at: string;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
}

export interface WaEventLogDetail extends Omit<WaEventLog, 'outcome'> {
  payload: Record<string, unknown> | unknown[];
  messages: WaEventLogMessage[];
  /** The earlier identical message when the repeat guard did not send this one again (send_status duplicate_recent). */
  duplicate_message?: (WaEventLogMessage & { conversation_id: number | null; sender_type: string }) | null;
}

export interface WaEventLogsPage {
  items: WaEventLog[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

export interface WaEventStats {
  days: number;
  trend: { labels: string[]; fired: number[] };
  statuses: Record<string, number>;
  messages: Record<string, number>;
  total: number;
}

export interface WaEventTestResult {
  log_id: number | null;
  log: WaEventLogDetail | null;
  outcome: WaEventOutcome | null;
}
