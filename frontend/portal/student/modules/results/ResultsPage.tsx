import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Award, BookOpenCheck, FileText, GraduationCap, Layers, ListOrdered, Lock, Medal, Sparkles, TrendingUp, TriangleAlert } from 'lucide-react';
import { Stagger, Tabs } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { formatDate } from '@/lib/format';
import { EmptyBlock, ErrorState, KpiCard, KpiSkeleton, PageHead, Ring, Section } from '../../../components/Ui';
import { ListSkeleton, PrintButton, StatusBadge, useStudent } from '../../shared/ui';
import type { ResultItem, StudentResults } from '../../shared/types';
import { ResultDrawer } from './ResultDrawer';

const n2 = (v: number | null | undefined) => (v === null || v === undefined ? '—' : v.toFixed(2));

function ResultCard({ r, onOpen }: { r: ResultItem; onOpen: () => void }) {
  return (
    <article className="card lift flex h-full flex-col p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-flame-700 dark:text-flame-300">
            Semester {r.semester ?? '—'} · {r.session ?? ''}
          </p>
          <h3 className="mt-1 font-display text-[15px] font-bold leading-snug text-slate-900 dark:text-white">{r.exam_name}</h3>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {r.type}
            {r.result_date ? ` · declared ${formatDate(r.result_date)}` : ''}
          </p>
        </div>
        {r.withheld ? <StatusBadge status="WITHHELD" label="Withheld" icon={Lock} /> : <StatusBadge status={r.result_status} label={r.result_status === 'BACKLOG' ? 'Backlog' : undefined} />}
      </div>
      {!r.withheld && (
        <dl className="mt-4 grid grid-cols-3 gap-2 text-center">
          {r.is_final ? (
            <>
              <div className="rounded-xl bg-brand-50 px-2 py-2 dark:bg-brand-500/10">
                <dt className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">SGPA</dt>
                <dd className="font-display text-lg font-extrabold text-brand-900 dark:text-white">{n2(r.sgpa)}</dd>
              </div>
              <div className="rounded-xl bg-flame-50 px-2 py-2 dark:bg-flame-500/10">
                <dt className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">CGPA</dt>
                <dd className="font-display text-lg font-extrabold text-flame-700 dark:text-flame-200">{n2(r.cgpa)}</dd>
              </div>
            </>
          ) : (
            <>
              <div className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
                <dt className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Marks</dt>
                <dd className="font-display text-lg font-extrabold text-slate-900 dark:text-white">{r.total_marks ?? '—'}</dd>
              </div>
              <div className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
                <dt className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Rank</dt>
                <dd className="font-display text-lg font-extrabold text-slate-900 dark:text-white">{r.class_rank ?? '—'}</dd>
              </div>
            </>
          )}
          <div className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
            <dt className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Percent</dt>
            <dd className="font-display text-lg font-extrabold text-slate-900 dark:text-white">{r.percentage !== null ? `${r.percentage}%` : '—'}</dd>
          </div>
        </dl>
      )}
      {r.withheld && <p className="mt-3 text-[13px] text-slate-500">Contact the examination cell to know why this result is withheld.</p>}
      <div className="mt-auto flex flex-wrap items-center gap-2 pt-4">
        <button type="button" onClick={onOpen} className="btn btn-navy btn-sm">
          <ListOrdered className="h-4 w-4" aria-hidden /> {r.withheld ? 'Details' : 'Subject marks'}
        </button>
        {r.printable && (
          <PrintButton path={`marksheet/${r.id}`} icon={FileText}>
            {r.marksheet ? 'Marksheet' : 'Statement'}
          </PrintButton>
        )}
        {r.marksheet && <span className="ml-auto text-[11px] font-medium text-slate-400">{r.marksheet.no}</span>}
      </div>
    </article>
  );
}

export default function StudentResultsPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentResults>(['results'], 'results');
  const [params, setParams] = useSearchParams();
  const openId = params.get('result') ? Number(params.get('result')) : null;
  const [tab, setTab] = useState<'semester' | 'internal'>('semester');
  const open = (id: number | null) => {
    const p = new URLSearchParams(params);
    if (id) p.set('result', String(id));
    else p.delete('result');
    setParams(p, { replace: true });
  };
  const finals = useMemo(() => (data?.results ?? []).filter((r) => r.is_final), [data]);
  const internals = useMemo(() => (data?.results ?? []).filter((r) => !r.is_final), [data]);
  const list = tab === 'semester' ? finals : internals;
  const s = data?.summary;
  const trend = data?.trend ?? [];

  return (
    <div>
      <PageHead area="student" title="Results" subtitle="Published results, grade cards and your CGPA journey." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Results' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data || !s ? (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <KpiSkeleton key={i} />
            ))}
          </div>
          <ListSkeleton rows={3} h="h-40" />
        </div>
      ) : data.results.length === 0 ? (
        <div className="card">
          <EmptyBlock icon={Award} title="No published results yet" text="Your results appear here as soon as the examination cell publishes them." />
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" itemClassName="h-full">
            {[
              <KpiCard
                key="cgpa"
                label="CGPA"
                icon={GraduationCap}
                tone="navy"
                value={s.cgpa ?? '—'}
                unit={s.cgpa !== null ? '/ 10' : undefined}
                aside={
                  s.cgpa !== null ? (
                    <div className="hidden sm:block">
                      <Ring value={(s.cgpa / 10) * 100} size={56} stroke={6} tone="navy" label={`CGPA ${s.cgpa}`}>
                        <Sparkles className="h-4 w-4 text-flame-500" aria-hidden />
                      </Ring>
                    </div>
                  ) : undefined
                }
                foot={s.division ? s.division : s.semester ? `After semester ${s.semester}` : 'Appears after your first semester result.'}
              />,
              <KpiCard key="sgpa" label="Latest SGPA" icon={TrendingUp} tone="flame" value={s.sgpa ?? '—'} foot={s.semester ? `Semester ${s.semester}${s.best_sgpa ? ` · best ${s.best_sgpa.toFixed(2)}` : ''}` : 'No semester results yet.'} />,
              <KpiCard key="credits" label="Credits earned" icon={Layers} tone="green" value={s.credits_earned} foot={`${s.semesters_completed} of ${s.total_semesters || '—'} semesters completed`} />,
              <KpiCard
                key="backlogs"
                label="Active backlogs"
                icon={s.backlogs ? TriangleAlert : Medal}
                tone={s.backlogs ? 'red' : 'green'}
                value={s.backlogs}
                badge={s.backlogs ? <span className="badge badge-red">Clear in the next supplementary exam</span> : <span className="badge badge-green">All subjects cleared</span>}
              />,
            ]}
          </Stagger>

          {trend.length > 0 && (
            <Section title="SGPA & CGPA trend" subtitle="Semester by semester" icon={TrendingUp}>
              {trend.length === 1 ? (
                <p className="text-sm text-slate-500">
                  Semester {trend[0].semester}: SGPA <strong className="text-slate-900 dark:text-white">{n2(trend[0].sgpa)}</strong>, CGPA <strong className="text-slate-900 dark:text-white">{n2(trend[0].cgpa)}</strong>. The trend line appears after your second semester.
                </p>
              ) : (
                <LineChart
                  height={240}
                  labels={trend.map((t) => t.label)}
                  series={[
                    { label: 'SGPA', data: trend.map((t) => t.sgpa ?? 0), color: '#F05A28' },
                    { label: 'CGPA', data: trend.map((t) => t.cgpa ?? 0), color: '#1D4ED8' },
                  ]}
                />
              )}
            </Section>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3">
            <Tabs
              variant="pills"
              value={tab}
              onChange={(k) => setTab(k as 'semester' | 'internal')}
              tabs={[
                { key: 'semester', label: 'Semester results', icon: BookOpenCheck, count: finals.length },
                { key: 'internal', label: 'Internal & mid-term', icon: ListOrdered, count: internals.length },
              ]}
            />
            <p className="text-xs text-slate-500 dark:text-slate-400">Marksheets carry a QR code that anyone can scan to verify.</p>
          </div>

          {list.length === 0 ? (
            <div className="card">
              <EmptyBlock compact icon={Award} title={tab === 'semester' ? 'No semester results yet' : 'No internal assessment results yet'} />
            </div>
          ) : (
            <Stagger className={clsx('grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3')} itemClassName="h-full">
              {list.map((r) => (
                <ResultCard key={r.id} r={r} onOpen={() => open(r.id)} />
              ))}
            </Stagger>
          )}
        </div>
      )}
      <ResultDrawer id={openId} onClose={() => open(null)} />
    </div>
  );
}
