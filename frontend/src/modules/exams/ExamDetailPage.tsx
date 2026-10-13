import { useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { BookOpen, CalendarDays, ChevronDown, ClipboardList, FileCheck2, LayoutDashboard, Lock, PenLine, Pencil, Printer, Send, Trophy, Users } from 'lucide-react';
import { Alert, Button, Card, CardSkeleton, Dropdown, EmptyState, PageHeader, Reveal, Skeleton, Tabs } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { useApi, useCrudMeta, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import type { ApiError } from '@/lib/api';
import { ExamStatusBadge, dateRange, todayIso } from './components/shared';
import type { ExamOverview } from './components/types';
import { WorkflowSteps } from './components/WorkflowSteps';
import { useExamWorkflow } from './components/useExamWorkflow';
import { OverviewTab } from './components/detail/OverviewTab';
import { SubjectsTab } from './components/detail/SubjectsTab';
import { ScheduleTab } from './components/detail/ScheduleTab';
import { StudentsTab } from './components/detail/StudentsTab';
import { MarksTab } from './components/detail/MarksTab';

const TABS = ['overview', 'subjects', 'schedule', 'students', 'marks'] as const;
type Tab = (typeof TABS)[number];
const STEP_TAB: Record<string, Tab> = { create: 'overview', subjects: 'subjects', schedule: 'schedule', students: 'students', marks: 'marks', verify: 'marks', publish: 'marks' };

export default function ExamDetailPage() {
  const { id } = useParams();
  const examId = Number(id);
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview';
  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    if (t === 'overview') next.delete('tab');
    else next.set('tab', t);
    setParams(next, { replace: true });
  };
  const { can } = useAuth();
  const q = useApi<ExamOverview>(['exams', 'overview', examId], `exams/${examId}/overview`, undefined, { enabled: examId > 0 });
  const d = q.data;
  const wf = useExamWorkflow(d?.exam, d?.results);
  const [editOpen, setEditOpen] = useState(false);
  const meta = useCrudMeta('exams');
  const invalidate = useInvalidate();

  if (q.error) {
    const err = q.error as ApiError;
    return (
      <>
        <PageHeader title="Exam" breadcrumbs={[{ label: 'Examination', to: '/examination' }, { label: 'Exam' }]} />
        <Card>
          {err.status === 404 ? (
            <EmptyState icon={ClipboardList} title="Exam not found" description="This exam does not exist or has been deleted." action={<Button to="/examination">Back to examinations</Button>} />
          ) : (
            <div className="p-6">
              <Alert variant="error" title="Unable to load the exam" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
                {err.message}
              </Alert>
            </div>
          )}
        </Card>
      </>
    );
  }

  if (!d) {
    return (
      <>
        <div className="mb-6 space-y-2">
          <Skeleton className="h-3 w-40" />
          <Skeleton className="h-7 w-96 max-w-full" />
          <Skeleton className="h-4 w-72" />
        </div>
        <Card className="mb-6 p-5">
          <Skeleton className="h-16 w-full" />
        </Card>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <CardSkeleton />
          <CardSkeleton />
          <CardSkeleton />
        </div>
      </>
    );
  }

  const e = d.exam;
  const perm = d.permissions;
  const locked = e.status === 'verified' || e.status === 'published';
  const counts = d.counts;
  return (
    <>
      <PageHeader
        title={e.name}
        description={
          <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <ExamStatusBadge status={e.display_status} />
            <span>{e.type_name}</span>
            <span className="text-slate-300 dark:text-slate-600">·</span>
            <span>{e.cohort}</span>
            <span className="text-slate-300 dark:text-slate-600">·</span>
            <span>Session {e.session_name}</span>
            <span className="text-slate-300 dark:text-slate-600">·</span>
            <span>{dateRange(e.start_date, e.end_date)}</span>
          </span>
        }
        breadcrumbs={[{ label: 'Examination', to: '/examination' }, { label: e.cohort }]}
        actions={
          <>
            <Dropdown
              label="Print"
              trigger={
                <>
                  <Printer className="h-4 w-4" /> Print <ChevronDown className="h-3.5 w-3.5" />
                </>
              }
              items={[
                { label: 'Date sheet', icon: CalendarDays, href: printUrl('exam-schedule.php', { exam_id: e.id }), target: '_blank', disabled: !counts.scheduled },
                { label: 'Admit cards (all eligible)', icon: FileCheck2, href: printUrl('admit-card.php', { exam_id: e.id }), target: '_blank', disabled: !counts.eligible || !counts.scheduled },
                { label: 'Marksheets', icon: Trophy, href: printUrl('marksheet.php', { exam_id: e.id }), target: '_blank', disabled: !d.marksheets },
              ]}
            />
            {can('examination', 'edit') && (
              <Button variant="secondary" icon={Pencil} onClick={() => setEditOpen(true)}>
                Edit
              </Button>
            )}
            {!locked && perm.marks && counts.subjects > 0 && counts.eligible > 0 && (counts.marks_entered > 0 || !e.start_date || e.start_date <= todayIso()) && (
              <Button variant="secondary" icon={PenLine} to={`/marks-entry?exam=${e.id}`}>
                Enter marks
              </Button>
            )}
            {!locked && perm.verify && d.current_step === 'verify' && (
              <Button icon={Lock} onClick={wf.verify} loading={wf.busy === 'verify'}>
                Verify marks
              </Button>
            )}
            {e.status === 'verified' && perm.publish && (
              <Button variant="success" icon={Send} onClick={wf.publish}>
                Publish results
              </Button>
            )}
            {e.status === 'published' && (
              <Button icon={Trophy} to={`/results?exam=${e.id}`}>
                View results
              </Button>
            )}
          </>
        }
      />

      {e.status === 'cancelled' && (
        <Alert variant="error" title="This exam is cancelled" className="mb-4">
          Edit the exam and change its status to re-open it.
        </Alert>
      )}

      <Reveal>
        <Card className="mb-6 p-3 sm:p-4">
          <WorkflowSteps steps={d.steps} current={d.current_step} onSelect={(k) => setTab(STEP_TAB[k] ?? 'overview')} />
        </Card>
      </Reveal>

      <Tabs
        className="mb-5"
        value={tab}
        onChange={setTab}
        tabs={[
          { key: 'overview', label: 'Overview', icon: LayoutDashboard },
          { key: 'subjects', label: 'Subjects', icon: BookOpen, count: counts.subjects },
          { key: 'schedule', label: 'Schedule', icon: CalendarDays, count: counts.scheduled },
          { key: 'students', label: 'Students', icon: Users, count: counts.eligible },
          { key: 'marks', label: 'Marks & results', icon: PenLine, count: `${counts.marks_percent}%` },
        ]}
      />

      {tab === 'overview' && <OverviewTab data={d} onTab={setTab} onVerify={wf.verify} onPublish={wf.publish} />}
      {tab === 'subjects' && <SubjectsTab data={d} />}
      {tab === 'schedule' && <ScheduleTab data={d} />}
      {tab === 'students' && <StudentsTab data={d} />}
      {tab === 'marks' && <MarksTab data={d} wf={wf} />}

      {wf.modal}
      <CrudFormModal
        open={editOpen}
        onClose={() => setEditOpen(false)}
        meta={meta.data}
        module="exams"
        id={e.id}
        onSaved={() => invalidate('exams', 'crud')}
      />
    </>
  );
}
