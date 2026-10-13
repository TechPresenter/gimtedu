import { useId } from 'react';
import { Link } from 'react-router-dom';
import { CalendarCheck, IndianRupee, Armchair, type LucideIcon } from 'lucide-react';
import { CountUp, useInView } from '@/components/ui';
import { formatDate, formatMoneyShort, formatNumber } from '@/lib/format';
import type { HeroData } from '../types';

interface RingSpec {
  key: string;
  label: string;
  sub: string;
  percent: number | null;
  to: string;
  colors: [string, string];
  icon: LucideIcon;
  details: string[];
}

const SIZE = 72;
const STROKE = 7;

function Ring({ spec }: { spec: RingSpec }) {
  const { ref, inView } = useInView<HTMLAnchorElement>();
  const gid = `dh-ring-${useId().replace(/[^a-zA-Z0-9]/g, '')}`;
  const tipId = `${gid}-tip`;
  const r = (SIZE - STROKE) / 2;
  const circ = 2 * Math.PI * r;
  const p = spec.percent === null ? 0 : Math.max(0, Math.min(100, spec.percent));
  const decimals = spec.percent !== null && !Number.isInteger(spec.percent) ? 1 : 0;
  const Icon = spec.icon;
  return (
    <Link
      ref={ref}
      to={spec.to}
      aria-describedby={tipId}
      className="dh-glass group relative flex flex-col items-center gap-2 rounded-2xl px-2 py-3 text-center outline-none transition duration-300 hover:-translate-y-0.5 hover:bg-white/[.13] focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none motion-reduce:hover:translate-y-0 sm:flex-row sm:gap-3 sm:px-3 sm:text-left"
    >
      <span className="relative shrink-0" style={{ width: SIZE, height: SIZE }}>
        <svg viewBox={`0 0 ${SIZE} ${SIZE}`} width={SIZE} height={SIZE} className="-rotate-90" aria-hidden>
          <defs>
            <linearGradient id={gid} x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stopColor={spec.colors[0]} />
              <stop offset="1" stopColor={spec.colors[1]} />
            </linearGradient>
          </defs>
          <circle cx={SIZE / 2} cy={SIZE / 2} r={r} fill="none" stroke="rgba(255,255,255,.13)" strokeWidth={STROKE} />
          {spec.percent !== null && (
            <circle
              cx={SIZE / 2}
              cy={SIZE / 2}
              r={r}
              fill="none"
              stroke={`url(#${gid})`}
              strokeWidth={STROKE}
              strokeLinecap="round"
              strokeDasharray={circ}
              strokeDashoffset={inView ? circ * (1 - p / 100) : circ}
              className="dh-draw"
            />
          )}
        </svg>
        <span className="absolute inset-0 flex items-center justify-center font-display text-[15px] font-bold tabular-nums text-white">
          {spec.percent === null ? <span className="text-slate-300">—</span> : <CountUp value={Number(p.toFixed(decimals))} format={(n) => `${n.toFixed(decimals)}%`} />}
        </span>
      </span>
      <span className="min-w-0">
        <span className="flex items-center justify-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-sky-100 sm:justify-start">
          <Icon className="hidden h-3.5 w-3.5 sm:block" aria-hidden />
          {spec.label}
        </span>
        <span className="mt-0.5 hidden truncate text-xs text-slate-300 sm:block">{spec.sub}</span>
      </span>
      <span
        id={tipId}
        role="tooltip"
        className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden w-max max-w-[15rem] -translate-x-1/2 translate-y-1 rounded-xl bg-white px-3 py-2 text-left text-xs leading-relaxed text-slate-600 opacity-0 shadow-pop ring-1 ring-slate-900/5 transition duration-200 group-hover:translate-y-0 group-hover:opacity-100 group-focus-visible:translate-y-0 group-focus-visible:opacity-100 motion-reduce:transition-none sm:block dark:bg-slate-800 dark:text-slate-300 dark:ring-white/10"
      >
        <span className="block font-semibold text-slate-900 dark:text-white">{spec.label}</span>
        {spec.details.map((d) => (
          <span key={d} className="block">{d}</span>
        ))}
      </span>
    </Link>
  );
}

/** Three animated progress rings from the selected session's real data; each opens its module. */
export function ProgressRings({ rings }: { rings: HeroData['rings'] }) {
  const specs: RingSpec[] = [];
  const a = rings.attendance;
  if (a) {
    const attended = a.present + a.late;
    specs.push({
      key: 'attendance', label: 'Attendance', icon: CalendarCheck, to: '/attendance', percent: a.percent, colors: ['#7DD3FC', '#818CF8'],
      sub: a.total ? `${formatNumber(attended)} of ${formatNumber(a.total)} present` : 'Not marked yet',
      details: a.total
        ? [`${a.is_today ? 'Today' : `Last marked ${formatDate(a.date)}`}`, `Present ${formatNumber(a.present)} · Late ${formatNumber(a.late)}`, `Absent ${formatNumber(a.absent)} · Leave ${formatNumber(a.leave)}`]
        : ['No student attendance marked yet.'],
    });
  }
  const f = rings.fees;
  if (f) {
    specs.push({
      key: 'fees', label: 'Fee collection', icon: IndianRupee, to: '/fees', percent: f.percent, colors: ['#5EEAD4', '#34D399'],
      sub: f.billed ? `${formatMoneyShort(f.collected)} of ${formatMoneyShort(f.billed)}` : 'No invoices this session',
      details: f.billed
        ? [`Collected ${formatMoneyShort(f.collected)} of ${formatMoneyShort(f.billed)} billed`, `${formatNumber(f.invoices)} invoices this session`, `Outstanding ${formatMoneyShort(f.balance)}`]
        : ['No fee invoices raised for this session yet.'],
    });
  }
  const ad = rings.admissions;
  if (ad) {
    specs.push({
      key: 'admissions', label: 'Seats filled', icon: Armchair, to: '/admissions', percent: ad.intake ? ad.percent : null, colors: ['#FDE68A', '#FB923C'],
      sub: ad.intake ? `${formatNumber(ad.admitted)} of ${formatNumber(ad.intake)} seats` : 'Intake not set',
      details: [
        `${formatNumber(ad.admitted)} students admitted this session`,
        ad.intake ? `Total intake ${formatNumber(ad.intake)} seats (active programs)` : 'Set program intake capacity to track seats.',
        ...(ad.pipeline !== null ? [`${formatNumber(ad.pipeline)} applications in pipeline`] : []),
      ],
    });
  }
  if (!specs.length) return null;
  return (
    <ul className="grid grid-cols-3 gap-2 sm:gap-3" aria-label="Session progress">
      {specs.map((s) => (
        <li key={s.key} className="min-w-0">
          <Ring spec={s} />
        </li>
      ))}
    </ul>
  );
}

/** Same footprint as the rings while the payload loads. */
export function ProgressRingsSkeleton() {
  return (
    <div className="grid grid-cols-3 gap-2 sm:gap-3" aria-hidden>
      {[0, 1, 2].map((i) => (
        <div key={i} className="dh-glass flex flex-col items-center gap-2 rounded-2xl px-2 py-3 sm:flex-row sm:gap-3 sm:px-3">
          <span className="h-[72px] w-[72px] shrink-0 animate-pulse rounded-full border-[7px] border-white/10 motion-reduce:animate-none" />
          <span className="w-full space-y-1.5">
            <span className="mx-auto block h-2.5 w-16 rounded bg-white/15 sm:mx-0" />
            <span className="hidden h-2.5 w-24 rounded bg-white/10 sm:block" />
          </span>
        </div>
      ))}
    </div>
  );
}
