import { useEffect, useState, type FormEvent } from 'react';
import { CheckCircle2, CircleDot, Lock, Pencil, PlayCircle, RotateCcw } from 'lucide-react';
import { Alert, Badge, Button, Combobox, DescriptionList, Drawer, Field, Skeleton, StatusBadge, Textarea, Timeline, useToast, type TimelineItem } from '@/components/ui';
import { api } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, timeAgo } from '@/lib/format';
import { useActionForm } from '../hooks';
import { COMPLAINT_CATEGORIES, COMPLAINT_COLORS, COMPLAINT_STATUSES, PRIORITY_COLORS, useTransportRefresh } from '../shared';
import type { ComplaintDetail } from '../types';

const ACTIONS: Record<string, { label: string; icon: typeof CheckCircle2; variant: 'primary' | 'secondary' | 'success' | 'danger' }> = {
  in_progress: { label: 'Start work', icon: PlayCircle, variant: 'primary' },
  resolved: { label: 'Resolve', icon: CheckCircle2, variant: 'success' },
  closed: { label: 'Close', icon: Lock, variant: 'secondary' },
  open: { label: 'Reopen', icon: RotateCcw, variant: 'secondary' },
};

/** Complaint details with workflow actions (open → in progress → resolved → closed, reopen) and its history. */
export function ComplaintDrawer({ complaintId, onClose, onEdit }: { complaintId: number | null; onClose: () => void; onEdit: (id: number) => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useTransportRefresh();
  const { data, isLoading, error } = useApi<ComplaintDetail>(['transport', 'complaint', complaintId], `transport/complaints/${complaintId}`, undefined, { enabled: !!complaintId });
  const [action, setAction] = useState<string | null>(null);
  const form = useActionForm({ resolution: '', note: '', assigned_to: '' });
  const { values, set, errors } = form;
  const c = data?.complaint;

  useEffect(() => {
    setAction(null);
  }, [complaintId]);
  useEffect(() => {
    if (action && c) form.reset({ resolution: action === 'resolved' || action === 'closed' ? (c.resolution ?? '') : '', note: '', assigned_to: c.assigned_to ? String(c.assigned_to) : '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [action]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!c || !action) return;
    const ok = await form.run(async () => {
      const res = await api.post(`transport/complaints/${c.id}/status`, { status: action, ...values });
      toast.success(res.message || 'Complaint updated.');
    });
    if (ok) {
      setAction(null);
      await refresh('transport_complaints');
    }
  };

  const history: TimelineItem[] = (data?.history ?? []).map((h) => ({
    icon: h.action === 'created' ? CircleDot : (ACTIONS[h.action]?.icon ?? CircleDot),
    tone: h.action === 'resolved' ? 'green' : h.action === 'closed' ? 'slate' : h.action === 'in_progress' ? 'amber' : h.action === 'open' ? 'red' : 'blue',
    title: h.title,
    description: h.note ?? undefined,
    time: `${h.user ?? 'System'} · ${formatDateTime(h.at)}`,
  }));

  return (
    <Drawer
      open={!!complaintId}
      onClose={onClose}
      width="max-w-xl"
      title={c ? c.subject : 'Complaint'}
      description={c ? `${c.complaint_no} · raised ${timeAgo(c.created_at)}` : undefined}
      footer={
        c && can('transport', 'edit') ? (
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <Button variant="ghost" icon={Pencil} onClick={() => onEdit(c.id)}>Edit details</Button>
            <div className="flex flex-wrap gap-2">
              {(data?.transitions ?? []).map((t) => {
                const a = ACTIONS[t];
                return a ? (
                  <Button key={t} size="sm" variant={action === t ? 'primary' : a.variant} icon={a.icon} onClick={() => setAction(t)}>
                    {a.label}
                  </Button>
                ) : null;
              })}
            </div>
          </div>
        ) : undefined
      }
    >
      {error ? (
        <Alert variant="error">Unable to load this complaint.</Alert>
      ) : isLoading || !c ? (
        <div className="space-y-4"><Skeleton className="h-6 w-1/2" /><Skeleton className="h-24 w-full" /><Skeleton className="h-40 w-full" /></div>
      ) : (
        <div className="space-y-6">
          <div className="flex flex-wrap gap-2">
            <StatusBadge status={c.status} colors={COMPLAINT_COLORS} />
            <StatusBadge status={c.priority} colors={PRIORITY_COLORS} label={`${String(c.priority).replace(/^\w/, (x: string) => x.toUpperCase())} priority`} />
            <Badge color="slate">{COMPLAINT_CATEGORIES[c.category] ?? c.category}</Badge>
          </div>

          {action && (
            <form onSubmit={submit} noValidate className="space-y-3 rounded-xl border border-brand-200 bg-brand-50/50 p-4 motion-safe:animate-fade-in dark:border-brand-500/30 dark:bg-brand-500/5">
              <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                {ACTIONS[action]?.label} — move to “{COMPLAINT_STATUSES[action]}”
              </p>
              {form.error && <Alert variant="error">{form.error}</Alert>}
              {(action === 'resolved' || action === 'closed') && (
                <Field label="Resolution" required={!c.resolution} htmlFor="cd-res" error={errors.resolution}>
                  <Textarea id="cd-res" rows={3} maxLength={500} value={values.resolution} onChange={(e) => set('resolution', e.target.value)} invalid={!!errors.resolution} placeholder="What was done to resolve it?" />
                </Field>
              )}
              {action === 'in_progress' && (
                <Field label="Assign to" htmlFor="cd-assign" error={errors.assigned_to} hint="Defaults to you">
                  <Combobox id="cd-assign" source="users" value={values.assigned_to || null} onChange={(v) => set('assigned_to', v ? String(v) : '')}
                    initialOptions={c.assigned_to ? [{ value: c.assigned_to, label: c.assigned_name ?? 'Assigned user' }] : []} placeholder="Search staff user…" />
                </Field>
              )}
              <Field label={action === 'open' ? 'Reason for reopening' : 'Note'} required={action === 'open'} htmlFor="cd-note" error={errors.note}>
                <Textarea id="cd-note" rows={2} maxLength={300} value={values.note} onChange={(e) => set('note', e.target.value)} invalid={!!errors.note} />
              </Field>
              <div className="flex justify-end gap-2">
                <Button size="sm" variant="secondary" onClick={() => setAction(null)} disabled={form.saving}>Cancel</Button>
                <Button size="sm" type="submit" loading={form.saving}>Confirm</Button>
              </div>
            </form>
          )}

          <DescriptionList
            items={[
              { label: 'Raised by', value: <>{c.complainant_name}{c.student_uid ? <span className="text-slate-500"> · {c.student_uid}</span> : null}</> },
              { label: 'Phone', value: c.phone ? <a href={`tel:${String(c.phone).replace(/\s/g, '')}`} className="link">{c.phone}</a> : null },
              { label: 'Route', value: c.route_code ? `${c.route_code} — ${c.route_name}` : null },
              { label: 'Vehicle', value: c.vehicle_no },
              { label: 'Assigned to', value: c.assigned_name },
              { label: 'Raised on', value: formatDateTime(c.created_at) },
              { label: 'Details', value: c.description, full: true },
              ...(c.resolution ? [{ label: 'Resolution', value: <span className="text-emerald-700 dark:text-emerald-300">{c.resolution}</span>, full: true }] : []),
              ...(c.resolved_at ? [{ label: 'Resolved', value: `${formatDateTime(c.resolved_at)}${c.resolved_by_name ? ` by ${c.resolved_by_name}` : ''}` }] : []),
            ]}
          />

          <section>
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">History</h3>
            <Timeline items={history} empty="No updates yet." />
          </section>
        </div>
      )}
    </Drawer>
  );
}
