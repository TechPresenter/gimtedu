import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, CheckCircle2, CircleDot, Clock, Hourglass, Lock, MessageSquare, NotebookPen, Play, RotateCcw, ShieldAlert, Star, Timer, UserPlus, Users, type LucideIcon,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, DescriptionList, Drawer, EmptyState, Field, Modal, PageHeader, ProgressBar, Reveal, Select, Skeleton, Stagger, StatCard, StatusBadge,
  Textarea, Timeline, useToast, type Tone,
} from '@/components/ui';
import { BarChart, DoughnutChart } from '@/components/charts';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, formatPercent, labelize, timeAgo, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import type { BadgeColor } from '@/lib/status';

interface Summary {
  kpis: { total: number; open_count: number; in_progress: number; resolved: number; closed: number; breached: number; high_open: number; complaints: number; last_30: number;
    avg_resolution_hours: number | null; avg_rating: number | null; rated: number; sla_compliance: number | null };
  categories: { category: string; label: string; total: number; open: number }[];
  types: Record<string, number>;
}
interface Details {
  ticket: Row;
  timeline: { type: string; title: string; user: string | null; at: string; note: string | null }[];
  transitions: { status: string; label: string }[];
  can: { edit: boolean; delete: boolean };
}

const STATUS_COLORS: Record<string, BadgeColor> = { open: 'blue', in_progress: 'amber', resolved: 'green', closed: 'slate' };
const PRIORITY_COLORS: Record<string, BadgeColor> = { urgent: 'red', high: 'amber', medium: 'blue', low: 'slate' };
const TYPE_COLORS: Record<string, BadgeColor> = { feedback: 'blue', complaint: 'red', suggestion: 'cyan', grievance: 'purple' };
const CATEGORIES: Record<string, string> = {
  academic: 'Academic', infrastructure: 'Infrastructure', hostel: 'Hostel', transport: 'Transport', fees: 'Fees & Accounts', faculty: 'Faculty', administration: 'Administration',
  library: 'Library', canteen: 'Canteen', ragging: 'Ragging / Harassment', other: 'Other',
};
const TRANSITION_UI: Record<string, { label: string; icon: LucideIcon; variant: 'primary' | 'success' | 'secondary' }> = {
  in_progress: { label: 'Start progress', icon: Play, variant: 'primary' },
  resolved: { label: 'Resolve', icon: CheckCircle2, variant: 'success' },
  closed: { label: 'Close ticket', icon: Lock, variant: 'secondary' },
  open: { label: 'Reopen', icon: RotateCcw, variant: 'secondary' },
};

function hours(h: number): string {
  const abs = Math.max(0, Math.round(h));
  if (abs < 24) return `${abs}h`;
  const d = Math.floor(abs / 24);
  const r = abs % 24;
  return r ? `${d}d ${r}h` : `${d}d`;
}

function SlaBadge({ age, sla, status }: { age: number; sla: number; status: string }) {
  const done = status === 'resolved' || status === 'closed';
  let tone: 'green' | 'amber' | 'red';
  let text: string;
  if (done) {
    tone = age <= sla ? 'green' : 'amber';
    text = `Resolved in ${hours(age)}`;
  } else if (age > sla) {
    tone = 'red';
    text = `SLA breached · ${hours(age - sla)} over`;
  } else {
    tone = age > sla * 0.75 ? 'amber' : 'green';
    text = `${hours(sla - age)} left`;
  }
  const cls = { green: 'badge-green', amber: 'badge-amber', red: 'badge-red' }[tone];
  return (
    <span className="inline-flex flex-col leading-tight">
      <span className={clsx('badge whitespace-nowrap', cls)}>{tone === 'red' ? <AlertTriangle className="h-3 w-3" /> : <Timer className="h-3 w-3" />}{text}</span>
      <span className="mt-0.5 text-[10.5px] text-slate-400">Age {hours(age)} · SLA {hours(sla)}</span>
    </span>
  );
}

function Stars({ value }: { value: number }) {
  return (
    <span className="inline-flex items-center gap-0.5" aria-label={`${value} out of 5`}>
      {[1, 2, 3, 4, 5].map((i) => <Star key={i} className={clsx('h-3.5 w-3.5', i <= Math.round(value) ? 'fill-amber-400 text-amber-400' : 'text-slate-300 dark:text-slate-600')} aria-hidden />)}
    </span>
  );
}

export default function FeedbackPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const openId = Number(params.get('id')) || null;
  const { data, isLoading } = useApi<Summary>(['fb-summary'], 'feedback/summary');
  const k = data?.kpis;
  const [assign, setAssign] = useState<{ ids: number[]; clear?: () => void; current?: string } | null>(null);
  const setOpen = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('id', String(id));
    else next.delete('id');
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader title="Feedback & Complaints" description="Feedback, complaints, suggestions and grievances with SLA tracking and a resolution workflow." breadcrumbs={[{ label: 'Communication' }, { label: 'Feedback & Complaints' }]} />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="open" label="Open" icon={CircleDot} tone="blue" loading={isLoading} value={k?.open_count ?? 0} to="/feedback?f.status=open" hint={k ? `${formatNumber(k.high_open)} high / urgent` : undefined} />,
          <StatCard key="progress" label="In Progress" icon={Hourglass} tone="amber" loading={isLoading} value={k?.in_progress ?? 0} to="/feedback?f.status=in_progress" hint="Being worked on" />,
          <StatCard key="resolved" label="Resolved" icon={CheckCircle2} tone="green" loading={isLoading} value={(k?.resolved ?? 0) + (k?.closed ?? 0)} hint={k ? `${formatNumber(k.closed)} closed` : undefined} />,
          <StatCard key="breached" label="SLA Breached" icon={ShieldAlert} tone="red" loading={isLoading} value={k?.breached ?? 0} to="/feedback?f.sla=breached" hint={k?.sla_compliance !== null && k?.sla_compliance !== undefined ? `${formatPercent(k.sla_compliance, 1)} resolved within SLA` : 'Unresolved past SLA'} />,
          <StatCard key="avg" label="Avg Resolution" icon={Clock} tone="purple" loading={isLoading} value={k?.avg_resolution_hours !== null && k?.avg_resolution_hours !== undefined ? hours(k.avg_resolution_hours) : '—'} hint="From submission to resolution" />,
          <StatCard key="rating" label="Avg Rating" icon={Star} tone="orange" loading={isLoading} value={k?.avg_rating ? `${k.avg_rating.toFixed(1)} / 5` : '—'} hint={k?.avg_rating ? <Stars value={k.avg_rating} /> : 'No ratings yet'} />,
        ]}
      </Stagger>

      <CrudTable
        module="feedback"
        urlState
        title="Tickets"
        addLabel="New ticket"
        emptyTitle="No feedback or complaints yet"
        emptyText="Log a complaint received in person or by phone, or wait for submissions from the portals."
        onView={(r) => setOpen(Number(r.id))}
        renderers={{
          ticket_no: (r) => <button type="button" onClick={() => setOpen(Number(r.id))} className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200">{String(r.ticket_no)}</button>,
          subject: (r) => (
            <div className="min-w-[14rem] max-w-[22rem] leading-tight">
              <button type="button" onClick={() => setOpen(Number(r.id))} className="line-clamp-1 text-left font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{String(r.subject)}</button>
              <p className="truncate text-xs text-slate-500">
                <span className={clsx('font-semibold', { complaint: 'text-red-600 dark:text-red-400', grievance: 'text-violet-600 dark:text-violet-400', suggestion: 'text-cyan-700 dark:text-cyan-400', feedback: 'text-blue-700 dark:text-blue-400' }[String(r.type)])}>{labelize(String(r.type))}</span>
                {' · '}{Number(r.is_anonymous) ? 'Anonymous' : String(r.name)} · {labelize(String(r.submitted_by_type))}
              </p>
            </div>
          ),
          type: (r) => <StatusBadge status={String(r.type)} colors={TYPE_COLORS} dot={false} />,
          category: (r) => <span className="badge badge-slate whitespace-nowrap">{CATEGORIES[String(r.category)] ?? labelize(String(r.category ?? ''))}</span>,
          priority: (r) => <StatusBadge status={String(r.priority)} colors={PRIORITY_COLORS} />,
          status: (r) => <span className="whitespace-nowrap"><StatusBadge status={String(r.status)} colors={STATUS_COLORS} /></span>,
          age_hours: (r) => <SlaBadge age={toNumber(r.age_hours)} sla={toNumber(r.sla_hours)} status={String(r.status)} />,
          assignee_name: (r) => (r.assignee_name ? <span className="inline-flex items-center gap-2 whitespace-nowrap"><Avatar name={String(r.assignee_name)} src={r.assignee_avatar as string | null} size="xs" />{String(r.assignee_name)}</span> : <span className="text-xs text-slate-400">Unassigned</span>),
          rating: (r) => (r.rating ? <Stars value={Number(r.rating)} /> : <span className="text-slate-400">—</span>),
        }}
        rowMenu={(r) => [
          can('feedback', 'edit') && { label: 'Assign', icon: UserPlus, onClick: () => setAssign({ ids: [Number(r.id)], current: r.assigned_to ? String(r.assigned_to) : '' }) },
          can('feedback', 'edit') && { label: 'Open workflow', icon: NotebookPen, onClick: () => setOpen(Number(r.id)) },
        ]}
        bulkActions={can('feedback', 'edit') ? [{ label: 'Assign', icon: Users, onClick: (ids, clear) => setAssign({ ids, clear }) }] : []}
      />

      <div className="mt-6 grid gap-6 lg:grid-cols-12">
        <Reveal className="lg:col-span-8">
          <Card className="h-full">
            <CardHeader title="By category" subtitle="All tickets vs still open" icon={MessageSquare} />
            <div className="card-body">
              {isLoading || !data ? <Skeleton className="h-60 w-full" /> : data.categories.length === 0 ? <EmptyState icon={MessageSquare} title="No tickets yet" /> : (
                <BarChart labels={data.categories.map((c) => c.label)} height={260}
                  series={[{ label: 'Total', data: data.categories.map((c) => c.total), color: '#1D4ED8' }, { label: 'Open', data: data.categories.map((c) => c.open), color: '#F97316' }]} />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-4" delay={80}>
          <Card className="h-full">
            <CardHeader title="By type" subtitle={k ? `${formatNumber(k.last_30)} received in the last 30 days` : undefined} />
            <div className="card-body">
              {isLoading || !data ? <Skeleton className="mx-auto h-44 w-44 rounded-full" /> : (
                <DoughnutChart labels={Object.keys(data.types).map(labelize)} data={Object.values(data.types)} colors={Object.keys(data.types).map((t) => ({ feedback: '#1D4ED8', complaint: '#F43F5E', suggestion: '#06B6D4', grievance: '#8B5CF6' }[t] ?? '#64748B'))}
                  centerValue={formatNumber(k?.total ?? 0)} centerLabel="Tickets" height={170} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <TicketDrawer id={openId} onClose={() => setOpen(null)} onAssign={(id, current) => setAssign({ ids: [id], current })} />
      <AssignModal target={assign} onClose={() => setAssign(null)} />
    </>
  );
}

function AssignModal({ target, onClose }: { target: { ids: number[]; clear?: () => void; current?: string } | null; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const { data: users = [] } = useLookup('users');
  const [value, setValue] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const current = value ?? target?.current ?? '';
  const close = () => {
    setValue(null);
    setError('');
    onClose();
  };
  const save = async () => {
    if (!target) return;
    setSaving(true);
    try {
      const res = target.ids.length === 1 ? await api.post(`feedback/${target.ids[0]}/assign`, { assigned_to: current || null }) : await api.post('crud/feedback/bulk', { action: 'assign', ids: target.ids, value: current || 0 });
      toast.success(res.message);
      target.clear?.();
      await invalidate('crud', 'fb-summary', 'fb-detail');
      close();
    } catch (e) {
      setError((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal open={!!target} onClose={close} static={saving} size="sm" title="Assign ticket" description={target ? `${target.ids.length} ticket${target.ids.length === 1 ? '' : 's'} selected. Open tickets move to In Progress when assigned.` : undefined}
      footer={<><Button variant="secondary" onClick={close} disabled={saving}>Cancel</Button><Button onClick={save} loading={saving}>Assign</Button></>}>
      <Field label="Assign to" error={error} htmlFor="fb-assign">
        <Select id="fb-assign" value={current} onChange={(e) => setValue(e.target.value)} options={users} placeholder="Unassigned" invalid={!!error} />
      </Field>
    </Modal>
  );
}

const EVENT_STYLE: Record<string, { icon: LucideIcon; tone: Tone }> = {
  created: { icon: MessageSquare, tone: 'blue' }, in_progress: { icon: Play, tone: 'amber' }, resolved: { icon: CheckCircle2, tone: 'green' }, closed: { icon: Lock, tone: 'slate' },
  open: { icon: RotateCcw, tone: 'purple' }, note: { icon: NotebookPen, tone: 'cyan' }, update: { icon: UserPlus, tone: 'slate' },
};

function TicketDrawer({ id, onClose, onAssign }: { id: number | null; onClose: () => void; onAssign: (id: number, current: string) => void }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const { data, isLoading, error } = useApi<Details>(['fb-detail', id], `feedback/${id}/details`, undefined, { enabled: !!id, retry: false });
  const t = data?.ticket;
  const [resolution, setResolution] = useState('');
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<string | null>(null);
  useEffect(() => {
    setResolution(String(t?.resolution ?? ''));
    setNote('');
    setErrors({});
  }, [t?.id, t?.resolution]);

  const refresh = () => invalidate('fb-detail', 'fb-summary', 'crud');
  const change = async (status: string) => {
    if ((status === 'resolved' || status === 'closed') && !resolution.trim()) {
      setErrors({ resolution: 'Describe how the issue was resolved first.' });
      document.getElementById('fb-resolution')?.focus();
      return;
    }
    setBusy(status);
    setErrors({});
    try {
      const res = await api.post(`feedback/${id}/status`, { status, resolution });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setBusy(null);
    }
  };
  const addNote = async () => {
    if (note.trim().length < 2) {
      setErrors({ note: 'Write a note first.' });
      return;
    }
    setBusy('note');
    try {
      const res = await api.post(`feedback/${id}/note`, { note });
      toast.success(res.message);
      setNote('');
      await refresh();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
    } finally {
      setBusy(null);
    }
  };

  const age = toNumber(t?.age_hours);
  const sla = toNumber(t?.sla_hours);
  const done = t && (t.status === 'resolved' || t.status === 'closed');
  return (
    <Drawer open={!!id} onClose={onClose} width="max-w-2xl" title={t ? String(t.subject) : 'Ticket'} description={t ? `${String(t.ticket_no)} · submitted ${formatDateTime(t.created_at)}` : undefined}>
      {error ? (
        <Alert variant="error" title="Unable to open the ticket">{(error as ApiError).message}</Alert>
      ) : isLoading || !t || !data ? (
        <div className="space-y-3"><Skeleton className="h-6 w-1/2" /><Skeleton className="h-24 w-full" /><Skeleton className="h-40 w-full" /></div>
      ) : (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={String(t.status)} colors={STATUS_COLORS} />
            <StatusBadge status={String(t.priority)} colors={PRIORITY_COLORS} label={`${labelize(String(t.priority))} priority`} />
            <StatusBadge status={String(t.type)} colors={TYPE_COLORS} dot={false} />
            <Badge color="slate">{CATEGORIES[String(t.category)] ?? labelize(String(t.category ?? ''))}</Badge>
            {Number(t.is_anonymous) ? <Badge color="purple">Confidential</Badge> : null}
          </div>
          <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
            <div className="flex items-center justify-between text-xs">
              <span className="font-semibold text-slate-700 dark:text-slate-200">SLA {hours(sla)} ({labelize(String(t.priority))})</span>
              <SlaBadge age={age} sla={sla} status={String(t.status)} />
            </div>
            <ProgressBar className="mt-2" value={sla ? (age / sla) * 100 : 0} tone={done ? 'green' : age > sla ? 'red' : age > sla * 0.75 ? 'amber' : 'green'} label="SLA elapsed" />
            {!done && <p className="mt-1.5 text-[11px] text-slate-500">Due by {formatDateTime(t.sla_due_at)}</p>}
          </div>
          <div className="whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm text-slate-700 dark:bg-slate-800/50 dark:text-slate-200">{String(t.message)}</div>
          <DescriptionList
            items={[
              { label: 'Submitted by', value: Number(t.is_anonymous) ? `Confidential (${labelize(String(t.submitted_by_type))})` : `${String(t.name)} (${labelize(String(t.submitted_by_type))})` },
              { label: 'Student', value: t.student_uid ? <span className="font-mono">{String(t.student_uid)}</span> : null },
              { label: 'Email', value: !Number(t.is_anonymous) && t.email ? <a className="link" href={`mailto:${t.email}`}>{String(t.email)}</a> : null },
              { label: 'Phone', value: !Number(t.is_anonymous) && t.phone ? String(t.phone) : null },
              { label: 'Assigned to', value: (t.assignee_name as string) || 'Unassigned' },
              { label: 'Rating', value: t.rating ? <Stars value={Number(t.rating)} /> : null },
            ]}
          />
          {data.can.edit && (
            <Card className="p-4">
              <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Workflow</h3>
              <p className="mb-3 text-xs text-slate-500">Open → In progress → Resolved → Closed. Resolution notes are required to resolve or close and are emailed to the submitter.</p>
              <Field label="Resolution notes" error={errors.resolution} htmlFor="fb-resolution">
                <Textarea id="fb-resolution" rows={3} value={resolution} onChange={(e) => { setResolution(e.target.value); setErrors((x) => ({ ...x, resolution: '' })); }} maxLength={5000} invalid={!!errors.resolution} placeholder="What was done to resolve the issue?" />
              </Field>
              <div className="mt-3 flex flex-wrap gap-2">
                {data.transitions.map((tr) => {
                  const ui = TRANSITION_UI[tr.status];
                  return (
                    <Button key={tr.status} size="sm" variant={ui?.variant ?? 'secondary'} icon={ui?.icon} loading={busy === tr.status} onClick={() => change(tr.status)}>
                      {ui?.label ?? tr.label}
                    </Button>
                  );
                })}
                <Button size="sm" variant="ghost" icon={UserPlus} onClick={() => onAssign(Number(t.id), t.assigned_to ? String(t.assigned_to) : '')}>Assign</Button>
              </div>
              <div className="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                <Field label="Internal note" error={errors.note} htmlFor="fb-note">
                  <Textarea id="fb-note" rows={2} value={note} onChange={(e) => { setNote(e.target.value); setErrors((x) => ({ ...x, note: '' })); }} maxLength={2000} invalid={!!errors.note} placeholder="Visible to staff only" />
                </Field>
                <div className="mt-2 flex justify-end"><Button size="xs" variant="secondary" icon={NotebookPen} loading={busy === 'note'} onClick={addNote}>Add note</Button></div>
              </div>
            </Card>
          )}
          {done && t.resolution && !data.can.edit && <Alert variant="success" title="Resolution">{String(t.resolution)}</Alert>}
          <div>
            <h3 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Timeline</h3>
            <Timeline
              items={data.timeline.map((ev) => {
                const st = EVENT_STYLE[ev.type] ?? { icon: Clock, tone: 'slate' as Tone };
                return { icon: st.icon, tone: st.tone, title: ev.title, description: ev.note, time: `${ev.user ? `${ev.user} · ` : ''}${formatDateTime(ev.at)} (${timeAgo(ev.at)})` };
              })}
            />
          </div>
        </div>
      )}
    </Drawer>
  );
}
