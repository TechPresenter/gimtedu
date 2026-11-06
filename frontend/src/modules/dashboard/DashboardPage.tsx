import { useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowRightLeft, CalendarClock, RefreshCw } from 'lucide-react';
import { Alert, Button, Card, CardSkeleton, Reveal, Skeleton, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { useDocumentTitle } from '@/lib/hooks';
import { timeAgo } from '@/lib/format';
import type { DashboardData } from './components/types';
import { HeroBanner } from './components/hero/HeroBanner';
import { GlanceStrip, KpiRow } from './components/KpiGrid';
import {
  AdmissionsTrendCard, AttendanceCard, DepartmentsCard, DistributionCard, ExamPerformanceCard, FeeCollectionCard, GenderCard, PlacementCard, StudentGrowthCard,
} from './components/DashboardCharts';
import {
  ActivitiesCard, hasQuickActions, QuickActionsCard, RecentAdmissionsCard, RecentEnquiriesCard, RecentNoticesCard, RecentPaymentsCard, SystemStatusCard, TopProgramsCard, UpcomingCard,
} from './components/DashboardLists';

type Breakpoint = 'md' | 'lg' | 'xl' | '2xl';
type Cols = Partial<Record<Breakpoint, number>>;

const GRID_COLS: Record<Breakpoint, Record<number, string>> = {
  md: { 2: 'md:grid-cols-2', 3: 'md:grid-cols-3', 4: 'md:grid-cols-4' },
  lg: { 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4' },
  xl: { 2: 'xl:grid-cols-2', 3: 'xl:grid-cols-3', 4: 'xl:grid-cols-4', 12: 'xl:grid-cols-12' },
  '2xl': { 2: '2xl:grid-cols-2', 3: '2xl:grid-cols-3', 4: '2xl:grid-cols-4' },
};
// Literal class names (Tailwind only generates classes it can find in the source).
const COL_SPAN: Record<Breakpoint, string[]> = {
  md: ['', 'md:col-span-1', 'md:col-span-2', 'md:col-span-3', 'md:col-span-4'],
  lg: ['', 'lg:col-span-1', 'lg:col-span-2', 'lg:col-span-3', 'lg:col-span-4'],
  xl: ['', 'xl:col-span-1', 'xl:col-span-2', 'xl:col-span-3', 'xl:col-span-4', 'xl:col-span-5', 'xl:col-span-6', 'xl:col-span-7', 'xl:col-span-8', 'xl:col-span-9', 'xl:col-span-10', 'xl:col-span-11', 'xl:col-span-12'],
  '2xl': ['', '2xl:col-span-1', '2xl:col-span-2', '2xl:col-span-3', '2xl:col-span-4'],
};

interface GridItem {
  key: string;
  node: ReactNode | null | false | undefined;
  /** Columns the card wants per breakpoint (default 1). */
  span?: Cols;
}

/**
 * Grid that only renders the cards that exist (blocks are permission-aware) and reveals each one on scroll.
 * When a role sees fewer cards than the row holds, the card that ends a row is widened to close the gap, so
 * reduced dashboards still look composed instead of half empty.
 */
function CardGrid({ cols, items }: { cols: Cols; items: GridItem[] }) {
  const list = items.filter((i) => i.node);
  if (!list.length) return null;
  const spans = list.map(() => ({}) as Cols);
  (Object.keys(cols) as Breakpoint[]).forEach((bp) => {
    const c = cols[bp] as number;
    let used = 0;
    list.forEach((item, idx) => {
      const s = Math.min(item.span?.[bp] ?? 1, c);
      if (used + s > c && idx > 0) {
        spans[idx - 1][bp] = (spans[idx - 1][bp] as number) + (c - used);
        used = 0;
      }
      spans[idx][bp] = s;
      used = (used + s) % c;
    });
    if (used > 0) spans[list.length - 1][bp] = (spans[list.length - 1][bp] as number) + (c - used);
  });
  return (
    <div className={clsx('grid gap-4 sm:gap-6', (Object.keys(cols) as Breakpoint[]).map((bp) => GRID_COLS[bp][cols[bp] as number]))}>
      {list.map((i, idx) => (
        <Reveal key={i.key} delay={Math.min(idx, 4) * 70} className={clsx('min-w-0', (Object.keys(spans[idx]) as Breakpoint[]).map((bp) => COL_SPAN[bp][spans[idx][bp] as number]))}>
          {i.node}
        </Reveal>
      ))}
    </div>
  );
}

function SkeletonGrid({ count, className }: { count: number; className: string }) {
  return (
    <div className={clsx('grid gap-4 sm:gap-6', className)}>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="card p-5">
          <Skeleton className="h-4 w-1/3" />
          <Skeleton className="mt-2 h-3 w-1/2" />
          <Skeleton className="mt-5 h-44 w-full rounded-xl" />
        </div>
      ))}
    </div>
  );
}

/**
 * Shown instead of the KPI grid and the analytics charts when a non-current session without any data is selected
 * (e.g. next year's session before admissions open): zeros and "down 100%" trends would only mislead.
 */
function NoDataSession({ name, currentId, currentName, onSwitch }: { name: string; currentId?: number | null; currentName?: string | null; onSwitch: (id: number, name: string) => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  return (
    <Card className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center" role="status">
      <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300" aria-hidden>
        <CalendarClock className="h-5 w-5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="font-display text-[15px] font-semibold text-slate-900 dark:text-white">No figures for session {name} yet</p>
        <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
          This session has no admissions, students or fee invoices yet, so its KPIs and charts are hidden instead of showing zeros.
          {currentName ? <> Switch to <strong className="font-semibold text-slate-700 dark:text-slate-200">{currentName}</strong> for live figures.</> : null}
        </p>
      </div>
      {currentId ? (
        <Button
          icon={ArrowRightLeft}
          loading={busy}
          className="shrink-0"
          onClick={async () => {
            setBusy(true);
            try {
              await onSwitch(currentId, currentName ?? '');
            } finally {
              setBusy(false);
            }
          }}
        >
          Switch to {currentName ?? 'the current session'}
        </Button>
      ) : null}
    </Card>
  );
}

export default function DashboardPage() {
  useDocumentTitle('Dashboard');
  const { session, can, setAcademicSession } = useAuth();
  const academic = useAcademicSession();
  const toast = useToast();
  const qc = useQueryClient();
  const sessionId = academic.id ?? null;
  const key = ['dashboard', sessionId];
  // The hero shows live counters ("classes running now", "collected today"): refresh them every 5 minutes while the tab
  // is visible (the server caches the payload for 60 s, so this stays cheap).
  const { data, isLoading, error, refetch } = useApi<DashboardData>(key, 'dashboard', { session_id: sessionId ?? undefined }, { staleTime: 60_000, refetchInterval: 5 * 60_000 });
  const { data: counts } = useQuery({
    queryKey: ['notification-counts'],
    queryFn: () => api.get<{ notifications: number; messages: number }>('notifications/count', undefined, { background: true }),
    staleTime: 30_000,
  });
  const [refreshing, setRefreshing] = useState(false);

  const refresh = async () => {
    setRefreshing(true);
    try {
      const fresh = await api.get<DashboardData>('dashboard', { session_id: sessionId ?? undefined, refresh: 1 });
      qc.setQueryData(key, fresh);
      void qc.invalidateQueries({ queryKey: ['notification-counts'] });
      toast.success('Dashboard refreshed with the latest data.');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setRefreshing(false);
    }
  };

  const switchSession = async (id: number, sessionName: string) => {
    try {
      await setAcademicSession(id);
      toast.success(`Switched to academic session ${sessionName}.`);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const ses = data?.session;
  // a past / upcoming session that has nothing recorded yet
  const noData = !!ses && !ses.is_current && ses.has_data === false;
  const k = data?.kpis;
  const c = data?.charts;
  const l = data?.lists;
  const name = session?.user?.name ?? 'Administrator';

  return (
    <div className="space-y-6">
      {/* Very soft colour mesh behind the page so the white cards pop (fixed, decorative, outside the page transform). */}
      {typeof document !== 'undefined' && createPortal(<div className="dh-page-mesh pointer-events-none fixed inset-0 -z-10" aria-hidden />, document.body)}

      <HeroBanner
        name={name}
        data={data}
        loading={isLoading}
        counts={counts}
        sessionName={data?.session.name ?? academic.session?.name ?? ''}
        onSwitchSession={switchSession}
      />

      <div className="-mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 font-display text-sm font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
          <span className="h-4 w-1 rounded-full bg-gradient-to-b from-indigo-500 to-sky-400" aria-hidden />
          Overview{data ? <span className="font-semibold text-slate-400 dark:text-slate-500">· Session {data.session.name}</span> : ''}
        </h2>
        <div className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
          {data && <span aria-live="polite">Updated {timeAgo(data.generated_at)}</span>}
          <Button size="xs" variant="ghost" icon={RefreshCw} loading={refreshing} onClick={refresh} disabled={isLoading} aria-label="Refresh dashboard data">
            Refresh
          </Button>
        </div>
      </div>

      {error ? (
        <Alert variant="error" title="Unable to load the dashboard" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <>
          {noData && ses ? (
            <NoDataSession name={ses.name} currentId={ses.current_id} currentName={ses.current_name} onSwitch={switchSession} />
          ) : (
            <>
              <KpiRow kpis={k} loading={isLoading} />
              <GlanceStrip kpis={k} loading={isLoading} />
            </>
          )}

          {isLoading || !data ? (
            <>
              <SkeletonGrid count={4} className="md:grid-cols-2 2xl:grid-cols-4" />
              <div className="grid grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-3">
                <CardSkeleton lines={6} />
                <CardSkeleton lines={6} />
                <CardSkeleton lines={6} />
              </div>
            </>
          ) : (
            <>
              {!noData && <CardGrid
                cols={{ md: 2, '2xl': 4 }}
                items={[
                  { key: 'adm', node: c?.admissions_trend && <AdmissionsTrendCard data={c.admissions_trend} can={can} /> },
                  { key: 'fee', node: c?.fee_collection && <FeeCollectionCard data={c.fee_collection} can={can} /> },
                  { key: 'att', node: c?.attendance && <AttendanceCard data={c.attendance} can={can} /> },
                  { key: 'dist', node: c?.distribution && <DistributionCard data={c.distribution} /> },
                ]}
              />}

              <CardGrid
                cols={{ lg: 2, xl: 3 }}
                items={[
                  { key: 'act', node: l?.activities && <ActivitiesCard items={l.activities} can={can} /> },
                  { key: 'up', node: l?.upcoming && <UpcomingCard items={l.upcoming} can={can} /> },
                  { key: 'top', node: l?.top_programs && <TopProgramsCard data={l.top_programs} /> },
                ]}
              />

              <CardGrid
                cols={{ xl: 12 }}
                items={[
                  { key: 'quick', node: hasQuickActions(can) && <QuickActionsCard can={can} />, span: { xl: 7 } },
                  { key: 'sys', node: data.system && <SystemStatusCard data={data.system} can={can} />, span: { xl: 5 } },
                ]}
              />

              {!noData && <CardGrid
                cols={{ lg: 2, xl: 3 }}
                items={[
                  { key: 'growth', node: c?.student_growth && <StudentGrowthCard data={c.student_growth} />, span: { lg: 2, xl: 2 } },
                  { key: 'gender', node: c?.gender && <GenderCard data={c.gender} /> },
                  { key: 'dept', node: c?.departments && <DepartmentsCard data={c.departments} /> },
                  { key: 'exam', node: c?.exam_performance && <ExamPerformanceCard data={c.exam_performance} can={can} /> },
                  { key: 'place', node: c?.placement && <PlacementCard data={c.placement} can={can} /> },
                ]}
              />}

              <CardGrid
                cols={{ lg: 2 }}
                items={[
                  { key: 'ra', node: l?.recent_admissions && <RecentAdmissionsCard rows={l.recent_admissions} can={can} /> },
                  { key: 're', node: l?.recent_enquiries && <RecentEnquiriesCard rows={l.recent_enquiries} /> },
                  { key: 'rp', node: l?.recent_payments && <RecentPaymentsCard rows={l.recent_payments} can={can} /> },
                  { key: 'rn', node: l?.recent_notices && <RecentNoticesCard rows={l.recent_notices} can={can} /> },
                ]}
              />
            </>
          )}
        </>
      )}
    </div>
  );
}
