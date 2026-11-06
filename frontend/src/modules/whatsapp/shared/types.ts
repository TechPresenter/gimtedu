/**
 * WhatsApp module types — mirror database/schema/48_whatsapp.sql (rows as returned by the PHP API: INT columns are
 * numbers, DECIMAL columns strings, DATETIME columns 'Y-m-d H:i:s' strings in Asia/Kolkata, JSON LONGTEXT columns
 * decoded by the endpoints that return them).
 */

export type WaDirection = 'in' | 'out';

export type WaMessageType =
  | 'text'
  | 'image'
  | 'video'
  | 'document'
  | 'audio'
  | 'sticker'
  | 'location'
  | 'contacts'
  | 'interactive_button'
  | 'interactive_list'
  | 'button_reply'
  | 'list_reply'
  | 'template'
  | 'system';

export type WaMessageStatus = 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'received' | 'simulated' | 'cancelled';

export type WaSenderType = 'contact' | 'agent' | 'bot' | 'automation' | 'event' | 'campaign' | 'followup' | 'system';

export type WaContactType = 'lead' | 'applicant' | 'student' | 'guardian' | 'alumni' | 'staff' | 'other';

export type WaStageKey =
  | 'new_enquiry'
  | 'contacted'
  | 'interested'
  | 'counselling_scheduled'
  | 'application_started'
  | 'application_submitted'
  | 'payment_pending'
  | 'admission_confirmed'
  | 'not_interested'
  | (string & {});

export type WaMode = 'test' | 'live';

/* ------------------------------------------------------------------ contacts / CRM */

export interface WaTag {
  id: number;
  name: string;
  color: string | null;
  created_at?: string;
  contacts_count?: number;
}

export interface WaContact {
  id: number;
  phone: string;
  country_code: string;
  national_number: string;
  name: string | null;
  email: string | null;
  alt_phone: string | null;
  contact_type: WaContactType;
  student_id: number | null;
  enquiry_id: number | null;
  admission_id: number | null;
  user_id: number | null;
  application_no: string | null;
  program_id: number | null;
  program_name: string | null;
  batch: string | null;
  city: string | null;
  state: string | null;
  stage: WaStageKey;
  stage_changed_at: string | null;
  admission_status: string | null;
  payment_status: string | null;
  counsellor_id: number | null;
  counsellor_name?: string | null;
  language: 'en' | 'hi' | (string & {});
  opt_in: 0 | 1 | boolean;
  opt_in_at: string | null;
  opt_in_source: string | null;
  opted_out_at: string | null;
  source: string | null;
  status: 'active' | 'blocked' | 'archived';
  interakt_user_id: string | null;
  interakt_synced_at: string | null;
  last_inbound_at: string | null;
  last_outbound_at: string | null;
  last_message_at: string | null;
  notes: string | null;
  created_by: number | null;
  created_at: string;
  updated_at: string | null;
  tags?: WaTag[];
  /** Computed by some endpoints. */
  window_open?: boolean;
  window_expires_at?: string | null;
}

/* ------------------------------------------------------------------ inbox */

export interface WaConversation {
  id: number;
  contact_id: number;
  status: 'open' | 'pending' | 'resolved';
  assigned_to: number | null;
  assigned_name?: string | null;
  unread_count: number;
  last_message_at: string | null;
  last_message_preview: string | null;
  last_message_direction: WaDirection | null;
  last_inbound_at: string | null;
  bot_active: 0 | 1 | boolean;
  bot_handover_at: string | null;
  resolved_at: string | null;
  resolved_by: number | null;
  created_at: string;
  updated_at: string | null;
  /** Joined contact fields (inbox list). */
  contact?: Partial<WaContact>;
  name?: string | null;
  phone?: string;
}

export interface WaInteractiveButton {
  id?: string;
  title: string;
}

export interface WaInteractiveListRow {
  id: string;
  title: string;
  description?: string;
}

export interface WaInteractiveListSection {
  title?: string;
  rows: WaInteractiveListRow[];
}

/** wa_messages.interactive (decoded). Shape depends on the message type. */
export interface WaInteractive {
  /** interactive_button: reply buttons. */
  buttons?: WaInteractiveButton[];
  /** interactive_list: button label + sections. */
  button?: string;
  sections?: WaInteractiveListSection[];
  header?: string;
  footer?: string;
  /** button_reply / list_reply (inbound). */
  reply?: { id?: string; title?: string; description?: string; payload?: string };
  /** location (inbound). */
  latitude?: number;
  longitude?: number;
  name?: string;
  address?: string;
  /** Template rendering snapshot (outbound template messages). */
  template?: WaTemplatePreviewData;
  [key: string]: unknown;
}

export interface WaTemplateVars {
  header?: string[];
  body?: string[];
  buttons?: Record<string, string[]>;
  payloads?: Record<string, string[]>;
  file_name?: string | null;
  named?: Record<string, string>;
}

export interface WaMessage {
  id: number;
  conversation_id: number;
  contact_id: number;
  direction: WaDirection;
  type: WaMessageType;
  body: string | null;
  media_url: string | null;
  media_local: string | null;
  media_mime: string | null;
  file_name: string | null;
  interactive: WaInteractive | string | null;
  template_id: number | null;
  template_name: string | null;
  template_vars: WaTemplateVars | string | null;
  interakt_id: string | null;
  callback_data: string | null;
  status: WaMessageStatus;
  error_code: string | null;
  error: string | null;
  cost: string | null;
  sender_type: WaSenderType;
  sent_by: number | null;
  sent_by_name?: string | null;
  campaign_id: number | null;
  workflow_run_id: number | null;
  event_log_id: number | null;
  followup_id: number | null;
  reply_to_id: number | null;
  created_at: string;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  failed_at: string | null;
  /** Same-origin URL that streams inbound media (GET whatsapp/media/{id}). */
  media_proxy_url?: string | null;
}

export interface WaNote {
  id: number;
  conversation_id: number | null;
  contact_id: number;
  user_id: number | null;
  user_name?: string | null;
  body: string;
  created_at: string;
}

export interface WaQuickReply {
  id: number;
  shortcut: string;
  title: string;
  body: string;
  category: string | null;
  usage_count: number;
  created_by: number | null;
  created_at: string;
  updated_at: string | null;
}

export interface WaAgentActivity {
  id: number;
  user_id: number | null;
  user_name?: string | null;
  conversation_id: number | null;
  contact_id: number | null;
  action: string;
  detail: string | null;
  created_at: string;
}

/* ------------------------------------------------------------------ templates */

export type WaTemplateCategory = 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
export type WaTemplateStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'paused' | 'disabled' | 'archived';
export type WaHeaderType = 'none' | 'text' | 'image' | 'video' | 'document';

export interface WaTemplateButton {
  type: 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER' | 'COPY_CODE' | 'OTP';
  text: string;
  url?: string;
  phone_number?: string;
  example?: string;
}

export interface WaTemplateVarRef {
  pos: number;
  key: string;
  sample?: string;
}

export interface WaTemplateVariables {
  header?: WaTemplateVarRef[];
  body?: WaTemplateVarRef[];
  buttons?: Record<string, { key: string; sample?: string }>;
}

export interface WaTemplate {
  id: number;
  name: string;
  display_name: string | null;
  category: WaTemplateCategory;
  language: string;
  header_type: WaHeaderType;
  header_text: string | null;
  header_media_url: string | null;
  header_handle: string | null;
  body: string;
  footer: string | null;
  buttons: WaTemplateButton[] | null;
  variables: WaTemplateVariables | null;
  otp_settings: { code_expiration_minutes?: number; add_security_recommendation?: boolean; otp_type?: string } | null;
  status: WaTemplateStatus;
  interakt_id: string | null;
  wa_template_id: string | null;
  rejection_reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  synced_at: string | null;
  usage_count: number;
  last_used_at: string | null;
  is_system: 0 | 1 | boolean;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string | null;
}

/** Data needed to draw a template inside a chat bubble / phone frame. */
export interface WaTemplatePreviewData {
  header_type?: WaHeaderType;
  header_text?: string | null;
  header_media_url?: string | null;
  file_name?: string | null;
  body: string;
  footer?: string | null;
  buttons?: WaTemplateButton[] | null;
}

/** POST-render shape returned by wa_render_template(). */
export interface WaRenderedTemplate {
  header: string[];
  body: string[];
  buttons: Record<string, string[]>;
  text: string;
  missing: string[];
}

/* ------------------------------------------------------------------ events */

export interface WaEventCondition {
  field: string;
  op: string;
  value: unknown;
}

export interface WaEvent {
  id: number;
  code: string;
  name: string;
  description: string | null;
  category: string;
  source: string;
  conditions: WaEventCondition[] | null;
  template_id: number | null;
  template_name?: string | null;
  recipient: 'contact' | 'student' | 'guardian' | 'counsellor' | 'custom_field';
  recipient_field: string | null;
  variable_map: Record<string, string> | null;
  delay_minutes: number;
  respect_business_hours: 0 | 1 | boolean;
  is_active: 0 | 1 | boolean;
  is_system: 0 | 1 | boolean;
  sample_payload: Record<string, unknown> | null;
  last_executed_at: string | null;
  execution_count: number;
  created_by: number | null;
  created_at: string;
  updated_at: string | null;
}

export interface WaEventLog {
  id: number;
  event_code: string;
  source: string | null;
  payload: Record<string, unknown> | null;
  dedupe_key: string | null;
  contact_id: number | null;
  status: 'received' | 'processed' | 'skipped' | 'duplicate' | 'failed';
  actions: unknown[] | null;
  error: string | null;
  created_at: string;
  processed_at: string | null;
}

/* ------------------------------------------------------------------ workflows */

export interface WaWorkflowNode {
  id: string;
  type: string;
  x: number;
  y: number;
  data: Record<string, unknown>;
}

export interface WaWorkflowEdge {
  from: string;
  to: string;
  label?: string;
}

export interface WaWorkflow {
  id: number;
  name: string;
  description: string | null;
  trigger_type: 'event' | 'manual' | 'stage' | 'schedule';
  trigger_event: string | null;
  trigger_config: Record<string, unknown> | null;
  definition: { nodes: WaWorkflowNode[]; edges: WaWorkflowEdge[] } | null;
  status: 'draft' | 'active' | 'inactive';
  business_hours_only: 0 | 1 | boolean;
  allow_reentry: 0 | 1 | boolean;
  version: number;
  runs_total: number;
  runs_completed: number;
  runs_failed: number;
  last_run_at: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string | null;
}

export interface WaWorkflowRun {
  id: number;
  workflow_id: number;
  contact_id: number | null;
  event_log_id: number | null;
  status: 'running' | 'waiting' | 'completed' | 'failed' | 'cancelled';
  current_node: string | null;
  context: Record<string, unknown> | null;
  next_run_at: string | null;
  waiting_for: 'delay' | 'reply' | 'business_hours' | null;
  attempts: number;
  error: string | null;
  dedupe_key: string | null;
  is_test: 0 | 1 | boolean;
  started_at: string;
  finished_at: string | null;
  updated_at: string | null;
}

export interface WaWorkflowStep {
  id: number;
  run_id: number;
  node_id: string;
  node_type: string;
  status: 'ok' | 'waiting' | 'skipped' | 'failed';
  output: Record<string, unknown> | null;
  error: string | null;
  created_at: string;
}

/* ------------------------------------------------------------------ follow-ups */

export interface WaFollowupStage {
  id: number;
  stage_key: WaStageKey;
  name: string;
  sort_order: number;
  color: string | null;
  template_id: number | null;
  delay_hours: number;
  max_followups: number;
  counsellor_id: number | null;
  next_action: string | null;
  stop_conditions: { on_reply?: boolean; on_stage_change?: boolean; on_opt_out?: boolean } | null;
  is_active: 0 | 1 | boolean;
  is_terminal: 0 | 1 | boolean;
  created_at: string;
  updated_at: string | null;
}

export interface WaFollowup {
  id: number;
  contact_id: number;
  stage_key: WaStageKey;
  attempt: number;
  template_id: number | null;
  due_at: string;
  status: 'scheduled' | 'sent' | 'skipped' | 'cancelled' | 'failed';
  message_id: number | null;
  note: string | null;
  created_at: string;
  updated_at: string | null;
}

/* ------------------------------------------------------------------ campaigns */

export type WaCampaignStatus = 'draft' | 'scheduled' | 'sending' | 'completed' | 'paused' | 'cancelled' | 'failed';

export interface WaCampaignAudience {
  programs?: number[];
  stages?: string[];
  admission_status?: string[];
  cities?: string[];
  batches?: string[];
  tags?: number[] | string[];
  contact_types?: string[];
  opt_in_only?: boolean;
}

export interface WaCampaign {
  id: number;
  name: string;
  type: string;
  template_id: number | null;
  template_name?: string | null;
  audience: WaCampaignAudience | null;
  audience_source: 'filters' | 'csv' | 'mixed';
  variable_map: Record<string, string> | null;
  scheduled_at: string | null;
  status: WaCampaignStatus;
  total_recipients: number;
  sent_count: number;
  delivered_count: number;
  read_count: number;
  failed_count: number;
  replied_count: number;
  skipped_count: number;
  interakt_campaign_id: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_by: number | null;
  created_at: string;
  updated_at: string | null;
}

export interface WaCampaignRecipient {
  id: number;
  campaign_id: number;
  contact_id: number | null;
  phone: string;
  name: string | null;
  variables: Record<string, unknown> | null;
  message_id: number | null;
  status: 'pending' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped';
  error: string | null;
  created_at: string;
  updated_at: string | null;
}

/* ------------------------------------------------------------------ bot */

export interface WaBotSession {
  id: number;
  contact_id: number;
  language: string;
  state: 'active' | 'qualifying' | 'handed_over' | 'closed';
  qualification: Record<string, unknown> | null;
  summary: string | null;
  messages_count: number;
  handed_over_at: string | null;
  handover_reason: string | null;
  last_activity_at: string | null;
  created_at: string;
  updated_at: string | null;
}

/* ------------------------------------------------------------------ integration logs */

export interface WaWebhookLog {
  id: number;
  event_type: string | null;
  signature_valid: 0 | 1 | null;
  payload: string | null;
  headers: string | null;
  interakt_message_id: string | null;
  status: 'received' | 'processed' | 'ignored' | 'failed';
  error: string | null;
  is_simulated: 0 | 1 | boolean;
  received_at: string;
  processed_at: string | null;
}

export interface WaApiLog {
  id: number;
  endpoint: string;
  method: string;
  request: string | null;
  response: string | null;
  http_status: number | null;
  ok: 0 | 1 | boolean;
  duration_ms: number | null;
  message_id: number | null;
  mode: 'live' | 'test';
  error: string | null;
  created_at: string;
}

export interface WaApiToken {
  id: number;
  name: string;
  token_last4: string;
  scopes: string;
  last_used_at: string | null;
  last_used_ip: string | null;
  created_by: number | null;
  created_at: string;
  revoked_at: string | null;
}

/* ------------------------------------------------------------------ core endpoints */

export type WaWebhookHealth = 'ok' | 'silent' | 'bad_signature' | 'never' | 'n/a';

/** GET whatsapp/status */
export interface WaStatus {
  mode: WaMode;
  mode_setting: WaMode;
  configured: boolean;
  is_dev: boolean;
  plan: string;
  business_number: string | null;
  business_name: string | null;
  rate_per_minute: number;
  in_business_hours: boolean;
  last_cron_at: string | null;
  /** Last opportunistic tick from page polling (runs only while the cron is missing; does not count as the cron). */
  last_light_tick_at?: string | null;
  /** Settings › Automation: automated messages (automations, events, follow-ups, campaigns) are held in the queue. */
  automation_paused?: boolean;
  /** End of the current quiet-hours period (automated messages wait until then), or null. */
  quiet_until?: string | null;
  /** A webhook secret is saved (outside dev installs, unsigned webhooks are ignored in test and live mode). */
  webhook_secret_set?: boolean;
  last_webhook_at: string | null;
  last_webhook_simulated: boolean;
  /**
   * Live mode: 'silent' = messages went out but no signed webhook has arrived since (Interakt may have turned the
   * webhook off), 'bad_signature' = in the last hour at least 3 webhooks carried an Interakt signature that did not
   * verify, more than passed (unsigned requests never count — they only add a line to the note), 'never' = no signed
   * webhook yet. 'n/a' in test mode.
   */
  webhook_health?: WaWebhookHealth;
  /** Plain-English explanation of webhook_health with the fix. */
  webhook_health_note?: string;
  /** Last non-simulated webhook signed with the saved secret (live mode). */
  last_valid_webhook_at?: string | null;
  /** Settings managers only: rejected webhooks this hour, and how many of them were not stored (hourly cap). */
  webhooks_rejected_hour?: number;
  webhooks_dropped_hour?: number;
  queue: { pending: number; due: number; processing: number; failed_24h: number; sent_today: number };
  webhooks_pending: number;
  worker: { healthy: boolean; status: 'ok' | 'stale' | 'never'; message: string };
  cron?: WaCronInfo;
  webhook_url?: string;
}

export interface WaCronInfo {
  url: string;
  linux: string;
  windows: string;
  last_run: string | null;
  last_stats?: Record<string, unknown> | null;
}

export interface WaVariable {
  key: string;
  label: string;
  sample: string;
  group: string;
}

export interface WaSendResult {
  ok: boolean;
  message_id: number | null;
  status: WaMessageStatus | 'refused' | null;
  error: string | null;
  code: string | null;
  /** Set when the admin send gate delayed an automated message (still queued). */
  held?: 'paused' | 'quiet_hours' | 'daily_limit' | null;
  scheduled_at?: string;
}

export interface WaMediaUpload {
  url: string;
  path: string;
  mime: string;
  name: string;
  size: number;
  type: 'image' | 'video' | 'audio' | 'document' | 'sticker';
}

export interface WaWorkerStats {
  ok: boolean;
  skipped?: string;
  webhooks?: Record<string, number>;
  outbox?: Record<string, number>;
  /** Per tick handler, in run order (priority: events, workflows … crm_sync last). */
  handlers?: Record<string, unknown>;
  /** Handlers that overran their time slice: name → seconds taken. */
  overran?: Record<string, number>;
  purged?: Record<string, number>;
  duration_ms?: number;
}
