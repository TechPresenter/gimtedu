import { lazy, Suspense, useMemo, useState, type ComponentType, type ReactNode } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  Activity, ArrowLeft, Award, BookOpen, Bus, CalendarCheck, Droplet, FileText, GraduationCap, Hotel, IdCard, LayoutDashboard, Mail, MessageSquare, MoreHorizontal,
  Pencil, Phone, Printer, ReceiptIndianRupee, Trophy, User, UserCheck, UserX, Briefcase, ClipboardList, Cake, AlertTriangle,
} from 'lucide-react';
import { Alert, Avatar, Button, Card, CardSkeleton, CountUp, Dropdown, EmptyState, PageHeader, Skeleton, StatusBadge, Tabs, type TabDef } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAuth, type PermissionAction } from '@/lib/auth';
import { appUrl, printUrl } from '@/lib/config';
import { formatDate, formatMoneyShort, formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { INACTIVE_STATUSES, STATUS_COLORS, statusLabel } from './constants';
import { StatusDialog } from './components/StatusDialog';
import type { StudentProfile } from './types';
import type { TabProps } from './profile/shared';

const tabsDef: { key: string; label: string; icon: typeof User; perm?: [string, PermissionAction]; load: () => Promise<{ default: ComponentType<TabProps> }> }[] = [
  { key: 'overview', label: 'Overview', icon: LayoutDashboard, load: () => import('./profile/OverviewTab') },
  { key: 'personal', label: 'Personal Details', icon: User, load: () => import('./profile/PersonalTab') },
  { key: 'academic', label: 'Academic Details', icon: GraduationCap, load: () => import('./profile/AcademicTab') },
  { key: 'attendance', label: 'Attendance', icon: CalendarCheck, perm: ['attendance', 'view'], load: () => import('./profile/AttendanceTab') },
  { key: 'fees', label: 'Fees', icon: ReceiptIndianRupee, perm: ['fees', 'view'], load: () => import('./profile/FeesTab') },
  { key: 'examination', label: 'Examination', icon: ClipboardList, perm: ['examination', 'view'], load: () => import('./profile/ExamsTab') },
  { key: 'results', label: 'Results', icon: Trophy, perm: ['results', 'view'], load: () => import('./profile/ResultsTab') },
  { key: 'documents', label: 'Documents', icon: FileText, load: () => import('./profile/DocumentsTab') },
  { key: 'certificates', label: 'Certificates', icon: Award, perm: ['certificates', 'view'], load: () => import('./profile/CertificatesTab') },
  { key: 'library', label: 'Library', icon: BookOpen, perm: ['library', 'view'], load: () => import('./profile/LibraryTab') },
  { key: 'hostel', label: 'Hostel', icon: Hotel, perm: ['hostel', 'view'], load: () => import('./profile/HostelTab') },
  { key: 'transport', label: 'Transport', icon: Bus, perm: ['transport', 'view'], load: () => import('./profile/TransportTab') },
  { key: 'placement', label: 'Placement', icon: Briefcase, perm: ['placement', 'view'], load: () => import('./profile/PlacementTab') },
  { key: 'communication', label: 'Communication', icon: MessageSquare, perm: ['communication', 'view'], load: () => import('./profile/CommunicationTab') },
  { key: 'activity', label: 'Activity History', icon: Activity, load: () => import('./profile/ActivityTab') },
];
const lazyTabs: Record<string, ComponentType<TabProps>> = Object.fromEntries(tabsDef.map((t) => [t.key, lazy(t.load)]));

export default function StudentProfilePage() {
  const { id: idParam } = useParams();
  const id = Number(idParam);
  const valid = Number.isInteger(id) && id > 0;
  const [params, setParams] = useSearchParams();
  const { can } = useAuth();
  const [dialog, setDialog] = useState<'deactivate' | 'activate' | null>(null);
  const q = useApi<StudentProfile>(['students', id, 'profile'], `students/${id}/profile`, undefined, { enabled: valid, retry: (n, e) => (e as ApiError).status !== 404 && n < 2 });

  const tabs = useMemo(() => tabsDef.filter((t) => !t.perm || can(t.perm[0], t.perm[1])), [can]);
  const requested = params.get('tab') ?? 'overview';
  const tab = tabs.some((t) => t.key === requested) ? requested : 'overview';
  const setTab = (k: string) => {
    const next = new URLSearchParams(params);
    if (k === 'overview') next.delete('tab');
    else next.set('tab', k);
    setParams(next, { replace: true });
  };

  if (!valid || (q.error && (q.error as ApiError).status === 404)) {
    return (
      <>
        <PageHeader title="Student not found" breadcrumbs={[{ label: 'Students', to: '/students' }, { label: 'Not found' }]} />
        <Card>
          <EmptyState icon={User} title="This student does not exist" description="The record may have been deleted or the link is incorrect." action={<Button to="/students" icon={ArrowLeft}>Back to students</Button>} />
        </Card>
      </>
    );
  }
  if (q.error) {
    return (
      <>
        <PageHeader title="Student profile" breadcrumbs={[{ label: 'Students', to: '/students' }, { label: 'Profile' }]} />
        <Alert variant="error" title="Unable to load the student profile" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
          {(q.error as ApiError).message}
        </Alert>
      </>
    );
  }
  if (!q.data) return <ProfileSkeleton />;

  const { student: s, stats } = q.data;
  const inactive = INACTIVE_STATUSES.includes(s.status) || s.status !== 'active';
  const canEdit = can('students', 'edit');
  const Active = lazyTabs[tab];
  const tabDefs: TabDef[] = tabs.map((t) => ({
    key: t.key,
    label: t.label,
    icon: t.icon,
    count: t.key === 'documents' ? stats.documents.total : t.key === 'certificates' && stats.certificates ? stats.certificates : undefined,
  }));
  const attendancePct = stats.attendance?.percent;
  const lowAttendance = attendancePct !== null && attendancePct !== undefined && attendancePct < (stats.attendance?.threshold ?? 75);

  return (
    <>
      <PageHeader title="Student profile" description={`${s.student_uid} · ${s.program_name} · Semester ${s.current_semester}`} breadcrumbs={[{ label: 'Students', to: '/students' }, { label: s.full_name }]} />

      {/* Header card */}
      <Card className="mb-5 overflow-hidden">
        <div className="relative h-24 overflow-hidden bg-gradient-to-r from-brand-950 via-brand-900 to-brand-700 sm:h-28" aria-hidden>
          <div className="absolute inset-0 opacity-[.15]" style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,.9) 1px, transparent 1px)', backgroundSize: '18px 18px' }} />
          <div className="absolute -right-10 -top-16 h-56 w-56 rounded-full bg-accent-500/25 blur-3xl motion-safe:animate-[pulse_6s_ease-in-out_infinite]" />
          <GraduationCap className="absolute bottom-2 right-6 h-20 w-20 text-white/10" />
        </div>
        <div className="px-5 pb-5">
          <div className="-mt-12 flex items-end justify-between gap-3 sm:-mt-14">
            <div className="relative w-fit shrink-0">
              {s.photo ? (
                <img src={appUrl(s.photo)} alt={s.full_name} className="h-24 w-24 rounded-2xl object-cover shadow-soft ring-4 ring-white sm:h-28 sm:w-28 dark:ring-slate-900" />
              ) : (
                <Avatar name={s.full_name} size="xl" className="!h-24 !w-24 !rounded-2xl !text-2xl shadow-soft !ring-4 !ring-white sm:!h-28 sm:!w-28 dark:!ring-slate-900" />
              )}
              <span className={clsx('absolute -bottom-1 -right-1 h-5 w-5 rounded-full ring-4 ring-white dark:ring-slate-900', s.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400')} title={statusLabel(s.status)} />
            </div>
            <div className="flex flex-wrap justify-end gap-2 pb-1">
              {canEdit && (
                <Button icon={Pencil} to={`/students/${s.id}/edit`}>
                  Edit
                </Button>
              )}
              <Button variant="secondary" icon={Printer} href={printUrl('student-profile.php', { id: s.id })} target="_blank" className="hidden md:inline-flex">
                Print
              </Button>
              <Button variant="secondary" icon={IdCard} href={printUrl('id-cards.php', { ids: s.id })} target="_blank" className="hidden md:inline-flex">
                ID Card
              </Button>
              <Dropdown
                label="More actions"
                trigger={<><MoreHorizontal className="h-4 w-4" /><span className="md:hidden">More</span></>}
                triggerClassName="btn btn-secondary"
                items={[
                  { label: 'Print profile', icon: Printer, href: printUrl('student-profile.php', { id: s.id }), target: '_blank' },
                  { label: 'Print ID card', icon: IdCard, href: printUrl('id-cards.php', { ids: s.id }), target: '_blank' },
                  can('certificates', 'create') && { label: 'Generate certificate', icon: Award, to: `/certificates?student_id=${s.id}` },
                  can('fees', 'create') && { label: 'Collect fee', icon: ReceiptIndianRupee, to: `/fees/collect?student_id=${s.id}` },
                  canEdit && { label: '', divider: true },
                  canEdit && !inactive && { label: 'Deactivate…', icon: UserX, danger: true, onClick: () => setDialog('deactivate') },
                  canEdit && inactive && { label: 'Re-activate', icon: UserCheck, onClick: () => setDialog('activate') },
                ]}
              />
            </div>
          </div>

          <div className="mt-3 min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="font-display text-xl font-bold text-slate-900 sm:text-2xl dark:text-white">{s.full_name}</h2>
              <StatusBadge status={s.status} label={statusLabel(s.status)} colors={STATUS_COLORS} />
              {!!s.is_hosteller && <span className="badge badge-purple">Hosteller</span>}
            </div>
            <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-300">
              {s.program_full_name}
              {s.course_name ? ` · ${s.course_name.replace(/^.* - /, '')}` : ''} · Semester {s.current_semester}
              {s.section_name ? ` · Section ${s.section_name}` : ''}
              {s.batch_name ? ` · Batch ${s.batch_start}–${s.batch_end}` : ''}
            </p>
          </div>

          {/* identifiers + contact */}
          <div className="mt-4 flex flex-wrap gap-2">
            {[
              ['Student ID', s.student_uid],
              ['Admission No', s.admission_no],
              ['Roll No', s.roll_no],
              ['Enrollment', s.enrollment_no],
            ].filter(([, v]) => v).map(([l, v]) => (
              <span key={l} className="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-xs dark:bg-slate-800">
                <span className="text-slate-500 dark:text-slate-400">{l}</span>
                <span className="font-mono font-semibold text-slate-800 dark:text-slate-100">{v}</span>
              </span>
            ))}
          </div>
          <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-slate-600 dark:text-slate-300">
            <a href={`tel:${s.mobile.replace(/\s/g, '')}`} className="inline-flex items-center gap-1.5 hover:text-brand-700 dark:hover:text-white"><Phone className="h-4 w-4 text-slate-400" />{s.mobile}</a>
            {s.email && <a href={`mailto:${s.email}`} className="inline-flex min-w-0 items-center gap-1.5 hover:text-brand-700 dark:hover:text-white"><Mail className="h-4 w-4 shrink-0 text-slate-400" /><span className="truncate">{s.email}</span></a>}
            {s.dob && <span className="inline-flex items-center gap-1.5"><Cake className="h-4 w-4 text-slate-400" />{formatDate(s.dob)}{s.age !== null ? ` (${s.age} yrs)` : ''}</span>}
            {s.blood_group && <span className="inline-flex items-center gap-1.5"><Droplet className="h-4 w-4 text-red-400" />{s.blood_group}</span>}
          </div>
          {s.status !== 'active' && (
            <Alert variant="warning" className="mt-4" title={`${statusLabel(s.status)} student`}>
              {s.status_reason ?? 'No reason recorded.'}
            </Alert>
          )}
        </div>

        {/* quick stats */}
        <div className="grid grid-cols-2 gap-px border-t border-slate-100 bg-slate-100 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-800">
          <QuickStat label="Attendance" onClick={stats.attendance ? () => setTab('attendance') : undefined}
            value={stats.attendance ? (attendancePct !== null && attendancePct !== undefined ? <><CountUp value={attendancePct} />%</> : '—') : 'N/A'}
            hint={stats.attendance ? (stats.attendance.total ? `${formatNumber(stats.attendance.attended)} / ${formatNumber(stats.attendance.total)} classes` : 'No classes marked yet') : 'No access'}
            warn={lowAttendance} />
          <QuickStat label="CGPA" onClick={stats.gpa ? () => setTab('results') : undefined}
            value={stats.gpa?.cgpa !== null && stats.gpa?.cgpa !== undefined ? <CountUp value={stats.gpa.cgpa.toFixed(2)} /> : '—'}
            hint={stats.gpa?.sgpa ? `Last SGPA ${stats.gpa.sgpa.toFixed(2)}` : 'No results yet'} />
          <QuickStat label="Fee balance" onClick={stats.fees ? () => setTab('fees') : undefined}
            value={stats.fees ? formatMoneyShort(stats.fees.balance) : 'N/A'}
            hint={stats.fees ? (stats.fees.overdue > 0 ? `${formatMoneyShort(stats.fees.overdue)} overdue` : stats.fees.invoices ? `${formatMoneyShort(stats.fees.paid)} paid` : 'No invoices yet') : 'No access'}
            warn={!!stats.fees && stats.fees.overdue > 0} />
          <QuickStat label="Documents" onClick={() => setTab('documents')}
            value={<>{stats.documents.verified}<span className="text-base font-semibold text-slate-400">/{stats.documents.total}</span></>}
            hint={stats.documents.total ? `${stats.documents.pending} pending · ${stats.documents.rejected} rejected` : 'Nothing uploaded'} />
        </div>
      </Card>

      <Tabs tabs={tabDefs} value={tab} onChange={setTab} className="mb-5" />

      <Suspense fallback={<div className="grid gap-5 lg:grid-cols-2"><CardSkeleton lines={5} /><CardSkeleton lines={5} /></div>}>
        <div key={tab} className="motion-safe:animate-fade-in">
          <Active profile={q.data} studentId={s.id} onTab={setTab} refresh={() => void q.refetch()} />
        </div>
      </Suspense>

      <StatusDialog open={!!dialog} onClose={() => setDialog(null)} mode={dialog ?? 'deactivate'} students={[{ id: s.id, name: s.full_name }]} onDone={() => void q.refetch()} />
    </>
  );
}

function QuickStat({ label, value, hint, onClick, warn }: { label: string; value: ReactNode; hint: string; onClick?: () => void; warn?: boolean }) {
  const body = (
    <>
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
      <p className={clsx('mt-1 font-display text-2xl font-bold', warn ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white')}>{value}</p>
      <p className={clsx('mt-0.5 flex items-center gap-1 truncate text-xs', warn ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400')}>
        {warn && <AlertTriangle className="h-3 w-3 shrink-0" />}
        {hint}
      </p>
    </>
  );
  const cls = 'min-w-0 bg-white px-5 py-4 text-left transition dark:bg-slate-900';
  return onClick ? (
    <button type="button" onClick={onClick} className={clsx(cls, 'group hover:bg-slate-50 dark:hover:bg-slate-800')}>
      {body}
    </button>
  ) : (
    <div className={cls}>{body}</div>
  );
}

function ProfileSkeleton() {
  return (
    <>
      <div className="mb-6 space-y-2">
        <Skeleton className="h-3 w-40" />
        <Skeleton className="h-7 w-64" />
      </div>
      <div className="card mb-5 overflow-hidden">
        <div className="skeleton h-28 !rounded-none" />
        <div className="flex items-end gap-4 px-5 pb-5">
          <Skeleton className="-mt-12 h-24 w-24 rounded-2xl" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-6 w-56" />
            <Skeleton className="h-4 w-80 max-w-full" />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-4 border-t border-slate-100 p-5 sm:grid-cols-4 dark:border-slate-800">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-12" />)}
        </div>
      </div>
      <Skeleton className="mb-5 h-10 w-full" />
      <div className="grid gap-5 lg:grid-cols-2">
        <CardSkeleton lines={5} />
        <CardSkeleton lines={5} />
      </div>
    </>
  );
}

