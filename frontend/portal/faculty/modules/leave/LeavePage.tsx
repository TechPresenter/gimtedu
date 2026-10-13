import { useState } from 'react';
import clsx from 'clsx';
import { CalendarClock, CalendarHeart, CalendarPlus, CalendarRange, ChevronLeft, ChevronRight, Hourglass, Plane, PartyPopper, XCircle } from 'lucide-react';
import { Alert, Stagger, useConfirm, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDate, formatDateTime, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, Section } from '../../../components/Ui';
import { errorMessage } from '../../../lib/errors';
import { usePortalQuery } from '../../../lib/hooks';
import type { LeaveBalance, LeaveData, LeaveRow } from '../../shared/types';
import { Bar, ListSkeleton, MiniStat, PillTabs, fpath, relDay, useFacultyInvalidate } from '../../shared/ui';
import { LeaveApplyModal } from './LeaveApplyModal';
import { MyAttendanceCard } from './MyAttendanceCard';

type StatusFilter = '' | 'pending' | 'approved' | 'rejected' | 'cancelled';

const STATUS_BADGE: Record<LeaveRow['status'], string> = { pending: 'badge-amber', approved: 'badge-green', rejected: 'badge-red', cancelled: 'badge-slate' };
const TYPE_DOT: Record<string, string> = {
  blue: 'bg-blue-500', green: 'bg-emerald-500', amber: 'bg-amber-500', purple: 'bg-violet-500', pink: 'bg-pink-500', cyan: 'bg-cyan-500', red: 'bg-red-500', slate: 'bg-slate-400',
};
const days = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} day${n === 1 ? '' : 's'}`;

function BalanceCard({ b }: { b: LeaveBalance }) {
  const pct = b.unlimited || !b.quota ? 0 : Math.round(((b.available ?? 0) / b.quota) * 100);
  return (
    <div className="card h-full p-4">
      <div className="flex items-start justify-between gap-2">
        <p className="flex min-w-0 items-center gap-2 text-[13px] font-semibold text-slate-700 dark:text-slate-200">
          <span className={clsx('h-2.5 w-2.5 shrink-0 rounded-full', TYPE_DOT[b.color ?? 'slate'] ?? 'bg-slate-400')} aria-hidden />
          <span className="truncate">{b.name}</span>
        </p>
        {!b.is_paid && <span className="badge badge-slate shrink-0">LOP</span>}
      </div>
      {b.unlimited ? (
        <>
          <p className="mt-2 font-display text-2xl font-extrabold text-slate-900 dark:text-white">
            {b.used} <span className="text-sm font-semibold text-slate-400">used</span>
          </p>
          <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">No fixed quota{b.pending ? ` · ${b.pending} pending` : ''}</p>
        </>
      ) : (
        <>
          <p className="mt-2 font-display text-2xl font-extrabold text-slate-900 dark:text-white">
            {b.available} <span className="text-sm font-semibold text-slate-400">/ {b.quota} left</span>
          </p>
          <Bar className="mt-2" value={pct} tone={pct <= 20 ? 'red' : pct <= 50 ? 'amber' : 'violet'} />
          <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
            {b.used} used{b.pending ? ` · ${b.pending} pending` : ''}
          </p>
        </>
      )}
    </div>
  );
}

function LeaveItem({ l, onCancel, busy }: { l: LeaveRow; onCancel: (l: LeaveRow) => void; busy: boolean }) {
  return (
    <li className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-start sm:px-5">
      <div className="flex min-w-0 flex-1 gap-3">
        <span className={clsx('mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full', TYPE_DOT[l.color ?? 'slate'] ?? 'bg-slate-400')} aria-hidden />
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm font-bold text-slate-900 dark:text-white">{l.leave_type_name}</p>
            <span className={clsx('badge', STATUS_BADGE[l.status])}>{labelize(l.status)}</span>
            {l.phase === 'current' && l.status === 'approved' && <span className="badge badge-flame">On leave now</span>}
            {!l.is_paid && <span className="badge badge-slate">Loss of pay</span>}
          </div>
          <p className="mt-0.5 text-[13px] font-medium text-slate-700 dark:text-slate-200">
            {formatDate(l.from_date)}
            {l.to_date !== l.from_date ? ` – ${formatDate(l.to_date)}` : ''} · {l.is_half_day ? 'Half day' : days(l.days)}
          </p>
          {l.reason && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">“{l.reason}”</p>}
          {l.remarks && (
            <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
              <span className="font-semibold">{l.status === 'rejected' ? 'Reason:' : 'Remarks:'}</span> {l.remarks}
            </p>
          )}
          <p className="mt-1 text-[11px] text-slate-400">
            Applied {formatDateTime(l.applied_at)}
            {l.approved_by && l.approved_at ? ` · ${l.status === 'rejected' ? 'Rejected' : 'Approved'} by ${l.approved_by}, ${formatDate(l.approved_at)}` : ''}
            {l.cancelled_at ? ` · Cancelled ${formatDate(l.cancelled_at)}` : ''}
          </p>
        </div>
      </div>
      {l.can_cancel && (
        <button type="button" className="btn btn-secondary btn-sm shrink-0 self-start !text-red-600 dark:!text-red-400" onClick={() => onCancel(l)} disabled={busy}>
          <XCircle className="h-4 w-4" aria-hidden /> Cancel
        </button>
      )}
    </li>
  );
}

export default function LeavePage() {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useFacultyInvalidate();
  const [status, setStatus] = useState<StatusFilter>('');
  const [page, setPage] = useState(1);
  const [applyOpen, setApplyOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const { data, isLoading, isFetching, error, refetch } = usePortalQuery<LeaveData>('faculty', ['leave', 'overview'], 'leave', { status: status || undefined, page });

  const cancel = async (l: LeaveRow) => {
    const ok = await confirm({
      title: 'Cancel this leave application?',
      message: `${l.leave_type_name}: ${formatDate(l.from_date)}${l.to_date !== l.from_date ? ` – ${formatDate(l.to_date)}` : ''}.${l.status === 'approved' ? ' The approvers will be informed that you cancelled an approved leave.' : ''}`,
      confirmText: 'Cancel leave',
      cancelText: 'Keep it',
      danger: true,
    });
    if (!ok) return;
    setBusy(true);
    try {
      const res = await api.post(`portal/faculty/leave/${l.id}/cancel`);
      toast.success(res.message || 'Leave cancelled.');
      await invalidate('leave', 'dashboard');
    } catch (e) {
      toast.error(errorMessage(e, 'Unable to cancel the application.'));
    } finally {
      setBusy(false);
    }
  };

  const limited = data?.balance.filter((b) => !b.unlimited && b.is_paid && !b.special) ?? [];
  const available = limited.reduce((n, b) => n + (b.available ?? 0), 0);

  return (
    <div>
      <PageHead
        area="faculty"
        title="Leave"
        subtitle={data ? `Leave balance and applications for session ${data.session.name}.` : 'Apply for leave and track your applications.'}
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'Leave' }]}
        actions={
          <button type="button" className="btn btn-primary" onClick={() => setApplyOpen(true)} disabled={!data}>
            <CalendarPlus className="h-4 w-4" aria-hidden /> Apply for leave
          </button>
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-28 rounded-2xl" />
            ))}
          </div>
          <ListSkeleton rows={3} className="h-24" />
        </div>
      ) : (
        <div className="space-y-5">
          {data.on_leave_today && (
            <Alert variant="info" title="You are on leave today">
              {data.on_leave_today.leave_type_name} until {formatDate(data.on_leave_today.to_date)}.
            </Alert>
          )}

          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="a" label="Paid leave available" value={days(available)} icon={Plane} tone="violet" sub={limited.map((b) => `${b.name.replace(/ Leave.*$/, '')} ${b.available}`).slice(0, 3).join(' · ')} />,
              <MiniStat key="t" label="Taken this session" value={days(data.taken)} icon={CalendarRange} tone="navy" sub={`${formatDate(data.session.start)} – ${formatDate(data.session.end)}`} />,
              <MiniStat key="p" label="Pending approval" value={data.counts.pending} icon={Hourglass} tone={data.counts.pending ? 'amber' : 'green'} sub={data.counts.pending ? 'awaiting HR decision' : 'nothing pending'} />,
              <MiniStat key="u" label="Upcoming" value={data.upcoming.length} icon={CalendarClock} tone="flame" sub={data.upcoming[0] ? `Next: ${formatDate(data.upcoming[0].from_date)} (${relDay(data.upcoming[0].from_date)})` : 'No planned leave'} />,
            ]}
          </Stagger>

          <div>
            <h2 className="section-heading mb-3">Balance by leave type</h2>
            <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4" itemClassName="h-full">
              {data.balance.map((b) => (
                <BalanceCard key={b.code} b={b} />
              ))}
            </Stagger>
          </div>

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-5">
            <Section
              title="My applications"
              subtitle={`${data.total} application${data.total === 1 ? '' : 's'}`}
              icon={CalendarHeart}
              className="xl:col-span-3"
              bodyClassName="p-0"
            >
              <div className="border-b border-slate-100 p-3 dark:border-slate-800">
                <PillTabs
                  label="Filter by status"
                  value={status}
                  onChange={(v) => {
                    setStatus(v);
                    setPage(1);
                  }}
                  tabs={[
                    { value: '', label: 'All' },
                    { value: 'pending', label: 'Pending', count: data.counts.pending },
                    { value: 'approved', label: 'Approved', count: data.counts.approved },
                    { value: 'rejected', label: 'Rejected', count: data.counts.rejected },
                    { value: 'cancelled', label: 'Cancelled', count: data.counts.cancelled },
                  ]}
                />
              </div>
              {data.items.length === 0 ? (
                <EmptyBlock
                  icon={Plane}
                  title={status ? `No ${status} applications` : 'No leave applications yet'}
                  text={status ? 'Try another status.' : 'Apply for casual, sick, earned or academic leave here — approvals are tracked in real time.'}
                  action={
                    !status ? (
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => setApplyOpen(true)}>
                        <CalendarPlus className="h-4 w-4" aria-hidden /> Apply for leave
                      </button>
                    ) : undefined
                  }
                />
              ) : (
                <ul className={clsx('divide-y divide-slate-100 transition-opacity dark:divide-slate-800', isFetching && 'opacity-70')}>
                  {data.items.map((l) => (
                    <LeaveItem key={l.id} l={l} onCancel={(x) => void cancel(x)} busy={busy} />
                  ))}
                </ul>
              )}
              {data.pages > 1 && (
                <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs dark:border-slate-800">
                  <span className="text-slate-500">
                    Page {data.page} of {data.pages}
                  </span>
                  <div className="flex gap-2">
                    <button type="button" className="btn btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} aria-label="Previous page">
                      <ChevronLeft className="h-4 w-4" aria-hidden />
                    </button>
                    <button type="button" className="btn btn-secondary btn-sm" disabled={page >= data.pages} onClick={() => setPage((p) => p + 1)} aria-label="Next page">
                      <ChevronRight className="h-4 w-4" aria-hidden />
                    </button>
                  </div>
                </div>
              )}
            </Section>

            <div className="space-y-5 xl:col-span-2">
              <MyAttendanceCard />
              <Section title="Upcoming holidays" icon={PartyPopper} bodyClassName="p-2 sm:p-3">
                {data.holidays.length === 0 ? (
                  <EmptyBlock compact icon={PartyPopper} title="No holidays announced" />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.holidays.map((h) => (
                      <li key={`${h.holiday_date}-${h.title}`} className="flex items-center justify-between gap-3 px-2 py-2.5">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{h.title}</p>
                          <p className="text-xs text-slate-500 dark:text-slate-400">
                            {formatDate(h.holiday_date)}
                            {h.end_date && h.end_date !== h.holiday_date ? ` – ${formatDate(h.end_date)}` : ''}
                          </p>
                        </div>
                        <span className={clsx('badge shrink-0', h.type === 'vacation' ? 'badge-purple' : 'badge-navy')}>{labelize(h.type)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Section>
            </div>
          </div>
        </div>
      )}
      {data && <LeaveApplyModal open={applyOpen} onClose={() => setApplyOpen(false)} data={data} />}
    </div>
  );
}
