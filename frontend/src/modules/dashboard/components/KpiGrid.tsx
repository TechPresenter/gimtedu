import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowDownRight, ArrowRight, ArrowUpRight, BookOpen, BriefcaseBusiness, Bus, CalendarCheck, ClipboardList, FileClock, GraduationCap, IdCard, IndianRupee, Library, MessageCircle, UserCheck,
  UserPlus, Users, UsersRound, Wallet, type LucideIcon,
} from 'lucide-react';
import { CountUp, Skeleton, Stagger } from '@/components/ui';
import { formatMoneyShort, formatNumber } from '@/lib/format';
import { HUES, type Hue } from './hues';
import { Sparkline } from './Sparkline';
import type { Kpi, KpiKey } from './types';

interface KpiDef {
  key: KpiKey;
  label: string;
  icon: LucideIcon;
  hue: Hue;
  to: string;
  format?: 'number' | 'money' | 'percent';
}

/** First row: the eight headline KPIs of the mockup, each with its own accent hue. */
const PRIMARY: KpiDef[] = [
  { key: 'total_students', label: 'Total Students', icon: GraduationCap, hue: 'indigo', to: '/students' },
  { key: 'new_admissions', label: 'New Admissions', icon: UserPlus, hue: 'emerald', to: '/students' },
  { key: 'pending_applications', label: 'Pending Applications', icon: FileClock, hue: 'amber', to: '/admissions/list' },
  { key: 'active_programs', label: 'Active Programs', icon: BookOpen, hue: 'violet', to: '/academics?tab=programs' },
  { key: 'faculty', label: 'Faculty Members', icon: Users, hue: 'rose', to: '/faculty' },
  { key: 'fee_collected', label: 'Fee Collected', icon: IndianRupee, hue: 'teal', to: '/fees/payments', format: 'money' },
  { key: 'pending_dues', label: 'Pending Dues', icon: Wallet, hue: 'orange', to: '/fees/invoices', format: 'money' },
  { key: 'attendance_today', label: "Today's Attendance", icon: CalendarCheck, hue: 'sky', to: '/attendance', format: 'percent' },
];

/** Second, compact row: the remaining brief §6 KPIs. */
const SECONDARY: KpiDef[] = [
  { key: 'active_students', label: 'Active Students', icon: UserCheck, hue: 'emerald', to: '/students?f.status=active' },
  { key: 'staff', label: 'Staff', icon: IdCard, hue: 'slate', to: '/staff' },
  { key: 'enquiries_open', label: 'Open Enquiries', icon: MessageCircle, hue: 'sky', to: '/enquiries' },
  { key: 'upcoming_exams', label: 'Upcoming Exams', icon: ClipboardList, hue: 'violet', to: '/examination' },
  { key: 'placed_students', label: 'Placed Students', icon: BriefcaseBusiness, hue: 'indigo', to: '/placement/offers' },
  { key: 'alumni', label: 'Alumni', icon: UsersRound, hue: 'rose', to: '/alumni' },
  { key: 'library_books', label: 'Library Books', icon: Library, hue: 'amber', to: '/library/books' },
  { key: 'transport_vehicles', label: 'Active Vehicles', icon: Bus, hue: 'orange', to: '/transport/vehicles' },
];

function renderValue(def: KpiDef, kpi: Kpi) {
  if (kpi.value === null || kpi.value === undefined) return <span className="text-slate-400">—</span>;
  if (def.format === 'money') return <CountUp value={kpi.value} format={formatMoneyShort} />;
  if (def.format === 'percent') return <CountUp value={kpi.value} format={(n) => `${n.toFixed(kpi.value !== null && Number.isInteger(kpi.value) ? 0 : 1)}%`} />;
  return <CountUp value={kpi.value} format={(n) => formatNumber(n)} />;
}

/**
 * Split the KPIs the user may see into the headline row and the compact strip. Roles that see only part of the
 * headline set get strip KPIs promoted into the row until it fills whole rows of four (no lonely half rows).
 */
function layout(kpis: Partial<Record<KpiKey, Kpi>> | undefined, loading: boolean): { primary: KpiDef[]; secondary: KpiDef[] } {
  if (loading) return { primary: PRIMARY, secondary: SECONDARY };
  const primary = PRIMARY.filter((d) => kpis?.[d.key]);
  const secondary = SECONDARY.filter((d) => kpis?.[d.key]);
  while (primary.length % 4 !== 0 && secondary.length) primary.push(secondary.shift() as KpiDef);
  return { primary, secondary };
}

function TrendPill({ kpi }: { kpi: Kpi }) {
  const t = kpi.trend;
  if (!t) return null;
  const good = t.positive ?? t.dir !== 'down';
  const Icon = t.dir === 'up' ? ArrowUpRight : t.dir === 'down' ? ArrowDownRight : ArrowRight;
  return (
    <span
      className={clsx(
        'inline-flex shrink-0 items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums',
        t.dir === 'flat' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : good ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
      )}
    >
      <Icon className="h-3 w-3" aria-hidden />
      {t.value}
    </span>
  );
}

/** Headline KPI card: hue tint, solid icon chip, trend pill, sparkline in the same hue, hover lift + glow + accent edge. */
function KpiCard({ def, kpi, loading }: { def: KpiDef; kpi?: Kpi; loading: boolean }) {
  const h = HUES[def.hue];
  const busy = loading || !kpi;
  const foot = kpi?.trend?.label ? kpi.trend.label : kpi?.hint ?? null;
  return (
    <Link
      to={def.to}
      className={clsx(
        'group relative flex h-full flex-col overflow-hidden rounded-2xl p-4 shadow-card outline-none transition duration-300 hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-brand-500/50 motion-reduce:transition-none motion-reduce:hover:translate-y-0',
        h.tint, h.ring, h.glow,
      )}
    >
      <span className={clsx('absolute inset-x-0 top-0 h-[3px] origin-left scale-x-0 bg-gradient-to-r transition-transform duration-500 ease-out group-hover:scale-x-100 group-focus-visible:scale-x-100 motion-reduce:transition-none', h.edge)} aria-hidden />
      <div className="flex items-start justify-between gap-2">
        <span className={clsx('inline-flex h-10 w-10 items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-105 motion-reduce:transition-none', h.chip)}>
          <def.icon className="h-5 w-5" aria-hidden />
        </span>
        {!busy && kpi && <TrendPill kpi={kpi} />}
      </div>
      <p className="mt-3 truncate text-[13px] font-medium text-slate-600 dark:text-slate-300" title={def.label}>{def.label}</p>
      {busy ? <Skeleton className="mt-1.5 h-7 w-20" /> : <p className="mt-0.5 font-display text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{renderValue(def, kpi)}</p>}
      <p className="mt-1 min-h-[1rem] truncate text-xs text-slate-500 dark:text-slate-400" title={foot ?? undefined}>{busy ? '' : foot}</p>
      <div className="mt-auto pt-2">
        {!busy && kpi?.spark ? (
          <Sparkline spark={kpi.spark} color={h.hex} />
        ) : (
          <div className="flex h-[34px] items-end" aria-hidden>
            <span className={clsx('h-[3px] w-full rounded-full bg-gradient-to-r opacity-25', h.edge)} />
          </div>
        )}
      </div>
    </Link>
  );
}

export function KpiRow({ kpis, loading }: { kpis?: Partial<Record<KpiKey, Kpi>>; loading: boolean }) {
  const defs = layout(kpis, loading).primary;
  if (!defs.length) return null;
  return (
    <Stagger className="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4 2xl:grid-cols-8" itemClassName="[&>*]:h-full" step={50}>
      {defs.map((d) => (
        <KpiCard key={d.key} def={d} kpi={kpis?.[d.key]} loading={loading} />
      ))}
    </Stagger>
  );
}

/** 'At a glance' strip with the secondary KPIs (only those the user may see). */
export function GlanceStrip({ kpis, loading }: { kpis?: Partial<Record<KpiKey, Kpi>>; loading: boolean }) {
  const defs = layout(kpis, loading).secondary;
  if (!defs.length) return null;
  // 1px dividers come from the grid gap; filler cells keep partial rows white at 2 and 4 columns.
  const n = defs.length;
  const fill2 = n === 1 ? 0 : (2 - (n % 2)) % 2;
  const fill4 = n < 4 ? 0 : (4 - (n % 4)) % 4;
  const smallCols: Record<number, string> = { 1: 'grid-cols-1', 2: 'grid-cols-2 sm:grid-cols-2', 3: 'grid-cols-2 sm:grid-cols-3' };
  const wideCols: Record<number, string> = { 1: '2xl:grid-cols-1', 2: '2xl:grid-cols-2', 3: '2xl:grid-cols-3', 4: '2xl:grid-cols-4', 5: '2xl:grid-cols-5', 6: '2xl:grid-cols-6', 7: '2xl:grid-cols-7', 8: '2xl:grid-cols-8' };
  return (
    <div className="card overflow-hidden">
      <ul className={clsx('grid gap-px bg-slate-100 dark:bg-slate-800', smallCols[n] ?? 'grid-cols-2 sm:grid-cols-4', wideCols[n])}>
        {defs.map((d) => {
          const kpi = kpis?.[d.key];
          const h = HUES[d.hue];
          return (
            <li key={d.key} className="bg-white dark:bg-slate-900">
              <Link to={d.to} className="group relative flex h-full items-center gap-2.5 px-3 py-3.5 outline-none transition hover:bg-slate-50/80 focus-visible:bg-slate-50 sm:gap-3 sm:px-4 dark:hover:bg-slate-800/50 dark:focus-visible:bg-slate-800/50">
                <span className={clsx('absolute inset-y-2 left-0 w-[3px] origin-center scale-y-0 rounded-r-full bg-gradient-to-b transition-transform duration-300 group-hover:scale-y-100 group-focus-visible:scale-y-100 motion-reduce:transition-none', h.edge)} aria-hidden />
                <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl transition group-hover:scale-105 motion-reduce:transition-none sm:h-9 sm:w-9', h.chip)}>
                  <d.icon className="h-4 w-4 sm:h-[18px] sm:w-[18px]" aria-hidden />
                </span>
                <span className="min-w-0">
                  <span className="block truncate text-[11px] font-semibold uppercase text-slate-500 sm:tracking-wide dark:text-slate-400" title={d.label}>{d.label}</span>
                  {loading || !kpi ? (
                    <Skeleton className="mt-1 h-5 w-12" />
                  ) : (
                    <span className="block font-display text-lg font-bold leading-tight text-slate-900 dark:text-white">{renderValue(d, kpi)}</span>
                  )}
                  {kpi?.hint && <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400" title={kpi.hint}>{kpi.hint}</span>}
                </span>
              </Link>
            </li>
          );
        })}
        {Array.from({ length: Math.max(fill2, fill4) }).map((_, j) => (
          <li key={`fill-${j}`} aria-hidden className={clsx('bg-white dark:bg-slate-900 2xl:hidden', j < fill2 ? 'block' : 'hidden', j < fill4 ? 'sm:block' : 'sm:hidden')} />
        ))}
      </ul>
    </div>
  );
}
