import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlarmClock, ArrowLeftRight, ArrowRight, BellRing, BookCheck, BookMarked, BookOpen, IndianRupee, Library, Printer, RotateCcw, Settings2, TrendingUp, Users,
} from 'lucide-react';
import {
  Alert, Avatar, Button, Card, CardBody, CardHeader, CardSkeleton, CountUp, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, StatCard, Stagger, Timeline, useConfirm, useToast,
} from '@/components/ui';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, timeAgo } from '@/lib/format';
import { RulesDialog, useLoanActions } from './library/dialogs';
import { QuickDesk } from './library/QuickDesk';
import { BookCover, MemberTypeBadge, openPrint, useLibraryRefresh } from './library/shared';
import type { DashboardPayload } from './library/types';

export default function LibraryDashboardPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useLibraryRefresh();
  const q = useApi<DashboardPayload>(['library', 'dashboard'], 'library/dashboard');
  const [rulesOpen, setRulesOpen] = useState(false);
  const [reminding, setReminding] = useState(false);
  const loanActions = useLoanActions();
  const d = q.data;
  const k = d?.kpis;

  const remindAll = async () => {
    const ok = await confirm({ title: 'Send overdue reminders?', message: `Email, SMS and in-app reminders go to every member with an overdue book (${k?.overdue ?? 0} loans).`, confirmText: 'Send reminders' });
    if (!ok) return;
    setReminding(true);
    try {
      const res = await api.post('library/remind', { all_overdue: true });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setReminding(false);
    }
  };

  return (
    <>
      <PageHeader
        title="Library"
        description={`Central library at a glance — catalogue, circulation and fines${d?.session ? ` · Session ${d.session}` : ''}.`}
        breadcrumbs={[{ label: 'Library' }]}
        actions={
          <>
            <Button variant="secondary" icon={Settings2} onClick={() => setRulesOpen(true)}>
              Rules
            </Button>
            <Button variant="secondary" icon={Printer} onClick={() => openPrint('overdue')}>
              Overdue report
            </Button>
            <Button icon={ArrowLeftRight} to="/library/circulation">
              Issue / Return
            </Button>
          </>
        }
      />

      {q.error && (
        <Alert variant="error" title="Unable to load the library dashboard" className="mb-5" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
          {(q.error as ApiError).message}
        </Alert>
      )}

      <Stagger className="mb-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 xl:grid-cols-6" step={60}>
        {[
          <StatCard key="t" label="Total titles" value={k?.titles ?? 0} icon={BookMarked} tone="navy" loading={q.isLoading} hint={`${formatNumber(k?.members ?? 0)} active members`} to="/library/books" />,
          <StatCard key="c" label="Total copies" value={k?.copies ?? 0} icon={Library} tone="blue" loading={q.isLoading} hint={`${formatNumber(k?.damaged_lost ?? 0)} damaged / lost`} to="/library/books?tab=copies" />,
          <StatCard key="i" label="Issued" value={k?.issued ?? 0} icon={BookOpen} tone="purple" loading={q.isLoading} hint={`${k?.issued_today ?? 0} today · ${k?.active_borrowers ?? 0} borrowers`} to="/library/circulation?tab=history&f.state=issued" />,
          <StatCard key="a" label="Available" value={k?.available ?? 0} icon={BookCheck} tone="green" loading={q.isLoading} hint={`${k?.utilisation ?? 0}% of stock on loan`} to="/library/books?tab=copies&f.status=available" />,
          <StatCard key="o" label="Overdue" value={k?.overdue ?? 0} icon={AlarmClock} tone="red" loading={q.isLoading} hint={`${formatMoney(k?.accrued_fine ?? 0)} fine accruing`} to="/library/circulation?tab=history&f.state=overdue" />,
          <StatCard
            key="f"
            label="Fines collected"
            value={<CountUp value={k?.fine_collected_session ?? 0} format={(n) => formatMoney(n)} />}
            icon={IndianRupee}
            tone="amber"
            loading={q.isLoading}
            hint={`This session · ${formatMoney(k?.fine_pending ?? 0)} unpaid`}
            to="/library/fines"
          />,
        ]}
      </Stagger>

      <div className="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Issues per month" subtitle="Books issued and returned — last 8 months" icon={TrendingUp} />
            <CardBody className="pt-0">
              {d ? (
                d.monthly.some((m) => m.issues || m.returns) ? (
                  <BarChart
                    height={280}
                    labels={d.monthly.map((m) => m.label)}
                    series={[
                      { label: 'Issued', data: d.monthly.map((m) => m.issues), color: chartColor(0) },
                      { label: 'Returned', data: d.monthly.map((m) => m.returns), color: chartColor(1) },
                    ]}
                  />
                ) : (
                  <EmptyState icon={BookOpen} title="No circulation yet" description="Issue the first book to start the trend." action={<Button to="/library/circulation" icon={ArrowLeftRight}>Open issue desk</Button>} />
                )
              ) : (
                <Skeleton className="h-[280px] w-full" />
              )}
            </CardBody>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <QuickDesk />
        </Reveal>
      </div>

      <div className="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full overflow-hidden">
            <CardHeader
              title="Overdue books"
              subtitle={k ? `${k.overdue} loan${k.overdue === 1 ? '' : 's'} past due · fine ${formatMoney(d?.rules.fine_per_day ?? 0)}/day` : 'Loading…'}
              icon={AlarmClock}
              className="flex-wrap gap-y-3"
              actions={
                <div className="flex gap-2">
                  {loanActions.canEdit && (k?.overdue ?? 0) > 0 && (
                    <Button size="sm" variant="secondary" icon={BellRing} loading={reminding} onClick={remindAll}>
                      Remind all
                    </Button>
                  )}
                  <Button size="sm" variant="ghost" iconRight={ArrowRight} to="/library/circulation?tab=history&f.state=overdue">
                    View all
                  </Button>
                </div>
              }
            />
            {!d ? (
              <CardBody className="space-y-3 pt-0">
                {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}
              </CardBody>
            ) : d.overdue.length === 0 ? (
              <EmptyState icon={BookCheck} title="Nothing overdue" description="Every book on loan is within its due date." />
            ) : (
              <ul className="divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
                {d.overdue.map((l) => (
                  <li key={l.id} className="flex flex-col gap-2 px-4 py-3 transition hover:bg-slate-50/60 sm:flex-row sm:items-center sm:gap-4 dark:hover:bg-slate-800/30">
                    <div className="flex min-w-0 items-center gap-2.5 sm:w-52 sm:shrink-0">
                      <Avatar name={l.member.name} src={l.member.photo} size="sm" />
                      <div className="min-w-0 leading-tight">
                        <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{l.member.name}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{l.member.membership_no} · {l.member.phone ?? l.member.type}</p>
                      </div>
                    </div>
                    <div className="min-w-0 flex-1">
                      <Link to={`/library/books/${l.book_id}`} className="line-clamp-1 text-sm font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">
                        {l.title}
                      </Link>
                      <p className="text-xs text-slate-500 dark:text-slate-400">
                        <span className="font-mono">{l.accession_no}</span> · due {formatDate(l.due_date)} ·{' '}
                        <span className="font-semibold text-red-600 dark:text-red-400">{l.days_overdue} days late</span> · {formatMoney(l.accrued_fine)}
                      </p>
                    </div>
                    {loanActions.canEdit && (
                      <div className="flex shrink-0 gap-1.5">
                        <Button size="xs" variant="secondary" icon={BellRing} disabled={loanActions.busy} onClick={() => loanActions.remind([l.id])} title={l.reminder_count ? `${l.reminder_count} reminder(s) sent so far` : 'Send reminder'}>
                          Remind{l.reminder_count ? ` · ${l.reminder_count}` : ''}
                        </Button>
                        <Button size="xs" icon={RotateCcw} onClick={() => loanActions.openReturn(l)}>
                          Return
                        </Button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Collection by category" subtitle={d ? `${formatNumber(k?.copies ?? 0)} copies in ${d.categories.length} groups` : 'Loading…'} icon={Library} />
            <CardBody className="pt-0">
              {!d ? (
                <Skeleton className="h-48 w-full" />
              ) : d.categories.length ? (
                <div className="space-y-4">
                  <DoughnutChart
                    height={170}
                    legendList={false}
                    labels={d.categories.map((c) => c.name)}
                    data={d.categories.map((c) => c.copies)}
                    centerValue={formatNumber(d.categories.reduce((a, c) => a + c.copies, 0))}
                    centerLabel="copies"
                  />
                  <ul className="space-y-2">
                    {d.categories.map((c, i) => {
                      const total = d.categories.reduce((a, x) => a + x.copies, 0) || 1;
                      return (
                        <li key={c.name} className="flex items-center justify-between gap-3 text-sm">
                          <span className="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300">
                            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: chartColor(i) }} />
                            {c.id ? (
                              <Link to={`/library/books?f.category_id=${c.id}`} className="truncate hover:text-brand-700 dark:hover:text-brand-300">{c.name}</Link>
                            ) : (
                              <span className="truncate">{c.name}</span>
                            )}
                          </span>
                          <span className="shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400">
                            <strong className="text-sm font-semibold text-slate-900 dark:text-white">{formatNumber(c.copies)}</strong> · {Math.round((c.copies / total) * 100)}%
                          </span>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ) : (
                <EmptyState icon={BookMarked} title="No books yet" description="Add titles to see the category mix." action={<Button to="/library/books" icon={BookMarked}>Add books</Button>} />
              )}
            </CardBody>
          </Card>
        </Reveal>
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Most borrowed books" subtitle="Issues in the last 12 months" icon={TrendingUp} actions={<Button size="sm" variant="ghost" iconRight={ArrowRight} to="/library/books">Catalogue</Button>} />
            <CardBody className="pt-0">
              {!d ? (
                <div className="space-y-3">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
              ) : d.popular.length === 0 ? (
                <EmptyState icon={BookOpen} title="No loans in the last year" description="Popular titles appear here once books are issued." />
              ) : (
                <ol className="grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
                  {d.popular.map((b, i) => (
                    <li key={b.id} className="flex items-center gap-3">
                      <span className="w-5 shrink-0 text-center font-display text-sm font-bold text-slate-400">{i + 1}</span>
                      <BookCover title={b.title} src={b.cover_image} size="sm" />
                      <div className="min-w-0 flex-1">
                        <Link to={`/library/books/${b.id}`} className="line-clamp-1 text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                          {b.title}
                        </Link>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{b.authors ?? b.category}</p>
                        <div className="mt-1 flex items-center gap-2">
                          <ProgressBar value={(b.issues / (d.popular[0]?.issues || 1)) * 100} tone={i < 3 ? 'green' : 'blue'} className="h-1.5" label={`${b.issues} issues`} />
                          <span className="shrink-0 text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">{b.issues}</span>
                        </div>
                      </div>
                      <span className="hidden shrink-0 text-right text-[11px] leading-tight text-slate-500 sm:block">
                        <strong className="block text-slate-700 dark:text-slate-200">{b.available_copies}/{b.total_copies}</strong>
                        on shelf
                      </span>
                    </li>
                  ))}
                </ol>
              )}
            </CardBody>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          {!d ? (
            <CardSkeleton lines={6} />
          ) : (
            <Card className="h-full">
              <CardHeader title="Recent circulation" subtitle={`${k?.issued_today ?? 0} issued · ${k?.returned_today ?? 0} returned today`} icon={ArrowLeftRight} />
              <CardBody className="pt-0">
                <Timeline
                  empty="No circulation activity yet."
                  items={d.recent.slice(0, 6).map((r) => ({
                    icon: r.status === 'returned' ? RotateCcw : r.status === 'lost' ? AlarmClock : BookOpen,
                    tone: r.status === 'returned' ? 'green' : r.status === 'lost' ? 'red' : 'blue',
                    title: (
                      <>
                        <strong className="font-semibold">{r.member_name}</strong> {r.status === 'returned' ? 'returned' : r.status === 'lost' ? 'lost' : 'borrowed'}{' '}
                        <span className="text-slate-600 dark:text-slate-300">{r.title.length > 48 ? `${r.title.slice(0, 48)}…` : r.title}</span>
                      </>
                    ),
                    description: (
                      <span className="inline-flex items-center gap-1.5">
                        <MemberTypeBadge type={r.member_type} /> <span className="font-mono">{r.accession_no}</span>
                      </span>
                    ),
                    time: timeAgo(r.at),
                  }))}
                />
              </CardBody>
            </Card>
          )}
        </Reveal>
      </div>

      {d && d.member_mix.length > 0 && (
        <Reveal className="mt-5">
          <Card>
            <CardBody className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex items-center gap-3">
                <span className="kpi-icon bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200">
                  <Users className="h-5 w-5" />
                </span>
                <div>
                  <p className="text-sm font-semibold text-slate-900 dark:text-white">Who borrows</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">Loans by member type, last 12 months</p>
                </div>
              </div>
              <div className="flex flex-wrap gap-6">
                {d.member_mix.map((m) => (
                  <div key={m.type} className="text-center">
                    <p className="font-display text-xl font-bold text-slate-900 dark:text-white">
                      <CountUp value={m.loans} />
                    </p>
                    <MemberTypeBadge type={m.type} />
                  </div>
                ))}
                <div className="text-center">
                  <p className="font-display text-xl font-bold text-slate-900 dark:text-white">{formatMoneyShort(k?.fine_collected ?? 0)}</p>
                  <span className="text-xs text-slate-500">fines collected (all time)</span>
                </div>
              </div>
              {can('library', 'view') && (
                <Button variant="secondary" size="sm" to="/library/members" iconRight={ArrowRight}>
                  Members
                </Button>
              )}
            </CardBody>
          </Card>
        </Reveal>
      )}

      <RulesDialog open={rulesOpen} onClose={() => setRulesOpen(false)} />
      {loanActions.dialogs}
    </>
  );
}
