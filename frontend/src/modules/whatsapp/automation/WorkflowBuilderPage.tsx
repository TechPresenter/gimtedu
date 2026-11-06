import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle,
  CircleCheck,
  Copy,
  Eraser,
  FlaskConical,
  History,
  LayoutGrid,
  Maximize2,
  MoreHorizontal,
  Power,
  PowerOff,
  Redo2,
  Save,
  Trash2,
  Undo2,
  UserPlus,
  X,
} from 'lucide-react';
import { Alert, Badge, Button, Card, Combobox, Dropdown, EmptyState, Field, IconButton, Input, Modal, PageHeader, Skeleton, StatusBadge, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { ApiError, api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { useWorkflow, useWorkflowMeta, useWorkflowRefresh } from './api';
import { Canvas, NODE_MIME, type CanvasHandle, type Selection } from './Canvas';
import { autoLayout, deadlineKeys, linearize, mergeIssues, newId, nodeMap, normalizeDefinition, removeNode, setEdge, spotFor, validateDefinition } from './graph';
import { DeadlineNote, NodeInspector } from './Inspector';
import { NODE_SPECS, PALETTE_GROUPS, PORT_LABEL, PORT_TONE, nodeSummary, nodeTitle } from './nodeTypes';
import { PerformancePanel } from './Performance';
import { TestRunModal } from './TestRunModal';
import type { Definition, Issue, Meta, NodeData, NodeType, Port, Workflow, WorkflowStatus } from './types';

const STATUS_COLORS = { active: 'green', draft: 'amber', inactive: 'slate' } as const;
const BLANK: Definition = { nodes: [{ id: 'trigger', type: 'trigger', x: 260, y: 0, data: { event: '', filters: [] } }], edges: [] };
const DAY_LABELS: Record<string, string> = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };

interface Settings {
  name: string;
  description: string;
  business_hours_only: boolean;
  allow_reentry: boolean;
}

function isTyping(el: EventTarget | null) {
  const t = el as HTMLElement | null;
  return !!t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
}

/* ------------------------------------------------------------------------------------------ palette */

function Palette({ onAdd, disabled, hasTrigger }: { onAdd: (t: NodeType) => void; disabled?: boolean; hasTrigger: boolean }) {
  return (
    <div className="flex h-full flex-col">
      <div className="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <p className="font-display text-sm font-semibold text-slate-900 dark:text-white">Steps</p>
        <p className="text-[11px] text-slate-500 dark:text-slate-400">Drag onto the canvas, or click to add below the selected step.</p>
      </div>
      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-3 py-3">
        {PALETTE_GROUPS.map((g) => (
          <div key={g.group}>
            <p className="mb-1.5 px-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">{g.group}</p>
            <div className="space-y-1">
              {g.types.map((t) => {
                const S = NODE_SPECS[t];
                const off = disabled || (t === 'trigger' && hasTrigger);
                return (
                  <button
                    key={t}
                    type="button"
                    draggable={!off}
                    disabled={off}
                    onDragStart={(e) => {
                      e.dataTransfer.setData(NODE_MIME, t);
                      e.dataTransfer.effectAllowed = 'copy';
                    }}
                    onClick={() => onAdd(t)}
                    title={off && t === 'trigger' ? 'A workflow has exactly one trigger' : S.description}
                    className="group flex w-full items-center gap-2.5 rounded-xl border border-transparent px-2 py-1.5 text-left transition hover:border-slate-200 hover:bg-white hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-transparent disabled:hover:bg-transparent disabled:hover:shadow-none dark:hover:border-slate-700 dark:hover:bg-slate-800 enabled:cursor-grab"
                  >
                    <span className={clsx('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg', S.chip)}>
                      <S.icon className="h-3.5 w-3.5" aria-hidden />
                    </span>
                    <span className="min-w-0 text-[12.5px] font-medium leading-tight text-slate-700 group-hover:text-slate-900 dark:text-slate-200 dark:group-hover:text-white">{S.label}</span>
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------------------------------ settings panel */

function WorkflowPanel({
  settings,
  setSettings,
  nameError,
  wf,
  meta,
  def,
  issues,
  readOnly,
  onFocusNode,
}: {
  settings: Settings;
  setSettings: (s: Partial<Settings>) => void;
  nameError: string | null;
  wf?: Workflow;
  meta?: Meta;
  def: Definition;
  issues: Issue[];
  readOnly?: boolean;
  onFocusNode: (id: string) => void;
}) {
  const errors = issues.filter((i) => i.level === 'error');
  const warnings = issues.filter((i) => i.level === 'warning');
  const hours = Object.entries(meta?.business_hours ?? {}).filter(([, v]) => Array.isArray(v) && v.length === 2);
  const deadlineSteps = def.nodes.filter((n) => deadlineKeys(n, meta).length > 0);
  const deadlineVars = Array.from(new Set(deadlineSteps.flatMap((n) => deadlineKeys(n, meta))));
  const lim = meta?.limits;
  return (
    <div className="flex h-full flex-col">
      <div className="border-b border-slate-100 px-4 py-3.5 dark:border-slate-800">
        <p className="font-display text-sm font-semibold text-slate-900 dark:text-white">Workflow settings</p>
        <p className="text-[11px] text-slate-500 dark:text-slate-400">Select a step on the canvas to configure it.</p>
      </div>
      <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
        <Field label="Name" required htmlFor="wf-name" error={nameError}>
          <Input id="wf-name" value={settings.name} disabled={readOnly} maxLength={150} onChange={(e) => setSettings({ name: e.target.value })} invalid={!!nameError} />
        </Field>
        <Field label="Description" htmlFor="wf-desc">
          <Textarea id="wf-desc" rows={3} value={settings.description} disabled={readOnly} maxLength={1000} onChange={(e) => setSettings({ description: e.target.value })} placeholder="What this automation does" />
        </Field>
        <Toggle
          checked={settings.business_hours_only}
          disabled={readOnly}
          onChange={(v) => setSettings({ business_hours_only: v })}
          label="Send only in business hours"
          description="Messages due outside office hours wait until the next opening."
        />
        {settings.business_hours_only && (
          <div className="rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
            {hours.length ? (
              <ul className="grid grid-cols-2 gap-x-3 gap-y-0.5">
                {hours.map(([d, v]) => (
                  <li key={d}>
                    <span className="font-semibold">{DAY_LABELS[d] ?? d}</span> {v[0]}–{v[1]}
                  </li>
                ))}
              </ul>
            ) : (
              <p>Business hours are set in WhatsApp › Settings.</p>
            )}
          </div>
        )}
        <Toggle
          checked={settings.allow_reentry}
          disabled={readOnly}
          onChange={(v) => setSettings({ allow_reentry: v })}
          label="Allow re-entry"
          description={
            settings.allow_reentry
              ? `A contact may start a new run while one is in progress — at least ${lim?.reentry_cooldown_minutes ?? 10} minutes apart, at most ${lim?.max_runs_per_day ?? 10} runs a day.`
              : `Off = a contact can be in only one run of this workflow at a time (at most ${lim?.max_runs_per_day ?? 10} runs a day).`
          }
        />

        {deadlineSteps.length > 0 && (
          <div>
            <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Data this workflow needs</p>
            <DeadlineNote meta={meta} keys={deadlineVars} />
            <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
              Used by {deadlineSteps.length} step{deadlineSteps.length > 1 ? 's' : ''}: {deadlineSteps.map((n) => nodeTitle(n)).join(', ')}.
            </p>
          </div>
        )}

        <div>
          <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Checks</p>
          {issues.length === 0 ? (
            <p className="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
              <CircleCheck className="h-4 w-4" aria-hidden /> Ready to activate — no issues found.
            </p>
          ) : (
            <ul className="space-y-1.5">
              {[...errors, ...warnings].map((i, k) => (
                <li key={k}>
                  <button
                    type="button"
                    disabled={!i.node}
                    onClick={() => i.node && onFocusNode(i.node)}
                    className={clsx('flex w-full items-start gap-1.5 rounded-lg px-2.5 py-1.5 text-left text-xs', i.level === 'error' ? 'bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-50 text-amber-800 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-200')}
                  >
                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                    {i.message}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        {wf && (
          <div>
            <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Runs</p>
            <div className="grid grid-cols-3 gap-2 text-center">
              {[
                ['Total', wf.runs_total],
                ['Completed', wf.runs_completed],
                ['Failed', wf.runs_failed],
              ].map(([l, v]) => (
                <div key={l as string} className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/60">
                  <p className="font-display text-base font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(v)}</p>
                  <p className="text-[10px] text-slate-500">{l}</p>
                </div>
              ))}
            </div>
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
              Version {wf.version}
              {wf.updated_at && ` · saved ${timeAgo(wf.updated_at)}`}
              {wf.updated_by_name && ` by ${wf.updated_by_name}`}
              {wf.last_run_at && ` · last run ${timeAgo(wf.last_run_at)}`}
            </p>
            <p className="mt-1 text-[11px] text-slate-400 dark:text-slate-500">Runs keep the version they started with; saving affects new runs only.</p>
          </div>
        )}
        {wf && <PerformancePanel workflowId={wf.id} onFocusNode={onFocusNode} />}
        {lim && (
          <div className="rounded-xl border border-slate-100 p-3 text-[11px] leading-relaxed text-slate-500 dark:border-slate-800 dark:text-slate-400">
            <p className="mb-1 font-semibold text-slate-600 dark:text-slate-300">Loop protection</p>
            Each step runs at most {lim.max_node_visits}× per contact and a run at most {lim.max_steps_per_run} steps. A workflow is never restarted by an event its own steps raised, and at most {lim.max_chain} workflows can start each other in a row.
          </div>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------------------------------ mobile list */

function MobileStepList({ def, meta }: { def: Definition; meta?: Meta }) {
  const items = linearize(def);
  return (
    <ol className="space-y-2">
      {items.map(({ node, depth, via }, i) => {
        const S = NODE_SPECS[node.type];
        return (
          <li key={node.id} style={{ marginLeft: Math.min(depth, 4) * 14 }} className="relative">
            {via && <span className={clsx('mb-1 inline-block rounded px-1.5 text-[10px] font-bold uppercase', PORT_TONE[via as Port])}>{PORT_LABEL[via as Port]}</span>}
            <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
              <span className="w-5 shrink-0 pt-1.5 text-right text-xs font-semibold tabular-nums text-slate-400">{i + 1}</span>
              <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', S.chip)}>
                <S.icon className="h-4 w-4" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">{nodeTitle(node)}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">{nodeSummary(node, meta)}</p>
              </div>
            </div>
          </li>
        );
      })}
    </ol>
  );
}

/* ------------------------------------------------------------------------------------------ manual enrolment */

function EnrollModal({ open, onClose, workflowId }: { open: boolean; onClose: () => void; workflowId: number }) {
  const [contactId, setContactId] = useState<string | number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  const refresh = useWorkflowRefresh();
  const navigate = useNavigate();
  useEffect(() => {
    if (open) {
      setContactId(null);
      setError(null);
    }
  }, [open]);
  const submit = async () => {
    if (!contactId) {
      setError('Pick the contact to enrol.');
      return;
    }
    setBusy(true);
    try {
      const res = await api.post<{ run: { id: number } }>(`whatsapp/workflows/${workflowId}/enroll`, { contact_id: contactId });
      toast.success(res.message);
      refresh();
      onClose();
      navigate(`/whatsapp/automations?tab=runs&workflow=${workflowId}&run=${res.data.run.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.contact_id ?? err.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      title="Enrol a contact"
      description="Starts a live run of this workflow for one contact now (trigger filters are skipped)."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={UserPlus} loading={busy} onClick={submit}>
            Enrol &amp; start
          </Button>
        </>
      }
    >
      <Field label="Contact" required htmlFor="wf-enroll-contact" error={error}>
        <Combobox id="wf-enroll-contact" optionsUrl="whatsapp/workflows/options/contacts" value={contactId} onChange={(v) => setContactId(v)} placeholder="Search name or phone…" invalid={!!error} />
      </Field>
    </Modal>
  );
}

/* ------------------------------------------------------------------------------------------ page */

export default function WorkflowBuilderPage() {
  const params = useParams();
  const isNew = params.id === 'new';
  const wfId = isNew ? null : Number(params.id) || null;
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWorkflowRefresh();
  const { can } = useAuth();
  const canEdit = isNew ? can('whatsapp_automation', 'create') : can('whatsapp_automation', 'edit');
  const readOnly = !canEdit;
  const { data: wf, isLoading, error, refetch } = useWorkflow(wfId);
  const { data: meta } = useWorkflowMeta();

  const [def, setDef] = useState<Definition>(BLANK);
  const defRef = useRef(def);
  defRef.current = def;
  const [settings, setSettingsState] = useState<Settings>({ name: 'Untitled workflow', description: '', business_hours_only: false, allow_reentry: false });
  const [saved, setSaved] = useState<string>(() => JSON.stringify({ def: BLANK, s: { name: 'Untitled workflow', description: '', business_hours_only: false, allow_reentry: false } }));
  const [version, setVersion] = useState(1);
  const [selection, setSelection] = useState<Selection>(null);
  const [nameError, setNameError] = useState<string | null>(null);
  const [busy, setBusy] = useState<'save' | 'status' | null>(null);
  const [testOpen, setTestOpen] = useState(false);
  const [enrollOpen, setEnrollOpen] = useState(false);
  const [trace, setTrace] = useState<Record<string, string> | undefined>();
  const [fitSignal, setFitSignal] = useState(0);
  const [, setTick] = useState(0);
  const past = useRef<Definition[]>([]);
  const future = useRef<Definition[]>([]);
  const lastRecord = useRef({ key: '', at: 0 });
  const canvasRef = useRef<CanvasHandle>(null);
  const loaded = useRef<string>('');

  // load the saved workflow (once per id / version)
  useEffect(() => {
    if (!wf) return;
    const key = `${wf.id}:${wf.version}`;
    if (loaded.current === key) return;
    const first = !loaded.current.startsWith(`${wf.id}:`);
    loaded.current = key;
    const d = normalizeDefinition(wf.definition);
    const s: Settings = { name: wf.name, description: wf.description ?? '', business_hours_only: !!wf.business_hours_only, allow_reentry: !!wf.allow_reentry };
    setDef(d);
    setSettingsState(s);
    setVersion(wf.version);
    setSaved(JSON.stringify({ def: d, s }));
    if (first) {
      past.current = [];
      future.current = [];
      setSelection(null);
      setFitSignal((n) => n + 1);
    }
  }, [wf]);

  useEffect(() => {
    if (isNew) setFitSignal((n) => n + 1);
  }, [isNew]);

  const dirty = useMemo(() => JSON.stringify({ def, s: settings }) !== saved, [def, settings, saved]);
  // checks only the server can make (API / webhook host names resolved through DNS), refreshed shortly after edits
  const [serverIssues, setServerIssues] = useState<Issue[]>([]);
  const hasHttp = def.nodes.some((n) => n.type === 'api' || n.type === 'webhook');
  useEffect(() => {
    if (!hasHttp) {
      setServerIssues([]);
      return;
    }
    const ctrl = new AbortController();
    const t = window.setTimeout(() => {
      api
        .post<{ issues: Issue[] }>('whatsapp/workflows/validate', { definition: def }, { signal: ctrl.signal, background: true })
        .then((r) => setServerIssues(r.data.issues ?? []))
        .catch(() => undefined);
    }, 800);
    return () => {
      window.clearTimeout(t);
      ctrl.abort();
    };
  }, [def, hasHttp]);
  const issues = useMemo(() => mergeIssues(validateDefinition(def, meta), serverIssues, def), [def, meta, serverIssues]);
  const errorCount = issues.filter((i) => i.level === 'error').length;
  const warnCount = issues.length - errorCount;

  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  /** Apply a new definition; record = add an undo step (typing in one field is coalesced). */
  const apply = useCallback((next: Definition, record: boolean, base?: Definition, key = '') => {
    if (record) {
      const now = Date.now();
      const coalesce = key !== '' && lastRecord.current.key === key && now - lastRecord.current.at < 900;
      if (!coalesce) {
        past.current.push(base ?? defRef.current);
        if (past.current.length > 80) past.current.shift();
        future.current = [];
      }
      lastRecord.current = { key, at: now };
      setTick((t) => t + 1);
    }
    defRef.current = next;
    setDef(next);
  }, []);

  const onCanvasChange = useCallback((next: Definition, record: boolean, base?: Definition) => apply(next, record, base), [apply]);

  const undo = useCallback(() => {
    const prev = past.current.pop();
    if (!prev) return;
    future.current.push(defRef.current);
    lastRecord.current = { key: '', at: 0 };
    setDef(prev);
    setTick((t) => t + 1);
  }, []);
  const redo = useCallback(() => {
    const next = future.current.pop();
    if (!next) return;
    past.current.push(defRef.current);
    lastRecord.current = { key: '', at: 0 };
    setDef(next);
    setTick((t) => t + 1);
  }, []);

  const selectedNode = selection?.kind === 'node' ? def.nodes.find((n) => n.id === selection.id) ?? null : null;

  const addNode = useCallback(
    (type: NodeType, pos?: { x: number; y: number }) => {
      if (readOnly) return;
      const cur = defRef.current;
      if (type === 'trigger' && cur.nodes.some((n) => n.type === 'trigger')) {
        toast.warning('A workflow has exactly one Event Trigger.');
        return;
      }
      const sel = selection?.kind === 'node' ? cur.nodes.find((n) => n.id === selection.id) : null;
      const p = pos ?? spotFor(cur, sel);
      const node = { id: newId(type), type, x: p.x, y: p.y, data: NODE_SPECS[type].defaults() };
      let next: Definition = { ...cur, nodes: [...cur.nodes, node] };
      // added by click: connect the first free port of the selected step
      if (!pos && sel && type !== 'trigger') {
        const free = NODE_SPECS[sel.type].ports.find((port) => !cur.edges.some((e) => e.from === sel.id && e.label === port));
        if (free) next = setEdge(next, sel.id, free, node.id);
      }
      apply(next, true);
      setSelection({ kind: 'node', id: node.id });
      if (!pos) requestAnimationFrame(() => canvasRef.current?.centerOn(node.id));
    },
    [apply, readOnly, selection, toast],
  );

  const patchNode = useCallback(
    (id: string, patch: NodeData) => {
      const cur = defRef.current;
      apply({ ...cur, nodes: cur.nodes.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...patch } } : n)) }, true, undefined, `patch:${id}:${Object.keys(patch).join(',')}`);
    },
    [apply],
  );

  const deleteNode = useCallback(
    (id: string) => {
      const n = defRef.current.nodes.find((x) => x.id === id);
      if (!n || n.type === 'trigger') return;
      apply(removeNode(defRef.current, id), true);
      setSelection(null);
    },
    [apply],
  );

  const duplicateNode = useCallback(
    (id: string) => {
      const n = defRef.current.nodes.find((x) => x.id === id);
      if (!n || n.type === 'trigger') return;
      const copy = { ...n, id: newId(n.type), x: n.x + 30, y: n.y + 30, data: JSON.parse(JSON.stringify(n.data)) };
      apply({ ...defRef.current, nodes: [...defRef.current.nodes, copy] }, true);
      setSelection({ kind: 'node', id: copy.id });
    },
    [apply],
  );

  const deleteSelection = useCallback(() => {
    if (readOnly || !selection) return;
    if (selection.kind === 'node') deleteNode(selection.id);
    else {
      apply(setEdge(defRef.current, selection.from, selection.label, null), true);
      setSelection(null);
    }
  }, [apply, deleteNode, readOnly, selection]);

  const layout = () => {
    apply(autoLayout(defRef.current), true);
    setFitSignal((n) => n + 1);
  };

  const setSettings = (s: Partial<Settings>) => {
    setSettingsState((cur) => ({ ...cur, ...s }));
    if (s.name !== undefined) setNameError(s.name.trim() ? null : 'Enter a name for the workflow.');
  };

  const save = useCallback(async (): Promise<Workflow | null> => {
    if (readOnly) return null;
    if (!settings.name.trim()) {
      setNameError('Enter a name for the workflow.');
      setSelection(null);
      toast.error('Enter a name for the workflow.');
      return null;
    }
    setBusy('save');
    try {
      const id = wfId;
      // first save of a new workflow creates it with the canvas as version 1; later saves bump the version
      const res = id
        ? await api.put<Workflow>(`whatsapp/workflows/${id}`, { ...settings, definition: defRef.current, version })
        : await api.post<Workflow>('whatsapp/workflows', { ...settings, definition: defRef.current });
      const d = normalizeDefinition(res.data.definition);
      loaded.current = `${res.data.id}:${res.data.version}`;
      setVersion(res.data.version);
      setDef(d);
      setSaved(JSON.stringify({ def: d, s: settings }));
      const errs = (res.data.issues ?? []).filter((i) => i.level === 'error').length;
      toast[errs || /values were cleared/.test(res.message) ? 'warning' : 'success'](res.message);
      refresh();
      if (!wfId) navigate(`/whatsapp/automations/${res.data.id}`, { replace: true });
      return res.data;
    } catch (e) {
      const err = e as ApiError;
      if (err.errors?.name) setNameError(err.errors.name);
      toast.error(err.message);
      if (err.status === 409) refetch();
      return null;
    } finally {
      setBusy(null);
    }
  }, [navigate, readOnly, refetch, refresh, settings, toast, version, wfId]);

  const setStatus = async (status: WorkflowStatus) => {
    // save first (a new workflow is created once — the id comes from that save, not from this render's closure)
    let id = wfId;
    if (!id || dirty) {
      const savedWf = await save();
      if (!savedWf) return;
      id = savedWf.id;
    }
    if (status === 'active' && errorCount) {
      toast.error(`Fix ${errorCount} issue(s) before activating — see Workflow settings › Checks.`);
      setSelection(null);
      return;
    }
    // a warning, not a block: the Event Manager may already message contacts on the same event
    const double = status === 'active' ? issues.find((i) => i.code === 'duplicate_message') : undefined;
    if (double) {
      const ok = await confirm({ title: 'Contacts may get two messages', message: double.message, confirmText: 'Activate anyway' });
      if (!ok) return;
    }
    if (status === 'inactive' && (wf?.run_counts?.waiting ?? 0) + (wf?.run_counts?.running ?? 0) > 0) {
      const ok = await confirm({ title: 'Deactivate this workflow?', message: 'No new runs will start. Runs already in progress finish their remaining steps.', confirmText: 'Deactivate' });
      if (!ok) return;
    }
    setBusy('status');
    try {
      const res = await api.post<Workflow>(`whatsapp/workflows/${id}/status`, { status });
      const warn = (res.data.issues ?? []).some((i) => i.code === 'deadline' || i.code === 'schedule' || i.code === 'duplicate_message');
      toast[warn ? 'warning' : 'success'](res.message);
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const duplicate = async () => {
    if (!wfId) return;
    try {
      const res = await api.post<{ id: number }>(`whatsapp/workflows/${wfId}/duplicate`);
      toast.success(res.message);
      refresh();
      navigate(`/whatsapp/automations/${res.data.id}`);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const remove = async () => {
    if (!wf) return;
    if (!(await confirm({ title: `Delete “${wf.name}”?`, message: `The workflow and its ${formatNumber(wf.runs_total)} run(s) of history are deleted permanently.`, confirmText: 'Delete workflow', danger: true }))) return;
    try {
      const res = await api.del(`whatsapp/workflows/${wf.id}`);
      toast.success(res.message);
      refresh();
      loaded.current = '';
      setSaved(JSON.stringify({ def, s: settings }));
      navigate('/whatsapp/automations', { replace: true });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const openTest = async () => {
    if (!wfId) {
      const r = await save();
      if (!r) return;
    }
    setTestOpen(true);
  };

  // keyboard shortcuts
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (!readOnly) save();
        return;
      }
      if (isTyping(e.target) || window.innerWidth < 1024) return;
      if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) {
        e.preventDefault();
        undo();
      } else if (mod && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) {
        e.preventDefault();
        redo();
      } else if ((e.key === 'Delete' || e.key === 'Backspace') && selection) {
        e.preventDefault();
        deleteSelection();
      } else if (e.key === 'Escape') {
        setSelection(null);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [deleteSelection, readOnly, redo, save, selection, undo]);

  const status: WorkflowStatus = wf?.status ?? 'draft';
  const title = settings.name.trim() || 'Untitled workflow';

  if (!isNew && error) {
    const e = error as ApiError;
    return (
      <>
        <PageHeader title="Workflow" breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Automation Builder', to: '/whatsapp/automations' }, { label: 'Workflow' }]} />
        {e.status === 404 ? (
          <Card>
            <EmptyState icon={X} title="Workflow not found" description="It may have been deleted." action={<Button to="/whatsapp/automations">Back to workflows</Button>} />
          </Card>
        ) : (
          <Alert variant="error" title="Unable to load the workflow" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
            {e.message}
          </Alert>
        )}
      </>
    );
  }

  const loading = !isNew && isLoading;
  const edgeSel = selection?.kind === 'edge' ? selection : null;
  const edgeFrom = edgeSel ? nodeMap(def)[edgeSel.from] : null;
  const edgeTo = edgeSel ? nodeMap(def)[def.edges.find((e) => e.from === edgeSel.from && e.label === edgeSel.label)?.to ?? ''] : null;

  return (
    <>
      <PageHeader
        title={loading ? 'Workflow' : title}
        description={
          loading ? undefined : (
            <span className="inline-flex flex-wrap items-center gap-2">
              <StatusBadge status={isNew ? 'draft' : status} colors={STATUS_COLORS} />
              <span>{isNew ? 'New workflow — not saved yet' : `Version ${version}`}</span>
              {dirty && !readOnly && <Badge color="amber">Unsaved changes</Badge>}
              {readOnly && <Badge color="slate">View only</Badge>}
            </span>
          )
        }
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Automation Builder', to: '/whatsapp/automations' }, { label: loading ? 'Workflow' : title }]}
        actions={
          !loading && (
            <>
              {wfId && (
                <Button variant="secondary" icon={History} to={`/whatsapp/automations?tab=runs&workflow=${wfId}`}>
                  Runs
                </Button>
              )}
              {canEdit && (
                <Button variant="secondary" icon={FlaskConical} onClick={openTest} disabled={busy !== null}>
                  Test
                </Button>
              )}
              {canEdit && (
                <Button variant={dirty ? 'primary' : 'secondary'} icon={Save} loading={busy === 'save'} disabled={!dirty && !isNew} onClick={() => save()} className="hidden lg:inline-flex">
                  Save
                </Button>
              )}
              {can('whatsapp_automation', 'publish') &&
                (status === 'active' && !isNew ? (
                  <Button variant="secondary" icon={PowerOff} loading={busy === 'status'} onClick={() => setStatus('inactive')}>
                    Deactivate
                  </Button>
                ) : (
                  <Button variant="success" icon={Power} loading={busy === 'status'} onClick={() => setStatus('active')} title={errorCount ? `${errorCount} issue(s) to fix first` : undefined}>
                    Activate
                  </Button>
                ))}
              {wfId && (can('whatsapp_automation', 'create') || can('whatsapp_automation', 'delete') || can('whatsapp_automation', 'publish')) && (
                <Dropdown
                  label="More actions"
                  triggerClassName="btn btn-secondary !px-2.5"
                  trigger={<MoreHorizontal className="h-4 w-4" />}
                  items={[
                    can('whatsapp_automation', 'publish') && { label: 'Enrol a contact…', icon: UserPlus, disabled: status !== 'active' || dirty, hint: status !== 'active' ? 'activate first' : dirty ? 'save first' : undefined, onClick: () => setEnrollOpen(true) },
                    can('whatsapp_automation', 'create') && { label: 'Duplicate', icon: Copy, onClick: duplicate },
                    can('whatsapp_automation', 'delete') && { label: 'Delete workflow', icon: Trash2, danger: true, disabled: status === 'active', hint: status === 'active' ? 'deactivate first' : undefined, onClick: remove },
                  ]}
                />
              )}
            </>
          )
        }
      />

      {loading ? (
        <Skeleton className="h-[560px] w-full rounded-2xl" />
      ) : (
        <>
          {/* mobile / tablet: read-only step list */}
          <div className="space-y-4 lg:hidden">
            <Alert variant="info" title="Edit on a larger screen">
              The drag-and-drop builder needs a desktop or laptop. Here you can review the steps, test and activate.
            </Alert>
            {issues.length > 0 && (
              <details className="rounded-xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-900">
                <summary className="cursor-pointer text-slate-600 dark:text-slate-300">
                  {errorCount > 0 && <span className="font-semibold text-red-600 dark:text-red-300">{errorCount} error(s)</span>} {warnCount > 0 && <span className="text-amber-700 dark:text-amber-300">{warnCount} warning(s)</span>}
                </summary>
                <ul className="mt-2 space-y-1.5">
                  {[...issues].sort((a, b) => (a.level === b.level ? 0 : a.level === 'error' ? -1 : 1)).map((i, k) => (
                    <li key={k} className={clsx('flex items-start gap-1.5 rounded-lg px-2.5 py-1.5 text-xs', i.level === 'error' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200')}>
                      <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                      <span className="min-w-0 break-words">{i.message}</span>
                    </li>
                  ))}
                </ul>
              </details>
            )}
            {def.nodes.some((n) => deadlineKeys(n, meta).length > 0) && <DeadlineNote meta={meta} keys={Array.from(new Set(def.nodes.flatMap((n) => deadlineKeys(n, meta))))} />}
            <MobileStepList def={def} meta={meta} />
            {wf && (
              <Card className="p-4">
                <PerformancePanel workflowId={wf.id} onFocusNode={() => undefined} />
              </Card>
            )}
          </div>

          {/* desktop builder */}
          <Card className="hidden overflow-hidden lg:block">
            <div className="grid h-[calc(100vh-14rem)] min-h-[580px] grid-cols-[12rem_minmax(0,1fr)_19rem] 2xl:grid-cols-[14.5rem_minmax(0,1fr)_22rem]">
              <aside className="min-h-0 border-r border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/40" aria-label="Step palette">
                <Palette onAdd={(t) => addNode(t)} disabled={readOnly} hasTrigger={def.nodes.some((n) => n.type === 'trigger')} />
              </aside>
              <div className="flex min-h-0 min-w-0 flex-col">
                <div className="flex items-center gap-1 border-b border-slate-100 px-2 py-1.5 dark:border-slate-800">
                  <IconButton icon={Undo2} label="Undo (Ctrl+Z)" size="sm" disabled={readOnly || !past.current.length} onClick={undo} />
                  <IconButton icon={Redo2} label="Redo (Ctrl+Y)" size="sm" disabled={readOnly || !future.current.length} onClick={redo} />
                  <span className="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700" aria-hidden />
                  <IconButton icon={LayoutGrid} label="Auto-arrange steps" size="sm" disabled={readOnly} onClick={layout} />
                  <IconButton icon={Maximize2} label="Fit to screen" size="sm" onClick={() => canvasRef.current?.fit()} />
                  <IconButton icon={Trash2} label="Delete selection (Del)" size="sm" tone="danger" disabled={readOnly || !selection || (selection.kind === 'node' && selectedNode?.type === 'trigger')} onClick={deleteSelection} />
                  {trace && (
                    <button type="button" onClick={() => setTrace(undefined)} className="ml-1 inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700 hover:bg-violet-200 dark:bg-violet-500/20 dark:text-violet-200">
                      <Eraser className="h-3 w-3" aria-hidden /> Clear test trace
                    </button>
                  )}
                  <div className="ml-auto flex items-center gap-2 pr-1">
                    <button
                      type="button"
                      onClick={() => setSelection(null)}
                      className={clsx(
                        'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                        errorCount ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-200' : warnCount ? 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
                      )}
                      title="Show the checks in Workflow settings"
                    >
                      {errorCount || warnCount ? <AlertTriangle className="h-3.5 w-3.5" aria-hidden /> : <CircleCheck className="h-3.5 w-3.5" aria-hidden />}
                      {errorCount ? `${errorCount} error${errorCount > 1 ? 's' : ''}` : warnCount ? `${warnCount} warning${warnCount > 1 ? 's' : ''}` : 'Valid'}
                    </button>
                    <span className="hidden text-[11px] text-slate-400 2xl:inline">Drag background to pan · Ctrl + wheel to zoom</span>
                  </div>
                </div>
                <div className="relative min-h-0 flex-1">
                  <Canvas ref={canvasRef} def={def} meta={meta} selection={selection} onSelect={setSelection} onChange={onCanvasChange} onDropType={(t, p) => addNode(t, p)} issues={issues} readOnly={readOnly} trace={trace} fitSignal={fitSignal} />
                  {def.nodes.length <= 1 && !readOnly && (
                    <div className="pointer-events-none absolute inset-x-0 top-1/2 mx-auto max-w-sm -translate-y-1/2 rounded-2xl border border-dashed border-slate-300 bg-white/80 p-5 text-center text-sm text-slate-500 backdrop-blur dark:border-slate-700 dark:bg-slate-900/80 dark:text-slate-400">
                      Choose the trigger event, then drag steps from the left and connect them by dragging from a step's bottom dot.
                    </div>
                  )}
                </div>
              </div>
              <aside className="min-h-0 border-l border-slate-100 dark:border-slate-800" aria-label="Step settings">
                {selectedNode ? (
                  <NodeInspector
                    key={selectedNode.id}
                    def={def}
                    node={selectedNode}
                    meta={meta}
                    issues={issues}
                    readOnly={readOnly}
                    onPatch={patchNode}
                    onConnect={(from, port, to) => apply(setEdge(defRef.current, from, port, to), true)}
                    onDelete={deleteNode}
                    onDuplicate={duplicateNode}
                    onClose={() => setSelection(null)}
                  />
                ) : edgeSel && edgeFrom && edgeTo ? (
                  <div className="space-y-4 p-4">
                    <p className="font-display text-sm font-semibold text-slate-900 dark:text-white">Connection</p>
                    <p className="text-sm text-slate-600 dark:text-slate-300">
                      <span className="font-semibold">{nodeTitle(edgeFrom)}</span>
                      {edgeSel.label !== 'next' && (
                        <span className={clsx('mx-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase', PORT_TONE[edgeSel.label])}>{PORT_LABEL[edgeSel.label]}</span>
                      )}
                      {' → '}
                      <span className="font-semibold">{nodeTitle(edgeTo)}</span>
                    </p>
                    {!readOnly && (
                      <Button size="sm" variant="danger" icon={Trash2} onClick={deleteSelection}>
                        Delete connection
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" onClick={() => setSelection(null)}>
                      Back to workflow settings
                    </Button>
                  </div>
                ) : (
                  <WorkflowPanel
                    settings={settings}
                    setSettings={setSettings}
                    nameError={nameError}
                    wf={wf}
                    meta={meta}
                    def={def}
                    issues={issues}
                    readOnly={readOnly}
                    onFocusNode={(id) => {
                      setSelection({ kind: 'node', id });
                      canvasRef.current?.centerOn(id);
                    }}
                  />
                )}
              </aside>
            </div>
          </Card>
        </>
      )}
      {(wfId || loaded.current) && (
        <>
          {wfId && <EnrollModal open={enrollOpen} onClose={() => setEnrollOpen(false)} workflowId={wfId} />}
          <TestRunModal open={testOpen} onClose={() => setTestOpen(false)} workflowId={wfId ?? Number(loaded.current.split(':')[0])} def={def} meta={meta} onTrace={setTrace} />
        </>
      )}
    </>
  );
}
