import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRight, BedDouble, BedSingle, Building2, CalendarClock, DoorOpen, IndianRupee, KeyRound, LayoutGrid, LogIn, MessageSquarePlus, MessageSquareWarning, PieChart, Printer, TrendingUp,
  UserCheck, UserPlus, Users, Wrench, Zap,
} from 'lucide-react';
import { Alert, Avatar, Button, Card, CardHeader, CardSkeleton, Dropdown, EmptyState, PageHeader, Reveal, Skeleton, StatCard, Stagger, StatusBadge, toneClasses, type Tone } from '@/components/ui';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoneyShort, formatNumber, formatTime, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { AllocateModal, type AllocateDefaults } from './hostel/AllocationDialogs';
import { AllocationDrawer } from './hostel/AllocationDrawer';
import { COMPLAINT_CATEGORIES, COMPLAINT_STATUS, COMPLAINT_STATUS_COLORS, HostelTypeBadge, OccupancyBar, PRIORITY_COLORS, hoursLabel, openHostelPrint, type HostelStat } from './hostel/shared';

interface Dashboard {
  kpis: {
    rooms: number; rooms_occupied: number; rooms_full: number; rooms_vacant: number; rooms_maintenance: number; beds: number; beds_occupied: number; beds_available: number;
    beds_maintenance: number; occupancy_pct: number; students: number; boys: number; girls: number; pending_fees: number; pending_fee_students: number; overdue_fees: number;
    billed_fees: number; open_complaints: number; complaints_open: number; complaints_in_progress: number; urgent_complaints: number; resolved_this_month: number;
    avg_resolution_hours: number | null; visitors_today: number; visitors_inside: number;
  };
  hostels: HostelStat[];
  room_types: { room_type: string; label: string; rooms: number; beds: number; occupied: number }[];
  complaint_categories: { category: string; label: string; total: number; open: number }[];
  trend: { month: string; label: string; allocated: number; vacated: number; transferred: number }[];
  recent_complaints: Row[];
  visitors_today: Row[];
  recent_allocations: Row[];
}

const quickActions: { label: string; icon: typeof UserPlus; tone: Tone; to?: string; action?: 'allocate' | 'register'; perm?: 'create' | 'view' }[] = [
  { label: 'Allot room', icon: UserPlus, tone: 'green', action: 'allocate', perm: 'create' },
  { label: 'Check in visitor', icon: LogIn, tone: 'blue', to: '/hostel/visitors?new=1', perm: 'create' },
  { label: 'New complaint', icon: MessageSquarePlus, tone: 'orange', to: '/hostel/complaints?new=1', perm: 'create' },
  { label: 'Room map', icon: LayoutGrid, tone: 'navy', to: '/hostel/rooms?tab=grid', perm: 'view' },
  { label: 'Fee not invoiced', icon: IndianRupee, tone: 'purple', to: '/hostel/allocations?status=active&f.fee=none', perm: 'view' },
  { label: 'Occupancy register', icon: Printer, tone: 'slate', action: 'register', perm: 'view' },
];

export default function HostelDashboardPage() {
  const { can } = useAuth();
  const { data, isLoading, error } = useApi<Dashboard>(['hostel', 'dashboard'], 'hostel/dashboard');
  const [allocate, setAllocate] = useState<AllocateDefaults | null>(null);
  const [allocationId, setAllocationId] = useState<number | null>(null);
  const k = data?.kpis;

  return (
    <>
      <PageHeader
        title="Hostel Dashboard"
        description="Rooms, beds, residents, hostel fees, complaints and visitors across all hostels at a glance."
        breadcrumbs={[{ label: 'Hostel' }]}
        actions={
          <>
            <Button variant="secondary" icon={LayoutGrid} to="/hostel/rooms?tab=grid">Room map</Button>
            {can('hostel', 'create') && <Button icon={UserPlus} onClick={() => setAllocate({})}>Allot room</Button>}
          </>
        }
      />
      {error && <Alert variant="error" title="Unable to load the hostel dashboard" className="mb-6">{(error as Error).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="rooms" label="Total rooms" icon={DoorOpen} tone="navy" loading={isLoading} value={k?.rooms ?? 0} hint={k ? `${formatNumber(k.rooms_vacant)} vacant · ${formatNumber(k.rooms_maintenance)} in repair` : undefined} to="/hostel/rooms?tab=rooms" />,
          <StatCard key="occ" label="Occupied rooms" icon={KeyRound} tone="blue" loading={isLoading} value={k?.rooms_occupied ?? 0} hint={k ? `${formatNumber(k.rooms_full)} rooms full` : undefined} to="/hostel/rooms?tab=grid" />,
          <StatCard key="avail" label="Available beds" icon={BedSingle} tone="green" loading={isLoading} value={k?.beds_available ?? 0} hint={k ? `of ${formatNumber(k.beds)} beds` : undefined} to="/hostel/rooms?tab=rooms&f.availability=has_free" />,
          <StatCard key="students" label="Hostel students" icon={Users} tone="purple" loading={isLoading} value={k?.students ?? 0} hint={k ? `${formatNumber(k.boys)} boys · ${formatNumber(k.girls)} girls` : undefined} to="/hostel/allocations" />,
          <StatCard key="fees" label="Pending hostel fees" icon={IndianRupee} tone="orange" loading={isLoading} value={k ? formatMoneyShort(k.pending_fees) : '—'} hint={k ? `${formatNumber(k.pending_fee_students)} students with dues` : undefined} to="/hostel/allocations?status=active&f.fee=due" />,
          <StatCard key="complaints" label="Open complaints" icon={MessageSquareWarning} tone="red" loading={isLoading} value={k?.open_complaints ?? 0} hint={k ? `${formatNumber(k.urgent_complaints)} high / urgent` : undefined} to="/hostel/complaints?f.stage=pending" />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader
            title="Occupancy by hostel"
            subtitle={k ? `${formatNumber(k.beds_occupied)} of ${formatNumber(k.beds)} beds occupied · ${k.occupancy_pct}%` : 'Beds occupied, free and under maintenance'}
            icon={Building2}
            actions={<Link to="/hostel/rooms?tab=hostels" className="link text-xs">All hostels <ArrowRight className="inline h-3.5 w-3.5" /></Link>}
          />
          <div className="card-body !pt-1">
            {isLoading || !data ? (
              <div className="space-y-5">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-12" />)}</div>
            ) : data.hostels.length === 0 ? (
              <EmptyState icon={Building2} title="No hostels yet" description="Add hostels, blocks and rooms to start allotting beds." action={<Button to="/hostel/rooms?tab=hostels" icon={Building2}>Add hostel</Button>} />
            ) : (
              <ul className="space-y-5">
                {data.hostels.map((h) => (
                  <li key={h.id}>
                    <div className="mb-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                      <Link to={`/hostel/rooms?tab=grid&hostel=${h.id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{h.name}</Link>
                      <HostelTypeBadge type={h.type} />
                      <span className="ml-auto text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{h.occupancy_pct}%</span>
                    </div>
                    <OccupancyBar occupied={h.occupied} available={h.available} maintenance={h.maintenance} />
                    <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                      <span><strong className="text-slate-700 dark:text-slate-200">{h.occupied}</strong> residents</span>
                      <span><strong className="text-accent-700 dark:text-accent-400">{h.available}</strong> free beds</span>
                      {h.maintenance > 0 && <span><strong className="text-amber-600">{h.maintenance}</strong> maintenance</span>}
                      <span>{h.rooms} rooms · {h.blocks} block{h.blocks === 1 ? '' : 's'}</span>
                      {h.open_complaints > 0 && <span className="text-red-600 dark:text-red-400">{h.open_complaints} open complaint{h.open_complaints === 1 ? '' : 's'}</span>}
                      {h.warden_name && <span className="hidden sm:inline">Warden {h.warden_name}</span>}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>
        <Card>
          <CardHeader title="Beds by room type" subtitle="Share of all beds" icon={PieChart} />
          <div className="card-body">
            {isLoading || !data ? (
              <Skeleton className="h-48 w-full" />
            ) : data.room_types.length === 0 ? (
              <EmptyState icon={BedDouble} title="No rooms yet" className="py-8" />
            ) : (
              <>
                <div className="flex justify-center">
                  <DoughnutChart labels={data.room_types.map((r) => r.label)} data={data.room_types.map((r) => r.beds)} centerValue={formatNumber(k?.beds ?? 0)} centerLabel="beds" height={170} valueFormat="number" legendList={false} />
                </div>
                <ul className="mt-4 space-y-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                  {data.room_types.map((r, i) => (
                    <li key={r.room_type} className="flex items-center justify-between gap-2">
                      <span className="flex items-center gap-2"><span className="h-2 w-2 rounded-full" style={{ background: chartColor(i) }} />{r.label} · {r.rooms} rooms</span>
                      <span className="tabular-nums">{r.occupied}/{r.beds} occupied</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </Card>
      </div>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader
              title="Recent complaints"
              subtitle={k ? `${k.complaints_open} open · ${k.complaints_in_progress} in progress · ${k.resolved_this_month} resolved this month${k.avg_resolution_hours ? ` · avg ${hoursLabel(k.avg_resolution_hours)}` : ''}` : undefined}
              icon={MessageSquareWarning}
              actions={<Link to="/hostel/complaints" className="link text-xs">View all <ArrowRight className="inline h-3.5 w-3.5" /></Link>}
            />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="space-y-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-12" />)}</div>
              ) : data.recent_complaints.length === 0 ? (
                <EmptyState icon={MessageSquareWarning} title="No complaints" description="Residents' complaints will show up here." className="py-8" action={can('hostel', 'create') && <Button size="sm" to="/hostel/complaints?new=1" icon={MessageSquarePlus}>Log complaint</Button>} />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.recent_complaints.map((c) => (
                    <li key={String(c.id)} className="flex items-start gap-3 py-3">
                      <span className={clsx('kpi-icon !h-9 !w-9 shrink-0', ['urgent', 'high'].includes(String(c.priority)) ? toneClasses.red.icon : toneClasses.amber.icon)}>
                        {c.category === 'electrical' ? <Zap className="h-4 w-4" /> : <Wrench className="h-4 w-4" />}
                      </span>
                      <div className="min-w-0 flex-1">
                        <Link to={`/hostel/complaints?view=${c.id}`} className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(c.subject)}</Link>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                          {c.hostel_code}{c.room_no ? ` · Room ${c.room_no}` : ' · Common area'} · {COMPLAINT_CATEGORIES[String(c.category)] ?? String(c.category)}
                          {c.student_name ? ` · ${c.student_name}` : ''} · {timeAgo(c.created_at)}
                        </p>
                      </div>
                      <div className="flex shrink-0 flex-col items-end gap-1">
                        <StatusBadge status={String(c.status)} label={COMPLAINT_STATUS[String(c.status)]} colors={COMPLAINT_STATUS_COLORS} />
                        <StatusBadge status={String(c.priority)} colors={PRIORITY_COLORS} dot={false} />
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader
              title="Visitors today"
              subtitle={k ? `${k.visitors_today} visited · ${k.visitors_inside} inside now` : undefined}
              icon={UserCheck}
              actions={<Link to="/hostel/visitors" className="link text-xs">Register <ArrowRight className="inline h-3.5 w-3.5" /></Link>}
            />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="space-y-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10" />)}</div>
              ) : data.visitors_today.length === 0 ? (
                <EmptyState icon={UserCheck} title="No visitors today" className="py-8" action={can('hostel', 'create') && <Button size="sm" to="/hostel/visitors?new=1" icon={LogIn}>Check in visitor</Button>} />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.visitors_today.map((v) => (
                    <li key={String(v.id)} className="flex items-center gap-3 py-2.5">
                      <Avatar name={String(v.visitor_name)} size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{String(v.visitor_name)} <span className="font-normal text-slate-500">· {String(v.relation ?? '')}</span></p>
                        <p className="truncate text-xs text-slate-500">for {String(v.student_name)} · {String(v.hostel_code)}</p>
                      </div>
                      <div className="shrink-0 text-right text-xs">
                        <p className="font-medium text-slate-700 dark:text-slate-200">{formatTime(String(v.check_in).slice(11, 16))}</p>
                        {v.check_out ? <p className="text-slate-400">out {formatTime(String(v.check_out).slice(11, 16))}</p> : <p className="font-semibold text-amber-600">inside</p>}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Allotments vs vacating" subtitle="Last 6 months" icon={TrendingUp} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-60 w-full" />
              ) : (
                <BarChart
                  labels={data.trend.map((t) => t.label)}
                  height={250}
                  series={[
                    { label: 'New allotments', data: data.trend.map((t) => t.allocated), color: '#22943F' },
                    { label: 'Room transfers', data: data.trend.map((t) => t.transferred), color: '#8B5CF6' },
                    { label: 'Vacated', data: data.trend.map((t) => t.vacated), color: '#F97316' },
                  ]}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Quick actions" icon={Zap} />
            <div className="card-body !pt-0">
              <div className="grid grid-cols-2 gap-2.5">
                {quickActions
                  .filter((q) => can('hostel', q.perm ?? 'view'))
                  .map((q) => {
                    const t = toneClasses[q.tone];
                    const inner = (
                      <>
                        <span className={clsx('kpi-icon !h-9 !w-9', t.icon)}><q.icon className="h-4 w-4" /></span>
                        <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">{q.label}</span>
                      </>
                    );
                    const cls = 'flex flex-col items-start gap-2 rounded-xl border border-slate-200 p-3 text-left transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft dark:border-slate-700';
                    if (q.to) return <Link key={q.label} to={q.to} className={cls}>{inner}</Link>;
                    if (q.action === 'register') {
                      // one register per hostel: let the warden pick which one to print
                      return (
                        <Dropdown
                          key={q.label}
                          label="Print occupancy register"
                          align="right"
                          width="w-64"
                          triggerClassName={clsx(cls, 'h-full w-full')}
                          trigger={inner}
                          header={<span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Print register of</span>}
                          items={(data?.hostels ?? []).map((h) => ({ label: h.name, icon: Printer, hint: `${h.occupied} residents`, onClick: () => openHostelPrint(`register/${h.id}`) }))}
                        />
                      );
                    }
                    return (
                      <button key={q.label} type="button" className={cls} onClick={() => setAllocate({})}>
                        {inner}
                      </button>
                    );
                  })}
              </div>
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader
              title="Latest allotments"
              icon={CalendarClock}
              actions={<Link to="/hostel/allocations" className="link text-xs">All allocations <ArrowRight className="inline h-3.5 w-3.5" /></Link>}
            />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-10" />)}</div>
              ) : data.recent_allocations.length === 0 ? (
                <EmptyState icon={BedDouble} title="No allotments yet" className="py-8" action={can('hostel', 'create') && <Button size="sm" icon={UserPlus} onClick={() => setAllocate({})}>Allot room</Button>} />
              ) : (
                <ul className="grid grid-cols-1 gap-2 md:grid-cols-2">
                  {data.recent_allocations.map((a) => (
                    <li key={String(a.id)}>
                      <button type="button" onClick={() => setAllocationId(Number(a.id))} className="flex w-full items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-left transition hover:border-brand-200 hover:bg-brand-50/40 dark:border-slate-700 dark:hover:bg-slate-800/60">
                        <Avatar name={String(a.student_name)} src={a.photo as string | null} size="sm" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{String(a.student_name)}</span>
                          <span className="block truncate text-xs text-slate-500">{a.hostel_code} · Room {a.room_no}{a.bed_no ? ` · Bed ${a.bed_no}` : ''}{a.program_short ? ` · ${a.program_short}` : ''}</span>
                        </span>
                        <span className="shrink-0 text-xs text-slate-500">{formatDate(a.allocated_on)}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          {isLoading || !data ? (
            <CardSkeleton lines={5} />
          ) : (
            <Card className="h-full">
              <CardHeader title="Complaints by category" subtitle="Last 90 days" icon={MessageSquareWarning} />
              <div className="card-body !pt-0">
                {data.complaint_categories.length === 0 ? (
                  <EmptyState icon={MessageSquareWarning} title="No complaints in 90 days" className="py-8" />
                ) : (
                  <ul className="space-y-3 pt-2">
                    {data.complaint_categories.slice(0, 7).map((c, i) => {
                      const max = Math.max(...data.complaint_categories.map((x) => x.total), 1);
                      return (
                        <li key={c.category}>
                          <div className="mb-1 flex justify-between text-xs">
                            <span className="font-medium text-slate-700 dark:text-slate-200">{c.label}</span>
                            <span className="tabular-nums text-slate-500">{c.total}{c.open ? <span className="text-red-600 dark:text-red-400"> · {c.open} open</span> : ''}</span>
                          </div>
                          <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div className="h-full rounded-full transition-all duration-500" style={{ width: `${(c.total / max) * 100}%`, background: chartColor(i) }} />
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>
            </Card>
          )}
        </Reveal>
      </div>

      <AllocateModal open={allocate !== null} defaults={allocate} onClose={() => setAllocate(null)} onDone={(id) => setAllocationId(id)} />
      <AllocationDrawer id={allocationId} onClose={() => setAllocationId(null)} />
    </>
  );
}
