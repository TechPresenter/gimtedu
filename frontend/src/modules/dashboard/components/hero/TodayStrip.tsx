import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarDays, ClipboardList, HandCoins, PhoneCall, Presentation, Sparkles, Wallet, type LucideIcon } from 'lucide-react';
import { formatMoneyShort, formatNumber, formatTime } from '@/lib/format';
import { HUES, type Hue } from '../hues';
import type { HeroData } from '../types';

interface Chip {
  key: string;
  to: string;
  icon: LucideIcon;
  hue: Hue;
  value: ReactNode;
  label: string;
  sub?: string;
}

const plural = (n: number, one: string, many = `${one}s`) => (n === 1 ? one : many);
const shortDay = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' });

function buildChips(today: HeroData['today']): Chip[] {
  const chips: Chip[] = [];
  const c = today.classes;
  if (c) {
    if (c.holiday) chips.push({ key: 'classes', to: '/attendance?tab=holidays', icon: Presentation, hue: 'sky', value: 'Holiday', label: c.holiday });
    else if (c.running > 0) chips.push({ key: 'classes', to: '/timetable?view=daily', icon: Presentation, hue: 'sky', value: formatNumber(c.running), label: `${plural(c.running, 'class', 'classes')} running now`, sub: `${formatNumber(c.total)} today${c.next_at ? ` · next ${formatTime(c.next_at)}` : ''}` });
    else if (c.next_at) chips.push({ key: 'classes', to: '/timetable?view=daily', icon: Presentation, hue: 'sky', value: formatNumber(c.next_count), label: `${plural(c.next_count, 'class', 'classes')} at ${formatTime(c.next_at)}`, sub: `${formatNumber(c.done)} of ${formatNumber(c.total)} done` });
    else chips.push({ key: 'classes', to: '/timetable?view=daily', icon: Presentation, hue: 'sky', value: formatNumber(c.total), label: c.total ? `${plural(c.total, 'class', 'classes')} held today` : 'No classes today' });
  }
  const e = today.exams;
  if (e) {
    chips.push({
      key: 'exams', to: '/exam-schedule', icon: ClipboardList, hue: 'violet', value: formatNumber(e.papers), label: `exam ${plural(e.papers, 'paper')} today`,
      sub: e.papers ? (e.running ? `${formatNumber(e.running)} in progress` : e.next_at ? `next at ${formatTime(e.next_at)}` : `${formatNumber(e.exams)} ${plural(e.exams, 'exam')}`) : 'No papers scheduled',
    });
  }
  const ev = today.events;
  if (ev) chips.push({ key: 'events', to: '/events', icon: CalendarDays, hue: 'rose', value: formatNumber(ev.count), label: `${plural(ev.count, 'event')} today`, sub: ev.title ? `${ev.time ? `${formatTime(ev.time)} · ` : ''}${ev.title}` : 'Nothing on the calendar' });
  const d = today.dues_week;
  if (d) chips.push({ key: 'dues', to: '/fees/invoices?f.balance=due', icon: Wallet, hue: 'amber', value: formatMoneyShort(d.amount), label: 'fees due this week', sub: d.invoices ? `${formatNumber(d.invoices)} ${plural(d.invoices, 'invoice')} · till ${shortDay(d.until)}` : `Nothing due till ${shortDay(d.until)}` });
  const col = today.collected;
  if (col) chips.push({ key: 'collected', to: '/fees/payments', icon: HandCoins, hue: 'teal', value: formatMoneyShort(col.amount), label: 'collected today', sub: `${formatNumber(col.receipts)} ${plural(col.receipts, 'receipt')}` });
  const q = today.enquiries;
  if (q) chips.push({ key: 'enquiries', to: '/enquiries?f.status=new', icon: PhoneCall, hue: 'emerald', value: formatNumber(q.new), label: `new ${plural(q.new, 'enquiry', 'enquiries')} today`, sub: q.followups ? `${formatNumber(q.followups)} follow-ups due` : 'No follow-ups due' });
  return chips;
}

const COLS: Record<number, string> = {
  1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-2 xl:grid-cols-4', 5: 'sm:grid-cols-3 2xl:grid-cols-5', 6: 'sm:grid-cols-3 2xl:grid-cols-6',
};

/** "Today at GIMT": live counters for the day, each linking to the screen that handles it. */
export function TodayStrip({ today, loading }: { today?: HeroData['today']; loading: boolean }) {
  const chips = today ? buildChips(today) : [];
  if (!loading && !chips.length) return null;
  const n = loading ? 6 : chips.length;
  return (
    <div className="relative border-t border-white/10 bg-gradient-to-r from-white/[.05] via-white/[.03] to-transparent px-5 py-4 sm:px-7 xl:px-8">
      <h2 className="mb-3 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-100">
        <Sparkles className="h-3.5 w-3.5 text-amber-300" aria-hidden /> Today at GIMT
      </h2>
      <ul className={clsx('grid grid-cols-2 gap-2 sm:gap-3', COLS[n] ?? COLS[6])}>
        {loading
          ? Array.from({ length: 6 }).map((_, i) => (
            <li key={i} className="dh-glass flex h-[60px] items-center gap-3 rounded-2xl px-3" aria-hidden>
              <span className="h-9 w-9 shrink-0 rounded-xl bg-white/10" />
              <span className="w-full space-y-1.5">
                <span className="block h-3 w-10 rounded bg-white/20" />
                <span className="block h-2 w-20 rounded bg-white/10" />
              </span>
            </li>
          ))
          : chips.map((ch) => (
            <li key={ch.key} className="min-w-0">
              <Link
                to={ch.to}
                title={ch.sub ? `${ch.label} · ${ch.sub}` : ch.label}
                className="dh-glass group flex h-full min-h-[60px] items-center gap-3 rounded-2xl px-3 py-2.5 outline-none transition duration-300 hover:-translate-y-0.5 hover:bg-white/[.13] focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
              >
                <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition duration-300 group-hover:scale-105 motion-reduce:transition-none', HUES[ch.hue].chip)}>
                  <ch.icon className="h-[18px] w-[18px]" aria-hidden />
                </span>
                <span className="min-w-0">
                  <span className="flex flex-col sm:flex-row sm:items-baseline sm:gap-1.5">
                    <span className="font-display text-lg font-bold leading-none tabular-nums text-white">{ch.value}</span>
                    <span className="mt-0.5 truncate text-xs font-medium text-sky-50 sm:mt-0">{ch.label}</span>
                  </span>
                  {ch.sub && <span className="mt-1 block truncate text-[11px] text-slate-300">{ch.sub}</span>}
                </span>
              </Link>
            </li>
          ))}
      </ul>
    </div>
  );
}
