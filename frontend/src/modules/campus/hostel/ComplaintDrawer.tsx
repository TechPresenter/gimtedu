import { useEffect, useState, type FormEvent } from 'react';
import { CheckCircle2, CirclePlay, Lock, MessageSquareWarning, Pencil, Printer, RotateCcw, UserCog, Wrench } from 'lucide-react';
import { Alert, Button, Combobox, DescriptionList, Drawer, Field, Modal, PageLoader, PersonCell, StatusBadge, Textarea, Timeline, Toggle, useConfirm, useToast, type TimelineItem } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { CrudRecordPayload } from '@/components/crud';
import type { Row } from '@/lib/types';
import { useHostelRefresh } from './AllocationDialogs';
import { COMPLAINT_CATEGORIES, COMPLAINT_STATUS, COMPLAINT_STATUS_COLORS, PRIORITY_COLORS, floorLabel, hoursLabel, openHostelPrint } from './shared';

type WorkflowAction = 'assign' | 'resolve' | 'reopen' | null;

/** Complaint detail + workflow (assign → in progress → resolved → closed, reopen). */
export function ComplaintDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useHostelRefresh();
  const { data, isLoading, error } = useApi<CrudRecordPayload>(['hostel', 'complaint', id], `crud/hostel_complaints/${id}`, undefined, { enabled: !!id });
  const { data: meta } = useCrudMeta('hostel_complaints');
  const [modal, setModal] = useState<WorkflowAction>(null);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const c = data?.row as Row | undefined;
  const canEdit = can('hostel', 'edit');

  const quick = async (action: 'start' | 'close') => {
    if (!c) return;
    if (action === 'close') {
      const ok = await confirm({ title: 'Close complaint?', message: `Close ${c.complaint_no} after the resident has confirmed the fix? It can be reopened later if needed.`, confirmText: 'Close complaint' });
      if (!ok) return;
    }
    setBusy(true);
    try {
      const res = await api.post(`hostel/complaints/${c.id}/${action}`, {});
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const timeline: TimelineItem[] = [];
  if (c) {
    timeline.push({ icon: MessageSquareWarning, tone: 'red', title: <>Raised{c.student_name ? <> by <strong>{String(c.student_name)}</strong></> : ' at the warden office'}</>, time: formatDateTime(c.created_at) });
    if (c.assigned_at) timeline.push({ icon: UserCog, tone: 'blue', title: <>Assigned to <strong>{String(c.assigned_to)}</strong></>, time: formatDateTime(c.assigned_at) });
    if (c.status === 'in_progress') timeline.push({ icon: Wrench, tone: 'amber', title: 'Work in progress', time: c.updated_at ? `Updated ${timeAgo(c.updated_at)}` : undefined });
    if (c.resolved_at) timeline.push({ icon: CheckCircle2, tone: 'green', title: 'Resolved', description: String(c.resolution ?? ''), time: `${formatDateTime(c.resolved_at)} · took ${hoursLabel((new Date(String(c.resolved_at).replace(' ', 'T')).getTime() - new Date(String(c.created_at).replace(' ', 'T')).getTime()) / 3_600_000)}` });
    if (c.closed_at) timeline.push({ icon: Lock, tone: 'slate', title: 'Closed', time: formatDateTime(c.closed_at) });
  }

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-xl"
        title={c ? String(c.subject) : 'Complaint'}
        description={c ? `${c.complaint_no ?? ''} · ${c.hostel_name}` : undefined}
        footer={
          c && (
            <div className="flex w-full flex-wrap justify-end gap-2">
              <Button variant="secondary" size="sm" icon={Printer} onClick={() => openHostelPrint(`complaint/${c.id}`)}>Job card</Button>
              {canEdit && (
                <>
                  <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(true)}>Edit</Button>
                  {['open', 'in_progress'].includes(String(c.status)) && <Button variant="secondary" size="sm" icon={UserCog} onClick={() => setModal('assign')}>{c.assigned_to ? 'Reassign' : 'Assign'}</Button>}
                  {c.status === 'open' && <Button variant="secondary" size="sm" icon={CirclePlay} loading={busy} onClick={() => void quick('start')}>Start work</Button>}
                  {['open', 'in_progress'].includes(String(c.status)) && <Button size="sm" variant="success" icon={CheckCircle2} onClick={() => setModal('resolve')}>Resolve</Button>}
                  {c.status === 'resolved' && <Button size="sm" icon={Lock} loading={busy} onClick={() => void quick('close')}>Close</Button>}
                  {['resolved', 'closed'].includes(String(c.status)) && <Button size="sm" variant="secondary" icon={RotateCcw} onClick={() => setModal('reopen')}>Reopen</Button>}
                </>
              )}
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the complaint">{(error as Error).message}</Alert>
        ) : isLoading || !c ? (
          <PageLoader label="Loading complaint…" />
        ) : (
          <div className="space-y-6">
            <div className="flex flex-wrap gap-1.5">
              <StatusBadge status={String(c.status)} label={COMPLAINT_STATUS[String(c.status)]} colors={COMPLAINT_STATUS_COLORS} />
              <StatusBadge status={String(c.priority)} label={`${String(c.priority).replace(/^./, (x) => x.toUpperCase())} priority`} colors={PRIORITY_COLORS} dot={false} />
              <span className="badge badge-slate">{COMPLAINT_CATEGORIES[String(c.category)] ?? String(c.category)}</span>
            </div>
            {c.student_name && <PersonCell name={String(c.student_name)} src={c.student_photo as string | null} sub={String(c.student_uid ?? '')} />}
            <DescriptionList
              items={[
                { label: 'Hostel', value: `${c.hostel_name} (${c.hostel_code})` },
                { label: 'Location', value: c.room_no ? `Room ${c.room_no}${c.block_name ? ` · ${c.block_name}` : ''} · ${floorLabel(c.floor_no)}` : 'Common area' },
                { label: 'Assigned to', value: c.assigned_to ? String(c.assigned_to) : <span className="text-amber-600">Not assigned</span> },
                { label: 'Open for', value: ['open', 'in_progress'].includes(String(c.status)) ? hoursLabel(Number(c.age_hours)) : '—' },
                { label: 'Details', value: c.description ? <span className="whitespace-pre-line">{String(c.description)}</span> : null, full: true },
                ...(c.resolution ? [{ label: 'Resolution', value: String(c.resolution), full: true }] : []),
              ]}
            />
            <section>
              <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Progress</h3>
              <Timeline items={timeline} />
            </section>
          </div>
        )}
      </Drawer>
      <WorkflowModal action={modal} complaint={c ?? null} onClose={() => setModal(null)} />
      <CrudFormModal open={editing && !!c} onClose={() => setEditing(false)} meta={meta} module="hostel_complaints" id={c ? Number(c.id) : null} onSaved={() => void refresh()} />
    </>
  );
}

function WorkflowModal({ action, complaint: c, onClose }: { action: WorkflowAction; complaint: Row | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useHostelRefresh();
  const [staff, setStaff] = useState<number | null>(null);
  const [start, setStart] = useState(true);
  const [text, setText] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (action) {
      setStaff(c?.assigned_staff_id ? Number(c.assigned_staff_id) : null);
      setStart(c?.status === 'open');
      setText('');
      setErrors({});
      setFormError(null);
    }
  }, [action, c]);
  if (!action || !c) return null;
  const titles = { assign: 'Assign complaint', resolve: 'Resolve complaint', reopen: 'Reopen complaint' };
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      const body = action === 'assign' ? { assigned_staff_id: staff, start: start ? 1 : 0 } : action === 'resolve' ? { resolution: text } : { remarks: text };
      const res = await api.post(`hostel/complaints/${c.id}/${action}`, body);
      toast.success(res.message);
      await refresh();
      onClose();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      title={titles[action]}
      description={`${c.complaint_no ?? ''} · ${c.subject ?? ''}`}
      footer={<><Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button><Button type="submit" form="hostel-complaint-flow" loading={saving} variant={action === 'resolve' ? 'success' : 'primary'}>{action === 'assign' ? 'Assign' : action === 'resolve' ? 'Mark resolved' : 'Reopen'}</Button></>}
    >
      <form id="hostel-complaint-flow" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        {action === 'assign' && (
          <>
            <Field label="Assign to" required htmlFor="cf-staff" error={errors.assigned_staff_id} hint="Wardens, maintenance, mess, security and IT staff are listed first.">
              <Combobox id="cf-staff" value={staff} onChange={(v) => setStaff(v ? Number(v) : null)} optionsUrl="crud/hostel_complaints/options/assigned_staff_id" placeholder="Search staff…" invalid={!!errors.assigned_staff_id} />
            </Field>
            {c.status === 'open' && <Toggle checked={start} onChange={setStart} label="Mark as In progress" description="The assignee starts work right away." />}
          </>
        )}
        {action === 'resolve' && (
          <Field label="What was done?" required htmlFor="cf-res" error={errors.resolution}>
            <Textarea id="cf-res" rows={3} maxLength={500} value={text} onChange={(e) => setText(e.target.value)} placeholder="e.g. Electrician replaced the fan capacitor; tested OK with the resident." invalid={!!errors.resolution} />
          </Field>
        )}
        {action === 'reopen' && (
          <Field label="Why is it being reopened?" required htmlFor="cf-reopen" error={errors.remarks}>
            <Textarea id="cf-reopen" rows={3} maxLength={255} value={text} onChange={(e) => setText(e.target.value)} placeholder="e.g. Leakage started again after two days." invalid={!!errors.remarks} />
          </Field>
        )}
      </form>
    </Modal>
  );
}
