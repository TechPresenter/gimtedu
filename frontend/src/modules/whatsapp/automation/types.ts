/** Types of the WhatsApp automation builder (mirrors api/routes/whatsapp/workflows.php). */
import type { Option } from '@/lib/types';

export type NodeType =
  | 'trigger'
  | 'contact'
  | 'template'
  | 'message'
  | 'delay'
  | 'condition'
  | 'ifelse'
  | 'wait_reply'
  | 'tag'
  | 'stage'
  | 'assign'
  | 'api'
  | 'webhook'
  | 'end';

export type Port = 'next' | 'true' | 'false' | 'replied' | 'timeout';

export interface Rule {
  field: string;
  op: string;
  value?: string;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type NodeData = Record<string, any>;

export interface WfNode {
  id: string;
  type: NodeType;
  x: number;
  y: number;
  data: NodeData;
}

export interface WfEdge {
  from: string;
  to: string;
  label: Port;
}

export interface Definition {
  nodes: WfNode[];
  edges: WfEdge[];
}

export interface Issue {
  level: 'error' | 'warning';
  node: string | null;
  message: string;
  /** kind of issue (deadline, schedule, loop, ssrf, dns, secret…) */
  code?: string;
  /** only the server can check it (DNS resolution) — merged into the builder's own checks */
  server?: boolean;
}

export interface DeadlineInfo {
  valid: boolean;
  reason: 'missing' | 'invalid' | 'past' | null;
  value: string | null;
  date: string | null;
  where: string;
  url: string;
}

export interface RunnerLimits {
  max_node_visits: number;
  max_steps_per_run: number;
  max_runs_per_day: number;
  reentry_cooldown_minutes: number;
  max_chain: number;
  http_timeout: number;
  http_max_kb: number;
}

export type WorkflowStatus = 'draft' | 'active' | 'inactive';

export interface Workflow {
  id: number;
  name: string;
  description: string | null;
  trigger_type: string;
  trigger_event: string | null;
  status: WorkflowStatus;
  version: number;
  business_hours_only: boolean;
  allow_reentry: boolean;
  runs_total: number;
  runs_completed: number;
  runs_failed: number;
  last_run_at: string | null;
  created_at: string;
  updated_at: string | null;
  updated_by_name?: string | null;
  definition: Definition;
  issues?: Issue[];
  run_counts?: Record<string, number>;
}

export interface WorkflowRow {
  id: number;
  name: string;
  description: string | null;
  trigger_type: string;
  trigger_event: string | null;
  trigger_name: string;
  status: WorkflowStatus;
  version: number;
  runs_total: number;
  runs_completed: number;
  runs_failed: number;
  runs_active: number;
  last_run_at: string | null;
  business_hours_only: boolean;
  allow_reentry: boolean;
  created_at: string;
  updated_at: string | null;
  updated_by_name: string | null;
  node_count: number;
  node_types: Partial<Record<NodeType, number>>;
  /** active window of the trigger (workflow scheduling) */
  schedule: { from: string | null; until: string | null; state: 'open' | 'upcoming' | 'ended' } | null;
}

export interface Starter {
  key: string;
  name: string;
  description: string;
  trigger_event: string;
  trigger_name: string;
  node_count: number;
}

export interface WorkflowList {
  rows: WorkflowRow[];
  summary: { total: number; active: number; draft: number; inactive: number; runs_30d: number; completed_30d: number; failed_30d: number; in_progress: number };
  trend: { labels: string[]; started: number[]; completed: number[]; failed: number[] };
  starters: Starter[];
}

export interface MetaEvent {
  code: string;
  name: string;
  category: string;
  description: string | null;
  sample: Record<string, unknown>;
  internal: boolean;
  /** Event Manager: the event is switched on … */
  active?: boolean;
  /** … and sends this template itself (name / display name) — a workflow sending a template on it doubles up */
  template?: string | null;
  template_label?: string | null;
}

export interface MetaTemplateVar {
  key: string;
  part: 'header' | 'body' | 'button';
  pos: number;
  sample: string;
}

export interface MetaTemplate {
  id: number;
  name: string;
  display_name: string | null;
  category: string;
  language: string;
  status: string;
  header_type: string;
  header_text: string | null;
  body: string;
  footer: string | null;
  buttons: { type: string; text: string }[];
  variables: MetaTemplateVar[];
}

export interface ConditionField {
  value: string;
  label: string;
  group: string;
  kind: 'bool' | 'text' | 'stage' | 'tags';
}

export interface Meta {
  events: MetaEvent[];
  templates: MetaTemplate[];
  stages: Option[];
  lead_statuses: Option[];
  tags: { name: string; color: string | null }[];
  counsellors: Option[];
  variables: { key: string; label: string; sample: string }[];
  condition_fields: ConditionField[];
  condition_ops: Option[];
  mode: 'test' | 'live';
  business_hours: Record<string, string[]>;
  in_business_hours: boolean;
  deadline?: DeadlineInfo;
  limits?: RunnerLimits;
}

export interface WorkflowStats {
  days: number;
  runs: { total: number; completed: number; failed: number; cancelled: number; active: number; completion_rate: number | null; avg_minutes: number | null };
  messages: { queued: number; total: number; sent: number; delivered: number; read: number; failed: number };
  nodes: { id: string; type: NodeType; title: string; ok: number; skipped: number; failed: number; waiting: number; reached: number; branches: Record<string, number> }[];
}

export type RunStatus = 'running' | 'waiting' | 'completed' | 'failed' | 'cancelled';

export interface RunRow {
  id: number;
  workflow_id: number;
  workflow_name: string;
  contact_id: number | null;
  contact_name: string | null;
  contact_phone: string | null;
  status: RunStatus;
  current_node: string | null;
  current_node_label: string | null;
  current_node_type: NodeType | null;
  waiting_for: 'delay' | 'reply' | 'business_hours' | null;
  next_run_at: string | null;
  attempts: number;
  error: string | null;
  is_test: boolean;
  started_at: string;
  finished_at: string | null;
  steps_count: number;
  messages_count: number;
}

export interface RunList {
  rows: RunRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  counts: Partial<Record<RunStatus, number>>;
}

export interface Step {
  id: number;
  node_id: string;
  node_type: string;
  status: 'ok' | 'waiting' | 'skipped' | 'failed';
  title: string;
  summary: string | null;
  output: Record<string, unknown>;
  error: string | null;
  created_at: string;
}

export interface RunDetail {
  run: RunRow & { workflow_version: number; contact_stage: string | null; conversation_id: number | null };
  steps: Step[];
  messages: { id: number; conversation_id: number; type: string; template_name: string | null; body: string | null; status: string; error: string | null; created_at: string }[];
  context: { payload: Record<string, unknown>; last_reply: { text: string; at: string } | null; vars: Record<string, unknown>; test: Record<string, unknown> | null; version: number | null };
  nodes: { id: string; type: string; title: string }[];
}
