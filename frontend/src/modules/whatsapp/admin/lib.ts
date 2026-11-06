import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import type { WaCronInfo } from '../shared';

/**
 * Types + query hooks of the WhatsApp admin part (dashboard, reports, integrations, API & webhooks, team, settings).
 * Every key starts with 'wa' so useWaRefresh() from ../shared invalidates them too.
 */

/* ------------------------------------------------------------------ common */
export type Period = 'daily' | 'weekly' | 'monthly' | 'custom';
export interface Range {
  period: Period;
  bucket: 'day' | 'week' | 'month';
  label: string;
  days: number;
  from: string;
  to: string;
  labels: string[];
}
export interface Trend {
  value: string;
  dir: 'up' | 'down' | 'flat';
  positive: boolean;
}
export interface Paged<T> {
  rows: T[];
  pagination: { total: number; page: number; per_page: number; pages: number };
}

/* ------------------------------------------------------------------ dashboard */
export interface Kpi {
  value: number;
  trend?: Trend | null;
  rate?: number;
  new?: number;
  [k: string]: unknown;
}
export interface FunnelStage {
  key: string;
  label: string;
  current: number;
  reached: number;
  pct_of_total: number;
  pct_of_prev: number;
}
export interface Funnel {
  stages: FunnelStage[];
  total: number;
  lost: number;
  confirmed: number;
  conversion_rate: number;
}
export interface ActivityItem {
  id: string;
  kind: 'agent' | 'event' | 'inbound' | 'campaign';
  action: string;
  at: string;
  title: string;
  detail: string | null;
  link: string | null;
}
export interface Connection {
  mode: 'test' | 'live';
  mode_setting: 'test' | 'live';
  configured: boolean;
  is_dev: boolean;
  plan: string;
  rate_per_minute: number;
  business_number: string | null;
  business_name: string | null;
  last_webhook_at: string | null;
  last_webhook_simulated: boolean;
  webhooks_pending: number;
  worker: { healthy: boolean; status: 'ok' | 'stale' | 'never'; message: string };
  last_cron_at: string | null;
  /** Last opportunistic tick run while a WhatsApp page was open (never marks the cron healthy). */
  last_light_tick_at?: string | null;
  /** Seconds since that tick (server clock). */
  light_tick_age?: number | null;
  /** The cron is missing / stopped but messages still flow while WhatsApp pages are open (light tick ≤ 10 min ago). */
  pages_only?: boolean;
  queue: { pending: number; due: number; processing: number; failed_24h: number; sent_today: number };
  webhooks_24h: { total: number; failed: number; bad_signature: number };
  api_24h: { total: number; failed: number; avg_ms: number };
  last_test?: ConnectionTest | null;
  incoming_supported: boolean;
  /**
   * Core webhook health (live mode): ok | silent (sends continue but no Interakt webhook arrives — it may have been
   * auto-disabled) | bad_signature (webhooks fail the signature check) | never | n/a (test mode). null on older cores.
   */
  webhook_health?: WebhookHealth | null;
  webhook_health_note?: string | null;
  health: 'ok' | 'warning' | 'error';
  issues: string[];
}
export type WebhookHealth = 'ok' | 'silent' | 'bad_signature' | 'never' | 'n/a';
export interface ConnectionTest {
  ok: boolean;
  /** true in test mode: Interakt was not contacted (nothing faked) */
  skipped?: boolean;
  status: number;
  error: string | null;
  duration_ms: number;
  templates: number | null;
  at: string;
  mode: string;
}
export interface DashboardData {
  range: Range;
  kpis: Record<
    | 'contacts' | 'leads' | 'enquiries' | 'sent' | 'received' | 'delivered' | 'read' | 'failed' | 'conversations' | 'pending_replies'
    | 'admissions' | 'fee_notifications' | 'automations' | 'campaigns',
    Kpi
  >;
  charts: {
    labels: string[];
    delivery: { sent: number[]; delivered: number[]; read: number[]; failed: number[] };
    direction: { outbound: number[]; inbound: number[] };
    funnel: Funnel;
    campaigns: { id: number; name: string; status: string; type: string; sent: number; delivered: number; read: number; failed: number; read_rate: number; delivery_rate: number }[];
    events: { category: string; label: string; total: number; processed: number; failed: number; skipped: number }[];
  };
  activity: ActivityItem[];
  connection: Connection;
  automation: {
    workflows_active: number;
    events_active: number;
    runs_today: number;
    failed_today: number;
    in_progress: number;
    runs_window: number;
    completion_rate: number;
    failed_window: number;
    events_today: number;
    events_failed_today: number;
    followups_scheduled: number;
    followups_due: number;
    followups_sent_today: number;
    paused: boolean;
    workflows: { id: number; name: string; runs: number; runs_today: number; completed: number; failed: number }[];
  };
}

export function useWaDashboard(q: Query) {
  return useQuery({
    queryKey: ['wa', 'dashboard', q],
    queryFn: ({ signal }) => api.get<DashboardData>('whatsapp/dashboard', q, { signal, background: true }),
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
    placeholderData: keepPreviousData,
  });
}

/* ------------------------------------------------------------------ reports */
export type ColFormat = 'text' | 'number' | 'percent' | 'money' | 'date' | 'datetime' | 'badge' | 'status' | 'code' | 'link' | 'person' | 'truncate' | 'decimal';
export interface ReportColumn {
  key: string;
  label: string;
  format: ColFormat;
  link?: string;
  sub?: string;
}
export interface ReportTable {
  key: string;
  title: string;
  columns: ReportColumn[];
  rows: Record<string, unknown>[];
}
export interface ReportSummary {
  label: string;
  value: number;
  format: 'number' | 'percent' | 'money' | 'minutes';
  tone: string;
  hint?: string;
}
export type ReportChart =
  | { type: 'line' | 'bar'; title: string; labels: string[]; series: { label: string; data: number[]; color?: string; dashed?: boolean; fill?: boolean }[]; percent?: boolean; horizontal?: boolean; stacked?: boolean }
  | { type: 'doughnut'; title: string; labels: string[]; data: number[]; colors?: string[] }
  | { type: 'funnel'; title: string; stages: FunnelStage[] };
export interface ReportData {
  key: string;
  title: string;
  description: string;
  range: Range;
  summary: ReportSummary[];
  charts: ReportChart[];
  tables: ReportTable[];
}
export interface Opt {
  value: string | number;
  label: string;
}
export interface ReportMeta {
  reports: { key: string; title: string; description: string }[];
  programs: Opt[];
  sources: Opt[];
  sender_types: Opt[];
  categories: Opt[];
  contact_types: Opt[];
  can_export: boolean;
}

export function useReportMeta() {
  return useQuery({ queryKey: ['wa', 'reports', 'meta'], queryFn: ({ signal }) => api.get<ReportMeta>('whatsapp/reports/meta', undefined, { signal }), staleTime: 5 * 60_000 });
}

export function useReport(key: string, q: Query) {
  return useQuery({
    queryKey: ['wa', 'reports', key, q],
    queryFn: ({ signal }) => api.get<ReportData>(`whatsapp/reports/${key}`, q, { signal }),
    placeholderData: keepPreviousData,
  });
}

/* ------------------------------------------------------------------ integration */
export interface ChecklistItem {
  type: string;
  label: string;
  required: boolean;
  note: string;
  plan?: string;
  last_received_at: string | null;
}
export interface IntegrationInfo extends Connection {
  base_url: string;
  key_set: boolean;
  key_last4: string | null;
  key_saved_at: string | null;
  secret_set: boolean;
  secret_last4: string | null;
  webhook_url: string;
  webhook_https: boolean;
  checklist: ChecklistItem[];
  templates: { approved: number; pending: number; rejected: number; draft: number; last_sync_at: string | null };
  plans: { value: string; label: string; rate: number; limit: number | null; incoming: boolean }[];
  allow_simulate_live: boolean;
  cron?: WaCronInfo;
}

export function useIntegration() {
  return useQuery({ queryKey: ['wa', 'admin', 'integration'], queryFn: ({ signal }) => api.get<IntegrationInfo>('whatsapp/integration', undefined, { signal }) });
}

/* ------------------------------------------------------------------ logs */
export interface WebhookLog {
  id: number;
  event_type: string | null;
  signature_valid: boolean | null;
  interakt_message_id: string | null;
  status: 'received' | 'processed' | 'ignored' | 'failed';
  error: string | null;
  is_simulated: boolean;
  received_at: string;
  processed_at: string | null;
  size: number;
  /**
   * Only rows whose origin is proven (signature verified, or simulated by GIMT) can be re-run — unsigned rows may be
   * forged. null in the list for very large payloads (the drawer decides). reprocess_reason explains the decision.
   */
  can_reprocess: boolean | null;
  reprocess_reason: string | null;
}
export interface WebhookLogDetail extends Omit<WebhookLog, 'size'> {
  headers: Record<string, string>;
  payload: unknown;
  message: { id: number; conversation_id: number; status: string; type: string; template_name: string | null } | null;
}
export interface ApiLog {
  id: number;
  endpoint: string;
  method: string;
  http_status: number | null;
  ok: boolean;
  duration_ms: number | null;
  message_id: number | null;
  mode: 'live' | 'test';
  error: string | null;
  created_at: string;
}
export interface ApiLogDetail extends ApiLog {
  request: unknown;
  response: unknown;
}
/** Retry-queue row, keyed by the message id (id === message_id). Failed rows may have no outbox row (outbox_id null). */
export interface QueueRow {
  id: number;
  message_id: number;
  outbox_id: number | null;
  outbox_status: string | null;
  retryable: boolean;
  status: 'pending' | 'processing' | 'done' | 'failed' | 'cancelled';
  priority: number;
  attempts: number;
  max_attempts: number;
  scheduled_at: string;
  locked_at: string | null;
  last_error: string | null;
  created_at: string;
  updated_at: string | null;
  type: string;
  template_name: string | null;
  sender_type: string;
  message_status: string;
  error_code: string | null;
  conversation_id: number;
  campaign_id: number | null;
  contact_id: number;
  contact_name: string | null;
  phone: string;
}
export interface ErrorSummary {
  days: number;
  labels: string[];
  api: { total: number; failed: number; rate: number; series: number[] };
  messages: { total: number; failed: number; rate: number; series: number[] };
  webhooks: { total: number; failed: number; rate: number; series: number[] };
  outbox: Record<string, number>;
  top_messages: { code: string | null; error: string; count: number; last_at: string }[];
  top_api: { endpoint: string; http_status: number | null; error: string; count: number; last_at: string }[];
  top_webhooks: { event_type: string; error: string; count: number; last_at: string }[];
}
export interface TrackHit {
  id: number;
  interakt_id: string | null;
  direction: 'in' | 'out';
  type: string;
  status: string;
  sender_type: string;
  template_name: string | null;
  preview: string;
  created_at: string;
  conversation_id: number;
  contact_id: number;
  contact_name: string | null;
  phone: string;
  error: string | null;
}
export interface TrackDetail {
  message: {
    id: number;
    interakt_id: string | null;
    direction: 'in' | 'out';
    type: string;
    status: string;
    sender_type: string;
    sender_name: string | null;
    template_name: string | null;
    body: string | null;
    error: string | null;
    error_code: string | null;
    cost: number | null;
    created_at: string;
    conversation_id: number;
    contact_id: number;
    contact_name: string | null;
    phone: string;
    campaign_id: number | null;
    workflow_run_id: number | null;
    event_log_id: number | null;
  };
  steps: { key: string; label: string; at: string | null; done: boolean; error?: string | null }[];
  outbox: { id: number; status: string; priority: number; attempts: number; max_attempts: number; scheduled_at: string; locked_at: string | null; last_error: string | null; created_at: string; updated_at: string | null } | null;
  api_calls: { id: number; endpoint: string; method: string; http_status: number | null; ok: boolean; duration_ms: number | null; mode: string; error: string | null; created_at: string }[];
  webhooks: { id: number; event_type: string; status: string; signature_valid: boolean | null; is_simulated: boolean; error: string | null; received_at: string }[];
}

/* ------------------------------------------------------------------ tokens */
export interface ApiToken {
  id: number;
  name: string;
  last4: string;
  scopes: string[];
  last_used_at: string | null;
  last_used_ip: string | null;
  created_at: string;
  created_by_name: string | null;
  revoked_at: string | null;
  active: boolean;
  events: number;
}

/* ------------------------------------------------------------------ team */
export interface TeamMember {
  id: number;
  name: string;
  email: string;
  avatar: string | null;
  designation: string | null;
  last_login_at: string | null;
  open: number;
  waiting: number;
  leads: number;
  replies: number;
  replies_today: number;
  conversations: number;
  resolved: number;
  actions: number;
  first_responses: number;
  avg_first_response_sec: number | null;
  median_first_response_sec: number | null;
  last_activity_at: string | null;
  roles: { id: number; name: string; color: string | null; is_super: boolean }[];
  is_super: boolean;
  permissions: 'all' | Record<string, string[]>;
  level: 'admin' | 'agent' | 'viewer';
}
export interface TeamData {
  members: TeamMember[];
  summary: { members: number; agents: number; online_today: number; open: number; unassigned: number; replies_today: number };
  can_manage_users: boolean;
  can_manage_roles: boolean;
}
export interface MatrixData {
  modules: { key: string; label: string; actions: string[] }[];
  actions: Record<string, { label: string; description: string }>;
  roles: { id: number; name: string; slug: string; color: string | null; description: string | null; is_super: boolean; is_system: boolean; users: number; permissions: string[] }[];
  can_edit: boolean;
  is_super: boolean;
}
export interface TeamActivity {
  uid: string;
  created_at: string;
  user_id: number | null;
  user_name: string | null;
  avatar: string | null;
  source: 'inbox' | 'audit';
  action: string;
  description: string | null;
  module: string;
  module_label: string;
  conversation_id: number | null;
  contact_id: number | null;
  contact_name: string | null;
}

/* ------------------------------------------------------------------ settings */
export interface DayHours {
  open: string;
  close: string;
  closed: boolean;
}
export interface SettingsData {
  profile: { wa_business_name: string; wa_business_number: string; wa_default_language: string; wa_brochure_url?: string; institute_name: string; logo_url: string };
  hours: { wa_business_hours: Record<string, DayHours>; timezone: string; in_business_hours: boolean };
  notifications: { wa_notify_new_message: boolean; wa_notify_assignment: boolean; wa_notify_handover: boolean; wa_daily_digest: boolean; wa_digest_time: string; last_digest_at: string | null };
  automation: {
    wa_automation_paused: boolean;
    wa_quiet_enabled: boolean;
    wa_quiet_start: string;
    wa_quiet_end: string;
    wa_max_per_contact_day: number;
    wa_max_attempts: number;
    wa_auto_sync_contacts: boolean;
    wa_optout_reply: string;
    wa_optin_reply: string;
  };
  webhook: { secret_set: boolean; secret_last4: string | null; wa_allow_simulate_live: boolean; webhook_url: string };
  retention: { wa_retention_days: number; last_purge_at: string | null };
  security: { wa_export_scope: 'permission' | 'managers'; wa_mask_phones: boolean };
  can: { edit: boolean; manage: boolean };
}

export function useWaSettings() {
  return useQuery({ queryKey: ['wa', 'admin', 'settings'], queryFn: ({ signal }) => api.get<SettingsData>('whatsapp/settings', undefined, { signal }) });
}

/* ------------------------------------------------------------------ docs */
export interface DocsData {
  base_url: string;
  ingest_event_url: string;
  ingest_track_url: string;
  webhook_url: string;
  events: { code: string; name: string; category: string; source: string; active: boolean; sample_payload: Record<string, unknown> | null }[];
  sample_event: DocsData['events'][number] | null;
  track_event: DocsData['events'][number] | null;
  scopes: Record<string, { label: string; description: string }>;
  webhook_samples: Record<string, unknown>;
  signature: string;
}

/**
 * Honest background-worker state for the dashboard card and the Integrations page. When the cron has not run (or
 * stopped) but light ticks still process messages while WhatsApp pages are open, say so instead of "Never ran".
 *   short:  'Running' | 'Pages only' | 'Never ran' | 'Stale'   (status strips)
 *   title:  'Running' | 'Cron not set up' | 'Cron stopped' | 'Never ran' | 'Stopped'
 *   detail: one line, e.g. "Cron not set up — messages are processed only while WhatsApp pages are open (last 20 s ago)"
 *   note:   the same without the title (for places that show the title above it)
 */
export function workerState(c: Pick<Connection, 'worker' | 'last_cron_at' | 'last_light_tick_at' | 'light_tick_age' | 'pages_only'>): {
  state: 'ok' | 'warning' | 'error';
  short: string;
  title: string;
  detail: string;
  note: string;
} {
  if (c.worker.healthy) return { state: 'ok', short: 'Running', title: 'Running', detail: c.worker.message, note: c.worker.message };
  const never = c.worker.status === 'never';
  if (c.pages_only) {
    const age = c.light_tick_age;
    const ago = age === null || age === undefined ? 'recently' : `${formatSeconds(age)} ago`;
    const title = never ? 'Cron not set up' : 'Cron stopped';
    const note = `messages are processed only while WhatsApp pages are open (last ${ago}).`;
    return { state: 'warning', short: 'Pages only', title, detail: `${title} — ${note}`, note: note.charAt(0).toUpperCase() + note.slice(1) };
  }
  return never
    ? { state: 'error', short: 'Never ran', title: 'Never ran', detail: c.worker.message, note: c.worker.message }
    : { state: 'warning', short: 'Stale', title: 'Stopped', detail: c.worker.message, note: c.worker.message };
}

/** Seconds → "45 s" / "12 min" / "3.5 h" / "2.1 days". */
export function formatSeconds(sec: number | null | undefined): string {
  if (sec === null || sec === undefined) return '—';
  if (sec < 60) return `${Math.round(sec)} s`;
  if (sec < 3600) return `${Math.round(sec / 60)} min`;
  if (sec < 86400) return `${(sec / 3600).toFixed(1).replace(/\.0$/, '')} h`;
  return `${(sec / 86400).toFixed(1).replace(/\.0$/, '')} days`;
}
