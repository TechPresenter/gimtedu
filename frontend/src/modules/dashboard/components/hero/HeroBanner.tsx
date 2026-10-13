import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { Activity, ArrowRightLeft, ArrowUpRight, BellRing, CalendarDays, CalendarRange, Info, Mail, MessageCircle, Moon, Sun, Sunrise, Sunset, type LucideIcon } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { formatDate, formatMoneyShort, formatNumber, formatPercent, formatTime } from '@/lib/format';
import type { DashboardData } from '../types';
import { HeroArt } from './HeroArt';
import { useMinuteClock, useOffscreenPause } from './hooks';
import { ProgressRings, ProgressRingsSkeleton } from './ProgressRings';
import { TodayStrip } from './TodayStrip';
import '../dashboard.css';

interface TimeOfDay {
  greeting: string;
  icon: LucideIcon;
  anim: string;
  tone: string;
}

function timeOfDay(d: Date): TimeOfDay {
  const h = d.getHours();
  if (h >= 5 && h < 12) return { greeting: 'Good Morning', icon: Sunrise, anim: 'dh-rise', tone: 'from-amber-300 via-orange-400 to-rose-400' };
  if (h >= 12 && h < 17) return { greeting: 'Good Afternoon', icon: Sun, anim: 'dh-spin-slow', tone: 'from-yellow-300 via-amber-400 to-orange-500' };
  if (h >= 17 && h < 20) return { greeting: 'Good Evening', icon: Sunset, anim: 'dh-rise', tone: 'from-orange-400 via-rose-400 to-fuchsia-500' };
  return { greeting: 'Good Evening', icon: Moon, anim: 'dh-sway', tone: 'from-indigo-400 via-sky-500 to-blue-600' };
}

interface Counts {
  notifications: number;
  messages: number;
}

interface HeroBannerProps {
  name: string;
  data?: DashboardData;
  loading: boolean;
  counts?: Counts;
  /** Name of the session picked in the top bar (shown before the payload arrives). */
  sessionName: string;
  /** Switch the top-bar academic session (shown in the "no data in this session" hint). */
  onSwitchSession?: (id: number, name: string) => Promise<void>;
}

/** Glassy pill link with a count bubble. */
function GlassPill({ to, icon: Icon, count, label }: { to: string; icon: LucideIcon; count?: number; label: string }) {
  return (
    <Link
      to={to}
      className="dh-glass inline-flex items-center gap-2 rounded-full py-1.5 pl-1.5 pr-3.5 text-[13px] font-medium text-white outline-none transition duration-300 hover:-translate-y-0.5 hover:bg-white/[.14] focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
    >
      <span className="inline-flex h-6 min-w-[1.5rem] items-center justify-center gap-1 rounded-full bg-white px-1.5 text-[11px] font-bold tabular-nums text-brand-900">
        <Icon className="h-3.5 w-3.5" aria-hidden />
        {count !== undefined && formatNumber(count)}
      </span>
      {label}
    </Link>
  );
}

/**
 * Dashboard hero: code-native navy mesh with drifting light, dot grid, a slow shine and an original SVG
 * illustration — time-of-day greeting with a live clock, a one-line pulse built from real data, notification /
 * enquiry pills, session chip, three progress rings and the "Today at GIMT" strip.
 */
export function HeroBanner({ name, data, loading, counts, sessionName, onSwitchSession }: HeroBannerProps) {
  const now = useMinuteClock();
  const { ref, paused } = useOffscreenPause<HTMLElement>();
  const [switching, setSwitching] = useState(false);
  const tod = timeOfDay(now);
  const TodIcon = tod.icon;
  const firstName = name.trim().split(/\s+/)[0] || 'there';
  const dateLabel = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const timeLabel = now.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase();

  const ses = data?.session;
  const hero = data?.hero ?? undefined;
  const kpis = data?.kpis;
  const showHint = !!ses && !ses.is_current && ses.has_data === false && !!ses.current_id && ses.current_id !== ses.id;

  /* One-line pulse from real data */
  const pulse: ReactNode[] = [];
  const att = hero?.rings.attendance;
  if (att && att.percent !== null) {
    pulse.push(
      <>
        <strong className="font-semibold text-emerald-300">{formatPercent(att.percent, Number.isInteger(att.percent) ? 0 : 1)}</strong> attendance {att.is_today ? 'today' : `on ${formatDate(att.date)}`}
      </>,
    );
  }
  const col = hero?.today.collected;
  if (col) {
    pulse.push(col.amount > 0 ? <><strong className="font-semibold text-teal-200">{formatMoneyShort(col.amount)}</strong> collected today</> : <>No fee collections yet today</>);
  }
  const cls = hero?.today.classes;
  if (cls) {
    if (cls.holiday) pulse.push(<>Campus holiday · {cls.holiday}</>);
    else if (cls.running > 0) pulse.push(<><strong className="font-semibold text-sky-200">{formatNumber(cls.running)}</strong> {cls.running === 1 ? 'class' : 'classes'} running now</>);
    else if (cls.next_at) pulse.push(<>Next classes at <strong className="font-semibold text-sky-200">{formatTime(cls.next_at)}</strong></>);
    else if (cls.total > 0) pulse.push(<>All {formatNumber(cls.total)} classes done for today</>);
  }
  const ex = hero?.today.exams;
  if (pulse.length < 3 && ex && ex.papers > 0) pulse.push(<><strong className="font-semibold text-violet-200">{formatNumber(ex.papers)}</strong> exam {ex.papers === 1 ? 'paper' : 'papers'} today</>);

  const switchSession = async () => {
    if (!ses?.current_id || !onSwitchSession) return;
    setSwitching(true);
    try {
      await onSwitchSession(ses.current_id, ses.current_name ?? '');
    } finally {
      setSwitching(false);
    }
  };

  const enquiries = kpis?.enquiries_open;
  const admissionsOpen = ses?.admissions_open;

  return (
    <section
      ref={ref}
      aria-labelledby="dash-hero-title"
      className={clsx('dh-hero relative isolate overflow-hidden rounded-3xl text-white shadow-pop ring-1 ring-brand-950/10 dark:ring-white/10', paused && 'dh-paused')}
    >
      {/* Ambient layers (decorative) */}
      <div className="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden>
        <div className="dh-blob dh-blob-a dh-drift-a -left-24 -top-40 h-[26rem] w-[26rem]" />
        <div className="dh-blob dh-blob-b dh-drift-b -top-10 right-[8%] h-[22rem] w-[22rem]" />
        <div className="dh-blob dh-blob-c dh-drift-c -bottom-48 left-[30%] h-[24rem] w-[30rem]" />
        <div className="dh-dots absolute inset-0" />
        <div className="dh-shine absolute inset-y-0 left-0 w-1/4 bg-gradient-to-r from-transparent via-white/[.07] to-transparent" />
        <HeroArt paused={paused} className="absolute -right-16 -top-4 w-[22rem] opacity-[.35] sm:-right-6 sm:w-[26rem] sm:opacity-50 xl:hidden" />
      </div>

      <div className="relative px-5 pb-5 pt-5 sm:px-7 sm:pb-6 sm:pt-6 xl:px-8">
        {/* Meta row: date + live clock | session + website */}
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2 text-xs font-medium text-sky-50">
            <span className="dh-glass inline-flex items-center gap-1.5 rounded-full px-3 py-1.5">
              <CalendarDays className="h-3.5 w-3.5 text-sky-200" aria-hidden />
              <time dateTime={now.toISOString().slice(0, 10)}>{dateLabel}</time>
            </span>
            <span className="dh-glass inline-flex items-center gap-2 rounded-full px-3 py-1.5 tabular-nums">
              <span className="relative inline-flex h-2 w-2" aria-hidden>
                <span className="dh-pulse absolute inline-flex h-full w-full rounded-full bg-emerald-400" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
              </span>
              <span className="sr-only">Current time</span>
              <time dateTime={now.toISOString()}>{timeLabel}</time>
            </span>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="dh-glass inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium text-sky-50">
              <CalendarRange className="h-3.5 w-3.5 text-sky-200" aria-hidden />
              <span>
                Session <strong className="font-semibold text-white">{(ses?.name ?? sessionName) || '—'}</strong>
              </span>
              <span className="h-3 w-px bg-white/25" aria-hidden />
              <span className="inline-flex items-center gap-1.5">
                <span className={clsx('h-1.5 w-1.5 rounded-full', admissionsOpen ? 'bg-emerald-400' : 'bg-slate-400')} aria-hidden />
                {data ? (admissionsOpen ? 'Admissions Open' : 'Admissions Closed') : 'Loading…'}
              </span>
            </span>
            <a
              href={appUrl('/')}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 rounded-full bg-white px-3.5 py-1.5 text-xs font-semibold text-brand-900 shadow-sm outline-none transition duration-300 hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
            >
              View Website <ArrowUpRight className="h-3.5 w-3.5" aria-hidden />
              <span className="sr-only">(opens in a new tab)</span>
            </a>
          </div>
        </div>

        <div className="mt-5 grid items-center gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] 2xl:grid-cols-[minmax(0,1fr)_minmax(0,27rem)]">
          <div className="min-w-0">
            {/* Greeting */}
            <div className="flex items-center gap-4">
              <span className={clsx('relative inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br shadow-lg shadow-black/20', tod.tone)} aria-hidden>
                <span className="absolute inset-0 rounded-2xl ring-1 ring-inset ring-white/30" />
                <TodIcon className={clsx('h-7 w-7 text-white drop-shadow', tod.anim)} strokeWidth={2.2} />
              </span>
              <div className="min-w-0">
                <p className="text-sm font-medium text-sky-100">{tod.greeting},</p>
                <h1 id="dash-hero-title" className="truncate font-display text-[28px] font-extrabold leading-tight tracking-tight sm:text-4xl">
                  <span className="sr-only">{tod.greeting}, </span>
                  <span className="dh-name">{firstName}</span>
                </h1>
              </div>
            </div>

            {/* Pulse */}
            <div className="mt-3 min-h-[1.5rem]">
              {loading ? (
                <span className="block h-4 w-3/4 max-w-md animate-pulse rounded bg-white/15 motion-reduce:animate-none" aria-hidden />
              ) : pulse.length > 0 ? (
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-200 sm:text-[15px]">
                  <Activity className="h-4 w-4 shrink-0 text-sky-300" aria-hidden />
                  {pulse.map((p, i) => (
                    <span key={i} className="inline-flex items-center gap-2">
                      {i > 0 && <span className="h-1 w-1 rounded-full bg-white/40" aria-hidden />}
                      <span>{p}</span>
                    </span>
                  ))}
                </p>
              ) : (
                <p className="text-sm text-slate-200 sm:text-[15px]">Welcome to GIMT SmartCampus — here&rsquo;s what&rsquo;s happening today.</p>
              )}
            </div>

            {/* Notification / enquiry pills */}
            <div className="mt-4 flex flex-wrap gap-2">
              <GlassPill to="/notifications" icon={BellRing} count={counts?.notifications ?? 0} label={counts?.notifications ? `new ${counts.notifications === 1 ? 'notification' : 'notifications'}` : 'No new notifications'} />
              {enquiries && enquiries.value !== null && (
                <GlassPill to="/enquiries" icon={MessageCircle} count={enquiries.value} label={`open ${enquiries.value === 1 ? 'enquiry' : 'enquiries'}${enquiries.hint && /follow-ups due/.test(enquiries.hint) ? ` · ${enquiries.hint}` : ''}`} />
              )}
              {!!counts?.messages && <GlassPill to="/messages" icon={Mail} count={counts.messages} label={`unread ${counts.messages === 1 ? 'message' : 'messages'}`} />}
            </div>

            {/* Rings or the "switch to the live session" hint */}
            <div className="mt-6">
              {showHint && ses ? (
                <div className="dh-glass flex flex-col gap-3 rounded-2xl p-4 sm:flex-row sm:items-center" role="status">
                  <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-md shadow-orange-500/30">
                    <Info className="h-5 w-5" aria-hidden />
                  </span>
                  <p className="min-w-0 flex-1 text-sm text-white">
                    You are viewing <strong>{ses.name}</strong> — switch to <strong>{ses.current_name}</strong> for live figures.
                    <span className="mt-0.5 block text-xs text-slate-300">This session has no admissions, students or fee invoices yet, so its numbers are still zero.</span>
                  </p>
                  {onSwitchSession && (
                    <button
                      type="button"
                      onClick={switchSession}
                      disabled={switching}
                      className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-full bg-white px-4 py-2 text-[13px] font-semibold text-brand-900 shadow-sm outline-none transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-sky-300 disabled:cursor-wait disabled:opacity-70 motion-reduce:transition-none"
                    >
                      <ArrowRightLeft className="h-4 w-4" aria-hidden />
                      {switching ? 'Switching…' : `Switch to ${ses.current_name}`}
                    </button>
                  )}
                </div>
              ) : loading ? (
                <ProgressRingsSkeleton />
              ) : hero ? (
                <ProgressRings rings={hero.rings} />
              ) : null}
            </div>
          </div>

          {/* Illustration (wide screens) */}
          <div className="relative hidden xl:block" aria-hidden>
            <HeroArt paused={paused} className="mx-auto w-full max-w-[27rem] drop-shadow-[0_20px_40px_rgba(2,8,23,.35)]" />
          </div>
        </div>
      </div>

      <TodayStrip today={hero?.today} loading={loading} />
    </section>
  );
}
