import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRight, Award, BadgeCheck, BarChart3, BriefcaseBusiness, Building2, CalendarClock, GraduationCap, IndianRupee, PieChart, Plus, Presentation, Printer, RefreshCw,
  TrendingUp, Trophy, Users,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, EmptyState, IconButton, PageHeader, ProgressBar, Reveal, Select, Skeleton, Stagger, StatCard, StatusBadge,
} from '@/components/ui';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatNumber } from '@/lib/format';
import type { PlacementDashboard } from './placement/types';
import { APP_STATUS, CompanyLogo, DRIVE_STATUS, DRIVE_STATUS_COLORS, DateTile, JOB_TYPES, OFFER_STATUS, TIE_UP, TIE_UP_COLORS, TRAINING_TYPES, ctcRange, lpa, relativeDays } from './placement/shared';

const OFFER_COLORS: Record<string, string> = { accepted: '#22943F', joined: '#0B2A5B', offered: '#F59E0B', declined: '#94A3B8', revoked: '#EF4444' };
const PIPELINE = ['applied', 'shortlisted', 'aptitude', 'technical', 'hr', 'on_hold', 'selected', 'rejected'];

export default function PlacementDashboardPage() {
  const { can } = useAuth();
  const { id: currentId, all: sessions } = useAcademicSession();
  const [params, setParams] = useSearchParams();
  const sessionId = Number(params.get('session') ?? 0) || currentId || 0;
  const { data, isLoading, error, refetch, isFetching } = useApi<PlacementDashboard>(['placement', 'dashboard', sessionId], 'placement/dashboard', { session_id: sessionId || undefined });
  const k = data?.kpis;
  const sessionName = data?.session.name ?? sessions.find((s) => s.id === sessionId)?.name ?? '';

  const offerStatus = useMemo(() => {
    const entries = Object.entries(data?.offer_status ?? {}).filter(([, n]) => n > 0);
    const order = ['accepted', 'joined', 'offered', 'declined', 'revoked'];
    entries.sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0]));
    return entries;
  }, [data]);
  const pipeline = useMemo(() => PIPELINE.map((s) => ({ key: s, n: data?.application_status?.[s] ?? 0 })), [data]);
  const totalApps = pipeline.reduce((a, b) => a + b.n, 0);

  const setSession = (v: string) => {
    const next = new URLSearchParams(params);
    if (v && Number(v) !== currentId) next.set('session', v);
    else next.delete('session');
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="Placement"
        description={sessionName ? `Placement season ${sessionName} — drives, offers and outcomes at a glance.` : 'Drives, offers and outcomes at a glance.'}
        breadcrumbs={[{ label: 'Placement' }]}
        actions={
          <>
            <Select
              inputSize="sm"
              aria-label="Academic session"
              value={String(sessionId || '')}
              onChange={(e) => setSession(e.target.value)}
              options={sessions.map((s) => ({ value: s.id, label: `${s.name}${s.is_current ? ' (current)' : ''}` }))}
              className="!w-44"
            />
            <IconButton icon={RefreshCw} label="Refresh" onClick={() => refetch()} className={clsx(isFetching && '[&_svg]:animate-spin')} />
            <Button variant="secondary" icon={Printer} onClick={() => window.open(printUrl('placement-report.php', { session_id: sessionId || undefined }), '_blank', 'noopener')}>
              Placement report
            </Button>
            {can('placement', 'create') && (
              <Button icon={Plus} to="/placement/drives?add=1">
                New drive
              </Button>
            )}
          </>
        }
      />

      {error ? (
        <Alert variant="error" title="Unable to load the placement dashboard" className="mb-5" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as Error).message}
        </Alert>
      ) : null}

      <Stagger className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 xl:grid-cols-6" step={60}>
        {[
          <StatCard key="c" label="Total companies" value={k?.companies ?? 0} icon={Building2} tone="navy" loading={isLoading} to="/placement/companies"
            hint={k ? `${k.companies_visited} visited · ${k.mou} MoUs` : undefined} />,
          <StatCard key="d" label="Placement drives" value={k?.drives ?? 0} icon={BriefcaseBusiness} tone="blue" loading={isLoading} to="/placement/drives"
            hint={k ? `${k.drives_completed} done · ${k.drives_upcoming} ahead` : undefined} />,
          <StatCard key="e" label="Eligible students" value={k?.eligible ?? 0} icon={Users} tone="purple" loading={isLoading} to="/placement/applications"
            hint="Across session drives" />,
          <StatCard key="p" label="Placed students" value={k?.placed ?? 0} icon={BadgeCheck} tone="green" loading={isLoading} to="/placement/offers"
            hint={k ? `${formatNumber(k.placement_percent, 1)}% · ${k.confirmed} confirmed` : undefined} />,
          <StatCard key="h" label="Highest package" value={k ? lpa(k.highest) : '—'} icon={Trophy} tone="amber" loading={isLoading}
            hint={k?.highest_company ? `${k.highest_company}` : 'No offers yet'} />,
          <StatCard key="a" label="Average package" value={k ? lpa(k.average) : '—'} icon={IndianRupee} tone="cyan" loading={isLoading}
            hint={k?.median ? `Median ${lpa(k.median)}` : undefined} />,
        ]}
      </Stagger>

      {/* Placement progress strip */}
      {k && k.eligible > 0 && (
        <Reveal className="mb-5">
          <Card className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
            <div className="flex items-center gap-3">
              <span className="kpi-icon bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                <TrendingUp className="h-5 w-5" />
              </span>
              <div>
                <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Placement progress · {sessionName}</p>
                <p className="font-display text-2xl font-bold text-slate-900 dark:text-white">{formatNumber(k.placement_percent, 1)}%</p>
              </div>
            </div>
            <div className="min-w-0 flex-1">
              <div className="relative h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={Math.round(k.placement_percent)} aria-valuemin={0} aria-valuemax={100} aria-label="Students placed">
                <div className="absolute inset-y-0 left-0 rounded-full bg-amber-400" style={{ width: `${Math.min(100, (k.placed / k.eligible) * 100)}%` }} />
                <div className="absolute inset-y-0 left-0 rounded-full bg-accent-600" style={{ width: `${Math.min(100, (k.confirmed / k.eligible) * 100)}%` }} />
              </div>
              <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-accent-600" />{k.confirmed} confirmed (accepted / joined)</span>
                <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-amber-400" />{k.placed - k.confirmed} awaiting acceptance</span>
                <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-slate-300 dark:bg-slate-600" />{Math.max(0, k.eligible - k.placed)} yet to be placed</span>
              </div>
            </div>
            {k.highest_student && (
              <div className="rounded-xl bg-amber-50 px-4 py-2.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                <p className="flex items-center gap-1 font-semibold"><Award className="h-3.5 w-3.5" /> Top offer</p>
                <p>{k.highest_student} · {k.highest_company} · {lpa(k.highest)}</p>
              </div>
            )}
          </Card>
        </Reveal>
      )}

      {/* Department % + package bands */}
      <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-3">
          <Card className="h-full">
            <CardHeader title="Department-wise placement" subtitle="Placed students as % of eligible students" icon={BarChart3} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[260px] w-full" />
              ) : !data.departments.length ? (
                <EmptyState icon={BarChart3} title="No eligible students yet" description="Create drives with eligibility criteria to start tracking placement percentage by department." />
              ) : (
                <>
                  <BarChart
                    labels={data.departments.map((d) => d.label)}
                    series={[{ label: 'Placed %', data: data.departments.map((d) => d.percent), color: chartColor(1) }]}
                    percent
                    horizontal
                    height={Math.max(160, data.departments.length * 44)}
                  />
                  <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[30rem] text-sm">
                      <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                          <th className="py-2 pr-3 font-medium">Department</th>
                          <th className="px-2 py-2 text-right font-medium">Eligible</th>
                          <th className="px-2 py-2 text-right font-medium">Placed</th>
                          <th className="px-2 py-2 text-right font-medium">Avg CTC</th>
                          <th className="py-2 pl-2 text-right font-medium">Highest</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {data.departments.map((d) => (
                          <tr key={d.id}>
                            <td className="py-2 pr-3 text-slate-700 dark:text-slate-200">{d.name}</td>
                            <td className="px-2 py-2 text-right tabular-nums">{d.eligible}</td>
                            <td className="px-2 py-2 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{d.placed}</td>
                            <td className="px-2 py-2 text-right tabular-nums">{lpa(d.average)}</td>
                            <td className="py-2 pl-2 text-right tabular-nums">{lpa(d.highest)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Package distribution" subtitle={k ? `${k.offers} offers · average ${lpa(k.average)}` : 'Offers by CTC band'} icon={IndianRupee} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[300px] w-full" />
              ) : !data.distribution.some((b) => b.count) ? (
                <EmptyState icon={IndianRupee} title="No offers this session" description="Package statistics appear once offers are recorded." />
              ) : (
                <BarChart labels={data.distribution.map((b) => b.label)} series={[{ label: 'Offers', data: data.distribution.map((b) => b.count), color: chartColor(0) }]} height={300} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      {/* Company-wise + month-wise */}
      <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-3">
          <Card className="h-full">
            <CardHeader title="Company-wise hiring" subtitle="Top recruiters by offers this session" icon={Building2}
              actions={<Link to="/placement/companies?f.hiring=this_session" className="link text-xs">All companies</Link>} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[300px] w-full" />
              ) : !data.companies.length ? (
                <EmptyState icon={Building2} title="No hires recorded yet" description="Company-wise hiring appears as offers are recorded." />
              ) : (
                <ol className="space-y-3">
                  {data.companies.map((c, i) => {
                    const max = Math.max(...data.companies.map((x) => x.offers), 1);
                    return (
                      <li key={c.id} className="grid grid-cols-[auto_1fr_auto] items-center gap-3">
                        <CompanyLogo name={c.label} src={c.logo} size="sm" wide />
                        <div className="min-w-0">
                          <div className="mb-1 flex items-baseline justify-between gap-2 text-sm">
                            <Link to={`/placement/companies?company=${c.id}`} className="truncate font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">{c.label}</Link>
                            <span className="shrink-0 text-xs text-slate-500 dark:text-slate-400">avg {lpa(c.average)}</span>
                          </div>
                          <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label={`${c.label}: ${c.offers} offers`}>
                            <div className="h-full rounded-full transition-all duration-500" style={{ width: `${(c.offers / max) * 100}%`, background: i === 0 ? '#22943F' : chartColor(0) }} />
                          </div>
                        </div>
                        <span className="w-8 text-right font-display text-base font-bold tabular-nums text-slate-900 dark:text-white">{c.offers}</span>
                      </li>
                    );
                  })}
                </ol>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Month-wise offers" subtitle={`Offers recorded across ${sessionName || 'the session'}`} icon={CalendarClock} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[300px] w-full" />
              ) : (
                <BarChart
                  labels={data.months.map((m) => m.label.slice(0, 3))}
                  series={[{ label: 'Offers', data: data.months.map((m) => m.offers), color: chartColor(1) }]}
                  height={300}
                />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      {/* Upcoming drives + top recruiters */}
      <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-3">
          <Card className="h-full">
            <CardHeader title="Upcoming drives" subtitle="Ongoing, open and upcoming recruitment drives" icon={BriefcaseBusiness}
              actions={<Link to="/placement/drives?tab=active" className="link inline-flex items-center gap-1 text-xs">All drives <ArrowRight className="h-3.5 w-3.5" /></Link>} />
            {isLoading || !data ? (
              <div className="space-y-3 p-5">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-14 w-full" />)}</div>
            ) : !data.upcoming.length ? (
              <EmptyState icon={BriefcaseBusiness} title="No upcoming drives" description="Schedule the next campus drive and open registrations for eligible students."
                action={can('placement', 'create') ? <Button icon={Plus} to="/placement/drives?add=1">New drive</Button> : undefined} />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.upcoming.map((d) => (
                  <li key={d.id}>
                    <Link to={`/placement/drives/${d.id}`} className="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-2 px-5 py-3 transition hover:bg-slate-50 sm:grid-cols-[auto_1fr_11.5rem] dark:hover:bg-slate-800/50">
                      <DateTile date={d.drive_date} tone={d.status === 'ongoing' ? 'green' : 'brand'} />
                      <div className="min-w-0">
                        <div className="flex min-w-0 items-center gap-2">
                          <CompanyLogo name={d.company_name} src={d.company_logo} size="sm" wide className="hidden sm:inline-flex" />
                          <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{d.company_name} <span className="font-normal text-slate-500 dark:text-slate-400">· {d.job_role}</span></p>
                            <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                              {JOB_TYPES[d.job_type] ?? d.job_type} · {ctcRange(d.package_min, d.package_max)} · {relativeDays(d.drive_date)}
                              {d.registration_deadline && d.status !== 'ongoing' ? ` · register by ${formatDate(d.registration_deadline)}` : ''}
                            </p>
                          </div>
                        </div>
                      </div>
                      <div className="col-span-2 sm:col-span-1">
                        <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                          <StatusBadge status={d.status} label={DRIVE_STATUS[d.status]} colors={DRIVE_STATUS_COLORS} />
                          <span className="tabular-nums text-slate-500 dark:text-slate-400">{d.registered}/{d.eligible}</span>
                        </div>
                        <ProgressBar value={d.eligible ? (d.registered / d.eligible) * 100 : 0} tone="green" label="Registered of eligible" />
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Top recruiters" subtitle="Ranked by hires this session" icon={Trophy}
              actions={<Link to="/placement/companies" className="link text-xs">Companies</Link>} />
            {isLoading || !data ? (
              <div className="space-y-3 p-5">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
            ) : !data.recruiters.length ? (
              <EmptyState icon={Trophy} title="No recruiters yet" description="Recruiters appear once students receive offers." />
            ) : (
              <ol className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.recruiters.map((r, i) => (
                  <li key={r.id}>
                    <Link to={`/placement/companies?company=${r.id}`} className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                      <span className="w-4 text-center text-xs font-bold text-slate-400">{i + 1}</span>
                      <CompanyLogo name={r.name} src={r.logo} size="sm" wide />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.name}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{r.industry ?? '—'} · highest {lpa(r.highest)}</p>
                      </div>
                      <div className="shrink-0 text-right">
                        <p className="font-display text-lg font-bold leading-tight text-slate-900 dark:text-white">{r.session_hires}</p>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">{r.total_hires} all-time</p>
                      </div>
                      <Badge color={TIE_UP_COLORS[r.tie_up_status] ?? 'slate'} className="hidden sm:inline-flex">{TIE_UP[r.tie_up_status] ?? r.tie_up_status}</Badge>
                    </Link>
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </Reveal>
      </div>

      {/* Offer status, pipeline, training */}
      <div className="grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Offer status" subtitle="Acceptance of offers this session" icon={PieChart}
              actions={<Link to="/placement/offers" className="link text-xs">Offers</Link>} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[180px] w-full" />
              ) : !offerStatus.length ? (
                <EmptyState icon={PieChart} title="No offers yet" />
              ) : (
                <DoughnutChart
                  labels={offerStatus.map(([s]) => (s === 'accepted' ? 'Accepted' : OFFER_STATUS[s] ?? s))}
                  data={offerStatus.map(([, n]) => n)}
                  colors={offerStatus.map(([s]) => OFFER_COLORS[s] ?? '#64748B')}
                  centerValue={offerStatus.reduce((a, [, n]) => a + n, 0)}
                  centerLabel="Offers made"
                  valueFormat="number"
                  height={170}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Application pipeline" subtitle={`${formatNumber(totalApps)} applications this session`} icon={Users}
              actions={<Link to="/placement/applications" className="link text-xs">Applications</Link>} />
            <div className="card-body space-y-2.5">
              {isLoading || !data ? (
                [0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-6 w-full" />)
              ) : !totalApps ? (
                <EmptyState icon={Users} title="No applications yet" description="Register eligible students from a drive to start the pipeline." />
              ) : (
                pipeline.map((p, i) => (
                  <Link key={p.key} to={`/placement/applications?f.status=${p.key}`} className="group block">
                    <div className="mb-1 flex items-center justify-between text-xs">
                      <span className="font-medium text-slate-600 group-hover:text-brand-700 dark:text-slate-300 dark:group-hover:text-brand-300">{APP_STATUS[p.key]}</span>
                      <span className="tabular-nums font-semibold text-slate-900 dark:text-white">{formatNumber(p.n)}</span>
                    </div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                      <div className="h-full rounded-full" style={{ width: `${totalApps ? Math.max(p.n ? 2 : 0, (p.n / totalApps) * 100) : 0}%`, background: p.key === 'rejected' ? '#EF4444' : p.key === 'selected' ? '#22943F' : chartColor(i) }} />
                    </div>
                  </Link>
                ))
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={160} className="lg:col-span-2 xl:col-span-1">
          <Card className="h-full">
            <CardHeader title="Training & readiness" subtitle="Trainings, workshops and mock tests" icon={Presentation}
              actions={<Link to="/placement/training" className="link text-xs">Training</Link>} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[160px] w-full" />
              ) : (
                <>
                  <div className="grid grid-cols-3 gap-3 text-center">
                    {[
                      { label: 'Sessions', value: data.training.session_total, icon: Presentation },
                      { label: 'Upcoming', value: data.training.upcoming, icon: CalendarClock },
                      { label: 'Attendances', value: data.training.attended, icon: GraduationCap },
                    ].map((t) => (
                      <div key={t.label} className="rounded-xl bg-slate-50 px-2 py-3 dark:bg-slate-800/60">
                        <t.icon className="mx-auto h-4 w-4 text-brand-600 dark:text-brand-300" />
                        <p className="mt-1 font-display text-lg font-bold text-slate-900 dark:text-white">{formatNumber(t.value)}</p>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">{t.label}</p>
                      </div>
                    ))}
                  </div>
                  {data.training.next ? (
                    <Link to={`/placement/training?training=${data.training.next.id}`} className="mt-4 flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-700">
                      <DateTile date={data.training.next.start_date} />
                      <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-accent-700 dark:text-accent-400">Next · {TRAINING_TYPES[data.training.next.type] ?? data.training.next.type}</p>
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{data.training.next.title}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{data.training.next.trainer ?? '—'} · {relativeDays(data.training.next.start_date)}</p>
                      </div>
                    </Link>
                  ) : (
                    <p className="mt-4 rounded-xl bg-slate-50 p-3 text-center text-sm text-slate-500 dark:bg-slate-800/60">No upcoming training scheduled.</p>
                  )}
                </>
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </>
  );
}
