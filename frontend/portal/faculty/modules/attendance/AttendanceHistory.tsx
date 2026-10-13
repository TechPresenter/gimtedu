import { useState } from 'react';
import clsx from 'clsx';
import { ChevronLeft, ChevronRight, ClipboardList, Lock, PencilLine, Eye } from 'lucide-react';
import { Select } from '@/components/ui';
import { formatDate, timeAgo } from '@/lib/format';
import { EmptyBlock, ErrorState } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import { hhmm } from '../../../lib/time';
import type { AttendanceDayData, HistoryData, HistoryRow } from '../../shared/types';
import { ATT_META, ATT_ORDER, ListSkeleton, PercentPill } from '../../shared/ui';

interface Props {
  classes: AttendanceDayData['classes'];
  initialClass?: string;
  onOpen: (row: HistoryRow) => void;
}

function CountsBar({ r }: { r: HistoryRow }) {
  const total = r.counts.total || 1;
  return (
    <div className="flex h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden>
      {ATT_ORDER.map((s) => (
        <span key={s} className={ATT_META[s].dot} style={{ width: `${(r.counts[s] / total) * 100}%` }} />
      ))}
    </div>
  );
}

export function AttendanceHistory({ classes, initialClass = '', onOpen }: Props) {
  const [cls, setCls] = useState(initialClass);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [section_id, subject_id] = cls ? cls.split(':').map(Number) : [undefined, undefined];
  const { data, isLoading, isFetching, error, refetch } = usePortalQuery<HistoryData>('faculty', ['attendance', 'history'], 'attendance/history', { section_id, subject_id, from: from || undefined, to: to || undefined, page, per_page: 12 });

  const reset = (fn: () => void) => {
    fn();
    setPage(1);
  };

  return (
    <div className="space-y-4">
      <div className="card grid grid-cols-2 gap-3 p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:p-4">
        <label className="col-span-2 block sm:col-span-1">
          <span className="form-label">Class</span>
          <Select
            value={cls}
            onChange={(e) => reset(() => setCls(e.target.value))}
            placeholder="All my classes"
            options={classes.map((c) => ({ value: `${c.section_id}:${c.subject_id}`, label: `${c.subject_code} · ${c.label}` }))}
          />
        </label>
        <label className="block">
          <span className="form-label">From</span>
          <input type="date" className="form-input" value={from} max={to || undefined} onChange={(e) => reset(() => setFrom(e.target.value))} />
        </label>
        <label className="block">
          <span className="form-label">To</span>
          <input type="date" className="form-input" value={to} min={from || undefined} onChange={(e) => reset(() => setTo(e.target.value))} />
        </label>
      </div>

      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <ListSkeleton rows={6} className="h-[72px]" />
      ) : data.items.length === 0 ? (
        <div className="card">
          <EmptyBlock
            icon={ClipboardList}
            title="No registers found"
            text={cls || from || to ? 'No attendance register matches these filters.' : 'Registers you mark appear here.'}
            action={
              cls || from || to ? (
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => reset(() => { setCls(''); setFrom(''); setTo(''); })}>
                  Clear filters
                </button>
              ) : undefined
            }
          />
        </div>
      ) : (
        <div className={clsx('card overflow-hidden transition-opacity', isFetching && 'opacity-70')}>
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            <span>
              <strong className="text-slate-800 dark:text-slate-100">{data.total}</strong> register{data.total === 1 ? '' : 's'}
              {data.stats.percent !== null && (
                <>
                  {' '}
                  · average attendance <strong className="text-slate-800 dark:text-slate-100">{data.stats.percent}%</strong>
                </>
              )}
            </span>
            <span>Editable for {data.edit_days} days (since {formatDate(data.earliest)})</span>
          </div>
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {data.items.map((r) => (
              <li key={r.id} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3">
                <div className="flex h-12 w-12 flex-col items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                  <span className="font-display text-base font-extrabold leading-none">{r.date.slice(8)}</span>
                  <span className="text-[10px] font-semibold uppercase">{new Date(`${r.date}T00:00:00`).toLocaleDateString('en-GB', { month: 'short' })}</span>
                </div>
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                    {r.subject} <span className="font-normal text-slate-400">· {r.subject_code}</span>
                  </p>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                    {r.class} · {r.slot ?? 'Extra class'}
                    {r.start_time ? ` (${hhmm(r.start_time)})` : ''} · {r.taken_by ? `by ${r.taken_by}` : ''} {r.updated_at ? timeAgo(r.updated_at) : ''}
                  </p>
                  <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <div className="w-28 sm:w-40">
                      <CountsBar r={r} />
                    </div>
                    <span className="text-[11px] tabular-nums text-slate-500">
                      {r.counts.present + r.counts.late}/{r.counts.total} · A {r.counts.absent} · L {r.counts.late} · LV {r.counts.leave}
                    </span>
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1.5">
                  <PercentPill value={r.counts.percent} />
                  <button type="button" className="btn btn-ghost btn-xs" onClick={() => onOpen(r)}>
                    {r.is_locked ? <Lock className="h-3.5 w-3.5" aria-hidden /> : r.editable ? <PencilLine className="h-3.5 w-3.5" aria-hidden /> : <Eye className="h-3.5 w-3.5" aria-hidden />}
                    {r.editable ? 'Edit' : 'View'}
                  </button>
                </div>
              </li>
            ))}
          </ul>
          {data.pages > 1 && (
            <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs dark:border-slate-800">
              <span className="text-slate-500">
                Page {data.page} of {data.pages}
              </span>
              <div className="flex gap-2">
                <button type="button" className="btn btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  <ChevronLeft className="h-4 w-4" aria-hidden /> Newer
                </button>
                <button type="button" className="btn btn-secondary btn-sm" disabled={page >= data.pages} onClick={() => setPage((p) => p + 1)}>
                  Older <ChevronRight className="h-4 w-4" aria-hidden />
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
