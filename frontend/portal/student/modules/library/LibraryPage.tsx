import { useState } from 'react';
import clsx from 'clsx';
import { AlarmClock, BookCopy, BookMarked, BookOpen, Coins, History, IdCard, Info, Library, RotateCcw } from 'lucide-react';
import { Stagger, Tabs } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, KpiCard, KpiSkeleton, PageHead, Section } from '../../../components/Ui';
import { useStudentSession } from '../../../lib/session';
import { ListSkeleton, StatusBadge, dueLabel, money, useStudent } from '../../shared/ui';
import type { Loan, StudentLibrary } from '../../shared/types';

function LoanCard({ l, finePerDay }: { l: Loan; finePerDay: number }) {
  const overdue = l.state === 'overdue';
  const soon = !overdue && l.days_left !== null && l.days_left <= 3;
  return (
    <article className={clsx('card flex h-full gap-3.5 p-4', overdue && 'border-red-200 dark:border-red-500/30')}>
      <div className={clsx('flex h-20 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg', overdue ? 'bg-red-50 text-red-500 dark:bg-red-500/10' : 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-200')}>
        {l.cover ? <img src={l.cover} alt="" loading="lazy" className="h-full w-full object-cover" /> : <BookOpen className="h-6 w-6" aria-hidden />}
      </div>
      <div className="min-w-0 flex-1">
        <p className="line-clamp-2 text-sm font-bold leading-snug text-slate-900 dark:text-white">{l.title}</p>
        {l.authors && <p className="truncate text-xs text-slate-500 dark:text-slate-400">{l.authors}</p>}
        <p className="mt-1 text-[11px] text-slate-400">
          {l.accession_no} · issued {formatDate(l.issue_date)}
          {l.renew_count ? ` · renewed ${l.renew_count}×` : ''}
        </p>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <span className={clsx('badge', overdue ? 'badge-red' : soon ? 'badge-amber' : 'badge-navy')}>
            <AlarmClock className="h-3 w-3" aria-hidden />
            {overdue ? dueLabel(l.days_left) : `Due ${formatDate(l.due_date)}`}
          </span>
          {!overdue && l.days_left !== null && <span className="text-[11px] font-medium text-slate-500">{dueLabel(l.days_left)}</span>}
          {l.accrued_fine > 0 && <span className="text-[11px] font-semibold text-red-600 dark:text-red-400">Fine so far {money(l.accrued_fine)}</span>}
        </div>
        {overdue && finePerDay > 0 && <p className="mt-1.5 text-[11px] text-slate-500">A fine of {money(finePerDay)} is added for every day late. Return it at the library counter.</p>}
      </div>
    </article>
  );
}

export default function StudentLibraryPage() {
  const s = useStudentSession().student!;
  const { data, isLoading, error, refetch } = useStudent<StudentLibrary>(['library'], 'library');
  const [tab, setTab] = useState<'history' | 'fines'>('history');
  const m = data?.member;

  return (
    <div>
      <PageHead area="student" title="Library" subtitle="Books with you, due dates, history and fines." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Library' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <KpiSkeleton key={i} />
            ))}
          </div>
          <ListSkeleton rows={3} h="h-28" />
        </div>
      ) : !m ? (
        <div className="card">
          <EmptyBlock icon={Library} title="You are not enrolled in the library yet" text="Visit the library counter with your college ID card to get your membership. Your loans and due dates will then show up here." />
        </div>
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <section className="hero-navy relative isolate overflow-hidden rounded-3xl p-5 text-white shadow-soft">
              <div className="pattern-dots absolute inset-0 -z-10 opacity-40" aria-hidden />
              <div className="flex items-center justify-between">
                <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-flame-300">Library card</p>
                <IdCard className="h-5 w-5 text-white/60" aria-hidden />
              </div>
              <p className="mt-5 font-mono text-2xl font-bold tracking-wider">{m.membership_no}</p>
              <p className="mt-1 font-display text-base font-bold">{s.name}</p>
              <p className="text-xs text-white/70">
                {s.student_uid} · {s.program_short}
              </p>
              <div className="mt-5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-white/80">
                <span>Up to {m.max_books} books</span>
                <span>{m.loan_days}-day loans</span>
                <span>Valid till {formatDate(m.valid_until)}</span>
              </div>
              {(m.status !== 'active' || m.expired) && <span className="badge badge-red mt-3">{m.expired ? 'Membership expired' : labelize(m.status)}</span>}
            </section>
            <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 lg:col-span-2" itemClassName="h-full">
              {[
                <KpiCard key="with" label="Books with you" icon={BookCopy} tone="navy" value={data.stats.issued} unit={`/ ${m.max_books}`} foot={data.stats.due_soon ? `${data.stats.due_soon} due in the next 3 days` : 'Nothing due in the next 3 days'} />,
                <KpiCard key="over" label="Overdue" icon={AlarmClock} tone={data.stats.overdue ? 'red' : 'green'} value={data.stats.overdue} foot={data.stats.overdue ? 'Return them to stop the fine' : 'All on time'} />,
                <KpiCard key="acc" label="Fine accruing" icon={Coins} tone={data.stats.accrued ? 'amber' : 'green'} value={money(data.stats.accrued)} foot={`${money(data.rules.fine_per_day)} per day late${data.rules.grace_days ? ` after ${data.rules.grace_days} grace days` : ''}`} />,
                <KpiCard key="unpaid" label="Unpaid fines" icon={Coins} tone={data.stats.unpaid_fines ? 'red' : 'green'} value={money(data.stats.unpaid_fines)} foot={`${data.stats.total} books borrowed in total`} />,
              ]}
            </Stagger>
          </div>

          <Section title="Books with you" subtitle={data.current.length ? `${data.current.length} on loan` : undefined} icon={BookMarked}>
            {data.current.length === 0 ? (
              <EmptyBlock compact icon={BookOpen} title="No books issued right now" text="Borrow books at the library counter with your college ID." />
            ) : (
              <>
                <Stagger className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" itemClassName="h-full">
                  {data.current.map((l) => (
                    <LoanCard key={l.id} l={l} finePerDay={data.rules.fine_per_day} />
                  ))}
                </Stagger>
                <p className="mt-4 flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
                  <RotateCcw className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                  Need more time? Renew a book at the library counter before its due date (up to {data.rules.max_renewals} renewals per book).
                </p>
              </>
            )}
          </Section>

          <div className="space-y-4">
            <Tabs
              variant="pills"
              value={tab}
              onChange={(k) => setTab(k as 'history' | 'fines')}
              tabs={[
                { key: 'history', label: 'Borrowing history', icon: History, count: data.history.length },
                { key: 'fines', label: 'Fines', icon: Coins, count: data.fines.length },
              ]}
            />
            <section className="card overflow-hidden">
              {tab === 'history' ? (
                data.history.length === 0 ? (
                  <EmptyBlock compact icon={History} title="No returned books yet" />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.history.map((l) => (
                      <li key={l.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{l.title}</p>
                          <p className="truncate text-xs text-slate-500">
                            {l.authors ?? l.category ?? ''} · {formatDate(l.issue_date)} → {l.return_date ? formatDate(l.return_date) : '—'}
                          </p>
                        </div>
                        {l.fine_amount > 0 && <span className="text-xs font-semibold text-red-600 dark:text-red-400">Fine {money(l.fine_amount)}</span>}
                        <StatusBadge status={l.state} label={l.state === 'returned_late' ? `Returned ${l.days_overdue}d late` : undefined} />
                      </li>
                    ))}
                  </ul>
                )
              ) : data.fines.length === 0 ? (
                <EmptyBlock compact icon={Coins} title="No library fines" text="Return books on time to keep it that way." />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.fines.map((f) => (
                    <li key={f.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{f.book ?? labelize(f.reason)}</p>
                        <p className="text-xs text-slate-500">
                          {labelize(f.reason)}
                          {f.days_overdue ? ` · ${f.days_overdue} days late` : ''} · {formatDate(f.date)}
                          {f.receipt_no ? ` · ${f.receipt_no}` : ''}
                          {f.waive_reason ? ` · waived: ${f.waive_reason}` : ''}
                        </p>
                      </div>
                      <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{money(f.amount)}</span>
                      <StatusBadge status={f.status} />
                    </li>
                  ))}
                </ul>
              )}
            </section>
            <p className="flex items-start gap-2 px-1 text-xs text-slate-500 dark:text-slate-400">
              <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> Fines are paid at the library counter. Lost or damaged books are charged as per library rules.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
