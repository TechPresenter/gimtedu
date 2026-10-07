import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlarmClock, ArrowRight, CalendarClock, CheckCheck, FilePlus2, Inbox, MessageCircle, Phone, PhoneCall, Sparkles, TrendingUp, UserPlus, Users,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, DescriptionList, Drawer, EmptyState, Field, Modal, PageHeader, PersonCell, Reveal, Select, Skeleton, Stagger, StatCard, StatusBadge, useToast,
} from '@/components/ui';
import { BarChart } from '@/components/charts';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber, formatPercent, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import type { BadgeColor } from '@/lib/status';
import { DueChip, ENQUIRY_STATUSES, FollowupForm, FollowupModal, OUTCOMES, StageBadge, followupIcon, followupLabel, sourceLabel, type FollowupTarget } from './shared';

interface Summary {
  kpis: { total: number; new_count: number; open_count: number; due_today: number; overdue: number; converted: number; interested: number; last_30: number; prev_30: number; today: number; unassigned: number; conversion_rate: number; trend: number | null };
  sources: { source: string; label: string; total: number; converted: number }[];
  statuses: Record<string, number>;
}
interface EnquiryDetail {
  enquiry: Row;
  followups: { id: number; type: string; notes: string; outcome: string | null; next_followup_date: string | null; completed_at: string | null; created_by_name: string | null; created_at: string; is_open: boolean }[];
  application: { id: number; application_no: string; stage: string } | null;
}

const STATUS_COLORS: Record<string, BadgeColor> = { new: 'blue', contacted: 'amber', interested: 'cyan', not_interested: 'red', converted: 'green', closed: 'slate' };
const PRIORITY_COLORS: Record<string, BadgeColor> = { high: 'red', medium: 'amber', low: 'slate' };
const OPEN = ['new', 'contacted', 'interested'];

export default function EnquiriesPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const { data, isLoading } = useApi<Summary>(['enq-summary'], 'enquiries/summary');
  const k = data?.kpis;
  const [drawer, setDrawer] = useState<number | null>(null);
  const [followup, setFollowup] = useState<FollowupTarget | null>(null);
  const [assign, setAssign] = useState<{ ids: number[]; clear?: () => void; current?: string } | null>(null);
  const canConvert = can('admissions', 'create');
  const convert = (r: Row) => (r.admission_id ? navigate(`/admissions/${r.admission_id}`) : navigate(`/admissions/new?enquiry=${r.id}`));

  return (
    <>
      <PageHeader
        title="Enquiries"
        description="Admission leads from the website, walk-ins, calls, campaigns and education fairs."
        breadcrumbs={[{ label: 'Admissions', to: '/admissions' }, { label: 'Enquiries' }]}
        actions={
          <Button variant="secondary" icon={ArrowRight} to="/admissions">
            Admission pipeline
          </Button>
        }
      />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="total" label="Total Enquiries" icon={MessageCircle} tone="blue" loading={isLoading} value={k?.total ?? 0}
            trend={k && k.trend !== null ? { value: formatPercent(Math.abs(k.trend)), dir: k.trend >= 0 ? 'up' : 'down', label: 'last 30 days' } : undefined} />,
          <StatCard key="new" label="New" icon={Sparkles} tone="cyan" loading={isLoading} value={k?.new_count ?? 0} to="/enquiries?f.status=new" hint={k ? `${formatNumber(k.today)} today · ${formatNumber(k.unassigned)} unassigned` : undefined} />,
          <StatCard key="today" label="Follow-ups Today" icon={CalendarClock} tone="amber" loading={isLoading} value={k?.due_today ?? 0} to="/enquiries?f.followup=today" hint="Scheduled for today" />,
          <StatCard key="overdue" label="Overdue" icon={AlarmClock} tone="red" loading={isLoading} value={k?.overdue ?? 0} to="/enquiries?f.followup=overdue" hint="Follow-up date passed" />,
          <StatCard key="interested" label="Interested" icon={TrendingUp} tone="purple" loading={isLoading} value={k?.interested ?? 0} to="/enquiries?f.status=interested" hint={k ? `${formatNumber(k.open_count)} open leads` : undefined} />,
          <StatCard key="converted" label="Converted" icon={CheckCheck} tone="green" loading={isLoading} value={k?.converted ?? 0} to="/enquiries?f.status=converted" hint={k ? `${formatPercent(k.conversion_rate, 1)} conversion rate` : undefined} />,
        ]}
      </Stagger>

      <CrudTable
        module="enquiries"
        urlState
        title="All enquiries"
        addLabel="Add enquiry"
        emptyTitle="No enquiries yet"
        emptyText="Enquiries from the website form, walk-ins and calls appear here. Add one to start following up."
        onView={(r) => setDrawer(Number(r.id))}
        renderers={{
          name: (r) => (
            <div className="min-w-[10rem] whitespace-nowrap">
              <PersonCell name={String(r.name)} sub={<span className="font-mono">{String(r.phone)}</span>} />
            </div>
          ),
          interest: (r) => (
            <div className="max-w-[12rem] leading-tight">
              <p className="truncate font-semibold text-slate-900 dark:text-white" title={String(r.interest ?? '')}>{String(r.interest ?? '—')}</p>
              {r.city && <p className="truncate text-xs text-slate-500">{String(r.city)}</p>}
            </div>
          ),
          source: (r) => <span className="badge badge-slate whitespace-nowrap">{sourceLabel(String(r.source))}</span>,
          status: (r) => <StatusBadge status={String(r.status)} label={ENQUIRY_STATUSES[String(r.status)]} colors={STATUS_COLORS} />,
          priority: (r) => <StatusBadge status={String(r.priority)} colors={PRIORITY_COLORS} dot={false} />,
          assignee_name: (r) =>
            r.assignee_name ? (
              <span className="inline-flex items-center gap-2 whitespace-nowrap"><Avatar name={String(r.assignee_name)} src={r.assignee_avatar as string | null} size="xs" />{String(r.assignee_name)}</span>
            ) : (
              <span className="text-xs text-slate-400">Unassigned</span>
            ),
          follow_up_date: (r) =>
            r.follow_up_date ? (
              <div className="whitespace-nowrap leading-tight">
                <p>{formatDate(r.follow_up_date)}</p>
                {OPEN.includes(String(r.status)) && <DueChip date={String(r.follow_up_date)} className="mt-0.5 !text-[10px]" />}
              </div>
            ) : (
              <span className="text-slate-400">—</span>
            ),
          created_at: (r) => <span className="whitespace-nowrap" title={formatDateTime(r.created_at)}>{formatDate(r.created_at)}</span>,
        }}
        rowMenu={(r) => [
          can('enquiries', 'edit') && r.status !== 'converted' && { label: 'Log follow-up', icon: PhoneCall, onClick: () => setFollowup({ kind: 'enquiry', id: Number(r.id), name: String(r.name), sub: String(r.phone) }) },
          can('enquiries', 'edit') && { label: 'Assign counsellor', icon: UserPlus, onClick: () => setAssign({ ids: [Number(r.id)], current: r.assigned_to ? String(r.assigned_to) : '' }) },
          r.admission_id ? { label: `View application ${r.application_no ?? ''}`, icon: FilePlus2, to: `/admissions/${r.admission_id}` } : canConvert && { label: 'Convert to application', icon: FilePlus2, onClick: () => convert(r) },
          { divider: true, label: 'd' },
          { label: 'Call', icon: Phone, href: `tel:${String(r.phone).replace(/\s/g, '')}` },
          { label: 'WhatsApp', icon: MessageCircle, href: `https://wa.me/${String(r.phone).replace(/\D/g, '')}`, target: '_blank' },
        ]}
        bulkActions={can('enquiries', 'edit') ? [{ label: 'Assign counsellor', icon: Users, onClick: (ids, clear) => setAssign({ ids, clear }) }] : []}
      />

      <Reveal className="mt-6">
        <Card>
          <CardHeader title="Leads by source" subtitle="Total enquiries vs enquiries converted to applications" icon={TrendingUp} />
          <div className="card-body">
            {isLoading || !data ? (
              <Skeleton className="h-56 w-full" />
            ) : data.sources.length === 0 ? (
              <EmptyState icon={Inbox} title="No enquiries yet" />
            ) : (
              <BarChart labels={data.sources.map((s) => s.label)} height={240}
                series={[{ label: 'Enquiries', data: data.sources.map((s) => s.total), color: '#1D4ED8' }, { label: 'Converted', data: data.sources.map((s) => s.converted), color: '#22943F' }]} />
            )}
          </div>
        </Card>
      </Reveal>

      <EnquiryDrawer id={drawer} onClose={() => setDrawer(null)} onConvert={convert} onAssign={(ids, current) => setAssign({ ids, current })} />
      <FollowupModal open={!!followup} target={followup} onClose={() => setFollowup(null)} showStatus />
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
  const save = async () => {
    if (!target) return;
    setSaving(true);
    setError('');
    try {
      const res = target.ids.length === 1 ? await api.post(`enquiries/${target.ids[0]}/assign`, { assigned_to: current || null }) : await api.post('crud/enquiries/bulk', { action: 'assign', ids: target.ids, value: current || 0 });
      toast.success(res.message);
      target.clear?.();
      await invalidate('crud', 'enq-summary', 'enq-detail');
      setValue(null);
      onClose();
    } catch (e) {
      setError((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal open={!!target} onClose={() => { setValue(null); onClose(); }} static={saving} size="sm" title="Assign counsellor"
      description={target ? `${target.ids.length} enquir${target.ids.length === 1 ? 'y' : 'ies'} selected. The counsellor is notified.` : undefined}
      footer={<><Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button><Button onClick={save} loading={saving}>Assign</Button></>}>
      <Field label="Counsellor" error={error} htmlFor="assign-user">
        <Select id="assign-user" value={current} onChange={(e) => setValue(e.target.value)} options={users} placeholder="Unassigned" invalid={!!error} />
      </Field>
    </Modal>
  );
}

function EnquiryDrawer({ id, onClose, onConvert, onAssign }: { id: number | null; onClose: () => void; onConvert: (r: Row) => void; onAssign: (ids: number[], current: string) => void }) {
  const { can } = useAuth();
  const { data, isLoading, error } = useApi<EnquiryDetail>(['enq-detail', id], `enquiries/${id}/followups`, undefined, { enabled: !!id });
  const e = data?.enquiry;
  const open = e ? OPEN.includes(String(e.status)) : false;
  return (
    <Drawer open={!!id} onClose={onClose} title={e ? String(e.name) : 'Enquiry'} description={e ? `Enquiry #${e.id} · received ${formatDateTime(e.created_at)}` : undefined}
      footer={e && (
        <>
          {can('enquiries', 'edit') && <Button variant="secondary" icon={UserPlus} onClick={() => onAssign([Number(e.id)], e.assigned_to ? String(e.assigned_to) : '')}>Assign</Button>}
          {data?.application ? (
            <Button icon={ArrowRight} to={`/admissions/${data.application.id}`}>View application</Button>
          ) : (
            can('admissions', 'create') && <Button variant="success" icon={FilePlus2} onClick={() => onConvert(e)}>Convert to application</Button>
          )}
        </>
      )}>
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !e ? (
        <div className="space-y-3"><Skeleton className="h-6 w-1/2" /><Skeleton className="h-24 w-full" /><Skeleton className="h-40 w-full" /></div>
      ) : (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={String(e.status)} label={ENQUIRY_STATUSES[String(e.status)]} colors={STATUS_COLORS} />
            <Badge color={PRIORITY_COLORS[String(e.priority)] ?? 'slate'}>{String(e.priority)} priority</Badge>
            <span className="badge badge-slate">{sourceLabel(String(e.source))}</span>
            {data?.application && <StageBadge stage={data.application.stage} />}
          </div>
          <DescriptionList
            items={[
              { label: 'Phone', value: <a className="link" href={`tel:${String(e.phone).replace(/\s/g, '')}`}>{String(e.phone)}</a> },
              { label: 'Email', value: e.email ? <a className="link" href={`mailto:${e.email}`}>{String(e.email)}</a> : null },
              { label: 'Program interest', value: String(e.program_name ?? e.program_interest ?? '') || null },
              { label: 'City', value: (e.city as string) || null },
              { label: 'Assigned to', value: (e.assignee_name as string) || 'Unassigned' },
              { label: 'Next follow-up', value: e.follow_up_date ? <span className="inline-flex items-center gap-2">{formatDate(e.follow_up_date)} {open && <DueChip date={String(e.follow_up_date)} />}</span> : null },
              { label: 'Message', value: (e.message as string) || null, full: true },
              { label: 'Internal notes', value: (e.notes as string) || null, full: true },
            ]}
          />
          {open && can('enquiries', 'edit') && (
            <Card className="p-4">
              <h3 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Log follow-up</h3>
              <FollowupForm target={{ kind: 'enquiry', id: Number(e.id), name: String(e.name) }} showStatus compact />
            </Card>
          )}
          <div>
            <h3 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">History ({data?.followups.length ?? 0})</h3>
            {!data?.followups.length ? (
              <p className="rounded-xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 dark:border-slate-700">No follow-ups logged yet.</p>
            ) : (
              <ol className="space-y-3">
                {data.followups.map((f) => {
                  const Icon = followupIcon(f.type);
                  return (
                    <li key={f.id} className="flex gap-3">
                      <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><Icon className="h-4 w-4" /></span>
                      <div className="min-w-0 flex-1 text-sm">
                        <p className="font-medium text-slate-800 dark:text-slate-100">
                          {followupLabel(f.type)}{f.outcome ? <span className="font-normal text-slate-500"> · {OUTCOMES[f.outcome] ?? f.outcome}</span> : null}
                        </p>
                        <p className="text-slate-600 dark:text-slate-300">{f.notes}</p>
                        <p className={clsx('mt-0.5 text-xs text-slate-400')}>
                          {f.created_by_name ?? 'System'} · {timeAgo(f.created_at)}
                          {f.next_followup_date ? ` · next ${formatDate(f.next_followup_date)}${f.completed_at ? ' (done)' : ''}` : ''}
                        </p>
                      </div>
                    </li>
                  );
                })}
              </ol>
            )}
          </div>
        </div>
      )}
    </Drawer>
  );
}
