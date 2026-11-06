/** Pure helpers for workflow definitions: normalising, client-side validation (mirrors the server), layout, geometry. */
import { NODE_H, NODE_SPECS, NODE_W, PORT_LABEL } from './nodeTypes';
import type { Definition, Issue, Meta, NodeType, Port, Rule, WfEdge, WfNode } from './types';

export function newId(type: NodeType): string {
  return `${type}_${Math.random().toString(36).slice(2, 8)}`;
}

/** Make a server definition safe to edit (objects for data / vars, numeric positions). */
export function normalizeDefinition(def: Partial<Definition> | null | undefined): Definition {
  const nodes = (Array.isArray(def?.nodes) ? def!.nodes : [])
    .filter((n) => n && n.id && NODE_SPECS[n.type as NodeType])
    .map((n) => {
      const data = n.data && typeof n.data === 'object' && !Array.isArray(n.data) ? { ...n.data } : {};
      if (n.type === 'template' && (!data.vars || Array.isArray(data.vars))) data.vars = {};
      if ((n.type === 'condition' || n.type === 'ifelse' || n.type === 'trigger') && !Array.isArray(data[n.type === 'trigger' ? 'filters' : 'rules'])) {
        data[n.type === 'trigger' ? 'filters' : 'rules'] = [];
      }
      return { id: String(n.id), type: n.type as NodeType, x: Number(n.x) || 0, y: Number(n.y) || 0, data };
    });
  const ids = new Set(nodes.map((n) => n.id));
  const edges = (Array.isArray(def?.edges) ? def!.edges : []).filter((e) => e && ids.has(e.from) && ids.has(e.to)).map((e) => ({ from: e.from, to: e.to, label: (e.label || 'next') as Port }));
  return { nodes, edges };
}

export function nodeMap(def: Definition): Record<string, WfNode> {
  return Object.fromEntries(def.nodes.map((n) => [n.id, n]));
}

export function edgeTarget(def: Definition, from: string, port: Port): string | undefined {
  return def.edges.find((e) => e.from === from && e.label === port)?.to;
}

/** Set (or clear with to = null) the edge leaving `from` through `port`. */
export function setEdge(def: Definition, from: string, port: Port, to: string | null): Definition {
  const edges: WfEdge[] = def.edges.filter((e) => !(e.from === from && e.label === port));
  if (to && to !== from) edges.push({ from, to, label: port });
  return { ...def, edges };
}

export function removeNode(def: Definition, id: string): Definition {
  return { nodes: def.nodes.filter((n) => n.id !== id), edges: def.edges.filter((e) => e.from !== id && e.to !== id) };
}

/* ------------------------------------------------------------------------------------------ geometry */

export function inPoint(n: WfNode) {
  return { x: n.x + NODE_W / 2, y: n.y };
}

export function outPoint(n: WfNode, port: Port) {
  const ports = NODE_SPECS[n.type].ports;
  if (ports.length <= 1) return { x: n.x + NODE_W / 2, y: n.y + NODE_H };
  const i = ports.indexOf(port);
  return { x: n.x + NODE_W * (i === 0 ? 0.27 : 0.73), y: n.y + NODE_H };
}

/** Smooth vertical bezier; edges going back up loop around the side. */
export function edgePath(a: { x: number; y: number }, b: { x: number; y: number }): { d: string; mid: { x: number; y: number } } {
  if (b.y > a.y + 20) {
    const dy = Math.max(36, (b.y - a.y) / 2);
    return { d: `M ${a.x} ${a.y} C ${a.x} ${a.y + dy}, ${b.x} ${b.y - dy}, ${b.x} ${b.y}`, mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 } };
  }
  const side = Math.max(a.x, b.x) + NODE_W / 2 + 40;
  return {
    d: `M ${a.x} ${a.y} C ${a.x} ${a.y + 70}, ${side} ${a.y + 70}, ${side} ${(a.y + b.y) / 2} S ${b.x} ${b.y - 70}, ${b.x} ${b.y}`,
    mid: { x: side, y: (a.y + b.y) / 2 },
  };
}

export function bounds(nodes: WfNode[]) {
  if (!nodes.length) return { minX: 0, minY: 0, maxX: NODE_W, maxY: NODE_H };
  return {
    minX: Math.min(...nodes.map((n) => n.x)),
    minY: Math.min(...nodes.map((n) => n.y)),
    maxX: Math.max(...nodes.map((n) => n.x + NODE_W)),
    maxY: Math.max(...nodes.map((n) => n.y + NODE_H)),
  };
}

/* ------------------------------------------------------------------------------------------ layout */

/**
 * Tidy top-down layout: depth-first from the trigger, each subtree gets the width of its leaves, parents are centred
 * over their children. Steps not reachable from the trigger are placed in a row underneath.
 */
export function autoLayout(def: Definition): Definition {
  const map = nodeMap(def);
  const trigger = def.nodes.find((n) => n.type === 'trigger');
  const colW = NODE_W + 44;
  const rowH = NODE_H + 66;
  const pos: Record<string, { x: number; y: number }> = {};
  const placed = new Set<string>();
  let cursor = 0;
  const visit = (id: string, depth: number): { left: number; right: number } => {
    placed.add(id);
    const n = map[id];
    const kids = NODE_SPECS[n.type].ports.map((p) => edgeTarget(def, id, p)).filter((t): t is string => !!t && !placed.has(t));
    if (!kids.length) {
      const x = cursor * colW;
      cursor++;
      pos[id] = { x, y: depth * rowH };
      return { left: x, right: x };
    }
    const spans = kids.map((k) => (placed.has(k) ? null : visit(k, depth + 1))).filter((s): s is { left: number; right: number } => !!s);
    if (!spans.length) {
      const x = cursor * colW;
      cursor++;
      pos[id] = { x, y: depth * rowH };
      return { left: x, right: x };
    }
    const left = spans[0].left;
    const right = spans[spans.length - 1].right;
    pos[id] = { x: (left + right) / 2, y: depth * rowH };
    return { left, right };
  };
  let maxDepthY = 0;
  if (trigger) visit(trigger.id, 0);
  Object.values(pos).forEach((p) => (maxDepthY = Math.max(maxDepthY, p.y)));
  let orphanX = 0;
  def.nodes.forEach((n) => {
    if (!pos[n.id]) {
      pos[n.id] = { x: orphanX, y: maxDepthY + rowH * 1.4 };
      orphanX += colW;
    }
  });
  return { ...def, nodes: def.nodes.map((n) => ({ ...n, x: Math.round(pos[n.id].x), y: Math.round(pos[n.id].y) })) };
}

/** A free spot below `after` (or at the bottom of the canvas) for a node added by click. */
export function spotFor(def: Definition, after?: WfNode | null): { x: number; y: number } {
  if (after) {
    let y = after.y + NODE_H + 66;
    let x = after.x;
    const taken = (xx: number, yy: number) => def.nodes.some((n) => Math.abs(n.x - xx) < NODE_W && Math.abs(n.y - yy) < NODE_H + 20);
    let guard = 0;
    while (taken(x, y) && guard++ < 20) x += NODE_W + 40;
    return { x, y };
  }
  const b = bounds(def.nodes);
  return { x: def.nodes.length ? b.minX : 0, y: def.nodes.length ? b.maxY + 66 : 0 };
}

/* ------------------------------------------------------------------------------------------ validation */

const NO_VALUE_OPS = ['is_empty', 'not_empty', 'is_true', 'is_false'];

function ruleError(r: Rule | undefined): string | null {
  if (!r || !r.field || !/^(contact|payload|reply|vars)\.[A-Za-z0-9_.]{1,80}$/.test(r.field)) return 'Choose the field each rule checks.';
  if (!r.op) return 'Choose how each rule compares.';
  if (!NO_VALUE_OPS.includes(r.op) && !String(r.value ?? '').trim()) return 'Enter the value each rule compares with.';
  return null;
}

const IPV4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;

/** Rough browser-side mirror of wa_wf_ip_is_public() for literal IPs (the server re-checks and resolves DNS). */
function privateIp(host: string): boolean {
  const m = IPV4.exec(host);
  if (m) {
    const [a, b] = [Number(m[1]), Number(m[2])];
    return a === 0 || a === 10 || a === 127 || a >= 224 || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && (b === 168 || b === 0)) || (a === 198 && (b === 18 || b === 19));
  }
  if (host.includes(':')) {
    const h = host.toLowerCase();
    if (h.startsWith('::ffff:')) return true; // mapped — the server judges the embedded IPv4; refuse in the form
    return h === '::' || h === '::1' || /^(fe[89ab]|f[cd]|ff|64:ff9b|2002:|2001:db8|2001:0?:|100:)/.test(h) || !/^[23]/.test(h);
  }
  return false;
}

export function urlError(url: unknown): string | null {
  const u = String(url ?? '').trim();
  if (!u) return 'Enter the URL to call.';
  if (u.length > 1000) return 'The URL is too long.';
  if (/[\s\\]/.test(u)) return 'The URL may not contain spaces or backslashes.';
  if (!/^https:\/\//i.test(u)) return 'Use a full https:// URL (plain http is not allowed).';
  const authority = u.replace(/^https:\/\/([^/?#]*).*$/is, '$1');
  if (authority.includes('{{') || authority.includes('}}')) return 'Variables can be used in the path or query only — the host name must be fixed.';
  let parsed: URL;
  try {
    parsed = new URL(u.replace(/\{\{[^}]*\}\}/g, 'x'));
  } catch {
    return 'Use a full https:// URL.';
  }
  if (parsed.username || parsed.password || authority.includes('@')) return 'Do not put a username or password in the URL — use a header instead.';
  if (parsed.hostname.endsWith('.')) return 'Remove the dot at the end of the host name.';
  const host = parsed.hostname.replace(/^\[|\]$/g, '').toLowerCase();
  const isIp = IPV4.test(host) || host.includes(':');
  if (!host || host === 'localhost' || /\.(localhost|local|internal|intranet|lan|home|corp|localdomain|arpa)$/.test(host) || (!isIp && !host.includes('.'))) return 'Local and internal hosts are not allowed.';
  if (!isIp && /(^|\.)(0x[0-9a-f]*|\d+)$/i.test(host)) return 'Write IP addresses in the usual form (a.b.c.d) — and only public ones are allowed.';
  if (isIp && privateIp(host)) return 'Private, loopback and reserved IP addresses are not allowed.';
  if (parsed.port && !['443', '8443'].includes(parsed.port)) return 'Only the standard HTTPS ports (443, 8443) are allowed.';
  return null;
}

/** Template variable keys of a node that are filled from the admissions deadline (mirrors wa_wf_deadline_keys). */
export function deadlineKeys(node: WfNode, meta?: Meta): string[] {
  if (node.type !== 'template' || !meta) return [];
  const vars: Record<string, string> = node.data.vars && typeof node.data.vars === 'object' && !Array.isArray(node.data.vars) ? node.data.vars : {};
  const keys = new Set<string>();
  Object.entries(vars).forEach(([k, expr]) => {
    if (typeof expr === 'string' && /\{\{\s*(admissions_deadline|last_date)\s*\}\}/.test(expr)) keys.add(k);
  });
  const tpl = meta.templates.find((t) => (t.name === node.data.template && t.status === 'approved') || t.name === node.data.template || String(t.id) === String(node.data.template));
  (tpl?.variables ?? []).forEach((v) => {
    if (['last_date', 'admissions_deadline'].includes(v.key) && !String(vars[v.key] ?? '').trim()) keys.add(v.key);
  });
  return Array.from(keys);
}

/* Date / time template variables (mirrors wa_wf_datetime_var_problems): a counselling / interview / exam / due date
   must come from real data — never {{tomorrow}} or a typed slot that every contact would be told. */
const RELATIVE_VARS = ['today', 'tomorrow', 'yesterday', 'date_today', 'now'];
const FIXED_DATE_KEYS = ['last_date', 'admissions_deadline', 'event_date', 'holiday_date', 'reopen_date'];

export function isDateTimeKey(key: string): boolean {
  return /(^|_)(date|time|datetime|slot)$/i.test(key);
}

/** Institute-wide dates (last date to apply, event day …) may be typed once for everyone; personal ones may not. */
export function typedDateAllowed(key: string): boolean {
  return FIXED_DATE_KEYS.includes(key);
}

/** Problem of one template variable value (null when it is fine). */
export function dateTimeVarProblem(key: string, value: unknown): string | null {
  const expr = String(value ?? '').trim();
  if (!expr || !isDateTimeKey(key)) return null;
  const tokens = Array.from(expr.matchAll(/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/g)).map((m) => m[1].toLowerCase());
  const rel = tokens.find((t) => RELATIVE_VARS.includes(t));
  if (rel) return `{{${key}}} is set to {{${rel}}} (a date relative to when the step runs)`;
  const typed = expr.replace(/\{\{[^}]*\}\}/g, '').replace(/[\s,.:;/()–—-]+/g, '');
  if (!FIXED_DATE_KEYS.includes(key) && (!tokens.length || typed !== '')) return `{{${key}}} is a typed value ("${expr.slice(0, 40)}")`;
  return null;
}

export function dateTimeMessage(problems: Record<string, string>): string {
  const first = Object.keys(problems)[0];
  return `This step would promise a date or time that is not real data: ${Object.values(problems).join('; ')}. Map it to a real data variable (e.g. {{payload.${first}}} from the event) or leave it empty to fill it from the contact / event — otherwise every contact is told the same made-up slot.`;
}

/** Event Manager message already sent on the trigger event (active event with a template), if any. */
export function eventMessage(def: Definition, meta?: Meta) {
  const code = def.nodes.find((n) => n.type === 'trigger')?.data.event;
  if (!code || code === 'manual') return null;
  const ev = meta?.events.find((e) => e.code === code);
  return ev && ev.active && ev.template ? ev : null;
}

/** Why the admissions deadline cannot be used (null when it can). */
export function deadlineProblem(meta?: Meta): string | null {
  const d = meta?.deadline;
  if (!d || d.valid) return null;
  if (d.reason === 'past') return `the admissions deadline (${d.value}) has already passed`;
  if (d.reason === 'invalid') return 'the admissions deadline is not a valid date';
  return 'the admissions deadline is not set';
}

/** Schedule bounds are IST wall-clock times (as the server reads them), whatever the browser's time zone. */
function scheduleTs(v: unknown): number | null {
  const s = String(v ?? '').trim().replace('T', ' ');
  const m = /^(\d{4}-\d{2}-\d{2})(?: (\d{1,2}):(\d{2})(?::(\d{2}))?)?$/.exec(s);
  if (!m) return null;
  const t = new Date(`${m[1]}T${(m[2] ?? '00').padStart(2, '0')}:${m[3] ?? '00'}:${m[4] ?? '00'}+05:30`).getTime();
  return Number.isNaN(t) ? null : t;
}

export function scheduleLabel(t: number): string {
  return new Date(t).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' });
}

/** Client-side mirror of wa_wf_validate() (the server re-checks on save / activate). */
export function validateDefinition(def: Definition, meta?: Meta): Issue[] {
  const issues: Issue[] = [];
  const add = (level: Issue['level'], node: string | null, message: string) => issues.push({ level, node, message });
  const triggers = def.nodes.filter((n) => n.type === 'trigger');
  if (!triggers.length) add('error', null, 'Add an Event Trigger — every workflow starts with exactly one trigger.');
  triggers.slice(1).forEach((t) => add('error', t.id, 'Only one Event Trigger is allowed per workflow. Delete the extra trigger.'));

  for (const n of def.nodes) {
    const d = n.data ?? {};
    switch (n.type) {
      case 'trigger': {
        if (!d.event) add('error', n.id, 'Choose the event that starts this workflow (or "Manual enrolment").');
        (Array.isArray(d.filters) ? d.filters : []).forEach((r: Rule) => {
          const e = ruleError(r);
          if (e) add('error', n.id, `Trigger filter: ${e}`);
        });
        const fromRaw = String(d.active_from ?? '').trim();
        const untilRaw = String(d.active_until ?? '').trim();
        const from = scheduleTs(fromRaw);
        const until = scheduleTs(untilRaw);
        if (fromRaw && !from) add('error', n.id, 'Schedule: "Active from" is not a valid date and time.');
        if (untilRaw && !until) add('error', n.id, 'Schedule: "Active until" is not a valid date and time.');
        if (from && until && until <= from) add('error', n.id, 'Schedule: "Active until" must be after "Active from".');
        else if (until && until < Date.now()) issues.push({ level: 'warning', node: n.id, message: `Schedule: the active window ended on ${scheduleLabel(until)} — no new runs start.`, code: 'schedule' });
        else if (from && from > Date.now()) issues.push({ level: 'warning', node: n.id, message: `Schedule: new runs start only from ${scheduleLabel(from)}.`, code: 'schedule' });
        break;
      }
      case 'template': {
        if (!d.template) add('error', n.id, 'Pick the WhatsApp template to send.');
        else if (meta) {
          const t = meta.templates.find((x) => x.name === d.template || String(x.id) === String(d.template));
          if (!t) add('error', n.id, `Template "${d.template}" does not exist any more. Pick another template.`);
          else {
            if (t.status !== 'approved') add('warning', n.id, `Template "${t.display_name || t.name}" is ${t.status} — WhatsApp only delivers approved templates.`);
            const vars: Record<string, unknown> = d.vars && typeof d.vars === 'object' && !Array.isArray(d.vars) ? d.vars : {};
            const fake: Record<string, string> = {};
            Array.from(new Set(t.variables.map((v) => v.key))).forEach((k) => {
              const p = dateTimeVarProblem(k, vars[k]);
              if (p) fake[k] = p;
            });
            if (Object.keys(fake).length) issues.push({ level: 'error', node: n.id, code: 'fake_datetime', message: dateTimeMessage(fake) });
            const keys = deadlineKeys(n, meta);
            const problem = deadlineProblem(meta);
            if (keys.length && problem) {
              issues.push({
                level: 'warning',
                node: n.id,
                code: 'deadline',
                message: `This step fills ${keys.join(', ')} from the admissions deadline, but ${problem}. Set it under ${meta.deadline?.where ?? 'Settings › Website'} (or type a date in the variable) — otherwise this step fails for every contact.`,
              });
            }
          }
        }
        break;
      }
      case 'message':
        if (!String(d.text ?? '').trim() && !String(d.media_url ?? '').trim()) add('error', n.id, 'Write the message text.');
        else if (String(d.text ?? '').length > 4096) add('error', n.id, 'A WhatsApp message can have at most 4096 characters.');
        if (['image', 'document', 'video'].includes(d.media_type) && !/^https:\/\//i.test(String(d.media_url ?? ''))) add('error', n.id, 'The attachment needs a public https:// URL.');
        add('warning', n.id, 'Free-text messages are only delivered inside the 24-hour window (after the contact wrote to you); otherwise the step is skipped.');
        break;
      case 'delay':
      case 'wait_reply': {
        const v = Number(d.value);
        const mult = { minutes: 60, hours: 3600, days: 86400 }[String(d.unit)] ?? 0;
        if (!Number.isInteger(v) || v < 1 || !mult) add('error', n.id, 'Enter how long to wait (a whole number of minutes, hours or days).');
        else if (v * mult > 90 * 86400) add('error', n.id, 'Waits longer than 90 days are not allowed.');
        break;
      }
      case 'condition':
      case 'ifelse': {
        const rules: Rule[] = Array.isArray(d.rules) ? d.rules : [];
        if (!rules.length) add('error', n.id, 'Add at least one rule to check.');
        (n.type === 'ifelse' ? rules.slice(0, 1) : rules).forEach((r) => {
          const e = ruleError(r);
          if (e) add('error', n.id, e);
        });
        break;
      }
      case 'tag':
        if (!(Array.isArray(d.tags) ? d.tags : []).filter((t: string) => String(t).trim()).length) add('error', n.id, 'Choose at least one tag.');
        break;
      case 'stage':
        if (!d.stage && !d.lead_status) add('error', n.id, 'Choose the stage (and/or lead status) to set.');
        break;
      case 'assign':
        if (d.mode === 'specific' && !d.user_id) add('error', n.id, 'Choose the counsellor to assign.');
        break;
      case 'api':
      case 'webhook': {
        const e = urlError(d.url);
        if (e) add('error', n.id, e);
        if (/[?&][^=&#]*(key|token|secret|password|passwd|signature|auth)[^=&#]*=[^&#{]/i.test(String(d.url ?? ''))) {
          issues.push({ level: 'warning', node: n.id, code: 'secret_in_url', message: 'The URL seems to carry a key or token in its query string, which everyone who can view this workflow sees. Send secrets in a header instead — header values are hidden after saving.' });
        }
        if (n.type === 'api') {
          if (d.save_as && !/^[a-z][a-z0-9_]{0,39}$/.test(String(d.save_as))) add('error', n.id, 'The response variable name may only use lowercase letters, digits and _.');
          if (String(d.body ?? '').length > 10000) add('error', n.id, 'The request body can have at most 10,000 characters.');
          const headers: { key?: string }[] = Array.isArray(d.headers) ? d.headers : [];
          if (headers.length > 20) add('error', n.id, 'At most 20 headers are allowed.');
          if (headers.some((h) => String(h?.key ?? '').trim() && !/^[A-Za-z0-9\-_]{1,100}$/.test(String(h.key).trim()))) add('error', n.id, 'Header names may only use letters, digits, - and _.');
        }
        break;
      }
    }
  }

  const trigger = triggers[0];
  if (trigger) {
    if (!edgeTarget(def, trigger.id, 'next')) add('error', trigger.id, 'Connect the trigger to the first step.');
    const seen = new Set([trigger.id]);
    const queue = [trigger.id];
    while (queue.length) {
      const cur = queue.shift()!;
      def.edges.filter((e) => e.from === cur).forEach((e) => {
        if (!seen.has(e.to)) {
          seen.add(e.to);
          queue.push(e.to);
        }
      });
    }
    def.nodes.forEach((n) => {
      if (!seen.has(n.id) && n.type !== 'trigger') add('error', n.id, 'This step is not connected to the trigger — connect it or delete it.');
    });
  }
  def.nodes.forEach((n) => {
    if (['condition', 'ifelse', 'wait_reply'].includes(n.type)) {
      NODE_SPECS[n.type].ports.forEach((p) => {
        if (!edgeTarget(def, n.id, p)) add('warning', n.id, `Nothing is connected to the "${PORT_LABEL[p]}" branch — runs that take it end there.`);
      });
    }
  });
  // loops must pass through a Delay / Wait for Reply
  const map = nodeMap(def);
  const waits = (id: string) => ['delay', 'wait_reply'].includes(map[id]?.type);
  const adj: Record<string, string[]> = {};
  def.edges.forEach((e) => {
    if (!waits(e.from) && !waits(e.to)) (adj[e.from] ??= []).push(e.to);
  });
  const state: Record<string, number> = {};
  let cycleAt: string | null = null;
  const dfs = (v: string): boolean => {
    state[v] = 1;
    for (const w of adj[v] ?? []) {
      if (state[w] === 1) {
        cycleAt = w;
        return true;
      }
      if (!state[w] && dfs(w)) return true;
    }
    state[v] = 2;
    return false;
  };
  let instant = false;
  for (const n of def.nodes) {
    if (!state[n.id] && dfs(n.id)) {
      issues.push({ level: 'error', node: cycleAt, code: 'loop', message: 'This loop has no Delay or Wait for Reply step — it would repeat forever. Add a wait inside the loop.' });
      instant = true;
      break;
    }
  }
  // loops through a wait are allowed but bounded by the runner
  if (!instant) {
    const all: Record<string, string[]> = {};
    def.edges.forEach((e) => (all[e.from] ??= []).push(e.to));
    const st: Record<string, number> = {};
    let at: string | null = null;
    const walk = (v: string): boolean => {
      st[v] = 1;
      for (const w of all[v] ?? []) {
        if (st[w] === 1) {
          at = w;
          return true;
        }
        if (!st[w] && walk(w)) return true;
      }
      st[v] = 2;
      return false;
    };
    for (const n of def.nodes) {
      if (!st[n.id] && walk(n.id)) {
        const visits = meta?.limits?.max_node_visits ?? 25;
        const steps = meta?.limits?.max_steps_per_run ?? 250;
        issues.push({ level: 'warning', node: at, code: 'loop', message: `This step is part of a loop. Each step runs at most ${visits} times per contact (${steps} steps per run) — then the run stops as failed.` });
        break;
      }
    }
  }
  if (trigger?.data.event === 'WA_STAGE_CHANGED') {
    const st = def.nodes.find((n) => n.type === 'stage' && String(n.data.stage ?? '').trim());
    if (st) {
      issues.push({ level: 'warning', node: st.id, code: 'self_trigger', message: 'This step changes the stage, and the workflow is started by stage changes. Loop protection: a stage change made by this workflow (or by a workflow it started) never starts it again.' });
    }
  }
  // the Event Manager may already message the contact on the same event (e.g. the welcome template of a new enquiry)
  const ev = eventMessage(def, meta);
  const sends = def.nodes.filter((n) => n.type === 'template' && String(n.data.template ?? '').trim());
  if (ev && sends.length) {
    const same = sends.find((n) => String(n.data.template).trim() === ev.template);
    issues.push({
      level: 'warning',
      node: (same ?? sends[0]).id,
      code: 'duplicate_message',
      message: same
        ? `The Event Manager already sends "${ev.template_label || ev.template}" when "${ev.name}" happens, and this step sends the same template — every contact would get it twice. Remove this step, or switch off that event's message in WhatsApp › Event Manager.`
        : `The Event Manager also sends "${ev.template_label || ev.template}" when "${ev.name}" happens, so contacts get that message in addition to this workflow's messages. Make sure the two do not repeat each other (WhatsApp › Event Manager).`,
    });
  }
  return issues;
}

/** Builder issues = the browser's own checks + the checks only the server can make (DNS resolution of API hosts). */
export function mergeIssues(local: Issue[], server: Issue[] | undefined, def: Definition): Issue[] {
  const ids = new Set(def.nodes.map((n) => n.id));
  const extra = (server ?? []).filter((i) => i.server && (!i.node || ids.has(i.node)) && !local.some((l) => l.node === i.node && l.message === i.message));
  return [...local, ...extra];
}

/** Order nodes for the read-only list (depth-first from the trigger, branches labelled). */
export function linearize(def: Definition): { node: WfNode; depth: number; via: Port | null }[] {
  const map = nodeMap(def);
  const out: { node: WfNode; depth: number; via: Port | null }[] = [];
  const seen = new Set<string>();
  const walk = (id: string, depth: number, via: Port | null) => {
    if (seen.has(id) || !map[id]) return;
    seen.add(id);
    out.push({ node: map[id], depth, via });
    const ports = NODE_SPECS[map[id].type].ports;
    ports.forEach((p) => {
      const to = edgeTarget(def, id, p);
      if (to) walk(to, ports.length > 1 ? depth + 1 : depth, ports.length > 1 ? p : null);
    });
  };
  const trigger = def.nodes.find((n) => n.type === 'trigger');
  if (trigger) walk(trigger.id, 0, null);
  def.nodes.forEach((n) => {
    if (!seen.has(n.id)) out.push({ node: n, depth: 0, via: null });
  });
  return out;
}
