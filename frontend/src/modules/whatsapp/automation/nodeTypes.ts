import {
  CircleStop,
  Clock,
  GitBranch,
  Globe,
  LayoutTemplate,
  MessageCircleReply,
  MessageSquareText,
  Split,
  Tag,
  UserCheck,
  UserPlus,
  Webhook,
  Workflow,
  Zap,
  type LucideIcon,
} from 'lucide-react';
import type { Meta, NodeData, NodeType, Port, WfNode } from './types';

export type NodeGroup = 'Start' | 'Messages' | 'Timing' | 'Logic' | 'CRM' | 'Integrations' | 'Finish';

export interface NodeSpec {
  type: NodeType;
  label: string;
  group: NodeGroup;
  icon: LucideIcon;
  ports: Port[];
  description: string;
  /** Tailwind classes for the icon chip and the accent bar (written literally so Tailwind keeps them). */
  chip: string;
  bar: string;
  /** Minimap / mobile list colour. */
  hex: string;
  defaults: () => NodeData;
}

export const NODE_W = 236;
export const NODE_H = 78;

export const NODE_SPECS: Record<NodeType, NodeSpec> = {
  trigger: {
    type: 'trigger', label: 'Event Trigger', group: 'Start', icon: Zap, ports: ['next'],
    description: 'Starts the workflow when a GIMT event happens (new enquiry, payment, stage change…).',
    chip: 'bg-brand-900 text-white dark:bg-brand-500/30 dark:text-brand-100', bar: 'bg-brand-900 dark:bg-brand-400', hex: '#0B2A5B',
    defaults: () => ({ event: '', filters: [] }),
  },
  contact: {
    type: 'contact', label: 'Create / Link Contact', group: 'CRM', icon: UserPlus, ports: ['next'],
    description: 'Creates the WhatsApp contact from the event (phone, name, e-mail) or links the enquiry / student to it — optionally also creates the ERP admission enquiry (enquiry ID).',
    chip: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200', bar: 'bg-sky-500', hex: '#0EA5E9',
    defaults: () => ({ contact_type: 'lead', stage: '', create_enquiry: false }),
  },
  template: {
    type: 'template', label: 'WhatsApp Template', group: 'Messages', icon: LayoutTemplate, ports: ['next'],
    description: 'Sends an approved template — works any time, even outside the 24-hour window.',
    chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200', bar: 'bg-emerald-500', hex: '#10B981',
    defaults: () => ({ template: '', vars: {} }),
  },
  message: {
    type: 'message', label: 'Send Message', group: 'Messages', icon: MessageSquareText, ports: ['next'],
    description: 'Free-text (or media) message — delivered only while the 24-hour window is open.',
    chip: 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-200', bar: 'bg-teal-500', hex: '#14B8A6',
    defaults: () => ({ text: '', media_type: 'none', media_url: '' }),
  },
  delay: {
    type: 'delay', label: 'Delay', group: 'Timing', icon: Clock, ports: ['next'],
    description: 'Waits a number of minutes, hours or days before the next step.',
    chip: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200', bar: 'bg-amber-500', hex: '#F59E0B',
    defaults: () => ({ value: 1, unit: 'hours' }),
  },
  wait_reply: {
    type: 'wait_reply', label: 'Wait for Reply', group: 'Timing', icon: MessageCircleReply, ports: ['replied', 'timeout'],
    description: 'Pauses until the contact replies (Replied) or the time runs out (No reply).',
    chip: 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-200', bar: 'bg-orange-500', hex: '#F97316',
    defaults: () => ({ value: 24, unit: 'hours', since: 'node', keywords: '' }),
  },
  condition: {
    type: 'condition', label: 'Condition', group: 'Logic', icon: GitBranch, ports: ['true', 'false'],
    description: 'Checks one or more rules (all / any) and branches Yes / No.',
    chip: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200', bar: 'bg-violet-500', hex: '#8B5CF6',
    defaults: () => ({ match: 'all', rules: [{ field: 'reply.received', op: 'is_true', value: '' }] }),
  },
  ifelse: {
    type: 'ifelse', label: 'If / Else', group: 'Logic', icon: Split, ports: ['true', 'false'],
    description: 'A single comparison: If it holds go Yes, else go No.',
    chip: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/20 dark:text-fuchsia-200', bar: 'bg-fuchsia-500', hex: '#D946EF',
    defaults: () => ({ rules: [{ field: 'contact.stage', op: 'equals', value: '' }] }),
  },
  tag: {
    type: 'tag', label: 'Add Student Tag', group: 'CRM', icon: Tag, ports: ['next'],
    description: 'Adds (or removes) tags on the contact for segmentation and campaigns.',
    chip: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-200', bar: 'bg-blue-500', hex: '#3B82F6',
    defaults: () => ({ action: 'add', tags: [] }),
  },
  stage: {
    type: 'stage', label: 'Update Status / Stage', group: 'CRM', icon: Workflow, ports: ['next'],
    description: 'Moves the contact to an admission follow-up stage and/or sets the lead status.',
    chip: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200', bar: 'bg-indigo-500', hex: '#6366F1',
    defaults: () => ({ stage: '', lead_status: '' }),
  },
  assign: {
    type: 'assign', label: 'Assign Counsellor', group: 'CRM', icon: UserCheck, ports: ['next'],
    description: 'Assigns a specific counsellor or the next one in a round-robin pool, and notifies them.',
    chip: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-200', bar: 'bg-cyan-500', hex: '#06B6D4',
    defaults: () => ({ mode: 'round_robin', user_id: '', pool: [], keep_existing: true, sync_enquiry: true, notify: true }),
  },
  api: {
    type: 'api', label: 'Send API Request', group: 'Integrations', icon: Globe, ports: ['next'],
    description: 'Calls an external HTTPS API (CRM, LMS…) with variables; can save the response.',
    chip: 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200', bar: 'bg-slate-500', hex: '#64748B',
    defaults: () => ({
      method: 'POST', url: '', headers: [{ key: 'Content-Type', value: 'application/json' }],
      body: '{\n  "phone": "{{phone}}",\n  "name": "{{student_name}}",\n  "course": "{{course_name}}"\n}', save_as: '', on_error: 'stop', call_in_test: false,
    }),
  },
  webhook: {
    type: 'webhook', label: 'Webhook', group: 'Integrations', icon: Webhook, ports: ['next'],
    description: 'POSTs the contact, event payload and variables as JSON to your HTTPS endpoint.',
    chip: 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200', bar: 'bg-zinc-500', hex: '#71717A',
    defaults: () => ({ url: '', on_error: 'continue', call_in_test: false }),
  },
  end: {
    type: 'end', label: 'End Workflow', group: 'Finish', icon: CircleStop, ports: [],
    description: 'Finishes the run for this contact.',
    chip: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200', bar: 'bg-rose-500', hex: '#F43F5E',
    defaults: () => ({}),
  },
};

export const PALETTE_GROUPS: { group: NodeGroup; types: NodeType[] }[] = [
  { group: 'Start', types: ['trigger'] },
  { group: 'Messages', types: ['template', 'message'] },
  { group: 'Timing', types: ['delay', 'wait_reply'] },
  { group: 'Logic', types: ['condition', 'ifelse'] },
  { group: 'CRM', types: ['contact', 'tag', 'stage', 'assign'] },
  { group: 'Integrations', types: ['api', 'webhook'] },
  { group: 'Finish', types: ['end'] },
];

export const PORT_LABEL: Record<Port, string> = { next: 'Next', true: 'Yes', false: 'No', replied: 'Replied', timeout: 'No reply' };

/** Port badge colours (edge labels + port dots). */
export const PORT_TONE: Record<Port, string> = {
  next: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  true: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
  false: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200',
  replied: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
  timeout: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200',
};

const UNIT_SHORT: Record<string, string> = { minutes: 'min', hours: 'h', days: 'd' };

export function durationLabel(d: NodeData): string {
  const v = Number(d.value) || 0;
  const unit = String(d.unit ?? 'hours');
  if (!v) return 'not set';
  return `${v} ${UNIT_SHORT[unit] ?? unit}`;
}

function ruleText(r: { field?: string; op?: string; value?: string } | undefined, meta?: Meta): string {
  if (!r || !r.field) return 'no rule yet';
  const f = meta?.condition_fields.find((x) => x.value === r.field)?.label ?? r.field;
  const op = meta?.condition_ops.find((x) => x.value === r.op)?.label ?? r.op ?? '';
  const noValue = ['is_empty', 'not_empty', 'is_true', 'is_false'].includes(String(r.op));
  return `${f} ${op}${noValue ? '' : ` "${r.value ?? ''}"`}`;
}

/** One-line summary shown on the node card and in the mobile list. */
export function nodeSummary(node: WfNode, meta?: Meta): string {
  const d = node.data ?? {};
  switch (node.type) {
    case 'trigger': {
      if (!d.event) return 'Choose an event';
      if (d.event === 'manual') return 'Manual enrolment';
      const ev = meta?.events.find((e) => e.code === d.event);
      const filters = Array.isArray(d.filters) ? d.filters.length : 0;
      const scheduled = String(d.active_from ?? '').trim() || String(d.active_until ?? '').trim();
      return `${ev?.name ?? d.event}${filters ? ` · ${filters} filter${filters > 1 ? 's' : ''}` : ''}${scheduled ? ' · scheduled' : ''}`;
    }
    case 'contact':
      return `From the event payload · ${d.contact_type ?? 'lead'}${d.create_enquiry ? ' · + ERP enquiry ID' : ''}`;
    case 'template': {
      if (!d.template) return 'Pick a template';
      const t = meta?.templates.find((x) => x.name === d.template || String(x.id) === String(d.template));
      return t ? `${t.display_name || t.name}${t.status !== 'approved' ? ` · ${t.status}` : ''}` : `${d.template} (missing)`;
    }
    case 'message':
      return d.text ? String(d.text).replace(/\s+/g, ' ').slice(0, 60) : 'Write the message';
    case 'delay':
      return `Wait ${durationLabel(d)}`;
    case 'wait_reply':
      return `Up to ${durationLabel(d)}${d.since === 'run' ? ' · counts earlier replies' : ''}`;
    case 'condition': {
      const rules = Array.isArray(d.rules) ? d.rules : [];
      if (rules.length > 1) return `${rules.length} rules · match ${d.match === 'any' ? 'any' : 'all'}`;
      return ruleText(rules[0], meta);
    }
    case 'ifelse':
      return ruleText(Array.isArray(d.rules) ? d.rules[0] : undefined, meta);
    case 'tag': {
      const tags = Array.isArray(d.tags) ? d.tags : [];
      return tags.length ? `${d.action === 'remove' ? 'Remove' : 'Add'}: ${tags.join(', ')}` : 'Choose tags';
    }
    case 'stage': {
      const parts: string[] = [];
      if (d.stage) parts.push(meta?.stages.find((s) => s.value === d.stage)?.label ?? d.stage);
      if (d.lead_status) parts.push(`lead: ${meta?.lead_statuses.find((s) => s.value === d.lead_status)?.label ?? d.lead_status}`);
      return parts.length ? parts.join(' · ') : 'Choose a stage';
    }
    case 'assign': {
      if (d.mode === 'specific') {
        const u = meta?.counsellors.find((c) => String(c.value) === String(d.user_id));
        return u ? u.label : 'Choose a counsellor';
      }
      const pool = Array.isArray(d.pool) ? d.pool.length : 0;
      return `Round-robin · ${pool ? `${pool} counsellors` : 'all counsellors'}`;
    }
    case 'api':
      return d.url ? `${String(d.method ?? 'POST').toUpperCase()} ${String(d.url).replace(/^https:\/\//, '')}` : 'Set the URL';
    case 'webhook':
      return d.url ? `POST ${String(d.url).replace(/^https:\/\//, '')}` : 'Set the URL';
    case 'end':
      return 'Run finishes here';
  }
  return '';
}

export function nodeTitle(node: WfNode): string {
  const label = String(node.data?.label ?? '').trim();
  return label || NODE_SPECS[node.type]?.label || node.type;
}
