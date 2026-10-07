import { useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, ArrowLeft, ArrowRight, ArrowRightLeft, BadgeCheck, Ban, CalendarClock, CheckCircle2, ClipboardCheck, Clock, FileCheck2, FileText, GraduationCap, Hourglass,
  IndianRupee, Mail, MessageCircle, MoreHorizontal, Pencil, Phone, PhoneCall, Printer, Receipt, Trash2, Undo2, Upload, UserCheck, UserX, History,
  type LucideIcon,
} from 'lucide-react';
import {
  Avatar, Button, Card, CardHeader, CardSkeleton, DescriptionList, Dropdown, EmptyState, PageHeader, ProgressBar, Reveal, Select, Skeleton, Tabs, Timeline,
  useConfirm, useToast, type TimelineItem, type Tone,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate, useLookup } from '@/lib/queries';
import { printUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatMoney, timeAgo, toNumber } from '@/lib/format';
import { DueChip, PIPELINE, STAGE_TONE, StageBadge, StageMoveModal, sourceLabel, stageLabel, type MoveRequest } from './shared';
import { OverviewTab } from './view/OverviewTab';
import { DocumentsTab } from './view/DocumentsTab';
import { CounsellingTab } from './view/CounsellingTab';
import { AssessmentTab, FeesTab } from './view/AssessmentFeesTabs';
import type { AdmissionProfile, Transition } from './view/types';

const SHORT_STAGE: Record<string, string> = {
  enquiry: 'Enquiry', application: 'Application', document_verification: 'Documents', entrance_interview: 'Interview', approval: 'Approval', fee_payment: 'Fee Payment', confirmed: 'Confirmed',
};

const TIMELINE_ICONS: Record<string, { icon: LucideIcon; tone: Tone }> = {
  followup: { icon: PhoneCall, tone: 'blue' }, document: { icon: Upload, tone: 'slate' }, verified: { icon: FileCheck2, tone: 'green' }, doc_rejected: { icon: Ban, tone: 'red' },
  fee: { icon: IndianRupee, tone: 'green' }, activity: { icon: Pencil, tone: 'slate' },
};

export default function AdmissionViewPage() {
  const { id } = useParams();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'overview';
  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    if (t === 'overview') next.delete('tab');
    else next.set('tab', t);
    setParams(next, { replace: true });
  };
  const { data, isLoading, error, refetch } = useApi<AdmissionProfile>(['adm-profile', Number(id)], `admissions/${id}/profile`, undefined, { retry: false });

  if (error) {
    const notFound = (error as ApiError).status === 404;
    return (
      <>
        <PageHeader title="Application" breadcrumbs={[{ label: 'Admissions', to: '/admissions' }, { label: 'Applications', to: '/admissions/list' }, { label: `#${id}` }]} />
        <Card>
          <EmptyState
            icon={notFound ? FileText : AlertTriangle}
            title={notFound ? 'Application not found' : 'Unable to load the application'}
            description={(error as ApiError).message}
            action={
              <>
                <Button variant="secondary" icon={ArrowLeft} to="/admissions/list">Back to applications</Button>
                {!notFound && <Button onClick={() => refetch()}>Retry</Button>}
              </>
            }
          />
        </Card>
      </>
    );
  }
  if (isLoading || !data) return <ProfileSkeleton />;
  const a = data.admission;
  const docsVerified = data.documents.filter((d) => d.status === 'verified').length;

  return (
    <>
      <Header data={data} />
      <Reveal>
        <PipelineCard data={data} onTab={setTab} />
      </Reveal>
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          <Tabs
            className="mb-5"
            value={tab}
            onChange={setTab}
            tabs={[
              { key: 'overview', label: 'Overview' },
              { key: 'documents', label: 'Documents', count: `${docsVerified}/${data.documents.length}` },
              { key: 'counselling', label: 'Counselling', count: data.followups.length },
              { key: 'assessment', label: 'Assessment' },
              { key: 'fees', label: 'Fees' },
              { key: 'timeline', label: 'Timeline', count: data.timeline.length },
            ]}
          />
          <div key={tab} className="animate-fade-in">
            {tab === 'overview' && <OverviewTab data={data} />}
            {tab === 'documents' && <DocumentsTab data={data} />}
            {tab === 'counselling' && <CounsellingTab data={data} />}
            {tab === 'assessment' && <AssessmentTab data={data} />}
            {tab === 'fees' && <FeesTab data={data} />}
            {tab === 'timeline' && (
              <Card>
                <CardHeader title="Timeline" subtitle="Every stage change, follow-up, document and payment" icon={History} />
                <div className="card-body">
                  <Timeline
                    items={data.timeline.map<TimelineItem>((ev) => {
                      const m = ev.type === 'stage' ? { icon: ArrowRightLeft, tone: STAGE_TONE[ev.stage ?? ''] ?? 'slate' } : TIMELINE_ICONS[ev.type] ?? { icon: Clock, tone: 'slate' as Tone };
                      return {
                        icon: m.icon,
                        tone: m.tone,
                        title: <span className="font-medium">{ev.title}</span>,
                        description: ev.description,
                        time: `${ev.user ? `${ev.user} · ` : ''}${formatDateTime(ev.at)} (${timeAgo(ev.at)})`,
                      };
                    })}
                    empty="No activity recorded yet."
                  />
                </div>
              </Card>
            )}
          </div>
        </div>
        <aside className="space-y-6">
          <SummaryCard data={data} />
          <NextFollowupCard data={data} onTab={setTab} />
          {data.student && (
            <Card className="border-emerald-200 dark:border-emerald-500/30">
              <CardHeader title="Student record" icon={GraduationCap} />
              <div className="card-body space-y-3">
                <DescriptionList
                  columns={1}
                  items={[
                    { label: 'Student ID', value: <span className="font-mono">{data.student.student_uid}</span> },
                    { label: 'Admission no · Roll no', value: `${data.student.admission_no} · ${data.student.roll_no ?? '—'}` },
                    { label: 'Converted', value: a.converted_at ? formatDateTime(a.converted_at) : null },
                  ]}
                />
                {data.can.view_student && (
                  <Button size="sm" variant="secondary" className="w-full" icon={ArrowRight} to={`/students/${data.student.id}`}>
                    Open student profile
                  </Button>
                )}
              </div>
            </Card>
          )}
        </aside>
      </div>
    </>
  );
}

/* ------------------------------------------------------------------ Header */

function Header({ data }: { data: AdmissionProfile }) {
  const a = data.admission;
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const approved = !!a.offer_letter_no;
  const paid = toNumber(a.fee_paid) > 0;
  const canDelete = data.can.delete && !a.student_id && !paid;
  const del = async () => {
    const ok = await confirm({ title: 'Delete application?', message: <>Delete <strong>{String(a.full_name)}</strong> ({String(a.application_no)})? Documents, follow-ups and history will be removed. This cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/admissions/${a.id}`);
      toast.success(res.message);
      await invalidate('crud', 'adm-board', 'adm-dashboard');
      navigate('/admissions/list');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  return (
    <PageHeader
      title={String(a.full_name)}
      description={
        <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="font-mono text-slate-700 dark:text-slate-200">{String(a.application_no)}</span>
          <span aria-hidden>·</span>
          <span>{String(a.program_name)}{a.course_name ? ` — ${a.course_name}` : ''}</span>
          <span aria-hidden>·</span>
          <span>Session {String(a.session_name ?? '')}</span>
        </span>
      }
      breadcrumbs={[{ label: 'Admissions', to: '/admissions' }, { label: 'Applications', to: '/admissions/list' }, { label: String(a.full_name) }]}
      actions={
        <>
          <Dropdown
            label="Print"
            trigger={<><Printer className="h-4 w-4" />Print</>}
            items={[
              { label: 'Application form', icon: FileText, onClick: () => window.open(printUrl('admission-form.php', { id: Number(a.id) }), '_blank') },
              { label: approved ? 'Offer letter' : 'Offer letter (after approval)', icon: BadgeCheck, disabled: !approved, onClick: () => window.open(printUrl('offer-letter.php', { id: Number(a.id) }), '_blank') },
              { label: paid ? 'Admission receipt' : 'Admission receipt (after payment)', icon: Receipt, disabled: !paid, onClick: () => window.open(printUrl('admission-receipt.php', { id: Number(a.id) }), '_blank') },
            ]}
          />
          {data.can.edit && (
            <Button variant="secondary" icon={Pencil} to={`/admissions/${a.id}/edit`}>
              Edit
            </Button>
          )}
          {canDelete && (
            <Dropdown label="More actions" triggerClassName="btn-icon" trigger={<MoreHorizontal className="h-5 w-5" />} items={[{ label: 'Delete application', icon: Trash2, danger: true, onClick: del }]} />
          )}
        </>
      }
    />
  );
}

/* ------------------------------------------------------------------ Pipeline / actions */

function lastPipelineStage(data: AdmissionProfile): string {
  const keys = PIPELINE.map((s) => s.key);
  if (keys.includes(String(data.admission.stage))) return String(data.admission.stage);
  const reached = data.history.map((h) => h.to_stage).filter((s) => keys.includes(s));
  return reached[reached.length - 1] ?? 'application';
}

function PipelineCard({ data, onTab }: { data: AdmissionProfile; onTab: (t: string) => void }) {
  const a = data.admission;
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const [busy, setBusy] = useState<string | null>(null);
  const [moveReq, setMoveReq] = useState<MoveRequest | null>(null);
  const stage = String(a.stage);
  const closed = ['rejected', 'withdrawn', 'waitlisted'].includes(stage);
  const converted = !!a.student_id;
  const t = (kind: Transition['kind']) => data.transitions.find((x) => x.kind === kind);
  const forward = t('approve') ?? t('next') ?? t('reopen');
  const due = toNumber(a.admission_fee ?? data.default_fee);
  const feePaid = toNumber(a.fee_paid) >= due && due > 0;
  const canConvert = data.can.convert && !converted && ['fee_payment', 'confirmed'].includes(stage) && feePaid;
  const names = `${String(a.full_name)} · ${String(a.application_no)}`;

  const move = async (tr: Transition) => {
    if (['rejected', 'withdrawn', 'waitlisted'].includes(tr.stage)) {
      setMoveReq({ ids: [Number(a.id)], to: tr.stage, names });
      return;
    }
    if (tr.kind === 'approve') {
      const ok = await confirm({ title: 'Approve application?', message: <>Approve <strong>{String(a.full_name)}</strong> for {String(a.program_name)}? An offer letter number is generated and the applicant is emailed the admission fee details.</>, confirmText: 'Approve' });
      if (!ok) return;
    }
    setBusy(tr.stage);
    try {
      const res = await api.post(`admissions/${a.id}/move`, { to_stage: tr.stage });
      toast.success(res.message);
      await invalidate('adm-profile', 'adm-board', 'adm-dashboard', 'crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const convert = async () => {
    const ok = await confirm({
      title: 'Convert to student?',
      message: (
        <div className="space-y-2">
          <p>Create the student record for <strong>{String(a.full_name)}</strong> in {String(a.program_name)}?</p>
          <ul className="list-disc space-y-1 pl-5 text-xs">
            <li>Student ID, admission number and roll number are generated as per settings</li>
            <li>Parents, semester 1 academic record and verified documents are copied</li>
            <li>The admission fee is posted to Fees as a paid invoice with receipt</li>
          </ul>
        </div>
      ),
      confirmText: 'Create student',
    });
    if (!ok) return;
    setBusy('convert');
    try {
      const res = await api.post<{ student_uid: string }>(`admissions/${a.id}/convert`);
      toast.success(res.message, 'Admission confirmed');
      await invalidate('adm-profile', 'adm-board', 'adm-dashboard', 'crud');
    } catch (e) {
      toast.error((e as ApiError).message, 'Conversion failed');
    } finally {
      setBusy(null);
    }
  };

  const others = data.transitions.filter((x) => x !== forward);
  const icons: Record<string, LucideIcon> = { back: Undo2, rejected: Ban, withdrawn: UserX, waitlisted: Hourglass, next: ArrowRight, approve: BadgeCheck, reopen: Undo2 };

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-5 p-5 xl:flex-row xl:items-center">
        <div className="flex min-w-0 items-center gap-4 xl:w-[330px] xl:shrink-0">
          <Avatar name={String(a.full_name)} src={a.photo as string | null} size="xl" className="ring-4 ring-brand-50 dark:ring-brand-500/10" />
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <StageBadge stage={stage} />
              {converted && <span className="badge badge-green"><GraduationCap className="h-3 w-3" />Enrolled</span>}
            </div>
            <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
              {sourceLabel(String(a.source))} · applied {formatDate(a.created_at)}
            </p>
            <p className="text-xs text-slate-500 dark:text-slate-400">{Number(a.days_in_stage)} day{Number(a.days_in_stage) === 1 ? '' : 's'} in {stageLabel(stage).toLowerCase()}</p>
            <div className="mt-2 flex flex-wrap gap-1.5">
              {a.phone && <a href={`tel:${String(a.phone).replace(/\s/g, '')}`} className="btn btn-secondary btn-xs" aria-label="Call applicant"><Phone className="h-3.5 w-3.5" />Call</a>}
              {a.whatsapp && <a href={`https://wa.me/${String(a.whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noreferrer" className="btn btn-secondary btn-xs" aria-label="WhatsApp applicant"><MessageCircle className="h-3.5 w-3.5" />WhatsApp</a>}
              {a.email && <a href={`mailto:${a.email}`} className="btn btn-secondary btn-xs" aria-label="Email applicant"><Mail className="h-3.5 w-3.5" />Email</a>}
            </div>
          </div>
        </div>
        <div className="min-w-0 flex-1 border-t border-slate-100 pt-5 dark:border-slate-800 xl:border-l xl:border-t-0 xl:pl-6 xl:pt-0">
          <PipelineStepper current={closed ? lastPipelineStage(data) : stage} closed={closed ? stage : null} />
        </div>
      </div>

      <div className="border-t border-slate-100 bg-slate-50/60 px-5 py-4 dark:border-slate-800 dark:bg-slate-800/30">
        {converted ? (
          <div className="flex flex-wrap items-center gap-3">
            <CheckCircle2 className="h-5 w-5 text-emerald-600" aria-hidden />
            <p className="text-sm text-slate-700 dark:text-slate-200">Admission confirmed and enrolled as <strong className="font-mono">{data.student?.student_uid ?? ''}</strong>.</p>
          </div>
        ) : (
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div className="min-w-0 flex-1">
              {closed ? (
                <p className={clsx('text-sm', stage === 'rejected' ? 'text-red-700 dark:text-red-300' : 'text-slate-700 dark:text-slate-200')}>
                  <strong>{stageLabel(stage)}.</strong> {stage === 'rejected' && a.rejection_reason ? String(a.rejection_reason) : stage === 'waitlisted' ? 'Approve when a seat becomes available, or reject.' : 'Reopen the application if the applicant comes back.'}
                </p>
              ) : stage === 'confirmed' || (stage === 'fee_payment' && feePaid) ? (
                <p className="text-sm text-slate-700 dark:text-slate-200"><strong>Ready to enrol.</strong> The admission fee is paid — convert the applicant to a student to generate the Student ID and roll number.</p>
              ) : forward ? (
                <>
                  <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">Next step: {forward.kind === 'approve' ? 'Approve & issue offer letter' : forward.label}</p>
                  {forward.blockers.length > 0 ? (
                    <ul className="mt-1 space-y-0.5">
                      {forward.blockers.map((b) => (
                        <li key={b} className="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400"><AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />{b}</li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-xs text-emerald-700 dark:text-emerald-400">All requirements met.</p>
                  )}
                </>
              ) : null}
            </div>
            {data.can.edit && (
              <div className="flex flex-wrap items-center gap-2">
                {forward && forward.blockers.some((b) => /document/i.test(b)) && <Button size="sm" variant="secondary" icon={FileCheck2} onClick={() => onTab('documents')}>Documents</Button>}
                {forward && forward.blockers.some((b) => /score/i.test(b)) && <Button size="sm" variant="secondary" icon={ClipboardCheck} onClick={() => onTab('assessment')}>Add scores</Button>}
                {forward && forward.blockers.some((b) => /fee/i.test(b)) && <Button size="sm" variant="secondary" icon={IndianRupee} onClick={() => onTab('fees')}>Record fee</Button>}
                {others.map((tr) => {
                  const Icon = icons[tr.kind] ?? ArrowRightLeft;
                  const label = tr.kind === 'back' ? `Back to ${tr.label}` : tr.kind === 'rejected' ? 'Reject' : tr.kind === 'withdrawn' ? 'Withdraw' : tr.kind === 'waitlisted' ? 'Waitlist' : tr.label;
                  const blocked = tr.blockers.length > 0;
                  return (
                    <Button key={tr.stage} size="sm" variant={tr.kind === 'rejected' ? 'ghost' : 'secondary'} className={clsx(tr.kind === 'rejected' && '!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-500/10')} icon={Icon}
                      disabled={blocked} title={blocked ? tr.blockers.join(' ') : undefined} loading={busy === tr.stage} onClick={() => move(tr)}>
                      {label}
                    </Button>
                  );
                })}
                {forward && !(stage === 'fee_payment' && feePaid && canConvert) && (
                  <Button size="sm" variant={forward.kind === 'approve' ? 'success' : 'primary'} icon={icons[forward.kind] ?? ArrowRight} disabled={forward.blockers.length > 0} title={forward.blockers.join(' ') || undefined}
                    loading={busy === forward.stage} onClick={() => move(forward)}>
                    {forward.kind === 'approve' ? 'Approve' : forward.kind === 'reopen' ? 'Reopen' : forward.stage === 'confirmed' ? 'Confirm admission' : `Move to ${forward.label}`}
                  </Button>
                )}
                {canConvert && (
                  <Button size="sm" variant="success" icon={UserCheck} loading={busy === 'convert'} onClick={convert}>
                    Convert to student
                  </Button>
                )}
              </div>
            )}
          </div>
        )}
      </div>
      <StageMoveModal request={moveReq} onClose={() => setMoveReq(null)} />
    </Card>
  );
}

function PipelineStepper({ current, closed }: { current: string; closed: string | null }) {
  const idx = PIPELINE.findIndex((s) => s.key === current);
  return (
    <div>
      <ol className="flex w-full items-start" aria-label="Admission stages">
        {PIPELINE.map((s, i) => {
          const done = i < idx || (i === idx && current === 'confirmed');
          const active = i === idx && current !== 'confirmed';
          return (
            <li key={s.key} className="relative flex min-w-0 flex-1 flex-col items-center text-center" aria-current={active ? 'step' : undefined}>
              {i > 0 && <span className={clsx('absolute right-1/2 top-3.5 h-0.5 w-full -translate-y-1/2', i <= idx ? 'bg-accent-500' : 'bg-slate-200 dark:bg-slate-700')} aria-hidden />}
              <span
                className={clsx(
                  'relative z-[1] inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold transition',
                  done ? 'bg-accent-600 text-white' : active ? (closed ? 'bg-red-500 text-white ring-4 ring-red-100 dark:ring-red-500/20' : 'bg-brand-800 text-white ring-4 ring-brand-100 dark:bg-brand-500 dark:ring-brand-500/20') : 'bg-slate-100 text-slate-500 dark:bg-slate-800',
                )}
              >
                {done ? <CheckCircle2 className="h-4 w-4" aria-hidden /> : i + 1}
              </span>
              <span className={clsx('mt-1.5 hidden w-full truncate px-0.5 text-[11px] font-medium sm:block', active ? 'text-brand-800 dark:text-white' : done ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400')} title={s.label}>
                {SHORT_STAGE[s.key] ?? s.label}
              </span>
            </li>
          );
        })}
      </ol>
      <p className="mt-2 text-center text-xs text-slate-500 sm:hidden">
        Step {idx + 1} of {PIPELINE.length} · <span className="font-semibold text-slate-700 dark:text-slate-200">{closed ? `${stageLabel(closed)} at ${SHORT_STAGE[current]}` : PIPELINE[idx]?.label}</span>
      </p>
    </div>
  );
}

/* ------------------------------------------------------------------ Sidebar */

function SummaryCard({ data }: { data: AdmissionProfile }) {
  const a = data.admission;
  const toast = useToast();
  const invalidate = useInvalidate();
  const { data: users = [] } = useLookup('users');
  const [saving, setSaving] = useState(false);
  const required = data.required_docs.length;
  const verified = data.required_docs.filter((r) => r.verified).length;
  const assign = async (uid: string) => {
    setSaving(true);
    try {
      const res = await api.post(`admissions/${a.id}/assign`, { assigned_to: uid || null });
      toast.success(res.message);
      await invalidate('adm-profile', 'adm-board', 'crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  const row = (label: string, value: ReactNode) => (
    <div className="flex items-start justify-between gap-3 py-2.5 text-sm">
      <dt className="text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="text-right font-medium text-slate-800 dark:text-slate-100">{value ?? <span className="text-slate-400">—</span>}</dd>
    </div>
  );
  return (
    <Card>
      <CardHeader title="Application summary" icon={FileText} />
      <div className="card-body !pt-2">
        <dl className="divide-y divide-slate-100 dark:divide-slate-800">
          {row('Application no', <span className="font-mono text-xs">{String(a.application_no)}</span>)}
          {row('Applied on', formatDate(a.created_at))}
          {row('Source', sourceLabel(String(a.source)))}
          {data.enquiry && row('From enquiry', <Link className="link" to={`/enquiries?q=${encodeURIComponent(String(a.phone ?? ''))}`}>#{data.enquiry.id} · {formatDate(data.enquiry.created_at)}</Link>)}
          {row('Qualifying %', a.previous_percentage ? `${toNumber(a.previous_percentage).toFixed(2)}%` : null)}
          {row('Score', a.score !== null && a.score !== undefined ? toNumber(a.score).toFixed(1) : null)}
          {row('Admission fee', a.admission_fee ? `${formatMoney(a.fee_paid)} / ${formatMoney(a.admission_fee)}` : `Default ${formatMoney(data.default_fee)}`)}
          {a.approved_at && row('Approved', `${formatDate(a.approved_at)}${a.approved_by_name ? ` · ${a.approved_by_name}` : ''}`)}
        </dl>
        <div className="mt-3">
          <div className="flex justify-between text-xs text-slate-500"><span>Required documents</span><span>{verified}/{required} verified</span></div>
          <ProgressBar className="mt-1.5" value={required ? (verified / required) * 100 : 0} tone={verified === required ? 'green' : 'amber'} label="Documents verified" />
        </div>
        <div className="mt-4">
          <label htmlFor="assign-counsellor" className="form-label">Counsellor</label>
          {data.can.edit ? (
            <Select id="assign-counsellor" value={a.assigned_to ? String(a.assigned_to) : ''} disabled={saving} onChange={(e) => assign(e.target.value)} options={users} placeholder="Unassigned" />
          ) : (
            <p className="text-sm text-slate-700 dark:text-slate-200">{String(a.counsellor_name ?? 'Unassigned')}</p>
          )}
        </div>
      </div>
    </Card>
  );
}

function NextFollowupCard({ data, onTab }: { data: AdmissionProfile; onTab: (t: string) => void }) {
  const open = data.followups.filter((f) => f.is_open).sort((x, y) => String(x.next_followup_date).localeCompare(String(y.next_followup_date)))[0];
  const last = data.followups[0];
  return (
    <Card>
      <CardHeader title="Follow-up" icon={CalendarClock} actions={data.can.edit && !['confirmed', 'rejected', 'withdrawn'].includes(String(data.admission.stage)) ? <Button size="xs" variant="soft" icon={PhoneCall} onClick={() => onTab('counselling')}>Log</Button> : undefined} />
      <div className="card-body space-y-3 text-sm">
        {open ? (
          <div className="flex items-center justify-between gap-2">
            <span className="text-slate-600 dark:text-slate-300">Next on {formatDate(open.next_followup_date)}</span>
            <DueChip date={open.next_followup_date} />
          </div>
        ) : (
          <p className="text-slate-500">No follow-up scheduled.</p>
        )}
        {last && (
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
            <p className="text-xs font-semibold text-slate-500">Last interaction · {timeAgo(last.created_at)}</p>
            <p className="mt-1 line-clamp-3 text-slate-700 dark:text-slate-200">{last.notes}</p>
          </div>
        )}
      </div>
    </Card>
  );
}

function ProfileSkeleton() {
  return (
    <>
      <div className="mb-6 space-y-2">
        <Skeleton className="h-3 w-48" />
        <Skeleton className="h-7 w-64" />
        <Skeleton className="h-4 w-80" />
      </div>
      <Card className="p-5">
        <div className="flex items-center gap-4">
          <Skeleton className="h-20 w-20 rounded-full" />
          <div className="flex-1 space-y-2"><Skeleton className="h-4 w-40" /><Skeleton className="h-3 w-64" /></div>
        </div>
        <Skeleton className="mt-5 h-10 w-full" />
      </Card>
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2"><CardSkeleton lines={6} /><CardSkeleton lines={4} /></div>
        <CardSkeleton lines={6} />
      </div>
    </>
  );
}
