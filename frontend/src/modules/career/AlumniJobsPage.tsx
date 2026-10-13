import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AlarmClock, BriefcaseBusiness, CheckCircle2, Clock, Eye, Hourglass, IndianRupee, Lock, MapPin, Plus, RotateCcw, Send, ThumbsDown, ThumbsUp, TimerOff } from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, Field, Modal, PageHeader, Reveal, Skeleton, Stagger, StatTile, Textarea, useConfirm, useToast,
} from '@/components/ui';
import { CrudFormModal, CrudViewModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { jobRenderers } from './alumni/renderers';
import { LinkedCrudTable, JOB_TYPE_LABELS, statsKey, useRefreshModules } from './alumni/shared';

interface JobStats {
  kpis: { pending: number; open_jobs: number; expired: number; closing_soon: number; closed: number; rejected: number; last_30: number; posters: number; companies: number };
  pending: Row[];
  by_type: { type: string; label: string; total: number }[];
}

export default function AlumniJobsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useRefreshModules();
  const { data: meta } = useCrudMeta('alumni_jobs');
  const { data, isLoading, error } = useApi<JobStats>(statsKey('alumni_jobs'), 'alumni/jobs/stats');
  const [formId, setFormId] = useState<number | null | 'new'>(null);
  const [viewId, setViewId] = useState<number | null>(null);
  const [rejecting, setRejecting] = useState<Row | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const k = data?.kpis;
  const canApprove = can('alumni', 'approve');
  const canEdit = can('alumni', 'edit');

  const act = async (row: Row, action: 'approve' | 'close' | 'reopen') => {
    if (action === 'close') {
      const ok = await confirm({ title: 'Close this job post?', message: <>Students will no longer see <strong>{String(row.title)}</strong> at {String(row.company)}.</>, confirmText: 'Close post' });
      if (!ok) return;
    }
    setBusy(`${action}-${row.id}`);
    try {
      const res = await api.post(`alumni/jobs/${row.id}/${action}`);
      toast.success(res.message);
      await refresh('alumni_jobs', 'alumni');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const closeExpired = async () => {
    const ok = await confirm({ title: 'Close expired posts?', message: `${k?.expired ?? 0} open post(s) are past their last date to apply. Close them all?`, confirmText: 'Close expired' });
    if (!ok) return;
    try {
      const res = await api.post('alumni/jobs/close-expired');
      toast.success(res.message);
      await refresh('alumni_jobs', 'alumni');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulk = async (action: 'approve' | 'close', ids: number[], clear: () => void) => {
    try {
      const res = await api.post<{ errors: Record<string, string> }>('crud/alumni_jobs/bulk', { action, ids });
      if (Object.keys(res.data.errors ?? {}).length) toast.warning(res.message);
      else toast.success(res.message);
      clear();
      await refresh('alumni_jobs', 'alumni');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Job Opportunities"
        description="Openings shared by alumni — review, approve and publish them for students and fellow alumni."
        breadcrumbs={[{ label: 'Alumni', to: '/alumni' }, { label: 'Job opportunities' }]}
        actions={
          <>
            {canEdit && k && k.expired > 0 && <Button variant="secondary" icon={TimerOff} onClick={closeExpired}>Close {k.expired} expired</Button>}
            {can('alumni', 'create') && <Button icon={Plus} onClick={() => setFormId('new')}>Post a job</Button>}
          </>
        }
      />
      {error ? <Alert variant="error" title="Unable to load job statistics" className="mb-6">{(error as Error).message}</Alert> : null}

      <Stagger className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5" step={50}>
        {[
          <StatTile key="o" label="Open opportunities" icon={BriefcaseBusiness} tone="green" loading={isLoading} value={k?.open_jobs ?? 0} sub={k ? `${k.closing_soon} closing this week` : undefined} />,
          <StatTile key="p" label="Pending approval" icon={Hourglass} tone="amber" loading={isLoading} value={k?.pending ?? 0} sub="Awaiting review" />,
          <StatTile key="e" label="Expired" icon={AlarmClock} tone="red" loading={isLoading} value={k?.expired ?? 0} sub="Past the last date" />,
          <StatTile key="c" label="Closed / filled" icon={Lock} tone="purple" loading={isLoading} value={k?.closed ?? 0} sub={k ? `${k.rejected} rejected` : undefined} />,
          <StatTile key="l" label="Last 30 days" icon={Send} tone="blue" loading={isLoading} value={k?.last_30 ?? 0} sub={k ? `${k.posters} alumni posters` : undefined} />,
        ]}
      </Stagger>

      {(isLoading || (data && data.pending.length > 0)) && (
        <Reveal>
          <Card className="mb-6">
            <CardHeader title="Approval queue" subtitle="New posts from alumni waiting for review" icon={Hourglass}
              actions={<Link to="/alumni/jobs?f.job_status=pending" className="link text-xs">View all pending</Link>} />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-40" />)}</div>
              ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                  {data.pending.map((j) => (
                    <div key={String(j.id)} className="flex flex-col rounded-xl border border-amber-200/70 bg-amber-50/40 p-4 dark:border-amber-500/20 dark:bg-amber-500/5">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="truncate font-semibold text-slate-900 dark:text-white">{String(j.title)}</p>
                          <p className="truncate text-sm text-slate-600 dark:text-slate-300">{String(j.company)}</p>
                        </div>
                        <Badge color="navy">{JOB_TYPE_LABELS[String(j.job_type)] ?? String(j.job_type)}</Badge>
                      </div>
                      <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                        {j.location && <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{String(j.location)}</span>}
                        {j.salary_range && <span className="inline-flex items-center gap-1"><IndianRupee className="h-3.5 w-3.5" />{String(j.salary_range).replace('₹', '')}</span>}
                        {j.last_date && <span className="inline-flex items-center gap-1"><Clock className="h-3.5 w-3.5" />Apply by {formatDate(j.last_date)}</span>}
                      </div>
                      <div className="mt-3 flex items-center gap-2 text-xs text-slate-500">
                        {j.poster_name ? (
                          <>
                            <Avatar name={String(j.poster_name)} src={j.poster_photo as string | null} size="xs" />
                            <Link to={`/alumni/${j.alumni_id}`} className="truncate font-medium text-slate-700 hover:text-brand-700 dark:text-slate-200">{String(j.poster_name)}</Link>
                            <span className="shrink-0">· Batch {String(j.batch_year)}</span>
                          </>
                        ) : <span>Shared by the alumni cell</span>}
                        <span className="ml-auto shrink-0">{timeAgo(j.created_at)}</span>
                      </div>
                      <div className="mt-3 flex gap-2 border-t border-amber-200/60 pt-3 dark:border-amber-500/20">
                        <Button size="sm" variant="ghost" icon={Eye} onClick={() => setViewId(Number(j.id))}>Review</Button>
                        {canApprove && (
                          <>
                            <Button size="sm" variant="secondary" icon={ThumbsDown} className="ml-auto" onClick={() => setRejecting(j)}>Reject</Button>
                            <Button size="sm" variant="success" icon={ThumbsUp} loading={busy === `approve-${j.id}`} onClick={() => act(j, 'approve')}>Approve</Button>
                          </>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </Reveal>
      )}

      <LinkedCrudTable
        module="alumni_jobs"
        title="All job posts"
        addLabel="Post a job"
        onSaved={() => void refresh('alumni')}
        emptyTitle="No job opportunities yet"
        emptyText="When alumni share openings at their companies, they appear here for review and publishing."
        renderers={jobRenderers}
        bulkActions={[
          ...(canApprove ? [{ label: 'Approve', icon: ThumbsUp, onClick: (ids: number[], clear: () => void) => bulk('approve', ids, clear) }] : []),
          ...(canEdit ? [{ label: 'Close', icon: Lock, onClick: (ids: number[], clear: () => void) => bulk('close', ids, clear) }] : []),
        ]}
        rowMenu={(r) => [
          canApprove && ['pending', 'rejected'].includes(String(r.status)) && { label: 'Approve & publish', icon: CheckCircle2, onClick: () => act(r, 'approve') },
          canApprove && r.status === 'pending' && { label: 'Reject…', icon: ThumbsDown, danger: true, onClick: () => setRejecting(r) },
          canEdit && r.status === 'open' && { label: 'Close post', icon: Lock, onClick: () => act(r, 'close') },
          canEdit && r.status === 'closed' && { label: 'Re-open post', icon: RotateCcw, onClick: () => act(r, 'reopen') },
          r.apply_link && { label: 'Open application link', icon: Send, href: String(r.apply_link), target: '_blank' },
        ]}
      />

      <CrudViewModal open={viewId !== null} onClose={() => setViewId(null)} meta={meta} module="alumni_jobs" id={viewId}
        onEdit={canEdit ? () => { setFormId(viewId); setViewId(null); } : undefined} />
      <RejectModal job={rejecting} onClose={() => setRejecting(null)} onDone={() => void refresh('alumni_jobs', 'alumni')} />
      <CrudFormModal open={formId !== null} onClose={() => setFormId(null)} meta={meta} module="alumni_jobs" id={formId === 'new' ? null : formId} onSaved={() => void refresh('alumni')} />
    </>
  );
}

function RejectModal({ job, onClose, onDone }: { job: Row | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const submit = async () => {
    if (!job) return;
    setSaving(true);
    setErr(null);
    try {
      const res = await api.post(`alumni/jobs/${job.id}/reject`, { reason });
      toast.success(res.message);
      setReason('');
      onDone();
      onClose();
    } catch (e) {
      const ae = e as ApiError;
      setErr(ae.errors?.reason ?? ae.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!job}
      onClose={onClose}
      static={saving}
      size="md"
      title="Reject job post"
      description={job ? `${job.title} at ${job.company}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="danger" icon={ThumbsDown} loading={saving} onClick={submit}>Reject post</Button>
        </>
      }
    >
      <Field label="Reason" required htmlFor="reject-reason" error={err} hint="Shared with the alumni who posted the opening.">
        <Textarea id="reject-reason" rows={3} value={reason} invalid={!!err} onChange={(e) => { setReason(e.target.value); setErr(null); }} placeholder="e.g. Role requires 8+ years of experience — not suitable for students." />
      </Field>
      <p className="mt-3 text-xs text-slate-500">{formatNumber(reason.trim().length)} characters</p>
    </Modal>
  );
}
