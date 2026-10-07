import { lazy, Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  Activity, BadgeCheck, BookOpen, BriefcaseBusiness, CalendarCheck, CalendarDays, CalendarPlus, ChevronDown, Clock, FileText, IdCard, KeyRound, LayoutDashboard, Mail,
  MoreHorizontal, Pencil, Phone, Printer, Trash2, UserRound, UserX, Wallet,
} from 'lucide-react';
import {
  Alert, Avatar, Button, Card, CardSkeleton, CountUp, Dropdown, EmptyState, PageHeader, PageLoader, Reveal, Skeleton, StatusBadge, Tabs, useConfirm, useToast,
  type DropdownItem, type TabDef,
} from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, type ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { printUrl } from '@/lib/config';
import { formatDate, formatNumber, labelize } from '@/lib/format';
import { EMPLOYEE_LABEL, EMPLOYEE_STATUSES, STAFF_CATEGORIES, employeeRef, scrollActiveTabIntoView, type EmployeeType, type NewAccount, type ProfilePayload } from '../hr';
import { CreateAccountModal, CredentialsModal } from './AccountModals';
import { LeaveFormModal } from './LeaveFormModal';
import { OverviewTab, ProfessionalTab } from './profile/OverviewTabs';

const SubjectsTab = lazy(() => import('./profile/SubjectsTab'));
const TimetableTab = lazy(() => import('./profile/TimetableTab'));
const AttendanceTab = lazy(() => import('./profile/AttendanceTab'));
const LeavesTab = lazy(() => import('./profile/LeavesTab'));
const DocumentsTab = lazy(() => import('./profile/DocumentsTab'));
const PayrollTab = lazy(() => import('./profile/PayrollTab'));
const ActivityTab = lazy(() => import('./profile/ActivityTab'));

const employmentColor: Record<string, string> = { permanent: 'badge-navy', contract: 'badge-cyan', probation: 'badge-amber', visiting: 'badge-purple', guest: 'badge-slate', outsourced: 'badge-purple', part_time: 'badge-slate' };

/** Faculty / staff profile: header card + tabbed sections (?tab=). */
export function EmployeeProfile({ type }: { type: EmployeeType }) {
  const { id } = useParams();
  const numId = Number(id);
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const labels = EMPLOYEE_LABEL[type];
  const { data, isLoading, error } = useApi<ProfilePayload>(['hr-profile', type, numId], `${type}/${numId}/profile`, undefined, { enabled: numId > 0, retry: false });
  const { data: meta } = useCrudMeta(type);
  const [editOpen, setEditOpen] = useState(false);
  const [leaveOpen, setLeaveOpen] = useState(false);
  const [loginOpen, setLoginOpen] = useState(false);
  const [account, setAccount] = useState<NewAccount | null>(null);
  const tabsRef = useRef<HTMLDivElement>(null);

  const refresh = () =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['hr-profile', type, numId] }),
      qc.invalidateQueries({ queryKey: ['crud', type] }),
      qc.invalidateQueries({ queryKey: ['hr-summary', type] }),
      qc.invalidateQueries({ queryKey: ['hr-activity', type, numId] }),
    ]);

  const tabs: TabDef[] = [
    { key: 'overview', label: 'Overview', icon: LayoutDashboard },
    { key: 'professional', label: type === 'faculty' ? 'Professional' : 'Employment', icon: BriefcaseBusiness },
    ...(type === 'faculty'
      ? [
          { key: 'subjects', label: 'Assigned Subjects', icon: BookOpen, count: data?.stats.subjects },
          { key: 'timetable', label: 'Timetable', icon: CalendarDays },
        ]
      : []),
    { key: 'attendance', label: 'Attendance', icon: CalendarCheck },
    { key: 'leaves', label: 'Leaves', icon: Clock, count: data?.stats.pending_leaves || undefined },
    { key: 'documents', label: 'Documents', icon: FileText, count: data?.stats.documents },
    { key: 'payroll', label: 'Payroll', icon: Wallet },
    { key: 'activity', label: 'Activity', icon: Activity },
  ];
  const requested = params.get('tab') ?? 'overview';
  const tab = tabs.some((t) => t.key === requested) ? requested : 'overview';
  const setTab = (k: string) => {
    const next = new URLSearchParams(params);
    if (k === 'overview') next.delete('tab');
    else next.set('tab', k);
    setParams(next, { replace: true });
  };

  useEffect(() => scrollActiveTabIntoView(tabsRef.current), [tab, data]);

  if (!numId) return <NotFound type={type} />;
  if (isLoading) return <ProfileSkeleton />;
  if (error || !data) {
    const status = (error as ApiError | null)?.status;
    return status === 404 ? <NotFound type={type} /> : (
      <>
        <PageHeader title={`${labels.singular} profile`} breadcrumbs={[{ label: 'Faculty & Staff' }, { label: labels.plural, to: labels.base }]} />
        <Alert variant="error" title="Unable to load this profile">{(error as ApiError)?.message ?? 'Please try again.'}</Alert>
      </>
    );
  }

  const p = data.person;
  const can = data.can;
  const working = ['active', 'on_leave'].includes(p.status);

  const changeStatus = async (status: string) => {
    if (status === p.status) return;
    const ok = await confirm({
      title: `Mark ${p.full_name} as ${labelize(status).toLowerCase()}?`,
      message: ['resigned', 'retired', 'inactive'].includes(status) ? 'Their login account (if any) will be deactivated. You can reactivate the profile later.' : 'The status will be updated across the system.',
      confirmText: 'Change status',
      danger: ['resigned', 'retired', 'inactive'].includes(status),
    });
    if (!ok) return;
    try {
      const res = await api.post(`${type}/${p.id}/status`, { status });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async () => {
    const ok = await confirm({ title: `Delete ${p.full_name}?`, message: <>This permanently deletes the profile together with its leave history and documents. Consider marking the {type === 'faculty' ? 'faculty member' : 'staff member'} <strong>resigned</strong> instead.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/${type}/${p.id}`);
      toast.success(res.message || `${labels.singular} deleted.`);
      await qc.invalidateQueries({ queryKey: ['crud', type] });
      navigate(labels.base);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const more: (DropdownItem | false)[] = [
    can.edit && !data.account && working && { label: 'Create login account', icon: KeyRound, onClick: () => setLoginOpen(true) },
    { label: 'Print profile', icon: Printer, href: printUrl('faculty-profile.php', { id: p.id, type }), target: '_blank' },
    can.edit && { label: '', divider: true },
    ...(can.edit ? EMPLOYEE_STATUSES.filter((s) => s.value !== p.status).map((s) => ({ label: `Mark ${s.label.toLowerCase()}`, icon: s.value === 'active' ? BadgeCheck : UserX, onClick: () => changeStatus(s.value) })) : []),
    can.delete && { label: '', divider: true },
    can.delete && { label: `Delete ${type === 'faculty' ? 'faculty' : 'staff'} member`, icon: Trash2, danger: true, onClick: remove },
  ];

  const stats: { label: string; value: ReactNode; hint?: string }[] =
    type === 'faculty'
      ? [
          { label: 'Experience', value: data.stats.experience_years !== null ? <><CountUp value={data.stats.experience_years} /> yrs</> : '—', hint: data.stats.tenure_years !== null ? `${formatNumber(data.stats.tenure_years, 1)} yrs at GIMT` : undefined },
          { label: 'Subjects', value: <CountUp value={data.stats.subjects ?? 0} />, hint: `${data.stats.sections_class_teacher ?? 0} class teacher` },
          { label: 'Periods / week', value: <CountUp value={data.stats.weekly_periods ?? 0} />, hint: 'Current timetable' },
          { label: 'Leave taken', value: <><CountUp value={data.stats.leave_taken} /> d</>, hint: `Session ${data.session.name}` },
          { label: 'Attendance', value: data.stats.attendance_percent !== null ? <><CountUp value={data.stats.attendance_percent} />%</> : '—', hint: 'This session' },
          { label: 'Documents', value: `${data.stats.documents_verified}/${data.stats.documents}`, hint: 'Verified' },
        ]
      : [
          { label: 'Tenure', value: data.stats.tenure_years !== null ? <><CountUp value={data.stats.tenure_years} /> yrs</> : '—', hint: p.joining_date ? `Since ${formatDate(p.joining_date)}` : undefined },
          { label: 'Category', value: STAFF_CATEGORIES[p.category ?? ''] ?? labelize(p.category), hint: p.section ?? undefined },
          { label: 'Leave taken', value: <><CountUp value={data.stats.leave_taken} /> d</>, hint: `Session ${data.session.name}` },
          { label: 'Pending leaves', value: <CountUp value={data.stats.pending_leaves} />, hint: 'Awaiting approval' },
          { label: 'Attendance', value: data.stats.attendance_percent !== null ? <><CountUp value={data.stats.attendance_percent} />%</> : '—', hint: 'This session' },
          { label: 'Documents', value: `${data.stats.documents_verified}/${data.stats.documents}`, hint: 'Verified' },
        ];

  return (
    <>
      <PageHeader
        title={`${type === 'faculty' ? 'Faculty' : 'Staff'} profile`}
        breadcrumbs={[{ label: 'Faculty & Staff' }, { label: labels.plural, to: labels.base }, { label: p.full_name }]}
        actions={
          <>
            <Button variant="secondary" icon={Printer} href={printUrl('faculty-profile.php', { id: p.id, type })} target="_blank">
              <span className="hidden sm:inline">Print</span>
            </Button>
            {can.create && working && (
              <Button variant="secondary" icon={CalendarPlus} onClick={() => setLeaveOpen(true)}>
                Apply leave
              </Button>
            )}
            {can.edit && (
              <Button icon={Pencil} onClick={() => setEditOpen(true)}>
                Edit profile
              </Button>
            )}
          </>
        }
      />

      {/* Header card */}
      <Reveal>
        <Card className="overflow-hidden">
          <div className="relative h-24 overflow-hidden bg-gradient-to-r from-brand-950 via-brand-900 to-brand-700 sm:h-28">
            <div className="absolute inset-0" style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,.14) 1px, transparent 1.5px)', backgroundSize: '18px 18px' }} aria-hidden />
            <div className="absolute -right-16 -top-24 h-64 w-64 rounded-full bg-accent-500/25 blur-3xl" aria-hidden />
            <div className="absolute -bottom-24 left-1/3 h-48 w-96 rounded-full bg-sky-400/10 blur-3xl" aria-hidden />
            {data.on_leave_today && (
              <span className="absolute right-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white ring-1 ring-white/25 backdrop-blur">
                <Clock className="h-3.5 w-3.5" /> On {data.on_leave_today.leave_type_name.toLowerCase()} until {formatDate(data.on_leave_today.to_date)}
              </span>
            )}
          </div>
          <div className="px-5 pb-5">
            <div className="relative -mt-10 flex flex-col gap-4 sm:-mt-12 sm:flex-row sm:items-start">
              <Avatar name={p.full_name} src={p.photo} size="xl" className="!h-24 !w-24 !text-2xl ring-4 !ring-white shadow-soft dark:!ring-slate-900" />
              <div className="min-w-0 flex-1 sm:pt-14">
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="font-display text-xl font-bold text-slate-900 dark:text-white sm:text-2xl">{p.full_name}</h2>
                  <StatusBadge status={p.status} />
                  <span className={clsx('badge', employmentColor[p.employment_type] ?? 'badge-slate')}>{labelize(p.employment_type)}</span>
                  {p.is_hod_of && <span className="badge badge-green">HOD · {p.department_code}</span>}
                </div>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                  {p.designation}
                  {p.department_name ? <> · {p.department_name}</> : null}
                  {type === 'staff' && p.section ? <> · {p.section}</> : null}
                </p>
                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-500 dark:text-slate-400">
                  <span className="inline-flex items-center gap-1.5"><IdCard className="h-3.5 w-3.5" /> {p.employee_id}</span>
                  {p.email && <a href={`mailto:${p.email}`} className="inline-flex items-center gap-1.5 hover:text-brand-700 dark:hover:text-white"><Mail className="h-3.5 w-3.5" /> {p.email}</a>}
                  {p.phone && <a href={`tel:${p.phone.replace(/\s/g, '')}`} className="inline-flex items-center gap-1.5 hover:text-brand-700 dark:hover:text-white"><Phone className="h-3.5 w-3.5" /> {p.phone}</a>}
                  {p.joining_date && <span className="inline-flex items-center gap-1.5"><CalendarDays className="h-3.5 w-3.5" /> Joined {formatDate(p.joining_date)}</span>}
                  <span className="inline-flex items-center gap-1.5"><UserRound className="h-3.5 w-3.5" /> {data.account ? `Login: ${data.account.username}` : 'No login account'}</span>
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-2 sm:pt-14">
                <Dropdown
                  label="More actions"
                  trigger={<><MoreHorizontal className="h-4 w-4" /> More <ChevronDown className="h-3.5 w-3.5 opacity-60" /></>}
                  items={more}
                />
              </div>
            </div>
            <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
              {stats.map((s) => (
                <div key={s.label} className="rounded-xl border border-slate-100 bg-slate-50/70 px-3.5 py-3 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-card dark:border-slate-800 dark:bg-slate-800/40 dark:hover:bg-slate-800">
                  <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{s.label}</dt>
                  <dd className="mt-0.5 truncate font-display text-lg font-bold text-slate-900 dark:text-white">{s.value}</dd>
                  {s.hint && <dd className="truncate text-[11px] text-slate-500 dark:text-slate-400">{s.hint}</dd>}
                </div>
              ))}
            </dl>
          </div>
        </Card>
      </Reveal>

      <div ref={tabsRef} className="sticky top-16 z-[5] -mx-4 mt-5 bg-slate-50/90 px-4 backdrop-blur supports-[backdrop-filter]:bg-slate-50/75 dark:bg-slate-950/80 sm:mx-0 sm:rounded-xl sm:px-0">
        <Tabs tabs={tabs} value={tab} onChange={setTab} className="[&_.tab]:px-2.5 2xl:[&_.tab]:px-3.5" />
      </div>

      <div className="mt-5" key={tab}>
        <Suspense fallback={<PageLoader />}>
          {tab === 'overview' && <OverviewTab type={type} data={data} onTab={setTab} onCreateLogin={can.edit && working ? () => setLoginOpen(true) : undefined} />}
          {tab === 'professional' && <ProfessionalTab type={type} data={data} />}
          {tab === 'subjects' && type === 'faculty' && <SubjectsTab id={p.id} />}
          {tab === 'timetable' && type === 'faculty' && <TimetableTab id={p.id} />}
          {tab === 'attendance' && <AttendanceTab type={type} id={p.id} />}
          {tab === 'leaves' && <LeavesTab type={type} person={p} canApply={can.create && working} />}
          {tab === 'documents' && <DocumentsTab type={type} id={p.id} canEdit={can.edit} onChanged={refresh} />}
          {tab === 'payroll' && <PayrollTab type={type} data={data} onEdit={can.edit ? () => setEditOpen(true) : undefined} />}
          {tab === 'activity' && <ActivityTab type={type} id={p.id} />}
        </Suspense>
      </div>

      <CrudFormModal open={editOpen} onClose={() => setEditOpen(false)} meta={meta} module={type} id={p.id} title={`Edit ${p.full_name}`} onSaved={() => void refresh()} />
      <LeaveFormModal
        open={leaveOpen}
        onClose={() => setLeaveOpen(false)}
        preset={leaveOpen ? { ref: employeeRef(type, p.id), label: `${p.full_name} (${p.employee_id})`, sub: p.designation } : null}
        onSaved={() => void refresh()}
      />
      <CreateAccountModal
        open={loginOpen}
        onClose={() => setLoginOpen(false)}
        type={type}
        id={p.id}
        name={p.full_name}
        email={p.email}
        onCreated={(a) => {
          setAccount(a);
          void refresh();
        }}
      />
      <CredentialsModal account={account} name={p.full_name} onClose={() => setAccount(null)} />
    </>
  );
}

function NotFound({ type }: { type: EmployeeType }) {
  const labels = EMPLOYEE_LABEL[type];
  return (
    <>
      <PageHeader title={`${labels.singular} not found`} breadcrumbs={[{ label: 'Faculty & Staff' }, { label: labels.plural, to: labels.base }]} />
      <Card>
        <EmptyState
          icon={UserX}
          title={`This ${labels.singular.toLowerCase()} does not exist`}
          description="The profile may have been deleted, or the link is incorrect."
          action={<Button to={labels.base}>Back to {labels.plural.toLowerCase()}</Button>}
        />
      </Card>
    </>
  );
}

function ProfileSkeleton() {
  return (
    <>
      <div className="mb-6 space-y-2">
        <Skeleton className="h-3 w-48" />
        <Skeleton className="h-7 w-64" />
      </div>
      <div className="card overflow-hidden">
        <div className="skeleton h-28 !rounded-none" />
        <div className="px-5 pb-5">
          <div className="-mt-10 flex items-end gap-4">
            <div className="skeleton h-24 w-24 !rounded-full ring-4 ring-white dark:ring-slate-900" />
            <div className="flex-1 space-y-2 pb-2">
              <Skeleton className="h-6 w-1/3" />
              <Skeleton className="h-4 w-1/2" />
            </div>
          </div>
          <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-16 !rounded-xl" />
            ))}
          </div>
        </div>
      </div>
      <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2"><CardSkeleton lines={6} /></div>
        <CardSkeleton lines={4} />
      </div>
    </>
  );
}
