import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRight, Award, BedDouble, BookMarked, Bus, CalendarCheck, CalendarDays, CircleCheck, FilePlus2, Library, MessageSquareText, NotebookPen, ScrollText, Ticket, TrendingUp, Wallet, type LucideIcon,
} from 'lucide-react';
import { Stagger } from '@/components/ui';
import { formatDate } from '@/lib/format';
import { EmptyBlock, Section } from '../../components/Ui';
import { areaPath } from '../../lib/config';
import { hhmm } from '../../lib/time';
import { PrintButton, dueLabel, money } from './ui';
import type { GpaPoint, StudentOverview } from './types';

/* ------------------------------------------------------------------ quick actions */

interface QuickAction {
  to: string;
  label: string;
  sub?: string;
  icon: LucideIcon;
  tone: string;
  badge?: number;
}

export function QuickActions({ data }: { data: StudentOverview | undefined }) {
  const admit = data?.admit_cards.find((a) => a.available);
  const items: QuickAction[] = [
    { to: 'timetable', label: 'Timetable', sub: 'This week', icon: CalendarDays, tone: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' },
    { to: 'attendance', label: 'Attendance', sub: 'Calendar & subjects', icon: CalendarCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
    { to: 'results', label: 'Results', sub: 'Marks & marksheets', icon: Award, tone: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' },
    { to: 'exams', label: admit ? 'Admit card' : 'Exams', sub: admit ? 'Ready to print' : 'Date sheets', icon: admit ? Ticket : NotebookPen, tone: 'bg-flame-50 text-flame-700 dark:bg-flame-500/15 dark:text-flame-300' },
    { to: 'fees', label: 'Pay fees', sub: data?.fees.overdue_amount ? `${money(data.fees.overdue_amount)} overdue` : 'Invoices & receipts', icon: Wallet, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', badge: data?.fees.overdue_count },
    { to: 'library', label: 'Library', sub: data?.library.issued ? `${data.library.issued} book${data.library.issued === 1 ? '' : 's'} with you` : 'Loans & fines', icon: Library, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', badge: data?.library.overdue },
    { to: 'certificates', label: 'Certificates', sub: data?.counts.pending_certificates ? `${data.counts.pending_certificates} pending` : 'Request bonafide', icon: ScrollText, tone: 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' },
    { to: 'helpdesk', label: 'Help desk', sub: data?.counts.open_tickets ? `${data.counts.open_tickets} open` : 'Raise a grievance', icon: MessageSquareText, tone: 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' },
  ];
  if (data?.hostel) items.push({ to: 'hostel', label: 'Hostel', sub: `Room ${data.hostel.room_no}`, icon: BedDouble, tone: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300', badge: data.counts.open_hostel_complaints || undefined });
  if (data?.transport) items.push({ to: 'transport', label: 'Transport', sub: data.transport.pickup_time ? `Pickup ${hhmm(data.transport.pickup_time)}` : data.transport.route, icon: Bus, tone: 'bg-lime-50 text-lime-700 dark:bg-lime-500/15 dark:text-lime-300' });
  return (
    <nav aria-label="Quick actions">
      <Stagger
        className="-mx-4 flex snap-x gap-2 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:grid sm:grid-cols-[repeat(auto-fit,minmax(104px,1fr))] sm:gap-3 sm:overflow-visible sm:px-0 sm:pb-0"
        step={35}
        itemClassName="h-full w-[88px] shrink-0 snap-start sm:w-auto"
      >
        {items.map((a) => (
          <Link key={a.to} to={areaPath('student', a.to)} className="card lift group relative flex h-full flex-col items-center gap-2 px-1.5 py-3 text-center sm:px-2 sm:py-4">
            <span className={clsx('inline-flex h-10 w-10 items-center justify-center rounded-2xl transition group-hover:scale-105 sm:h-11 sm:w-11', a.tone)}>
              <a.icon className="h-5 w-5" aria-hidden />
            </span>
            <span className="min-w-0">
              <span className="block truncate text-[12px] font-semibold leading-tight text-slate-800 dark:text-slate-100 sm:text-[13px]">{a.label}</span>
              {a.sub && <span className="mt-0.5 hidden truncate text-[11px] text-slate-500 dark:text-slate-400 sm:block">{a.sub}</span>}
            </span>
            {a.badge ? <span className="absolute right-2 top-2 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{a.badge}</span> : null}
          </Link>
        ))}
      </Stagger>
    </nav>
  );
}

/* ------------------------------------------------------------------ CGPA trend sparkline (no chart library on the dashboard) */

function Sparkline({ points }: { points: GpaPoint[] }) {
  const w = 300;
  const h = 110;
  const pad = 14;
  const vals = points.flatMap((p) => [p.sgpa, p.cgpa]).filter((v): v is number => v !== null);
  const min = Math.max(0, Math.floor(Math.min(...vals) - 0.5));
  const max = Math.min(10, Math.ceil(Math.max(...vals) + 0.5));
  const x = (i: number) => (points.length === 1 ? w / 2 : pad + (i * (w - pad * 2)) / (points.length - 1));
  const y = (v: number) => h - pad - ((v - min) / Math.max(0.1, max - min)) * (h - pad * 2);
  const line = (key: 'sgpa' | 'cgpa') =>
    points
      .map((p, i) => (p[key] === null ? null : `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p[key] as number).toFixed(1)}`))
      .filter(Boolean)
      .join(' ');
  const cg = line('cgpa');
  const area = points.length > 1 ? `${cg} L${x(points.length - 1)},${h - pad} L${x(0)},${h - pad} Z` : '';
  return (
    <svg viewBox={`0 0 ${w} ${h}`} className="h-28 w-full" role="img" aria-label={`CGPA trend: ${points.map((p) => `${p.label} ${p.cgpa ?? '—'}`).join(', ')}`}>
      <defs>
        <linearGradient id="cg-fill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#0B2A5B" stopOpacity="0.22" />
          <stop offset="100%" stopColor="#0B2A5B" stopOpacity="0" />
        </linearGradient>
      </defs>
      {area && <path d={area} fill="url(#cg-fill)" className="dark:opacity-60" />}
      <path d={line('sgpa')} fill="none" stroke="#F05A28" strokeWidth="2" strokeDasharray="5 4" strokeLinecap="round" />
      <path d={cg} fill="none" strokeWidth="2.75" strokeLinecap="round" strokeLinejoin="round" className="stroke-brand-900 dark:stroke-brand-300" />
      {points.map((p, i) =>
        p.cgpa !== null ? <circle key={i} cx={x(i)} cy={y(p.cgpa)} r="3.5" className="fill-white stroke-brand-900 dark:fill-slate-900 dark:stroke-brand-300" strokeWidth="2" /> : null,
      )}
    </svg>
  );
}

export function GpaTrendCard({ data }: { data: StudentOverview | undefined }) {
  const pts = data?.gpa_trend ?? [];
  const last = pts[pts.length - 1];
  const prev = pts[pts.length - 2];
  const delta = last?.cgpa !== null && last?.cgpa !== undefined && prev?.cgpa !== null && prev?.cgpa !== undefined ? +(last.cgpa - prev.cgpa).toFixed(2) : null;
  return (
    <Section
      title="CGPA trend"
      subtitle={last ? `After semester ${last.semester}` : 'Semester-wise'}
      icon={TrendingUp}
      action={
        <Link to={areaPath('student', 'results')} className="link inline-flex items-center gap-1 text-[13px]">
          Results <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </Link>
      }
    >
      {!data ? (
        <div className="skeleton h-32 rounded-xl" />
      ) : pts.length === 0 ? (
        <EmptyBlock compact icon={Award} title="No semester results yet" text="Your CGPA journey starts with your first end-semester result." />
      ) : (
        <div>
          <div className="flex items-end justify-between gap-3">
            <div>
              <p className="font-display text-3xl font-extrabold text-slate-900 dark:text-white">{last.cgpa?.toFixed(2) ?? '—'}</p>
              <p className="text-xs text-slate-500">
                CGPA{last.sgpa !== null ? ` · SGPA ${last.sgpa.toFixed(2)}` : ''}
              </p>
            </div>
            {delta !== null && <span className={clsx('badge', delta >= 0 ? 'badge-green' : 'badge-red')}>{delta >= 0 ? `▲ ${delta.toFixed(2)}` : `▼ ${Math.abs(delta).toFixed(2)}`} vs last sem</span>}
          </div>
          <Sparkline points={pts} />
          <div className="flex justify-between text-[10px] font-semibold uppercase tracking-wider text-slate-400">
            {pts.map((p) => (
              <span key={p.semester}>{p.label}</span>
            ))}
          </div>
          <p className="mt-2 flex gap-4 text-[11px] text-slate-500">
            <span className="inline-flex items-center gap-1.5">
              <span className="h-0.5 w-4 rounded bg-brand-900 dark:bg-brand-300" aria-hidden /> CGPA
            </span>
            <span className="inline-flex items-center gap-1.5">
              <span className="h-0.5 w-4 rounded border-t-2 border-dashed border-flame-500" aria-hidden /> SGPA
            </span>
          </p>
        </div>
      )}
    </Section>
  );
}

/* ------------------------------------------------------------------ fee dues + library loans */

export function FeeDuesCard({ data }: { data: StudentOverview | undefined }) {
  const f = data?.fees;
  return (
    <Section
      title="Fee dues"
      subtitle="Next installment"
      icon={Wallet}
      action={
        <Link to={areaPath('student', 'fees')} className="link inline-flex items-center gap-1 text-[13px]">
          Fees <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </Link>
      }
    >
      {!f ? (
        <div className="skeleton h-32 rounded-xl" />
      ) : !f.next_due && !f.overdue_amount ? (
        <EmptyBlock compact icon={CircleCheck} title="No dues right now" text="All your invoices are paid. New invoices will show up here." />
      ) : (
        <div className="space-y-3">
          {f.overdue_amount > 0 && (
            <div className="rounded-2xl bg-red-50 p-3.5 dark:bg-red-500/10">
              <p className="text-[11px] font-bold uppercase tracking-wider text-red-600 dark:text-red-300">Overdue</p>
              <p className="font-display text-2xl font-extrabold text-red-700 dark:text-red-200">{money(f.overdue_amount)}</p>
              <p className="text-xs text-red-700/80 dark:text-red-200/80">Since {formatDate(f.oldest_overdue)} · late fee applies</p>
            </div>
          )}
          {f.next_due && (
            <div className="flex items-center gap-3 rounded-2xl border border-slate-200/80 p-3.5 dark:border-slate-800">
              <div className="min-w-0 flex-1">
                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Next due · {formatDate(f.next_due.due_date)}</p>
                <p className="font-display text-xl font-extrabold text-slate-900 dark:text-white">{money(f.next_due.amount)}</p>
                <p className="truncate text-xs text-slate-500">
                  {f.next_due.title}
                  {f.next_due.installment_no ? ` · installment ${f.next_due.installment_no}` : ''}
                </p>
              </div>
              <span className={clsx('badge shrink-0', (f.next_due.days_left ?? 99) <= 7 ? 'badge-amber' : 'badge-slate')}>{dueLabel(f.next_due.days_left)}</span>
            </div>
          )}
          <Link to={areaPath('student', 'fees')} className="btn btn-primary btn-sm w-full">
            <Wallet className="h-4 w-4" aria-hidden /> How to pay
          </Link>
        </div>
      )}
    </Section>
  );
}

export function LibraryLoansCard({ data }: { data: StudentOverview | undefined }) {
  const l = data?.library;
  return (
    <Section
      title="Library loans"
      subtitle={l?.member ? `${l.issued} book${l.issued === 1 ? '' : 's'} with you` : undefined}
      icon={BookMarked}
      bodyClassName="p-2 sm:p-3"
      action={
        <Link to={areaPath('student', 'library')} className="link inline-flex items-center gap-1 text-[13px]">
          Library <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </Link>
      }
    >
      {!l ? (
        <div className="skeleton m-2 h-28 rounded-xl" />
      ) : !l.member ? (
        <EmptyBlock compact icon={Library} title="Not a library member yet" text="Enrol at the library counter with your ID card." />
      ) : l.loans.length === 0 ? (
        <EmptyBlock compact icon={BookMarked} title="No books due" text={l.unpaid_fines ? `Unpaid fines: ${money(l.unpaid_fines)}` : 'Borrow books at the library counter.'} />
      ) : (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {l.loans.map((b) => {
            const late = (b.days_left ?? 0) < 0;
            return (
              <li key={b.id} className="flex items-center gap-3 px-2 py-2.5">
                <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', late ? 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300' : 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300')}>
                  <BookMarked className="h-4 w-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{b.title}</p>
                  <p className="text-xs text-slate-500">Due {formatDate(b.due_date)}</p>
                </div>
                <span className={clsx('badge shrink-0', late ? 'badge-red' : (b.days_left ?? 9) <= 3 ? 'badge-amber' : 'badge-slate')}>{dueLabel(b.days_left)}</span>
              </li>
            );
          })}
        </ul>
      )}
    </Section>
  );
}

/** Admit-card shortcut for the exam schedule card header. */
export function AdmitCardAction({ data }: { data: StudentOverview | undefined }) {
  const a = data?.admit_cards.find((x) => x.available);
  if (!a) {
    return (
      <Link to={areaPath('student', 'exams')} className="link inline-flex items-center gap-1 text-[13px]">
        Exams <ArrowRight className="h-3.5 w-3.5" aria-hidden />
      </Link>
    );
  }
  return (
    <PrintButton path={`admit-card/${a.exam_id}`} size="xs" variant="soft" icon={Ticket}>
      Admit card
    </PrintButton>
  );
}

export function CertificateNudge({ data }: { data: StudentOverview | undefined }) {
  if (!data || data.counts.rejected_documents === 0) return null;
  return (
    <Link to={areaPath('student', 'documents')} className="flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 transition hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
      <FilePlus2 className="h-4 w-4 shrink-0" aria-hidden />
      <span className="flex-1">
        {data.counts.rejected_documents} of your documents {data.counts.rejected_documents === 1 ? 'was' : 'were'} rejected — submit a corrected copy at the academic office.
      </span>
      <ArrowRight className="h-4 w-4 shrink-0" aria-hidden />
    </Link>
  );
}
