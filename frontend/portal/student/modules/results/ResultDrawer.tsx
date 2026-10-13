import clsx from 'clsx';
import { FileText, Lock } from 'lucide-react';
import { Drawer } from '@/components/ui';
import { formatDate } from '@/lib/format';
import { ErrorState } from '../../../components/Ui';
import { ListSkeleton, MiniStat, PrintButton, StatusBadge, useStudent } from '../../shared/ui';
import type { ResultDetail, SubjectMark } from '../../shared/types';

const n = (v: number | null) => (v === null ? '—' : Number.isInteger(v) ? String(v) : v.toFixed(2).replace(/0$/, ''));

function MarkCell({ v, max, absent }: { v: number | null; max: number; absent: boolean }) {
  if (!max) return <span className="text-slate-300 dark:text-slate-600">—</span>;
  return (
    <span className="tabular-nums">
      <span className="font-semibold text-slate-900 dark:text-white">{v === null ? (absent ? 'AB' : '—') : n(v)}</span>
      <span className="text-slate-400">/{max}</span>
    </span>
  );
}

function resultTag(s: SubjectMark) {
  if (s.absent) return <span className="badge badge-slate">Absent</span>;
  if (s.pass === null) return <span className="badge badge-slate">—</span>;
  return s.pass ? <span className="badge badge-green">Pass</span> : <span className="badge badge-red">Fail</span>;
}

export function ResultDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error, refetch } = useStudent<ResultDetail>(['results', id], `results/${id}`, undefined, { enabled: id !== null });
  const r = id && data?.id === id ? data : undefined;
  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      width="max-w-3xl"
      title={r ? r.exam_name : 'Result'}
      description={r ? `Semester ${r.semester ?? '—'} · ${r.type}${r.session ? ` · Session ${r.session}` : ''}` : undefined}
      footer={
        r && r.printable ? (
          <PrintButton path={`marksheet/${r.id}`} variant={r.marksheet ? 'primary' : 'secondary'} icon={FileText}>
            {r.marksheet ? 'Print marksheet' : 'Print statement of marks'}
          </PrintButton>
        ) : undefined
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !r ? (
        <ListSkeleton rows={6} h="h-12" />
      ) : r.withheld ? (
        <div className="flex flex-col items-center px-4 py-12 text-center">
          <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300">
            <Lock className="h-6 w-6" aria-hidden />
          </span>
          <p className="mt-3 font-display text-base font-bold text-slate-900 dark:text-white">Result withheld</p>
          <p className="mt-1 max-w-sm text-sm text-slate-500">Your result for this examination has been withheld. Please contact the examination cell to know why and how to get it released.</p>
        </div>
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={r.result_status} label={r.result_status === 'BACKLOG' ? 'Pass with backlog' : undefined} />
            {r.division && <span className="badge badge-navy">{r.division}</span>}
            {r.marksheet && <span className="badge badge-slate">Marksheet {r.marksheet.no}</span>}
            {r.result_date && <span className="text-xs text-slate-500">Declared {formatDate(r.result_date)}</span>}
          </div>
          <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            <MiniStat label="Marks" value={`${n(r.total_marks)} / ${n(r.max_marks)}`} />
            <MiniStat label="Percentage" value={r.percentage !== null ? `${n(r.percentage)}%` : '—'} />
            {r.is_final ? <MiniStat label="SGPA" value={r.sgpa?.toFixed(2) ?? '—'} tone="navy" /> : <MiniStat label="Credits" value={`${n(r.credits_earned)} / ${n(r.credits_registered)}`} />}
            {r.is_final ? <MiniStat label="CGPA" value={r.cgpa?.toFixed(2) ?? '—'} tone="flame" /> : <MiniStat label="Class rank" value={r.class_rank ?? '—'} />}
          </div>

          <ul className="space-y-2 sm:hidden">
            {r.subjects.map((s) => (
              <li key={s.code} className="rounded-2xl border border-slate-200/80 p-3 dark:border-slate-800">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                      {s.code}
                      {s.credits ? ` · ${n(s.credits)} credits` : ''}
                    </p>
                    <p className="text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
                  </div>
                  <span className={clsx('font-display text-lg font-extrabold', s.pass === false ? 'text-red-600' : 'text-brand-900 dark:text-white')}>{s.grade ?? '—'}</span>
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                  {s.max_internal > 0 && (
                    <span>
                      Int <MarkCell v={s.internal} max={s.max_internal} absent={s.absent} />
                    </span>
                  )}
                  {s.max_external > 0 && (
                    <span>
                      Ext <MarkCell v={s.external} max={s.max_external} absent={s.absent} />
                    </span>
                  )}
                  {s.max_practical > 0 && (
                    <span>
                      Prac <MarkCell v={s.practical} max={s.max_practical} absent={s.absent} />
                    </span>
                  )}
                  <span>
                    Total <MarkCell v={s.total} max={s.max_total} absent={s.absent} />
                  </span>
                  <span className="ml-auto">{resultTag(s)}</span>
                </div>
              </li>
            ))}
          </ul>

          <div className="table-wrap hidden rounded-2xl border border-slate-200/80 dark:border-slate-800 sm:block">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Subject</th>
                  <th className="text-center">Internal</th>
                  <th className="text-center">External</th>
                  <th className="text-center">Practical</th>
                  <th className="text-center">Total</th>
                  <th className="text-center">Grade</th>
                  <th className="text-center">GP</th>
                  <th className="text-right">Result</th>
                </tr>
              </thead>
              <tbody>
                {r.subjects.map((s) => (
                  <tr key={s.code}>
                    <td>
                      <span className="font-semibold text-slate-900 dark:text-white">{s.name}</span>
                      <span className="block text-[11px] text-slate-400">
                        {s.code}
                        {s.credits ? ` · ${n(s.credits)} credits` : ''}
                      </span>
                    </td>
                    <td className="text-center">
                      <MarkCell v={s.internal} max={s.max_internal} absent={s.absent} />
                    </td>
                    <td className="text-center">
                      <MarkCell v={s.external} max={s.max_external} absent={s.absent} />
                    </td>
                    <td className="text-center">
                      <MarkCell v={s.practical} max={s.max_practical} absent={s.absent} />
                    </td>
                    <td className="text-center">
                      <MarkCell v={s.total} max={s.max_total} absent={s.absent} />
                    </td>
                    <td className="text-center font-display font-extrabold text-brand-900 dark:text-white">{s.grade ?? '—'}</td>
                    <td className="text-center tabular-nums">{n(s.grade_point)}</td>
                    <td className="text-right">{resultTag(s)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {r.grade_scale.length > 0 && (
            <div className="rounded-2xl bg-slate-50 p-3 text-[11px] leading-relaxed text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
              <span className="font-semibold text-slate-700 dark:text-slate-200">Grading scale: </span>
              {r.grade_scale.map((g) => `${g.grade} ${n(g.min)}–${n(g.max)}% (${n(g.grade_point)})`).join(' · ')}
            </div>
          )}
        </div>
      )}
    </Drawer>
  );
}
