import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  Award, Ban, BarChart3, BookOpen, ChevronLeft, Crown, Eye, FileBadge, GraduationCap, Hourglass, Lock, Medal, Percent, Printer, Send, ShieldCheck, Sigma, TrendingUp, Trophy, Undo2, Users,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, Combobox, DataTable, EmptyState, PageHeader, Reveal, Select, Skeleton, StatCard, Stagger, useToast, type Column,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart, DoughnutChart, LineChart } from '@/components/charts';
import { api, type ApiError } from '@/lib/api';
import { useApi, useInvalidate, useLookup } from '@/lib/queries';
import { useAcademicSession } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ExamStatusBadge, MiniProgress, RESULT_STATUS, ResultBadge, SEMESTER_OPTIONS, fmtGpa, fmtMarks } from './components/shared';
import type { ResultStatus, ResultsExamRow, ResultsOverview, SessionResults } from './components/types';
import { ResultDrawer, WithholdModal } from './components/ResultDrawer';
import { useExamWorkflow } from './components/useExamWorkflow';

export default function ResultsPage() {
  const [params, setParams] = useSearchParams();
  const { id: currentSession, all: sessions } = useAcademicSession();
  const sessionId = Number(params.get('session') ?? currentSession ?? 0) || null;
  const examId = Number(params.get('exam') ?? 0) || null;
  const program = params.get('program') ?? '';
  const semester = params.get('semester') ?? '';
  const set = (patch: Record<string, string | number | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, String(v))));
    setParams(next);
  };
  const programs = useLookup('programs');
  const exams = useApi<{ rows: ResultsExamRow[] }>(['results', 'exams', sessionId, program, semester], 'results/exams', { session_id: sessionId ?? 0, program_id: program || undefined, semester: semester || undefined });
  const examOptions = useMemo(() => (exams.data?.rows ?? []).map((e) => ({ value: e.id, label: e.name, sub: `${e.type_name} · ${e.total} results · ${e.status === 'published' ? 'published' : 'not published'}` })), [exams.data]);

  return (
    <>
      <PageHeader
        title="Results"
        description="Computed results with SGPA, CGPA and ranks — analyse pass percentages, grades and subjects, withhold results and publish them to students."
        breadcrumbs={[{ label: 'Results & Marksheet' }, { label: 'Results' }]}
        actions={
          <>
            <Button variant="secondary" icon={Award} to="/results/grades">Grading scale</Button>
            <Button variant="secondary" icon={FileBadge} to={examId ? `/marksheets?exam=${examId}` : '/marksheets'}>Marksheets</Button>
          </>
        }
      />
      <Card className="mb-5 p-4">
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-[11rem_minmax(0,1fr)_11rem_minmax(0,2fr)]">
          <Select inputSize="sm" aria-label="Session" value={String(sessionId ?? '')} onChange={(ev) => set({ session: ev.target.value, exam: null })} options={sessions.map((s) => ({ value: String(s.id), label: s.name }))} />
          <Select inputSize="sm" aria-label="Program" value={program} onChange={(ev) => set({ program: ev.target.value, exam: null })} options={programs.data ?? []} placeholder="All programs" />
          <Select inputSize="sm" aria-label="Semester" value={semester} onChange={(ev) => set({ semester: ev.target.value, exam: null })} options={SEMESTER_OPTIONS} placeholder="All semesters" />
          <Combobox size="sm" value={examId} options={examOptions} onChange={(v) => set({ exam: v ? Number(v) : null })} placeholder={exams.isLoading ? 'Loading exams…' : `Choose an exam (${examOptions.length})`} clearable />
        </div>
      </Card>
      {examId ? <ExamResults key={examId} examId={examId} onBack={() => set({ exam: null })} onLoaded={(sid) => { if (sid && sid !== sessionId) set({ session: sid }); }} /> : <SessionView sessionId={sessionId} exams={exams.data?.rows} loading={exams.isLoading} error={exams.error as ApiError | null} onPick={(id) => set({ exam: id })} />}
    </>
  );
}

/* ------------------------------------------------------------------ Session overview */
function SessionView({ sessionId, exams, loading, error, onPick }: { sessionId: number | null; exams?: ResultsExamRow[]; loading: boolean; error: ApiError | null; onPick: (id: number) => void }) {
  const q = useApi<SessionResults>(['results', 'session', sessionId], 'results/session', { session_id: sessionId ?? undefined });
  const d = q.data;
  const total = d ? Object.values(d.status).reduce((a, b) => a + b, 0) : 0;
  const appeared = d ? total - (d.status.ABSENT ?? 0) - (d.status.WITHHELD ?? 0) : 0;
  const passPct = appeared ? Math.round((1000 * (d?.status.PASS ?? 0)) / appeared) / 10 : null;
  const finals = (exams ?? []).filter((e) => e.is_final && e.avg_sgpa !== null);
  const avgSgpa = finals.length ? finals.reduce((a, e) => a + (e.avg_sgpa ?? 0) * e.total, 0) / Math.max(1, finals.reduce((a, e) => a + e.total, 0)) : null;
  // Before any end-semester result exists (e.g. only mid-terms published) show the average percentage instead of an empty SGPA
  const withPct = (exams ?? []).filter((e) => e.avg_percent !== null && e.total > 0);
  const avgPct = withPct.length ? withPct.reduce((a, e) => a + (e.avg_percent ?? 0) * e.total, 0) / withPct.reduce((a, e) => a + e.total, 0) : null;
  const columns: Column<ResultsExamRow>[] = [
    { key: 'name', header: 'Exam', render: (e) => (
      <button type="button" onClick={() => onPick(e.id)} className="block min-w-[14rem] text-left leading-tight">
        <span className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{e.name}</span>
        <span className="block text-xs text-slate-500">{e.type_name} · {e.status === 'published' ? `declared ${formatDate(e.result_date ?? e.published_at)}` : e.result_date ? `expected ${formatDate(e.result_date)}` : 'not declared yet'}</span>
      </button>
    ) },
    { key: 'total', header: 'Students', align: 'center', render: (e) => formatNumber(e.total) },
    { key: 'pass_percent', header: 'Pass %', render: (e) => (e.pass_percent === null ? '—' : <MiniProgress value={e.pass_percent} tone={e.pass_percent >= 85 ? 'green' : e.pass_percent >= 70 ? 'blue' : 'amber'} label="Pass percentage" />) },
    { key: 'avg_percent', header: 'Avg %', align: 'center', render: (e) => (e.avg_percent === null ? '—' : `${e.avg_percent}%`) },
    { key: 'avg_sgpa', header: 'Avg SGPA', align: 'center', render: (e) => (e.is_final ? fmtGpa(e.avg_sgpa) : '—') },
    { key: 'withheld', header: 'Withheld', align: 'center', render: (e) => (e.withheld ? <Badge color="purple">{e.withheld}</Badge> : <span className="text-slate-400">0</span>) },
    { key: 'status', header: 'Status', render: (e) => <ExamStatusBadge status={e.status} /> },
  ];
  return (
    <div className="space-y-6">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={70}>
        {[
          <StatCard key="r" label="Published results" value={total} icon={Users} tone="blue" loading={!d} hint={`${formatNumber(exams?.filter((e) => e.status === 'published').length ?? 0)} exams published`} />,
          <StatCard key="p" label="Pass percentage" value={passPct === null ? '—' : `${passPct}%`} icon={Percent} tone="green" loading={!d} hint={`${formatNumber(d?.status.PASS ?? 0)} passed of ${formatNumber(appeared)} appeared`} />,
          avgSgpa !== null || avgPct === null ? (
            <StatCard key="s" label="Average SGPA" value={avgSgpa === null ? '—' : avgSgpa.toFixed(2)} icon={Sigma} tone="purple" loading={!exams} hint={avgSgpa === null ? 'No end-semester results yet' : `${formatNumber(finals.length)} end-semester exam${finals.length === 1 ? '' : 's'}`} />
          ) : (
            <StatCard key="s" label="Average percentage" value={`${avgPct.toFixed(1)}%`} icon={Sigma} tone="purple" loading={!exams} hint="No end-semester results yet" />
          ),
          <StatCard key="w" label="Backlog / withheld" value={`${formatNumber((d?.status.BACKLOG ?? 0) + (d?.status.FAIL ?? 0))} / ${formatNumber(d?.status.WITHHELD ?? 0)}`} icon={Ban} tone="amber" loading={!d} hint="Students needing attention" />,
        ]}
      </Stagger>
      {q.error && <Alert variant="error">{(q.error as ApiError).message}</Alert>}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Program-wise pass percentage" subtitle="Published results of the session" icon={BarChart3} />
            <div className="p-5">
              {!d ? <Skeleton className="h-64 w-full" /> : d.programs.length ? (
                <BarChart labels={d.programs.map((p) => p.label)} series={[{ label: 'Pass %', data: d.programs.map((p) => p.pass_percent), color: '#22943F' }]} percent height={260} />
              ) : <EmptyState icon={BarChart3} title="No published results yet" description="Publish the results of an exam to see the analysis." />}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Result status" icon={Trophy} />
            <div className="p-5">
              {!d ? <Skeleton className="mx-auto h-44 w-44 rounded-full" /> : total ? (
                <DoughnutChart labels={Object.keys(d.status).map((k) => RESULT_STATUS[k as ResultStatus]?.label ?? k)} data={Object.values(d.status)} colors={Object.keys(d.status).map((k) => RESULT_STATUS[k as ResultStatus]?.hex ?? '#64748B')} centerValue={formatNumber(total)} centerLabel="Results" height={170} valueFormat="number" />
              ) : <EmptyState icon={Trophy} title="No results" className="py-8" />}
            </div>
          </Card>
        </Reveal>
      </div>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Session toppers" subtitle={d?.toppers_basis === 'percentage' ? 'Highest percentage in published results' : 'Highest SGPA in end-semester results'} icon={Crown} />
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {!d && Array.from({ length: 5 }).map((_, i) => <li key={i} className="px-5 py-3"><Skeleton className="h-9 w-full" /></li>)}
              {d?.toppers.map((t, i) => (
                <li key={t.id}>
                  <button type="button" onClick={() => onPick(t.exam_id)} className="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <span className={clsx('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', i === 0 ? 'bg-amber-100 text-amber-700' : i === 1 ? 'bg-slate-200 text-slate-700' : i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>{i + 1}</span>
                    <Avatar name={t.name} src={t.photo} size="sm" />
                    <span className="min-w-0 flex-1 leading-tight">
                      <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{t.name}</span>
                      <span className="block truncate text-xs text-slate-500">{t.program_short} · Sem {t.semester_no}{d.toppers_basis === 'percentage' ? ` · ${String(t.exam_name).split('—').pop()?.trim()}` : ''}</span>
                    </span>
                    <span className="font-display text-sm font-bold text-brand-800 dark:text-brand-200">{d.toppers_basis === 'percentage' || t.sgpa === null ? `${fmtMarks(t.percentage)}%` : fmtGpa(t.sgpa)}</span>
                  </button>
                </li>
              ))}
              {d && !d.toppers.length && <li><EmptyState icon={Crown} title="No toppers yet" className="py-8" /></li>}
            </ul>
          </Card>
        </Reveal>
        <Reveal delay={60} className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Pass percentage trend" subtitle="End-semester results across sessions" icon={TrendingUp} />
            <div className="p-5">
              {!d ? <Skeleton className="h-56 w-full" /> : d.trend.length ? (
                <LineChart labels={d.trend.map((t) => t.label)} series={[{ label: 'Pass %', data: d.trend.map((t) => t.pass_percent), color: '#22943F', fill: true }]} percent height={230} />
              ) : <EmptyState icon={TrendingUp} title="Not enough data" className="py-8" />}
            </div>
          </Card>
        </Reveal>
      </div>
      {d && d.pending.length > 0 && (
        <Reveal>
          <Alert variant="warning" title={`${d.pending.length} exam${d.pending.length === 1 ? '' : 's'} awaiting results`}>
            <span className="flex flex-wrap gap-x-3 gap-y-1">
              {d.pending.map((p) => (
                <Link key={p.id} to={`/examination/${p.id}?tab=marks`} className="font-medium underline-offset-2 hover:underline">
                  {p.cohort} ({p.status === 'verified' ? 'verified — ready to publish' : 'marks entry'})
                </Link>
              ))}
            </span>
          </Alert>
        </Reveal>
      )}
      <Reveal>
        <Card className="overflow-hidden">
          <CardHeader title="Exams with results" subtitle="Choose an exam to open its result sheet and analysis" icon={GraduationCap} />
          {error ? <div className="p-5"><Alert variant="error">{error.message}</Alert></div> : (
            <DataTable columns={columns} rows={exams ?? []} loading={loading} onRowClick={(e) => onPick(e.id)}
              empty={<EmptyState icon={GraduationCap} title="No results in this session" description="Results appear when the marks of an exam are verified." action={<Button to="/examination">Open examinations</Button>} />} />
          )}
        </Card>
      </Reveal>
    </div>
  );
}

/* ------------------------------------------------------------------ One exam */
function ExamResults({ examId, onBack, onLoaded }: { examId: number; onBack: () => void; onLoaded: (sessionId: number) => void }) {
  const q = useApi<ResultsOverview>(['results', 'overview', examId], 'results/overview', { exam_id: examId });
  const loadedSession = q.data?.exam.academic_session_id;
  useEffect(() => {
    if (loadedSession) onLoaded(loadedSession);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loadedSession]);
  const d = q.data;
  const wf = useExamWorkflow(d?.exam, d?.counts);
  const [drawer, setDrawer] = useState<number | null>(null);
  const [withhold, setWithhold] = useState<Row | null>(null);
  const toast = useToast();
  const invalidate = useInvalidate();
  if (q.error) {
    return <Alert variant="error" title="Unable to load results" action={<Button size="sm" variant="secondary" onClick={onBack}>Back</Button>}>{(q.error as ApiError).message}</Alert>;
  }
  if (!d) {
    return (
      <div className="space-y-5">
        <Skeleton className="h-20 w-full rounded-2xl" />
        <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-32 rounded-2xl" />)}</div>
        <Skeleton className="h-72 w-full rounded-2xl" />
      </div>
    );
  }
  const e = d.exam;
  const c = d.counts;
  const perm = d.permissions;
  const release = async (r: Row) => {
    try {
      const res = await api.post(`results/${r.id}/release`);
      toast.success(res.message);
      await invalidate('results', 'crud', 'exams');
    } catch (err) {
      toast.error((err as ApiError).message);
    }
  };
  const subjectCols: Column<ResultsOverview['subjects'][number]>[] = [
    { key: 'name', header: 'Subject', render: (s) => (
      <div className="min-w-[12rem] leading-tight">
        <p className="font-semibold text-slate-900 dark:text-white">{s.name}</p>
        <p className="text-xs text-slate-500"><span className="font-mono">{s.code}</span> · max {s.max_total}</p>
      </div>
    ) },
    { key: 'average_percent', header: 'Average', render: (s) => (s.average_percent === null ? '—' : <MiniProgress value={s.average_percent} tone={s.average_percent >= 60 ? 'green' : s.average_percent >= 45 ? 'blue' : 'amber'} label="Average percentage" />) },
    { key: 'highest', header: 'High / Low', align: 'center', render: (s) => <span className="whitespace-nowrap tabular-nums">{fmtMarks(s.highest)} / {fmtMarks(s.lowest)}</span> },
    { key: 'pass_percent', header: 'Pass %', align: 'center', render: (s) => (s.pass_percent === null ? '—' : <span className={clsx('font-semibold tabular-nums', s.pass_percent < 80 ? 'text-amber-600' : 'text-emerald-700 dark:text-emerald-400')}>{s.pass_percent}%</span>) },
    { key: 'failed', header: 'Failed', align: 'center', render: (s) => (s.failed ? <span className="font-medium text-red-600 dark:text-red-400">{s.failed}</span> : '0') },
    { key: 'absent', header: 'Absent', align: 'center' , render: (s) => s.absent },
    { key: 'grades', header: 'Grades', render: (s) => (
      <div className="flex min-w-[10rem] flex-wrap gap-1">
        {Object.entries(s.grades).sort().map(([g, n]) => (
          <span key={g} className={clsx('rounded px-1.5 py-0.5 text-[11px] font-semibold', g === 'F' ? 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300' : g === 'AB' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800' : 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200')}>{g} {n}</span>
        ))}
      </div>
    ) },
  ];
  const hasResults = c.total > 0;
  return (
    <div className="space-y-6">
      <Reveal>
        <Card className="p-4 sm:p-5">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div className="min-w-0 flex-1">
              <button type="button" onClick={onBack} className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-brand-700 dark:hover:text-brand-300">
                <ChevronLeft className="h-3.5 w-3.5" /> All results
              </button>
              <h2 className="font-display text-lg font-semibold text-slate-900 dark:text-white">{e.name}</h2>
              <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <ExamStatusBadge status={e.status} />
                <span>{e.cohort} · {e.session_name}</span>
                {e.published_at && <span>Published {formatDateTime(e.published_at)}{e.published_by_name ? ` by ${e.published_by_name}` : ''}</span>}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" icon={Eye} to={`/examination/${e.id}?tab=marks`}>Workflow</Button>
              {e.status === 'verified' && perm.publish && <Button size="sm" variant="success" icon={Send} onClick={wf.publish}>Publish results</Button>}
              {e.status === 'verified' && perm.verify && <Button size="sm" variant="secondary" icon={Lock} onClick={wf.unverify} loading={wf.busy === 'unverify'}>Unlock marks</Button>}
              {e.status === 'published' && e.is_final && perm.marksheets && <Button size="sm" icon={FileBadge} to={`/marksheets?exam=${e.id}`}>{d.marksheets ? `Marksheets (${d.marksheets})` : 'Generate marksheets'}</Button>}
              {e.status === 'published' && d.marksheets > 0 && <Button size="sm" variant="secondary" icon={Printer} href={printUrl('marksheet.php', { exam_id: e.id })} target="_blank">Print all</Button>}
              {e.status === 'published' && perm.publish && <Button size="sm" variant="secondary" icon={Undo2} onClick={wf.unpublish} loading={wf.busy === 'unpublish'}>Unpublish</Button>}
            </div>
          </div>
        </Card>
      </Reveal>
      {!hasResults ? (
        <Card>
          <EmptyState icon={Hourglass} title="Results are not computed yet" description={d.missing_marks.length ? `Marks are still incomplete: ${d.missing_marks.slice(0, 3).join(', ')}${d.missing_marks.length > 3 ? '…' : ''}. Results are computed when the marks are verified.` : 'Verify the marks of this exam to compute the results.'}
            action={<Button to={`/examination/${e.id}?tab=marks`} icon={ShieldCheck}>Open marks & verification</Button>} />
        </Card>
      ) : (
        <>
          {e.status === 'verified' && <Alert variant="info" title="Not yet published">These results are computed from the locked marks but students cannot see them until they are published.</Alert>}
          {!['verified', 'published'].includes(e.status) && (
            <Alert variant="warning" title="Marks are unlocked for corrections" action={<Button size="sm" variant="secondary" to={`/examination/${e.id}?tab=marks`}>Open verification</Button>}>
              These results were computed when the marks were last verified and may be out of date. Verify the marks again to recompute them before publishing.
            </Alert>
          )}
          <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={70}>
            {[
              <StatCard key="a" label="Appeared" value={c.appeared} icon={Users} tone="blue" hint={`${c.total} results · ${c.absent} absent`} />,
              <StatCard key="p" label="Pass percentage" value={c.pass_percent === null ? '—' : `${c.pass_percent}%`} icon={Percent} tone="green" hint={`${c.pass} passed · ${c.backlog} backlog · ${c.fail} fail`} />,
              <StatCard key="s" label={e.is_final ? 'Average SGPA' : 'Average percentage'} value={e.is_final ? fmtGpa(c.avg_sgpa) : c.avg_percent === null ? '—' : `${c.avg_percent}%`} icon={Sigma} tone="purple" hint={e.is_final ? `Average ${c.avg_percent ?? '—'}% · top ${c.top_percent ?? '—'}%` : `Top ${c.top_percent ?? '—'}%`} />,
              <StatCard key="w" label="Withheld" value={c.withheld} icon={Ban} tone="amber" hint={c.withheld ? 'Hidden until released' : 'No results withheld'} />,
            ]}
          </Stagger>
          {d.toppers.length > 0 && (
            <Stagger className="grid gap-3 sm:grid-cols-3 xl:grid-cols-5" step={60}>
              {d.toppers.map((t, i) => (
                <button key={t.id} type="button" onClick={() => setDrawer(t.id)} className={clsx('w-full rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-soft', i === 0 ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-white dark:border-amber-500/30 dark:from-amber-500/10 dark:to-slate-900' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900')}>
                  <div className="flex items-center justify-between">
                    <Avatar name={t.name} src={t.photo} />
                    <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold', i === 0 ? 'bg-amber-100 text-amber-700' : i === 1 ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' : i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
                      {i < 3 ? <Medal className="h-3.5 w-3.5" /> : null} Rank {t.class_rank}
                    </span>
                  </div>
                  <p className="mt-3 truncate font-semibold text-slate-900 dark:text-white">{t.name}</p>
                  <p className="truncate text-xs text-slate-500">{t.student_uid}{t.section_name ? ` · Sec ${t.section_name}` : ''}</p>
                  <p className="mt-2 font-display text-xl font-bold text-brand-800 dark:text-brand-200">{e.is_final && t.sgpa !== null ? fmtGpa(t.sgpa) : `${fmtMarks(t.percentage)}%`}<span className="ml-1 text-xs font-medium text-slate-500">{e.is_final ? `SGPA · ${fmtMarks(t.percentage)}%` : ''}</span></p>
                </button>
              ))}
            </Stagger>
          )}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Reveal className="lg:col-span-2">
              <Card className="h-full">
                <CardHeader title="Grade distribution" subtitle="All subject grades awarded in this exam" icon={BarChart3} />
                <div className="p-5">
                  <BarChart labels={d.grade_distribution.map((g) => g.grade)} series={[{ label: 'Subject grades', data: d.grade_distribution.map((g) => g.count) }]} height={240} />
                </div>
              </Card>
            </Reveal>
            <Reveal delay={80}>
              <Card className="h-full">
                <CardHeader title="Result status" icon={Trophy} />
                <div className="p-5">
                  <DoughnutChart
                    labels={(['PASS', 'BACKLOG', 'FAIL', 'ABSENT', 'WITHHELD'] as ResultStatus[]).map((k) => RESULT_STATUS[k].label)}
                    data={[c.pass, c.backlog, c.fail, c.absent, c.withheld]}
                    colors={(['PASS', 'BACKLOG', 'FAIL', 'ABSENT', 'WITHHELD'] as ResultStatus[]).map((k) => RESULT_STATUS[k].hex)}
                    centerValue={c.pass_percent === null ? '—' : `${c.pass_percent}%`}
                    centerLabel="Pass"
                    height={170}
                    valueFormat="number"
                  />
                </div>
              </Card>
            </Reveal>
          </div>
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Reveal>
              <Card className="h-full">
                <CardHeader title="Percentage bands" subtitle="Students by overall percentage" icon={BarChart3} />
                <div className="p-5">
                  <BarChart labels={d.bands.map((b) => b.label)} series={[{ label: 'Students', data: d.bands.map((b) => b.count), color: '#1D4ED8' }]} height={220} />
                </div>
              </Card>
            </Reveal>
            <Reveal delay={80}>
              <Card className="h-full">
                <CardHeader title="Subject pass percentage" subtitle="Students who passed each paper (excluding absentees)" icon={BookOpen} />
                <div className="p-5">
                  <BarChart labels={d.subjects.map((s) => s.code)} series={[{ label: 'Pass %', data: d.subjects.map((s) => s.pass_percent ?? 0), color: '#22943F' }]} percent height={220} />
                </div>
              </Card>
            </Reveal>
          </div>
          <Reveal>
            <Card className="overflow-hidden">
              <CardHeader title="Subject-wise analysis" subtitle="Averages, extremes, pass percentage and grade spread per paper" icon={BookOpen} />
              <DataTable columns={subjectCols} rows={d.subjects} rowKey={(s) => s.subject_id} />
            </Card>
          </Reveal>
          <CrudTable
            module="results"
            title="Result sheet"
            description={`${formatNumber(c.total)} students · ranked by percentage among passed students`}
            scope={{ exam_id: e.id }}
            defaultFilters={{ program_id: e.program_id ?? undefined, semester_no: e.semester_no ?? undefined }}
            hideFilters={['program_id', 'semester_no', 'academic_session_id']}
            hideColumns={['exam_name']}
            onView={(r) => setDrawer(Number(r.id))}
            rowMenu={(r) => [
              r.marksheet_id && r.marksheet_status === 'issued' ? { label: 'Print marksheet', icon: FileBadge, href: printUrl('marksheet.php', { id: r.marksheet_id }), target: '_blank' }
                : r.is_published && r.result_status !== 'WITHHELD' ? { label: 'Provisional statement', icon: Printer, href: printUrl('marksheet.php', { result_id: r.id }), target: '_blank' } : null,
              { label: 'Student profile', icon: GraduationCap, to: `/students/${r.student_id}?tab=results` },
              perm.withhold && r.result_status !== 'WITHHELD' && { label: 'Withhold result…', icon: Ban, danger: true, onClick: () => setWithhold(r), disabled: r.marksheet_status === 'issued', hint: r.marksheet_status === 'issued' ? 'Revoke marksheet first' : undefined },
              perm.withhold && r.result_status === 'WITHHELD' && { label: 'Release result', icon: ShieldCheck, onClick: () => release(r) },
            ]}
            renderers={{
              class_rank: (r) => (r.class_rank ? (
                <span className={clsx('inline-flex h-7 min-w-[1.75rem] items-center justify-center rounded-full px-1.5 text-xs font-bold', r.class_rank === 1 ? 'bg-amber-100 text-amber-700' : r.class_rank === 2 ? 'bg-slate-200 text-slate-700' : r.class_rank === 3 ? 'bg-orange-100 text-orange-700' : 'text-slate-600 dark:text-slate-300')}>{r.class_rank}</span>
              ) : <span className="text-slate-300 dark:text-slate-600">—</span>),
              total_marks: (r) => <span className="whitespace-nowrap tabular-nums">{fmtMarks(r.total_marks)}<span className="text-xs text-slate-400"> / {fmtMarks(r.max_marks, 0)}</span></span>,
              percentage: (r) => <span className="font-semibold tabular-nums">{r.percentage === null ? '—' : `${fmtMarks(r.percentage)}%`}</span>,
              sgpa: (r) => <span className="font-semibold tabular-nums text-brand-800 dark:text-brand-200">{fmtGpa(r.sgpa)}</span>,
              cgpa: (r) => <span className="tabular-nums">{fmtGpa(r.cgpa)}</span>,
              result_status: (r) => (
                <div className="leading-tight">
                  <ResultBadge status={r.result_status} />
                  {r.result_status === 'BACKLOG' && <p className="mt-0.5 text-[11px] text-slate-500">{r.backlog_count} subject{r.backlog_count === 1 ? '' : 's'}</p>}
                  {r.result_status === 'WITHHELD' && r.withheld_reason && <p className="mt-0.5 max-w-[14rem] truncate text-[11px] text-slate-500" title={r.withheld_reason}>{r.withheld_reason}</p>}
                </div>
              ),
            }}
            emptyTitle="No results match"
            emptyText="Try another search or filter."
          />
        </>
      )}
      <ResultDrawer resultId={drawer} onClose={() => setDrawer(null)} />
      {withhold && <WithholdModal open onClose={() => setWithhold(null)} resultId={Number(withhold.id)} name={String(withhold.student_name)} />}
      {wf.modal}
    </div>
  );
}
