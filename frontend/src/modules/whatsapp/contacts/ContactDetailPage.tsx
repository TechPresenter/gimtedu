import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowLeft, ArrowRightLeft, BookUser, CalendarClock, CircleDollarSign, ClipboardList, ClipboardPlus, ContactRound, FileText, GraduationCap, History, Inbox, Megaphone,
  MessageCircleMore, MessageSquareText, MoreHorizontal, NotebookPen, Pencil, Plus, Send, ShieldCheck, ShieldOff, StickyNote, Tag, Trash2, UserCheck, Users, Zap,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, CardSkeleton, Combobox, DescriptionList, Dropdown, EmptyState, Field, Input, PageHeader, PhoneLink, Reveal,
  Stepper, StatusBadge, Tabs, Timeline, useConfirm, useToast, type TimelineItem, type Tone,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatMoney, formatPhone, timeAgo } from '@/lib/format';
import { crmKeys, ENQUIRY_CONTACT_TYPES, isMasked, showPhone, STAGE_KEYS, TYPE_LABELS, useCrmRefresh, useFacets, stageInfo, type WaContact } from './crm';
import { CreateEnquiryDialog } from './CreateEnquiryDialog';
import {
  AssignDialog, ContactFormModal, DialogShell, NoteDialog, OptDialog, OptInBadge, PaymentBadge, SendTemplateModal, StageBadge, StageDialog, TypeBadge, WindowBadge, fieldErrors,
} from './crmUi';

interface FollowupRow {
  id: number;
  stage_key: string;
  stage_name: string;
  attempt: number;
  template_name: string | null;
  template_display: string | null;
  due_at: string;
  status: string;
  note: string | null;
}
interface HistoryRow {
  id: number;
  from_stage: string | null;
  to_stage: string;
  from_name: string | null;
  to_name: string;
  source: string;
  note: string | null;
  changed_by_name: string | null;
  created_at: string;
}
interface TimelineRow {
  kind: 'message_in' | 'message_out' | 'stage' | 'note' | 'followup' | 'event' | 'campaign' | 'agent';
  at: string;
  title: string;
  detail?: string;
  status?: string;
  ref?: number;
}
interface Detail extends WaContact {
  erp_stage: string | null;
  created_by_name: string | null;
  history: HistoryRow[];
  followups: FollowupRow[];
  stats: { messages: string; inbound: string; outbound: string; read_count: string; first_message_at: string | null };
  timeline: TimelineRow[];
  related: { id: number; name: string | null; phone: string; contact_type: string }[];
  linked: {
    student: null | { id: number; student_uid: string; admission_no: string; roll_no: string | null; name: string; mobile: string; email: string | null; status: string; current_semester: number | null; guardian_name: string | null; guardian_phone: string | null; program_name: string | null; batch_name: string | null };
    enquiry: null | { id: number; name: string; phone: string; email: string | null; status: string; source: string; priority: string; follow_up_date: string | null; created_at: string; program_interest: string | null; program_name: string | null; assigned_name: string | null };
    admission: null | { id: number; application_no: string; name: string; stage: string; fee_paid: string; admission_fee: string | null; created_at: string; stage_changed_at: string | null; program_name: string | null; assigned_name: string | null; session_name: string | null };
    fees: null | { invoices: string; total: string; paid: string; balance: string; overdue: string; next_due: string | null };
    payments: { id: number; receipt_no: string; amount: string; payment_date: string; mode: string; status: string }[];
  };
}

const FUNNEL = STAGE_KEYS.filter((k) => k !== 'not_interested');
const KIND_META: Record<TimelineRow['kind'], { icon: typeof Inbox; tone: Tone; group: string }> = {
  message_in: { icon: MessageCircleMore, tone: 'green', group: 'messages' },
  message_out: { icon: Send, tone: 'blue', group: 'messages' },
  stage: { icon: ArrowRightLeft, tone: 'purple', group: 'stage' },
  note: { icon: StickyNote, tone: 'amber', group: 'notes' },
  followup: { icon: CalendarClock, tone: 'orange', group: 'followups' },
  event: { icon: Zap, tone: 'cyan', group: 'events' },
  campaign: { icon: Megaphone, tone: 'pink', group: 'campaigns' },
  agent: { icon: UserCheck, tone: 'slate', group: 'events' },
};

export default function ContactDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCrmRefresh();
  const { data: facets } = useFacets();
  const { data: c, isLoading, error, refetch } = useQuery({ queryKey: crmKeys.contact(id ?? ''), queryFn: ({ signal }) => api.get<Detail>(`whatsapp/contacts/${id}`, undefined, { signal }), enabled: !!id });
  const [dialog, setDialog] = useState<null | 'edit' | 'template' | 'stage' | 'assign' | 'opt_in' | 'opt_out' | 'note' | 'tags'>(null);
  const [timelineFilter, setTimelineFilter] = useState('all');

  const crumbs = [{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Student Contacts', to: '/whatsapp/contacts' }];
  if (error) {
    const notFound = error instanceof ApiError && error.status === 404;
    return (
      <>
        <PageHeader title={notFound ? 'Contact not found' : 'Contact'} breadcrumbs={[...crumbs, { label: 'Contact' }]} />
        {notFound ? (
          <Card>
            <EmptyState icon={ContactRound} title="This contact no longer exists" description="It may have been merged into another contact or deleted." action={<Button icon={ArrowLeft} to="/whatsapp/contacts">Back to contacts</Button>} />
          </Card>
        ) : (
          <Alert variant="error" title="The contact could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as Error).message}</Alert>
        )}
      </>
    );
  }
  if (isLoading || !c) {
    return (
      <>
        <PageHeader title="Contact" breadcrumbs={[...crumbs, { label: 'Contact' }]} />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <CardSkeleton lines={6} />
          <div className="space-y-6 lg:col-span-2">
            <CardSkeleton lines={4} />
            <CardSkeleton lines={6} />
          </div>
        </div>
      </>
    );
  }

  const name = c.name || showPhone(c.phone);
  const isLead = ['lead', 'applicant'].includes(c.contact_type) || !!c.admission_id || !!c.enquiry_id;
  const remove = async () => {
    if (!(await confirm({ title: `Delete ${name}?`, message: 'The contact, its WhatsApp conversation, notes and follow-ups are deleted permanently. ERP records are not touched.', confirmText: 'Delete', danger: true }))) return;
    try {
      const r = await api.del(`whatsapp/contacts/${c.id}`);
      toast.success(r.message);
      refresh();
      navigate('/whatsapp/contacts');
    } catch (e) {
      toast.error((e as Error).message);
    }
  };

  const timeline: TimelineItem[] = c.timeline
    .filter((t) => timelineFilter === 'all' || KIND_META[t.kind]?.group === timelineFilter)
    .map((t) => {
      const m = KIND_META[t.kind] ?? KIND_META.agent;
      return {
        icon: m.icon,
        tone: m.tone,
        title: (
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-medium">{t.title}</span>
            {t.status && t.kind !== 'stage' && <StatusBadge status={t.status} />}
          </span>
        ),
        description: t.detail ? <span className="line-clamp-3 whitespace-pre-line">{t.detail}</span> : undefined,
        time: <span title={formatDateTime(t.at)}>{timeAgo(t.at)}</span>,
      };
    });
  const funnelCurrent = c.stage === 'not_interested' ? '' : c.stage;

  return (
    <>
      <PageHeader
        title={name}
        breadcrumbs={[...crumbs, { label: name }]}
        description={
          <span className="inline-flex flex-wrap items-center gap-x-3 gap-y-1">
            {isMasked(c.phone) ? <span>{c.phone}</span> : <PhoneLink value={`+${c.phone}`} />}
            <span>{TYPE_LABELS[c.contact_type] ?? c.contact_type}</span>
            {c.program_name && <span>{c.program_name}</span>}
          </span>
        }
        actions={
          <>
            {can('whatsapp', 'view') && (
              <Button variant="secondary" icon={MessageCircleMore} to={`/whatsapp/inbox?contact=${c.id}`}>
                Conversation
              </Button>
            )}
            {can('whatsapp', 'create') && (
              <Button variant="secondary" icon={Send} onClick={() => setDialog('template')}>
                Send template
              </Button>
            )}
            {can('whatsapp_contacts', 'edit') && (
              <Button icon={Pencil} onClick={() => setDialog('edit')}>
                Edit
              </Button>
            )}
            {can('whatsapp_contacts', 'edit') && (
              <Dropdown
                label="More actions"
                triggerClassName="btn btn-secondary px-2.5" trigger={<MoreHorizontal className="h-4 w-4" />}
                items={[
                  { label: 'Change stage', icon: ArrowRightLeft, onClick: () => setDialog('stage') },
                  { label: 'Assign counsellor', icon: UserCheck, onClick: () => setDialog('assign') },
                  { label: 'Edit tags', icon: Tag, onClick: () => setDialog('tags') },
                  { label: 'Add note', icon: NotebookPen, onClick: () => setDialog('note') },
                  c.opt_in ? { label: 'Opt out of WhatsApp', icon: ShieldOff, onClick: () => setDialog('opt_out'), divider: true } : { label: 'Record opt-in', icon: ShieldCheck, onClick: () => setDialog('opt_in'), divider: true },
                  can('whatsapp_contacts', 'delete') && { label: 'Delete contact', icon: Trash2, danger: true, divider: true, onClick: remove },
                ]}
              />
            )}
          </>
        }
      />

      {/* hero */}
      <Reveal>
        <Card className="mb-6 overflow-hidden">
          <div className="flex flex-col gap-5 p-5 md:flex-row md:items-center">
            <div className="flex min-w-0 items-center gap-4">
              <Avatar name={name} size="xl" />
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <TypeBadge type={c.contact_type} />
                  <StageBadge stage={c.stage} facets={facets} />
                  {c.status !== 'active' && <StatusBadge status={c.status} />}
                  <WindowBadge open={c.window_open} expires={c.window_expires_at} lastInbound={c.last_inbound_at} />
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {c.tags.map((t) => <Badge key={t.id} color={t.color}>{t.name}</Badge>)}
                  {can('whatsapp_contacts', 'edit') && (
                    <button type="button" onClick={() => setDialog('tags')} className="inline-flex items-center gap-1 rounded-full border border-dashed border-slate-300 px-2 py-0.5 text-[11px] font-medium text-slate-500 hover:border-accent-500 hover:text-accent-600 dark:border-slate-600 dark:text-slate-400">
                      <Plus className="h-3 w-3" aria-hidden /> Tag
                    </button>
                  )}
                </div>
              </div>
            </div>
            <dl className="grid flex-1 grid-cols-2 gap-x-6 gap-y-3 text-sm md:ml-auto md:max-w-xl md:grid-cols-4">
              <Fact label="Counsellor" value={c.counsellor_name ?? <span className="text-slate-400">Unassigned</span>} />
              <Fact label="WhatsApp opt-in" value={<OptInBadge contact={c} />} />
              <Fact label="Last message" value={c.last_message_at ? <span title={formatDateTime(c.last_message_at)}>{timeAgo(c.last_message_at)}</span> : <span className="text-slate-400">Never</span>} />
              <Fact label="Next follow-up" value={c.next_followup_at ? <span title={formatDateTime(c.next_followup_at)} className={clsx(new Date(c.next_followup_at.replace(' ', 'T')) < new Date() && 'font-semibold text-red-600 dark:text-red-400')}>{formatDateTime(c.next_followup_at)}</span> : <span className="text-slate-400">None</span>} />
            </dl>
          </div>
          {isLead && (
            <div className="border-t border-slate-100 bg-slate-50/60 px-5 py-4 dark:border-slate-800 dark:bg-slate-900/40">
              {c.stage === 'not_interested' ? (
                <p className="text-sm text-slate-600 dark:text-slate-300">Marked <strong>Not interested</strong>{c.stage_changed_at ? ` ${timeAgo(c.stage_changed_at)}` : ''}. Follow-ups are stopped.</p>
              ) : (
                <div className="-mx-1 overflow-x-auto px-1 pb-1">
                  <div className="min-w-[46rem]">
                    <Stepper steps={FUNNEL.map((k) => ({ key: k, label: stageInfo(k, facets).label }))} current={funnelCurrent} />
                  </div>
                </div>
              )}
            </div>
          )}
        </Card>
      </Reveal>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* left column */}
        <div className="space-y-6">
          <Reveal>
            <Card>
              <CardHeader title="Profile" icon={BookUser} />
              <div className="card-body">
                <DescriptionList
                  columns={1}
                  items={[
                    { label: 'Email', value: c.email ? <a href={`mailto:${c.email}`} className="text-brand-700 hover:underline dark:text-brand-300">{c.email}</a> : null },
                    { label: 'Alternate number', value: c.alt_phone ? (isMasked(c.alt_phone) ? c.alt_phone : <PhoneLink value={`+${c.alt_phone}`} />) : null },
                    { label: 'Programme / batch', value: [c.program_name, c.batch].filter(Boolean).join(' · ') || null },
                    { label: 'Location', value: [c.city, c.state].filter(Boolean).join(', ') || null },
                    { label: 'Application no', value: c.application_no },
                    { label: 'Lead source', value: c.lead_source ? <span className="capitalize">{c.lead_source.replace(/_/g, ' ')}</span> : null },
                    { label: 'Payment status', value: c.payment_status ? <PaymentBadge status={c.payment_status} /> : null },
                    { label: 'Language', value: c.language === 'hi' ? 'Hindi' : 'English' },
                    { label: 'Consent', value: c.opt_in ? `${c.opt_in_source ?? 'Opted in'}${c.opt_in_at ? ` · ${formatDate(c.opt_in_at)}` : ''}` : c.opted_out_at ? `Opted out ${formatDate(c.opted_out_at)}` : 'Not recorded' },
                    { label: 'Added', value: `${formatDateTime(c.created_at)}${c.source ? ` · via ${c.source}` : ''}${c.created_by_name ? ` by ${c.created_by_name}` : ''}` },
                    { label: 'Last synced from portal', value: c.synced_at ? formatDateTime(c.synced_at) : null },
                    ...(c.notes ? [{ label: 'Notes', value: <span className="whitespace-pre-line">{c.notes}</span> }] : []),
                  ]}
                />
              </div>
            </Card>
          </Reveal>

          <Reveal>
            <Card>
              <CardHeader title="Follow-ups" subtitle="Automatic WhatsApp follow-ups for the lead stages" icon={CalendarClock} actions={<Button size="xs" variant="ghost" to="/whatsapp/followups">Manager</Button>} />
              <div className="card-body">
                {!c.followups.length ? (
                  <p className="text-sm text-slate-500 dark:text-slate-400">No follow-ups {isLead ? 'yet — they are scheduled when the lead enters a stage with follow-ups.' : '(only leads and applicants get follow-ups).'}</p>
                ) : (
                  <ul className="space-y-3">
                    {c.followups.slice(0, 8).map((f) => (
                      <li key={f.id} className="flex items-start gap-3 text-sm">
                        <span className={clsx('mt-1.5 h-2 w-2 shrink-0 rounded-full', f.status === 'scheduled' ? 'bg-amber-500' : f.status === 'sent' ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600')} aria-hidden />
                        <div className="min-w-0 flex-1">
                          <p className="text-slate-800 dark:text-slate-100">
                            #{f.attempt} · {f.stage_name} <StatusBadge status={f.status} dot={false} />
                          </p>
                          <p className="text-xs text-slate-500">
                            {f.status === 'scheduled' ? 'Due ' : ''}{formatDateTime(f.due_at)}{f.template_name ? ` · ${f.template_display || f.template_name}` : ''}
                          </p>
                          {f.note && f.status !== 'sent' && <p className="text-xs text-slate-500">{f.note}</p>}
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </Card>
          </Reveal>

          {c.related.length > 0 && (
            <Reveal>
              <Card>
                <CardHeader title={c.contact_type === 'guardian' ? 'Student & family' : 'Parents / family'} icon={Users} />
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {c.related.map((r) => (
                    <li key={r.id}>
                      <Link to={`/whatsapp/contacts/${r.id}`} className="flex items-center gap-3 px-5 py-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <Avatar name={r.name ?? r.phone} size="sm" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate font-medium text-slate-900 dark:text-white">{r.name ?? '—'}</span>
                          <span className="text-xs text-slate-500">{showPhone(r.phone)}</span>
                        </span>
                        <TypeBadge type={r.contact_type} />
                      </Link>
                    </li>
                  ))}
                </ul>
              </Card>
            </Reveal>
          )}
        </div>

        {/* right column */}
        <div className="space-y-6 lg:col-span-2">
          <Reveal>
            <LinkedRecords c={c} />
          </Reveal>

          <Reveal>
            <Card>
              <CardHeader
                title="Activity timeline"
                subtitle={`${c.stats?.messages ?? 0} messages · ${c.stats?.inbound ?? 0} received · ${c.stats?.read_count ?? 0} read`}
                icon={History}
                actions={can('whatsapp_contacts', 'edit') && <Button size="xs" variant="secondary" icon={NotebookPen} onClick={() => setDialog('note')}>Add note</Button>}
              />
              <div className="border-b border-slate-100 px-5 pb-3 dark:border-slate-800">
                <Tabs
                  variant="pills"
                  value={timelineFilter}
                  onChange={setTimelineFilter}
                  tabs={[
                    { key: 'all', label: 'All' },
                    { key: 'messages', label: 'Messages' },
                    { key: 'stage', label: 'Stage' },
                    { key: 'followups', label: 'Follow-ups' },
                    { key: 'notes', label: 'Notes' },
                    { key: 'campaigns', label: 'Campaigns' },
                    { key: 'events', label: 'Events' },
                  ]}
                />
              </div>
              <div className="card-body">
                <Timeline items={timeline} empty="Nothing here yet." />
              </div>
            </Card>
          </Reveal>

          <Reveal>
            <Card>
              <CardHeader title="Stage history" icon={ArrowRightLeft} actions={can('whatsapp_contacts', 'edit') && <Button size="xs" variant="secondary" onClick={() => setDialog('stage')}>Change stage</Button>} />
              {!c.history.length ? (
                <div className="card-body"><p className="text-sm text-slate-500">No stage changes recorded.</p></div>
              ) : (
                <div className="table-wrap">
                  <table className="data-table text-sm">
                    <thead>
                      <tr><th>When</th><th>Change</th><th>By</th><th>Note</th></tr>
                    </thead>
                    <tbody>
                      {c.history.map((h) => (
                        <tr key={h.id}>
                          <td className="whitespace-nowrap text-xs">{formatDateTime(h.created_at)}</td>
                          <td>
                            <span className="inline-flex flex-wrap items-center gap-1.5">
                              {h.from_stage && <><StageBadge stage={h.from_stage} facets={facets} /><span className="text-slate-400">→</span></>}
                              <StageBadge stage={h.to_stage} facets={facets} />
                            </span>
                          </td>
                          <td className="text-xs">{h.changed_by_name ?? <span className="capitalize text-slate-500">{h.source}</span>}</td>
                          <td className="max-w-[16rem] truncate text-xs text-slate-500" title={h.note ?? undefined}>{h.note ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          </Reveal>
        </div>
      </div>

      <ContactFormModal open={dialog === 'edit'} onClose={() => setDialog(null)} contact={c} />
      {dialog === 'template' && <SendTemplateModal open onClose={() => setDialog(null)} contactId={c.id} contactName={name} />}
      <StageDialog open={dialog === 'stage'} onClose={() => setDialog(null)} current={c.stage} onSubmit={async (stage, note) => {
        const r = await api.post(`whatsapp/contacts/${c.id}/stage`, { stage, note });
        toast.success(r.message);
        refresh();
      }} />
      <AssignDialog open={dialog === 'assign'} onClose={() => setDialog(null)} onSubmit={async (uid) => {
        const r = await api.post(`whatsapp/contacts/${c.id}/assign`, { counsellor_id: uid });
        toast.success(r.message);
        refresh();
      }} />
      <OptDialog open={dialog === 'opt_in' || dialog === 'opt_out'} optIn={dialog === 'opt_in'} onClose={() => setDialog(null)} onSubmit={async (source) => {
        const r = await api.post(`whatsapp/contacts/${c.id}/opt`, { opt_in: dialog === 'opt_in' ? 1 : 0, source });
        toast.success(r.message);
        refresh();
      }} />
      <NoteDialog open={dialog === 'note'} onClose={() => setDialog(null)} contactId={c.id} />
      <EditTagsDialog open={dialog === 'tags'} onClose={() => setDialog(null)} contactId={c.id} current={c.tags.map((t) => t.name)} />
    </>
  );
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="mt-0.5 truncate font-medium text-slate-800 dark:text-slate-100">{value}</dd>
    </div>
  );
}

/** Student / fees / enquiry / application linked to the contact. */
function LinkedRecords({ c }: { c: Detail }) {
  const { can } = useAuth();
  const [enquiryOpen, setEnquiryOpen] = useState(false);
  const { student, enquiry, admission, fees, payments } = c.linked;
  if (!student && !enquiry && !admission) {
    const canCreate = can('whatsapp_contacts', 'edit') && can('enquiries', 'create') && ENQUIRY_CONTACT_TYPES.includes(c.contact_type) && !c.student_id && !c.admission_id;
    return (
      <Card>
        <CardHeader
          title="Admission portal records"
          icon={ClipboardList}
          actions={canCreate ? <Button size="sm" variant="secondary" icon={ClipboardPlus} onClick={() => setEnquiryOpen(true)}>Create admission enquiry</Button> : undefined}
        />
        <div className="card-body">
          <p className="text-sm text-slate-500 dark:text-slate-400">
            Not linked to an enquiry, application or student yet. The sync links contacts automatically by phone number{canCreate ? ' — or add this lead to Admissions › Enquiries now (source WhatsApp).' : '.'}
          </p>
        </div>
        {canCreate && <CreateEnquiryDialog open={enquiryOpen} onClose={() => setEnquiryOpen(false)} contact={c} origin="contact" />}
      </Card>
    );
  }
  return (
    <Card>
      <CardHeader title="Admission portal records" subtitle={enquiry?.source === 'whatsapp' && !student && !admission ? 'WhatsApp enquiry linked to this contact' :'Linked by phone number during the sync'} icon={ClipboardList} />
      <div className="grid grid-cols-1 gap-4 p-5 pt-0 md:grid-cols-2">
        {student && (
          <RecordBox icon={GraduationCap} title={c.contact_type === 'guardian' ? `Ward: ${student.name}` : 'Student'} to={can('students', 'view') ? `/students/${student.id}` : undefined}>
            <p className="font-medium text-slate-900 dark:text-white">{student.name} <StatusBadge status={student.status} /></p>
            <p>{[student.program_name, student.batch_name].filter(Boolean).join(' · ')}</p>
            <p className="text-xs text-slate-500">Adm. no {student.admission_no}{student.roll_no ? ` · Roll ${student.roll_no}` : ''}{student.current_semester ? ` · Semester ${student.current_semester}` : ''}</p>
            {c.contact_type !== 'guardian' && student.guardian_name && <p className="text-xs text-slate-500">Guardian: {student.guardian_name}{student.guardian_phone ? ` · ${isMasked(student.guardian_phone) ? student.guardian_phone : formatPhone(student.guardian_phone)}` : ''}</p>}
          </RecordBox>
        )}
        {fees && Number(fees.invoices) > 0 && (
          <RecordBox icon={CircleDollarSign} title="Fees" to={can('fees', 'view') && student ? `/students/${student.id}?tab=fees` : undefined}>
            <div className="grid grid-cols-3 gap-2">
              <Mini label="Total" value={formatMoney(fees.total)} />
              <Mini label="Paid" value={formatMoney(fees.paid)} tone="text-emerald-700 dark:text-emerald-300" />
              <Mini label="Balance" value={formatMoney(fees.balance)} tone={Number(fees.balance) > 0 ? 'text-amber-700 dark:text-amber-300' : undefined} />
            </div>
            {Number(fees.overdue) > 0 && <p className="text-xs font-semibold text-red-600 dark:text-red-400">{formatMoney(fees.overdue)} overdue</p>}
            {fees.next_due && Number(fees.balance) > 0 && <p className="text-xs text-slate-500">Next due {formatDate(fees.next_due)}</p>}
            {payments.length > 0 && <p className="text-xs text-slate-500">Last payment {formatMoney(payments[0].amount)} on {formatDate(payments[0].payment_date)} ({payments[0].receipt_no})</p>}
          </RecordBox>
        )}
        {admission && (
          <RecordBox icon={FileText} title="Application" to={can('admissions', 'view') ? `/admissions/${admission.id}` : undefined}>
            <p className="font-medium text-slate-900 dark:text-white">{admission.application_no} <StatusBadge status={admission.stage} /></p>
            <p>{admission.program_name}{admission.session_name ? ` · ${admission.session_name}` : ''}</p>
            <p className="text-xs text-slate-500">
              Fee {formatMoney(admission.fee_paid)}{admission.admission_fee ? ` of ${formatMoney(admission.admission_fee)}` : ''} paid · applied {formatDate(admission.created_at)}{admission.assigned_name ? ` · ${admission.assigned_name}` : ''}
            </p>
          </RecordBox>
        )}
        {enquiry && (
          <RecordBox icon={MessageSquareText} title="Enquiry" to={can('enquiries', 'view') ? `/enquiries?q=${encodeURIComponent(enquiry.name)}` : undefined}>
            <p className="font-medium text-slate-900 dark:text-white">{enquiry.name} <StatusBadge status={enquiry.status} /></p>
            <p>{enquiry.program_name ?? enquiry.program_interest ?? 'Programme not given'}</p>
            <p className="text-xs text-slate-500">
              {enquiry.source === 'whatsapp' ? 'WhatsApp' : <span className="capitalize">{enquiry.source.replace(/_/g, ' ')}</span>} · {formatDate(enquiry.created_at)}{enquiry.assigned_name ? ` · ${enquiry.assigned_name}` : ''}{enquiry.follow_up_date ? ` · follow-up ${formatDate(enquiry.follow_up_date)}` : ''}
            </p>
          </RecordBox>
        )}
      </div>
    </Card>
  );
}

function RecordBox({ icon: Icon, title, to, children }: { icon: typeof Inbox; title: string; to?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-slate-200 p-4 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
      <div className="mb-1 flex items-center gap-2">
        <Icon className="h-4 w-4 text-accent-600" aria-hidden />
        <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</span>
        {to && <Link to={to} className="ml-auto text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">Open</Link>}
      </div>
      {children}
    </div>
  );
}

function Mini({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div>
      <p className="text-[10px] uppercase tracking-wide text-slate-500">{label}</p>
      <p className={clsx('font-semibold text-slate-900 dark:text-white', tone)}>{value}</p>
    </div>
  );
}

/** Replace the contact's tag set. */
function EditTagsDialog({ open, onClose, contactId, current }: { open: boolean; onClose: () => void; contactId: number; current: string[] }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const { data: facets } = useFacets();
  const [tags, setTags] = useState<string[]>([]);
  const [newTag, setNewTag] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = current.join('|');
  useEffect(() => {
    if (open) {
      setTags(current);
      setNewTag('');
      setError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, key]);
  const opts = useMemo(() => [...new Set([...(facets?.tags ?? []).map((t) => t.name), ...tags])].sort().map((n) => ({ value: n, label: n })), [facets, tags]);
  const add = () => {
    if (newTag.trim()) setTags((t) => [...new Set([...t, newTag.trim()])]);
    setNewTag('');
  };
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api.post(`whatsapp/contacts/${contactId}/tags`, { tags });
      toast.success(r.message);
      refresh();
      onClose();
    } catch (e) {
      setError(fieldErrors(e).tags ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={open} onClose={onClose} title="Edit tags" busy={busy} onSubmit={submit} submitLabel="Save tags">
      <Field label="Tags" error={error}>
        <Combobox multiple options={opts} value={tags} onChange={(v) => setTags(v.map(String))} placeholder="No tags" />
      </Field>
      <Field label="New tag" htmlFor="wa-dt-new" hint="Press Enter to add.">
        <div className="flex gap-2">
          <Input id="wa-dt-new" value={newTag} maxLength={60} onChange={(e) => setNewTag(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } }} />
          <Button type="button" variant="secondary" icon={Plus} onClick={add} aria-label="Add tag" />
        </div>
      </Field>
    </DialogShell>
  );
}

