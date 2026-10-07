import clsx from 'clsx';
import { Award, BookCheck, LineChart as LineIcon, ListOrdered, Medal, Trophy, XCircle } from 'lucide-react';
import { Badge, Button, Card, CardBody, CardHeader, Reveal, StatusBadge, Stagger, type Column } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import type { GpaStats } from '../types';
import { MiniStat, TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface ResultRow extends Record<string, unknown> {
  id: number; exam_name: string; session_name: string | null; semester_no: number | null; total_marks: string | null; max_marks: string | null; percentage: string | null;
  credits_registered: string | null; credits_earned: string | null; sgpa: string | null; cgpa: string | null; result_status: string; backlog_count: number; class_rank: number | null;
  division: string | null; is_published: boolean; marksheet_no: string | null;
}
interface MarkRow extends Record<string, unknown> {
  code: string; name: string; credits: string; internal_marks: string | null; external_marks: string | null; practical_marks: string | null; total_marks: string | null; max_marks: string | null;
  grade: string | null; grade_point: string | null; is_absent: number; is_pass: number | null;
}
interface ResultsData { results: ResultRow[]; trend: { semester: number; sgpa: number; cgpa: number | null }[]; trend_source: string; latest: ResultRow | null; latest_marks: MarkRow[]; gpa: GpaStats }

const n2 = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(2));

export default function ResultsTab({ studentId }: TabProps) {
  const q = useApi<ResultsData>(['students', studentId, 'results'], `students/${studentId}/results`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const { results, trend, trend_source: source, latest, latest_marks: marks, gpa } = q.data!;
  if (!results.length && !trend.length) {
    return <TabEmpty icon={Trophy} title="No results declared yet" description="SGPA, CGPA and subject marks appear after the examination cell publishes results." action={<Button variant="secondary" to="/results">Open results</Button>} />;
  }
  const best = trend.reduce((m, t) => Math.max(m, t.sgpa), 0);
  const resultCols: Column<ResultRow>[] = [
    { key: 'exam_name', header: 'Exam', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.exam_name}</p><p className="text-xs text-slate-500">{r.semester_no ? `Semester ${r.semester_no}` : ''}{r.session_name ? ` · ${r.session_name}` : ''}</p></div> },
    { key: 'percentage', header: 'Marks', align: 'right', render: (r) => <span className="whitespace-nowrap tabular-nums">{r.total_marks ? `${formatNumber(r.total_marks)}/${formatNumber(r.max_marks)}` : '—'}{r.percentage ? <span className="block text-xs text-slate-500">{Number(r.percentage).toFixed(1)}%</span> : null}</span> },
    { key: 'credits', header: 'Credits', align: 'center', render: (r) => <span className="tabular-nums">{r.credits_earned ?? '—'}/{r.credits_registered ?? '—'}</span> },
    { key: 'sgpa', header: 'SGPA', align: 'right', render: (r) => <span className="font-semibold tabular-nums">{n2(r.sgpa)}</span> },
    { key: 'cgpa', header: 'CGPA', align: 'right', render: (r) => <span className="font-semibold tabular-nums text-brand-800 dark:text-brand-200">{n2(r.cgpa)}</span> },
    { key: 'result_status', header: 'Result', render: (r) => <div className="flex flex-wrap items-center gap-1"><StatusBadge status={r.result_status} />{r.backlog_count > 0 && <Badge color="red">{r.backlog_count} backlog</Badge>}</div> },
    { key: 'is_published', header: 'Published', render: (r) => (r.is_published ? <Badge color="green">Published</Badge> : <Badge color="amber">Draft</Badge>) },
  ];
  const markCols: Column<MarkRow>[] = [
    { key: 'name', header: 'Subject', render: (r) => <span><span className="mr-1.5 font-mono text-xs text-slate-500">{r.code}</span>{r.name}</span> },
    { key: 'internal_marks', header: 'Internal', align: 'right', render: (r) => r.internal_marks ?? '—' },
    { key: 'external_marks', header: 'External', align: 'right', render: (r) => r.external_marks ?? '—' },
    { key: 'practical_marks', header: 'Practical', align: 'right', render: (r) => r.practical_marks ?? '—' },
    { key: 'total_marks', header: 'Total', align: 'right', render: (r) => (r.is_absent ? <Badge color="red">Absent</Badge> : <span className="font-semibold tabular-nums">{r.total_marks ?? '—'}/{r.max_marks ?? '—'}</span>) },
    { key: 'grade', header: 'Grade', align: 'center', render: (r) => <span className={clsx('inline-flex h-7 min-w-[2rem] items-center justify-center rounded-lg px-1.5 text-xs font-bold', r.is_pass === 0 ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300')}>{r.grade ?? '—'}</span> },
    { key: 'grade_point', header: 'GP', align: 'right', render: (r) => r.grade_point ?? '—' },
  ];
  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full" step={50}>
        {[
          <MiniStat key="c" label="CGPA" value={gpa.cgpa !== null ? gpa.cgpa.toFixed(2) : '—'} icon={Trophy} tone="navy" sub={gpa.semester ? `After semester ${gpa.semester}` : undefined} />,
          <MiniStat key="s" label="Latest SGPA" value={gpa.sgpa !== null ? gpa.sgpa.toFixed(2) : '—'} icon={Medal} tone="green" sub={gpa.result_status ?? undefined} />,
          <MiniStat key="b" label="Best SGPA" value={best ? best.toFixed(2) : '—'} icon={Award} tone="amber" sub={`${trend.length} semester${trend.length === 1 ? '' : 's'} on record`} />,
          <MiniStat key="k" label="Backlogs" value={gpa.backlogs} icon={gpa.backlogs ? XCircle : BookCheck} tone={gpa.backlogs ? 'red' : 'slate'} sub={gpa.backlogs ? 'Subjects to clear' : 'All subjects cleared'} />,
        ]}
      </Stagger>
      <Reveal>
        <Card>
          <CardHeader title="SGPA / CGPA trend" icon={LineIcon} subtitle={source === 'academic' ? 'From the academic history (detailed results not yet published)' : 'From published results'} />
          <CardBody>
            {trend.length ? (
              <LineChart height={260} labels={trend.map((t) => `Sem ${t.semester}`)} series={[
                { label: 'SGPA', data: trend.map((t) => t.sgpa), color: '#1D4ED8', fill: true },
                { label: 'CGPA', data: trend.map((t) => t.cgpa ?? 0), color: '#22943F', dashed: true },
              ]} />
            ) : (
              <p className="py-10 text-center text-sm text-slate-500">No graded semesters yet.</p>
            )}
          </CardBody>
        </Card>
      </Reveal>
      {results.length > 0 && <TableCard<ResultRow> title="Results" subtitle={`${results.length} declared`} icon={ListOrdered} columns={resultCols} rows={results} empty="No results" emptyIcon={ListOrdered} delay={40} />}
      {latest && (
        <TableCard<MarkRow> title={`Subject marks — ${latest.exam_name}`} subtitle={latest.division ?? undefined} icon={BookCheck} columns={markCols} rows={marks} empty="Marks not entered yet" emptyIcon={BookCheck} delay={80} />
      )}
    </div>
  );
}
