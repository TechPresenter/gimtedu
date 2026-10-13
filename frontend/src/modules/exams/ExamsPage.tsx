import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  Award, CalendarCheck2, CalendarClock, CalendarDays, CheckCircle2, ClipboardList, FileCheck2, Hourglass, Layers, PenLine, Printer, Timer, Trophy, Workflow,
} from 'lucide-react';
import { Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, StatCard, Stagger, Tabs } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { DoughnutChart } from '@/components/charts';
import { useApi, useCrudMeta, useInvalidate } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { DateTile, EXAM_STATUS, ExamStatusBadge, MiniProgress, dateRange, timeRange } from './components/shared';
import type { ExamStatus, ExamSummary } from './components/types';

const PHASES = [
  { key: 'upcoming', label: 'Upcoming exams', icon: CalendarClock, tone: 'blue' as const, hint: 'Not started yet' },
  { key: 'ongoing', label: 'Ongoing', icon: Timer, tone: 'cyan' as const, hint: 'Papers being held now' },
  { key: 'completed', label: 'Completed', icon: CheckCircle2, tone: 'green' as const, hint: 'All papers held' },
  { key: 'results_pending', label: 'Results pending', icon: Hourglass, tone: 'amber' as const, hint: 'Awaiting marks / publication' },
];

export default function ExamsPage() {
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'types' ? 'types' : 'exams';
  const { can } = useAuth();
  const { id: sessionId, session } = useAcademicSession();
  const navigate = useNavigate();
  const [nonce, setNonce] = useState(0);
  const [createOpen, setCreateOpen] = useState(false);
  const examsMeta = useCrudMeta('exams');
  const invalidate = useInvalidate();
  const summary = useApi<ExamSummary>(['exams', 'summary', sessionId], 'exams/summary', { session_id: sessionId ?? undefined });
  const s = summary.data;
  const activePhase = params.get('f.phase');

  const setTab = (t: string) => {
    const next = new URLSearchParams();
    if (t !== 'exams') next.set('tab', t);
    setParams(next);
  };
  const filterPhase = (phase: string | null) => {
    const next = new URLSearchParams();
    if (phase && phase !== activePhase) next.set('f.phase', phase);
    setParams(next);
    setNonce((n) => n + 1);
  };

  const rowMenu = (r: Row) => [
    { label: 'Open workflow', icon: Workflow, to: `/examination/${r.id}` },
    can('results', 'create') || can('results', 'edit') ? { label: 'Enter marks', icon: PenLine, to: `/marks-entry?exam=${r.id}` } : null,
    { label: 'Results', icon: Trophy, to: `/results?exam=${r.id}` },
    { label: 'Print date sheet', icon: Printer, href: printUrl('exam-schedule.php', { exam_id: r.id }), target: '_blank' },
    { label: 'Print admit cards', icon: FileCheck2, href: printUrl('admit-card.php', { exam_id: r.id }), target: '_blank' },
  ];

  return (
    <>
      <PageHeader
        title="Examinations"
        description={`Create exams, build date sheets, allocate eligible students and drive every exam from marks entry to published results${session ? ` · Session ${session.name}` : ''}.`}
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'Examination' }]}
        actions={
          <>
            <Button variant="secondary" icon={CalendarDays} to="/exam-schedule">
              Exam schedule
            </Button>
            {(can('results', 'create') || can('results', 'edit')) && (
              <Button variant="secondary" icon={PenLine} to="/marks-entry">
                Marks entry
              </Button>
            )}
            <Button variant="secondary" icon={Award} to="/results">
              Results
            </Button>
          </>
        }
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'exams', label: 'Exams', icon: ClipboardList, count: s?.total },
            { key: 'types', label: 'Exam types', icon: Layers },
          ]}
        />
      </PageHeader>

      {tab === 'types' ? (
        <CrudTable
          module="exam_types"
          urlState
          addLabel="Add exam type"
          emptyTitle="No exam types yet"
          emptyText="Add exam types such as Mid Term, End Semester or Practical examinations. Final types produce SGPA, CGPA and marksheets."
        />
      ) : (
        <div className="space-y-6">
          <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={70}>
            {PHASES.map((p) => (
              <button key={p.key} type="button" onClick={() => filterPhase(p.key)} className="block w-full rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" aria-pressed={activePhase === p.key}>
                <StatCard
                  label={p.label}
                  value={s ? (s[p.key as keyof ExamSummary] as number) : 0}
                  icon={p.icon}
                  tone={p.tone}
                  loading={!s}
                  hint={activePhase === p.key ? 'Filtered — click to clear' : p.key === 'upcoming' && s ? `${s.papers_next_14} papers in the next 14 days` : p.key === 'results_pending' && s ? `${s.marks_pending_subjects} subjects awaiting marks` : p.hint}
                />
              </button>
            ))}
          </Stagger>

          <CrudTable
            key={nonce}
            module="exams"
            urlState
            addLabel="Create exam"
            viewTo={(r) => `/examination/${r.id}`}
            rowMenu={rowMenu}
            onCreate={() => setCreateOpen(true)}
            onSaved={() => invalidate('exams')}
            emptyTitle="No exams found"
            emptyText="Create an exam for a program and semester — curriculum subjects are added automatically, then build the date sheet and allocate students."
            renderers={{
              name: (r) => (
                <div className="min-w-[15rem] max-w-[24rem] leading-tight">
                  <Link to={`/examination/${r.id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                    {r.name}
                  </Link>
                  <div className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    <span className="font-medium text-slate-600 dark:text-slate-300">{r.type_name}</span> · {r.cohort} · {r.session_name}
                  </div>
                </div>
              ),
              start_date: (r) => <span className="whitespace-nowrap">{dateRange(r.start_date, r.end_date)}</span>,
              marks_percent: (r) =>
                r.marks_percent === null || r.marks_percent === undefined ? <span className="text-xs text-slate-400">—</span> : <MiniProgress value={Number(r.marks_percent)} tone={Number(r.marks_percent) >= 100 ? 'green' : 'blue'} label="Marks entered" />,
              pass_percent: (r) => (r.pass_percent === null ? <span className="text-slate-400">—</span> : <span className="tabular-nums">{r.pass_percent}%</span>),
              display_status: (r) => <ExamStatusBadge status={r.display_status} />,
            }}
          />

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Reveal className="lg:col-span-2">
              <Card className="h-full">
                <CardHeader
                  title="Upcoming papers"
                  subtitle="Next papers across all exams of the session"
                  icon={CalendarCheck2}
                  actions={
                    <Button size="sm" variant="ghost" to="/exam-schedule">
                      Full date sheet →
                    </Button>
                  }
                />
                <div className="divide-y divide-slate-100 dark:divide-slate-800">
                  {!s &&
                    Array.from({ length: 4 }).map((_, i) => (
                      <div key={i} className="flex items-center gap-3 px-5 py-3">
                        <Skeleton className="h-12 w-12 rounded-xl" />
                        <div className="flex-1 space-y-2">
                          <Skeleton className="h-4 w-1/2" />
                          <Skeleton className="h-3 w-1/3" />
                        </div>
                      </div>
                    ))}
                  {s && !s.upcoming_papers.length && (
                    <EmptyState icon={CalendarClock} title="No upcoming papers" description="Schedule the papers of an exam to see them here." className="py-10" />
                  )}
                  {s?.upcoming_papers.map((p) => (
                    <Link key={p.id} to={`/examination/${p.exam_id}?tab=schedule`} className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                      <DateTile date={p.exam_date} />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                          <span className="font-mono text-xs text-slate-500">{p.subject_code}</span> {p.subject_name}
                        </p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                          {p.program_short} · Sem {p.semester_no} · {p.type_name}
                        </p>
                      </div>
                      <div className="hidden text-right text-xs sm:block">
                        <p className="font-medium text-slate-700 dark:text-slate-200">{timeRange(p.start_time, p.end_time)}</p>
                        <p className="text-slate-500">{p.room_code ? `Room ${p.room_code}` : 'Room not set'} · {formatNumber(p.students_count)} students</p>
                      </div>
                    </Link>
                  ))}
                </div>
              </Card>
            </Reveal>
            <Reveal delay={80}>
              <Card className="h-full">
                <CardHeader title="Exams by status" subtitle="Workflow position of this session's exams" icon={Workflow} />
                <div className="p-5">
                  {!s ? (
                    <Skeleton className="mx-auto h-44 w-44 rounded-full" />
                  ) : s.by_status.length ? (
                    <DoughnutChart
                      labels={s.by_status.map((b) => EXAM_STATUS[b.status as ExamStatus]?.label ?? b.status)}
                      data={s.by_status.map((b) => Number(b.count))}
                      colors={s.by_status.map((b) => ({ draft: '#94A3B8', scheduled: '#2563EB', ongoing: '#06B6D4', marks_entry: '#F59E0B', verified: '#8B5CF6', published: '#22943F', cancelled: '#EF4444' })[b.status] ?? '#64748B')}
                      centerValue={formatNumber(s.total)}
                      centerLabel="Exams"
                      height={170}
                      valueFormat="number"
                    />
                  ) : (
                    <EmptyState icon={ClipboardList} title="No exams in this session" className="py-8" />
                  )}
                </div>
              </Card>
            </Reveal>
          </div>
          <CrudFormModal
            open={createOpen}
            onClose={() => setCreateOpen(false)}
            meta={examsMeta.data}
            module="exams"
            title="Create exam"
            onSaved={(_row, id) => {
              invalidate('exams', 'crud');
              navigate(`/examination/${id}`);
            }}
          />
        </div>
      )}
    </>
  );
}
