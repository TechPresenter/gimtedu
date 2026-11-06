import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  Archive,
  ArchiveRestore,
  CheckCircle2,
  Copy,
  Download,
  Eye,
  FlaskConical,
  GitBranchPlus,
  PencilLine,
  RefreshCw,
  Send,
  SendHorizontal,
  Trash2,
  Variable,
} from 'lucide-react';
import { Alert, Badge, Button, Checkbox, EmptyState, Field, Input, Modal, PhoneInput, RadioGroup, Skeleton, Textarea, useConfirm, useToast, type DropdownItem } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth, type PermissionAction } from '@/lib/auth';
import { WaText, useWaRefresh } from '../shared';
import { CATEGORY_TONE, STATUS_COLORS, STATUS_LABELS, namedBody, useTplDetail } from './lib';
import type { TplRemote, TplRow } from './types';

type Row = Pick<TplRow, 'id' | 'name' | 'display_name' | 'status' | 'interakt_id' | 'submitted_at' | 'category'>;

const label = (t: Row) => t.display_name || t.name;

/**
 * Template actions shared by the library, the detail drawer and the designer: submit, duplicate / new version,
 * archive / restore, delete, Interakt sync, plus the simulate-review and test-send dialogs (render `dialogs`).
 */
export function useTplActions(opts: { onDeleted?: (t: Row) => void; onChanged?: () => void } = {}) {
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const refresh = useWaRefresh();
  const [busy, setBusy] = useState<string | null>(null);
  const [simulate, setSimulate] = useState<Row | null>(null);
  const [testing, setTesting] = useState<Row | null>(null);
  const [importing, setImporting] = useState(false);

  const done = async () => {
    await refresh('templates');
    opts.onChanged?.();
  };

  /** Runs an action; resolves { ok, data } (errors are toasted; field errors handed to onErrors). */
  const run = async <T,>(key: string, fn: () => Promise<{ data: T; message: string }>, onErrors?: (errors: Record<string, string>) => void): Promise<{ ok: boolean; data?: T }> => {
    setBusy(key);
    try {
      const res = await fn();
      toast.success(res.message);
      await done();
      return { ok: true, data: res.data };
    } catch (e) {
      const err = e as ApiError;
      const fields = err.errors ?? {};
      const first = Object.values(fields)[0];
      toast.error(first ? `${err.message} ${first}` : err.message);
      if (onErrors && Object.keys(fields).length) onErrors(fields);
      return { ok: false };
    } finally {
      setBusy(null);
    }
  };

  const submit = async (t: Row, onErrors?: (errors: Record<string, string>) => void) => {
    const ok = await confirm({
      title: 'Submit for Meta approval?',
      message: (
        <>
          <strong>{label(t)}</strong> will be sent to Interakt for WhatsApp (Meta) review. After submission its wording can no longer be edited in GIMT — changes need a new version.
        </>
      ),
      confirmText: 'Submit for approval',
    });
    if (!ok) return { ok: false };
    return run(`submit-${t.id}`, () => api.post(`whatsapp/templates/${t.id}/submit`), onErrors);
  };

  const duplicate = async (t: Row, asVersion = false) => {
    const r = await run<{ id: number }>(`dup-${t.id}`, () => api.post<{ id: number }>(`whatsapp/templates/${t.id}/duplicate`, { version: asVersion }));
    if (r.data?.id) navigate(`/whatsapp/templates/designer/${r.data.id}`);
    return r;
  };

  const archive = async (t: Row) => {
    const remote = !!t.interakt_id;
    const ok = await confirm({
      title: 'Archive this template?',
      message: remote ? (
        <>
          <strong>{label(t)}</strong> is hidden from the library and from template pickers in GIMT. It is <strong>not</strong> deleted in Interakt — Interakt has no delete API, so remove it in the Interakt dashboard if it is no longer needed. You can restore it any time. To change the wording instead, use “Create new version”.
        </>
      ) : (
        <>
          <strong>{label(t)}</strong> is hidden from the library and template pickers. You can restore it any time.
        </>
      ),
      confirmText: 'Archive',
    });
    if (!ok) return { ok: false };
    return run(`archive-${t.id}`, () => api.post(`whatsapp/templates/${t.id}/archive`));
  };

  const restore = (t: Row) => run(`restore-${t.id}`, () => api.post(`whatsapp/templates/${t.id}/restore`));

  const remove = async (t: Row) => {
    const ok = await confirm({
      title: 'Delete this draft?',
      message: (
        <>
          <strong>{label(t)}</strong> ({t.name}) will be permanently deleted. This cannot be undone.
        </>
      ),
      confirmText: 'Delete draft',
      danger: true,
    });
    if (!ok) return { ok: false };
    const r = await run(`delete-${t.id}`, () => api.del(`whatsapp/templates/${t.id}`));
    if (r.ok) opts.onDeleted?.(t);
    return r;
  };

  const sync = () => run('sync', () => api.post('whatsapp/templates/sync'));

  const dialogs = (
    <>
      <SimulateDialog template={simulate} onClose={() => setSimulate(null)} onDone={done} />
      <TestSendDialog template={testing} onClose={() => setTesting(null)} />
      <ImportDialog open={importing} onClose={() => setImporting(false)} onDone={done} />
    </>
  );

  return { busy, submit, duplicate, archive, restore, remove, sync, openSimulate: setSimulate, openTest: setTesting, openImport: () => setImporting(true), dialogs };
}

export type TplActions = ReturnType<typeof useTplActions>;

/** Row / card "more" menu items. */
export function tplMenuItems(t: Row, a: TplActions, can: (m: string, act?: PermissionAction) => boolean, opts: { canSimulate: boolean; onView?: () => void }): DropdownItem[] {
  const items: (DropdownItem | false)[] = [
    !!opts.onView && { label: 'View details & analytics', icon: Eye, onClick: opts.onView },
    t.status === 'draft' && can('whatsapp_templates', 'edit') && { label: 'Edit in designer', icon: PencilLine, to: `/whatsapp/templates/designer/${t.id}` },
    t.status !== 'draft' && can('whatsapp_templates', 'edit') && { label: 'Map variables', icon: Variable, to: `/whatsapp/templates/designer/${t.id}` },
    t.status === 'draft' && can('whatsapp_templates', 'approve') && { label: 'Submit for approval', icon: Send, onClick: () => void a.submit(t) },
    t.status === 'approved' && can('whatsapp', 'create') && { label: 'Send a test message', icon: SendHorizontal, onClick: () => a.openTest(t) },
    opts.canSimulate && ['pending', 'approved', 'rejected', 'paused'].includes(t.status) && { label: 'Simulate Meta review', icon: FlaskConical, onClick: () => a.openSimulate(t) },
    can('whatsapp_templates', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => void a.duplicate(t, false), divider: true },
    t.status !== 'draft' && can('whatsapp_templates', 'create') && { label: 'Create new version', icon: GitBranchPlus, onClick: () => void a.duplicate(t, true) },
    t.status !== 'archived' && can('whatsapp_templates', 'edit') && { label: 'Archive', icon: Archive, onClick: () => void a.archive(t) },
    t.status === 'archived' && can('whatsapp_templates', 'edit') && { label: 'Restore', icon: ArchiveRestore, onClick: () => void a.restore(t) },
    (t.status === 'draft' || (t.status === 'archived' && !t.interakt_id && !t.submitted_at)) && can('whatsapp_templates', 'delete') && { label: 'Delete draft', icon: Trash2, danger: true, onClick: () => void a.remove(t) },
  ];
  return items.filter(Boolean) as DropdownItem[];
}

/* ------------------------------------------------------------------ simulate review (test mode) */

const REJECT_PRESETS = [
  'INCORRECT_CATEGORY: The content is promotional but the template was submitted as Utility.',
  'INVALID_FORMAT: Variable parameters are not formatted correctly or are missing sample values.',
  'TAG_CONTENT_MISMATCH: The sample values do not match the template wording.',
  'ABUSIVE_CONTENT: The template contains content that violates WhatsApp Business policy.',
];

function SimulateDialog({ template, onClose, onDone }: { template: Row | null; onClose: () => void; onDone: () => Promise<void> }) {
  const toast = useToast();
  const [status, setStatus] = useState('approved');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (template) {
      setStatus(template.status === 'approved' ? 'rejected' : 'approved');
      setReason('');
      setErrors({});
    }
  }, [template]);

  const save = async () => {
    if (!template) return;
    setSaving(true);
    try {
      const res = await api.post(`whatsapp/templates/${template.id}/simulate`, { status, reason });
      toast.success(res.message);
      await onDone();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!err.errors || !Object.keys(err.errors).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!template}
      onClose={onClose}
      title="Simulate Meta review"
      description="Test mode only — pretend Meta reviewed this template. Notifications and listeners fire exactly as they will for a real status update."
      icon={<FlaskConical className="h-5 w-5 text-amber-600" />}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save} loading={saving} icon={CheckCircle2}>
            Apply status
          </Button>
        </>
      }
    >
      {template && (
        <div className="space-y-4">
          <p className="text-sm text-slate-600 dark:text-slate-300">
            <span className="font-semibold text-slate-900 dark:text-white">{label(template)}</span> <code className="text-xs">{template.name}</code> is currently{' '}
            <Badge color={STATUS_COLORS[template.status]}>{STATUS_LABELS[template.status]}</Badge>
          </p>
          <Field label="New status" error={errors.status}>
            <RadioGroup
              name="sim-status"
              value={status}
              onChange={setStatus}
              options={[
                { value: 'approved', label: 'Approved' },
                { value: 'rejected', label: 'Rejected' },
                { value: 'paused', label: 'Paused (low quality)' },
              ]}
            />
          </Field>
          {status === 'rejected' && (
            <Field label="Rejection reason" required error={errors.reason} hint="Shown in the library exactly as Meta's reason would be.">
              <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} invalid={!!errors.reason} placeholder="Why Meta rejected it…" />
              <div className="mt-2 flex flex-wrap gap-1.5">
                {REJECT_PRESETS.map((p) => (
                  <button
                    key={p}
                    type="button"
                    onClick={() => setReason(p)}
                    className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 transition hover:bg-accent-50 hover:text-accent-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                  >
                    {p.split(':')[0]}
                  </button>
                ))}
              </div>
            </Field>
          )}
        </div>
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------------ test send */

function TestSendDialog({ template, onClose }: { template: Row | null; onClose: () => void }) {
  const toast = useToast();
  const user = useAuth().session?.user;
  const { data, isLoading } = useTplDetail(template?.id ?? null);
  const [phone, setPhone] = useState('');
  const [vars, setVars] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (template) {
      setErrors({});
      setPhone((p) => p || (user?.phone ? String(user.phone) : ''));
    }
  }, [template, user?.phone]);
  useEffect(() => {
    if (data?.named) setVars({ ...data.named.samples });
  }, [data]);

  const send = async () => {
    if (!template) return;
    setSaving(true);
    try {
      const res = await api.post(`whatsapp/templates/${template.id}/test`, { phone, vars });
      toast.success(res.message);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const keys = Object.keys(data?.named.samples ?? {});
  return (
    <Modal
      open={!!template}
      onClose={onClose}
      size="lg"
      title="Send a test message"
      description="Sends this approved template to one WhatsApp number with the values below. Use your own number."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={send} loading={saving} icon={SendHorizontal} disabled={!phone}>
            Send test
          </Button>
        </>
      }
    >
      {template && (
        <div className="space-y-4">
          <Field label="WhatsApp number" required error={errors.phone} htmlFor="tpl-test-phone">
            <PhoneInput id="tpl-test-phone" value={phone} onChange={setPhone} invalid={!!errors.phone} />
          </Field>
          {isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : keys.length ? (
            <div>
              <p className="form-label">Variable values</p>
              <div className="grid gap-3 sm:grid-cols-2">
                {keys.map((k) => (
                  <Field key={k} label={<code className="text-xs">{`{{${k}}}`}</code>} htmlFor={`tpl-test-${k}`}>
                    <Input id={`tpl-test-${k}`} inputSize="sm" value={vars[k] ?? ''} onChange={(e) => setVars((v) => ({ ...v, [k]: e.target.value }))} />
                  </Field>
                ))}
              </div>
            </div>
          ) : (
            <p className="text-sm text-slate-500 dark:text-slate-400">This template has no variables.</p>
          )}
        </div>
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------------ import from Interakt */

function ImportDialog({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => Promise<void> }) {
  const toast = useToast();
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [saving, setSaving] = useState(false);
  const q = useQuery({
    queryKey: ['wa', 'templates', 'remote'],
    queryFn: ({ signal }) => api.get<{ mode: 'test' | 'live'; items: TplRemote[] }>('whatsapp/templates/remote', undefined, { signal }),
    enabled: open,
    staleTime: 0,
  });
  useEffect(() => {
    if (open) setSelected(new Set());
  }, [open]);
  const items = q.data?.items ?? [];

  const doImport = async (all: boolean) => {
    setSaving(true);
    try {
      const res = await api.post('whatsapp/templates/import', all ? { all: true } : { remote_ids: [...selected] });
      toast.success(res.message);
      await onDone();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  const toggle = (id: string) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title="Import templates from Interakt"
      description="Templates created in the Interakt dashboard (or Facebook Business Manager, synced into Interakt) that are not in the GIMT library yet."
      icon={<Download className="h-5 w-5 text-brand-700 dark:text-brand-300" />}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
          {q.data?.mode === 'live' && items.length > 0 && (
            <>
              <Button variant="soft" onClick={() => doImport(true)} loading={saving}>
                Import all ({items.length})
              </Button>
              <Button onClick={() => doImport(false)} loading={saving} disabled={!selected.size} icon={Download}>
                Import selected ({selected.size})
              </Button>
            </>
          )}
        </>
      }
    >
      {q.isLoading ? (
        <div className="space-y-2">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-16 w-full" />
          ))}
        </div>
      ) : q.error ? (
        <Alert variant="error" title="Interakt did not answer" action={<Button size="sm" variant="secondary" icon={RefreshCw} onClick={() => q.refetch()}>Retry</Button>}>
          {(q.error as ApiError).message}
        </Alert>
      ) : q.data?.mode !== 'live' ? (
        <EmptyState
          icon={FlaskConical}
          title="Importing needs a live Interakt connection"
          description="GIMT is in test mode, so there is no Interakt account to read templates from. Save your Interakt API key and switch the mode to Live in WhatsApp Settings, then come back here."
          action={<Button to="/whatsapp/integrations" variant="secondary">Open Integrations</Button>}
        />
      ) : !items.length ? (
        <EmptyState icon={CheckCircle2} title="Everything is already imported" description="Every template on your Interakt account is in the GIMT library." />
      ) : (
        <ul className="max-h-[55vh] divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
          {items.map((r) => (
            <li key={r.remote_id} className="flex gap-3 p-3">
              <Checkbox checked={selected.has(r.remote_id)} onChange={() => toggle(r.remote_id)} aria-label={`Import ${r.name}`} />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="font-semibold text-slate-900 dark:text-white">{r.display_name || r.name}</span>
                  <code className="text-[11px] text-slate-500">{r.name}</code>
                  <Badge color={CATEGORY_TONE[r.category]?.badge ?? 'slate'}>{CATEGORY_TONE[r.category]?.label ?? r.category}</Badge>
                  <Badge color="slate">{r.language}</Badge>
                  <Badge color={STATUS_COLORS[r.status]}>{STATUS_LABELS[r.status]}</Badge>
                </div>
                <p className="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">
                  <WaText text={namedBody({ body: r.body, variables: { header: [], body: [], buttons: {} } })} highlightVars />
                </p>
                {r.variables > 0 && <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{r.variables} variable(s) — map them to GIMT fields after importing.</p>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  );
}
