import { Award, BarChart3, Calculator, CheckCircle2, FileBadge, Info, Ruler, Trophy, XCircle } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, StatTile, Stagger } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart } from '@/components/charts';
import { useCrudList } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { GradeScaleBar, scaleIssues, type ScaleRow } from './components/GradeScaleBar';

export default function GradeScalePage() {
  const list = useCrudList<ScaleRow & { used_count: number }>('grade_scales', { per_page: 100 });
  const rows = list.data?.rows ?? [];
  const issues = scaleIssues(rows);
  const pass = rows.filter((r) => Number(r.is_pass));
  const passMin = pass.length ? Math.min(...pass.map((r) => Number(r.min_percent))) : null;
  const awarded = rows.reduce((a, r) => a + Number(r.used_count || 0), 0);
  const sorted = [...rows].sort((a, b) => Number(b.min_percent) - Number(a.min_percent));
  const overlaps = issues.filter((i) => i.type === 'overlap');
  const gaps = issues.filter((i) => i.type === 'gap');

  return (
    <>
      <PageHeader
        title="Grading Scale"
        description="Percentage bands, letter grades and grade points used for every subject grade, SGPA, CGPA and the marksheet."
        breadcrumbs={[{ label: 'Results & Marksheet', to: '/results' }, { label: 'Grading Scale' }]}
        actions={
          <>
            <Button variant="secondary" icon={Trophy} to="/results">Results</Button>
            <Button variant="secondary" icon={FileBadge} to="/marksheets">Marksheets</Button>
          </>
        }
      />
      <Stagger className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={70}>
        {[
          <StatTile key="g" label="Grades defined" value={rows.length} icon={Award} tone="blue" loading={!list.data} sub={`${pass.length} pass · ${rows.length - pass.length} fail`} />,
          <StatTile key="p" label="Pass threshold" value={passMin === null ? '—' : `${passMin}%`} icon={CheckCircle2} tone="green" loading={!list.data} sub="Lowest pass band" />,
          <StatTile key="i" label="Scale issues" value={issues.length} icon={issues.length ? XCircle : Ruler} tone={issues.length ? 'red' : 'cyan'} loading={!list.data} sub={issues.length ? `${overlaps.length} overlaps · ${gaps.length} gaps` : 'No overlaps or gaps'} />,
          <StatTile key="a" label="Grades awarded" value={awarded} icon={BarChart3} tone="purple" loading={!list.data} sub="Across all exams" />,
        ]}
      </Stagger>

      {list.error && <Alert variant="error" className="mb-5" title="Unable to load the grading scale">{(list.error as ApiError).message}</Alert>}

      <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Scale" subtitle="Hover a band for details. Hatched red areas are percentages no grade covers." icon={Ruler} />
            <div className="p-5 pt-8">
              {!list.data ? <Skeleton className="h-14 w-full rounded-xl" /> : rows.length ? <GradeScaleBar rows={rows} /> : <EmptyState icon={Award} title="No grades defined" description="Add grade bands below — every percentage from 0 to 100 must map to exactly one grade." />}
              {issues.length > 0 && (
                <Alert variant={overlaps.length ? 'error' : 'warning'} className="mt-4" title={overlaps.length ? 'The scale has overlapping bands' : 'The scale has gaps'}>
                  Percentages inside a gap fall back to the next lower grade. Fix the bands so results are computed exactly as intended.
                </Alert>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="How results are computed" icon={Calculator} />
            <div className="space-y-3 p-5 text-sm text-slate-600 dark:text-slate-300">
              <p><strong className="text-slate-900 dark:text-white">Subject:</strong> total = internal + external + practical. A student passes when the total reaches the subject's pass marks; the grade comes from the percentage band above (a pass never gets a fail grade).</p>
              <p><strong className="text-slate-900 dark:text-white">SGPA</strong> = Σ (credits × grade point) ÷ Σ credits of the semester.</p>
              <p><strong className="text-slate-900 dark:text-white">CGPA</strong> = credit-weighted average of every semester's SGPA (latest published attempt).</p>
              <p className="flex gap-2 rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/60"><Info className="h-4 w-4 shrink-0 text-brand-600" /> Changing the scale affects new computations. Use “Recompute” on a verified exam to apply it to existing results.</p>
            </div>
          </Card>
        </Reveal>
      </div>

      {rows.length > 0 && awarded > 0 && (
        <Reveal className="mb-6">
          <Card>
            <CardHeader title="Grades awarded" subtitle="Subject grades across every exam" icon={BarChart3} />
            <div className="p-5">
              <BarChart labels={sorted.map((r) => r.grade)} series={[{ label: 'Subject grades', data: sorted.map((r) => Number(r.used_count || 0)), color: '#1D4ED8' }]} height={200} />
              <p className="mt-2 text-center text-xs text-slate-500">{formatNumber(awarded)} subject grades awarded</p>
            </div>
          </Card>
        </Reveal>
      )}

      <CrudTable
        module="grade_scales"
        title="Grade bands"
        addLabel="Add grade"
        onSaved={() => list.refetch()}
        emptyTitle="No grades yet"
        emptyText="Add the grade bands of your grading system (e.g. O, A+, A, B+, B, C, P, F)."
        renderers={{
          grade: (r) => (
            <div className="leading-tight">
              <span className="font-display text-base font-bold text-slate-900 dark:text-white">{r.grade}</span>
              <span className="block text-xs text-slate-500">{r.description ?? ''}</span>
            </div>
          ),
          grade_point: (r) => <span className="font-semibold tabular-nums">{Number(r.grade_point)}</span>,
        }}
      />
    </>
  );
}
