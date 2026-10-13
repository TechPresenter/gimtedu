import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRight, BellRing, ClipboardList, Mail, PhoneCall, Wallet } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { formatMoneyShort, formatNumber } from '@/lib/format';
import type { DashboardData } from './types';

function greeting(d = new Date()) {
  const h = d.getHours();
  if (h < 12) return 'Good Morning';
  if (h < 17) return 'Good Afternoon';
  return 'Good Evening';
}

interface Attention {
  key: string;
  label: string;
  to: string;
  icon: typeof Mail;
  tone: string;
}

/** Time-of-day greeting with the campus photo, session chip and a few "needs attention" shortcuts. */
export function WelcomeBanner({ name, data, sessionName, counts }: { name: string; data?: DashboardData; sessionName: string; counts?: { notifications: number; messages: number } }) {
  const today = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const k = data?.kpis;
  const attention: Attention[] = [];
  if (counts?.messages) attention.push({ key: 'msg', label: `${formatNumber(counts.messages)} unread message${counts.messages === 1 ? '' : 's'}`, to: '/messages', icon: Mail, tone: 'text-brand-700 dark:text-brand-200' });
  if (counts?.notifications) attention.push({ key: 'ntf', label: `${formatNumber(counts.notifications)} new notification${counts.notifications === 1 ? '' : 's'}`, to: '/notifications', icon: BellRing, tone: 'text-violet-700 dark:text-violet-300' });
  if (k?.pending_applications?.value) attention.push({ key: 'app', label: `${formatNumber(k.pending_applications.value)} applications in pipeline`, to: '/admissions/list', icon: ClipboardList, tone: 'text-sky-700 dark:text-sky-300' });
  if (k?.enquiries_open?.hint && /follow-ups due/.test(k.enquiries_open.hint)) attention.push({ key: 'enq', label: `Enquiries: ${k.enquiries_open.hint}`, to: '/enquiries', icon: PhoneCall, tone: 'text-amber-700 dark:text-amber-300' });
  if (k?.pending_dues?.value) attention.push({ key: 'due', label: `${formatMoneyShort(k.pending_dues.value)} fees outstanding`, to: '/fees/invoices', icon: Wallet, tone: 'text-rose-700 dark:text-rose-300' });

  return (
    <section
      aria-labelledby="welcome-title"
      className="relative overflow-hidden rounded-2xl border border-slate-200/70 bg-gradient-to-r from-brand-50 via-white to-sky-50 shadow-card dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-brand-950 md:flex md:min-h-[200px] md:items-center"
    >
      {/* Campus photo (right side; its left half carries baked-in text, so only the building part is shown) */}
      <div className="pointer-events-none absolute inset-y-0 right-36 hidden w-[500px] xl:block 2xl:w-[560px]" aria-hidden>
        <img src={appUrl('assets/images/site/dashboard-banner.jpg')} alt="" className="h-full w-full object-cover object-right" />
        <div className="absolute inset-0 bg-gradient-to-r from-white via-white/30 to-transparent to-60% dark:from-slate-900 dark:via-slate-900/50" />
        <div className="absolute inset-y-0 right-0 w-28 bg-gradient-to-l from-sky-50 to-transparent dark:from-brand-950" />
        <div className="absolute inset-0 hidden bg-slate-950/45 dark:block" />
      </div>
      <div className="pointer-events-none absolute -left-10 -top-16 h-48 w-48 rounded-full bg-brand-200/30 blur-3xl dark:bg-brand-500/10" aria-hidden />

      <div className="relative flex flex-col justify-center gap-3 p-5 sm:p-6 md:max-w-[60%] md:pr-0 xl:max-w-[52%]">
        <div>
          <h1 id="welcome-title" className="font-display text-[22px] font-bold leading-tight tracking-tight text-brand-900 sm:text-[28px] dark:text-white">
            {greeting()}, {name} <span aria-hidden>👋</span>
          </h1>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
            Welcome to GIMT College Management System. Here&rsquo;s what&rsquo;s happening today, <span className="whitespace-nowrap font-medium text-slate-700 dark:text-slate-200">{today}</span>.
          </p>
        </div>
        {attention.length > 0 && (
          <ul className="flex flex-wrap gap-2" aria-label="Needs your attention">
            {attention.slice(0, 3).map((a) => (
              <li key={a.key}>
                <Link
                  to={a.to}
                  className="inline-flex items-center gap-1.5 rounded-full border border-white/80 bg-white/80 px-3 py-1 text-xs font-medium text-slate-700 shadow-sm backdrop-blur transition hover:-translate-y-0.5 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-200"
                >
                  <a.icon className={clsx('h-3.5 w-3.5', a.tone)} aria-hidden />
                  {a.label}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Session chip */}
      <div className="relative mx-5 mb-5 flex items-center justify-between gap-3 rounded-xl bg-brand-900 px-4 py-3 text-white shadow-pop md:absolute md:right-3 md:top-1/2 md:m-0 md:w-[11.5rem] md:-translate-y-1/2 md:flex-col md:items-stretch md:bg-brand-900/90 md:backdrop-blur dark:bg-brand-950 md:dark:bg-brand-950/90">
        <div>
          <p className="text-[11px] font-medium uppercase tracking-wider text-brand-200">Academic Session</p>
          <p className="font-display text-xl font-bold leading-tight">{sessionName.replace('-', ' - ')}</p>
          <p className="mt-0.5 flex items-center gap-1.5 text-xs text-brand-100">
            <span className={clsx('h-1.5 w-1.5 rounded-full', data?.session.admissions_open ? 'bg-accent-400' : 'bg-slate-400')} aria-hidden />
            {data ? (data.session.admissions_open ? 'Admissions Open' : 'Admissions Closed') : 'Loading…'}
          </p>
        </div>
        <a
          href={appUrl('/')}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-accent-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-300 md:mt-3"
        >
          View Website <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </a>
      </div>
    </section>
  );
}
