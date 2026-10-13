import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  Activity, Award, BadgeCheck, BedDouble, BookOpen, Bus, ImageIcon, Inbox, Mail, MessageSquareWarning, UsersRound, BriefcaseBusiness, CalendarCheck, CalendarClock, CalendarDays, CalendarPlus, CircleCheck, ClipboardList, Database, FileText, Globe, GraduationCap,
  HandCoins, HardDrive, History, IdCard, IndianRupee, Library, Megaphone, MessageCircle, Pencil, Plus, Receipt, Send, Server, Settings, ShieldCheck, Trash2, Trophy, UserCog, UserPlus,
  Users, type LucideIcon,
} from 'lucide-react';
import { Avatar, Button, Dropdown, ProgressBar, StatusBadge, toneClasses, type Tone } from '@/components/ui';
import { formatDate, formatMoney, formatNumber, humanFileSize, labelize, timeAgo } from '@/lib/format';
import { Panel, PanelEmpty, PeriodSelect, ViewAll } from './Panel';
import type { ActivityItem, DashboardLists, SystemStatus, UpcomingCategory, UpcomingItem } from './types';

type Can = (module: string, action?: 'view' | 'create' | 'edit') => boolean;

/* ------------------------------------------------------------ Recent activities */
const MODULE_ICON: Record<string, { icon: LucideIcon; tone: Tone }> = {
  admissions: { icon: UserPlus, tone: 'blue' }, enquiries: { icon: MessageCircle, tone: 'cyan' }, students: { icon: GraduationCap, tone: 'navy' },
  faculty: { icon: Users, tone: 'purple' }, staff: { icon: Users, tone: 'purple' }, fees: { icon: IndianRupee, tone: 'green' }, expenses: { icon: Receipt, tone: 'amber' },
  certificates: { icon: BadgeCheck, tone: 'orange' }, results: { icon: Award, tone: 'purple' }, examination: { icon: ClipboardList, tone: 'blue' },
  attendance: { icon: CalendarCheck, tone: 'amber' }, notices: { icon: Megaphone, tone: 'pink' }, events: { icon: CalendarPlus, tone: 'pink' },
  library: { icon: Library, tone: 'amber' }, placement: { icon: BriefcaseBusiness, tone: 'cyan' }, academics: { icon: BookOpen, tone: 'navy' },
  timetable: { icon: CalendarDays, tone: 'navy' }, users: { icon: UserCog, tone: 'slate' }, roles: { icon: ShieldCheck, tone: 'slate' },
  security: { icon: ShieldCheck, tone: 'red' }, backup: { icon: Database, tone: 'slate' }, settings: { icon: Settings, tone: 'slate' },
  cms: { icon: Globe, tone: 'cyan' }, blog: { icon: FileText, tone: 'cyan' }, profile: { icon: UserCog, tone: 'slate' }, hostel: { icon: BedDouble, tone: 'amber' },
  transport: { icon: Bus, tone: 'orange' }, alumni: { icon: UsersRound, tone: 'pink' }, communication: { icon: Mail, tone: 'cyan' }, contact_messages: { icon: Inbox, tone: 'cyan' },
  feedback: { icon: MessageSquareWarning, tone: 'red' }, media: { icon: ImageIcon, tone: 'cyan' }, seo: { icon: Globe, tone: 'slate' }, messages: { icon: Mail, tone: 'navy' },
};
const ACTION_ICON: Record<string, LucideIcon> = { create: Plus, update: Pencil, delete: Trash2, approve: CircleCheck, verify: CircleCheck, publish: Send };

function activityVisual(a: ActivityItem): { icon: LucideIcon; tone: Tone } {
  const m = MODULE_ICON[a.module];
  if (a.action === 'delete') return { icon: Trash2, tone: 'red' };
  if (m) return m;
  return { icon: ACTION_ICON[a.action] ?? Activity, tone: 'slate' };
}

export function ActivitiesCard({ items, can }: { items: ActivityItem[]; can: Can }) {
  return (
    <Panel title="Recent Activities" subtitle="Latest changes across the campus" icon={History} action={can('activity_logs') ? <ViewAll to="/activity-logs" /> : undefined}>
      {items.length === 0 ? (
        <PanelEmpty icon={History} title="No recent activity" description="Admissions, payments, results and other changes will show up here." />
      ) : (
        <ol className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {items.map((a) => {
            const v = activityVisual(a);
            return (
              <li key={a.id} className="flex items-start gap-3 py-2.5">
                <span className={clsx('mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full', toneClasses[v.tone].icon)}>
                  <v.icon className="h-4 w-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="line-clamp-2 text-[13px] font-medium leading-snug text-slate-800 dark:text-slate-100">{a.description}</p>
                  <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                    {a.user} · {a.module_label}
                  </p>
                </div>
                <time dateTime={a.created_at} title={formatDate(a.created_at)} className="shrink-0 whitespace-nowrap pt-0.5 text-[11px] text-slate-400">
                  {timeAgo(a.created_at)}
                </time>
              </li>
            );
          })}
        </ol>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Upcoming events & deadlines */
const CATEGORY: Record<UpcomingCategory, { label: string; pill: string; tile: string }> = {
  admission: { label: 'Admission', pill: 'bg-rose-50 text-rose-700 ring-rose-600/15 dark:bg-rose-500/10 dark:text-rose-300', tile: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' },
  exam: { label: 'Examination', pill: 'bg-blue-50 text-blue-700 ring-blue-600/15 dark:bg-blue-500/10 dark:text-blue-300', tile: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' },
  finance: { label: 'Finance', pill: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300', tile: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' },
  event: { label: 'Event', pill: 'bg-violet-50 text-violet-700 ring-violet-600/15 dark:bg-violet-500/10 dark:text-violet-300', tile: 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' },
  result: { label: 'Result', pill: 'bg-orange-50 text-orange-700 ring-orange-600/15 dark:bg-orange-500/10 dark:text-orange-300', tile: 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' },
  placement: { label: 'Placement', pill: 'bg-cyan-50 text-cyan-700 ring-cyan-600/15 dark:bg-cyan-500/10 dark:text-cyan-300', tile: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300' },
  holiday: { label: 'Holiday', pill: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300', tile: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' },
};

function DateTile({ date, tile }: { date: string; tile: string }) {
  const d = new Date(`${date}T00:00:00`);
  return (
    <span className={clsx('flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-xl leading-none', tile)} aria-hidden>
      <span className="font-display text-base font-bold">{String(d.getDate()).padStart(2, '0')}</span>
      <span className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide">{d.toLocaleDateString('en-GB', { month: 'short' })}</span>
    </span>
  );
}

export function UpcomingCard({ items, can }: { items: UpcomingItem[]; can: Can }) {
  return (
    <Panel title="Upcoming Events & Deadlines" subtitle="Next 60 days" icon={CalendarClock} action={can('events') ? <ViewAll to="/events" /> : undefined}>
      {items.length === 0 ? (
        <PanelEmpty icon={CalendarClock} title="Nothing scheduled" description="Exams, fee due dates, interviews, events and holidays in the next 60 days appear here." action={can('events', 'create') ? <Button size="sm" to="/events?add=1">Add event</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {items.map((it, i) => {
            const c = CATEGORY[it.category] ?? CATEGORY.event;
            return (
              <li key={`${it.date}-${i}`}>
                <Link to={it.url} className="group -mx-2 flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:hover:bg-slate-800/50">
                  <DateTile date={it.date} tile={c.tile} />
                  <span className="min-w-0 flex-1">
                    <span className="line-clamp-2 block text-[13px] font-semibold leading-snug text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{it.title}</span>
                    <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                      <span className="sr-only">{formatDate(it.date)} · </span>
                      {it.subtitle}
                      {it.time && it.time !== '00:00' ? ` · ${it.time}` : ''}
                    </span>
                  </span>
                  <span className={clsx('hidden shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset sm:inline-flex', c.pill)}>{c.label}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Top programs */
export function TopProgramsCard({ data }: { data: NonNullable<DashboardLists['top_programs']> }) {
  const [mode, setMode] = useState<'admitted' | 'applications'>('admitted');
  const rows = (mode === 'applications' ? data.applications : data.admitted) ?? [];
  const max = Math.max(1, ...rows.map((r) => r.value));
  const tones: Tone[] = ['blue', 'green', 'orange', 'purple', 'cyan', 'pink'];
  return (
    <Panel
      title="Top Programs by Admissions"
      subtitle={mode === 'admitted' ? 'Students admitted this session' : 'Applications received this session'}
      icon={Trophy}
      action={data.applications ? <PeriodSelect label="Top programs measure" value={mode} onChange={setMode} options={[{ value: 'admitted', label: 'This Session' }, { value: 'applications', label: 'Applications' }]} /> : undefined}
    >
      {rows.length === 0 ? (
        <PanelEmpty icon={Trophy} title="No admissions this session yet" />
      ) : (
        <ol className="space-y-3.5">
          {rows.map((r, i) => (
            <li key={r.id} className="flex items-center gap-3">
              <span className={clsx('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', i === 0 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300')}>{i + 1}</span>
              <div className="min-w-0 flex-1">
                <div className="flex items-baseline justify-between gap-2">
                  <span className="truncate text-[13px] font-semibold text-slate-800 dark:text-slate-100" title={r.name}>
                    {r.label}
                    {r.category && <span className="ml-1 font-normal text-slate-400">({r.category})</span>}
                  </span>
                  <span className="text-sm font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.value)}</span>
                </div>
                <ProgressBar value={(r.value / max) * 100} tone={tones[i % tones.length]} className="mt-1.5 !h-1.5" label={`${r.label}: ${r.value}`} />
              </div>
            </li>
          ))}
        </ol>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Recent records */
function RowLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link to={to} className="group -mx-2 flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:hover:bg-slate-800/50">
      {children}
    </Link>
  );
}

export function RecentAdmissionsCard({ rows, can }: { rows: NonNullable<DashboardLists['recent_admissions']>; can: Can }) {
  return (
    <Panel title="Recent Admissions" subtitle="Latest applications received" icon={UserPlus} action={<ViewAll to="/admissions/list" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={UserPlus} title="No applications yet" action={can('admissions', 'create') ? <Button size="sm" to="/admissions/new">Create admission</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/admissions/${r.id}`}>
                <Avatar name={r.name} src={r.photo} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-semibold text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{r.name}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{[r.application_no, r.program].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-1">
                  <StatusBadge status={r.stage} />
                  <span className="text-[11px] text-slate-400">{timeAgo(r.created_at)}</span>
                </span>
              </RowLink>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

export function RecentEnquiriesCard({ rows }: { rows: NonNullable<DashboardLists['recent_enquiries']> }) {
  return (
    <Panel title="Recent Enquiries" subtitle="Counselling and website enquiries" icon={MessageCircle} action={<ViewAll to="/enquiries" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={MessageCircle} title="No enquiries yet" description="Website, walk-in and phone enquiries will be listed here." />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/enquiries?q=${encodeURIComponent(r.phone || r.name)}`}>
                <Avatar name={r.name} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-semibold text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{r.name}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{[r.program, r.city, r.source ? labelize(r.source) : null].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-1">
                  <StatusBadge status={r.status} />
                  <span className="text-[11px] text-slate-400">{timeAgo(r.created_at)}</span>
                </span>
              </RowLink>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

const MODE_LABEL: Record<string, string> = { upi: 'UPI', dd: 'Demand Draft', neft: 'NEFT', rtgs: 'RTGS', imps: 'IMPS', card: 'Card', online: 'Online' };

export function RecentPaymentsCard({ rows, can }: { rows: NonNullable<DashboardLists['recent_payments']>; can: Can }) {
  return (
    <Panel title="Recent Payments" subtitle="Latest fee receipts" icon={Receipt} action={<ViewAll to="/fees/payments" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={Receipt} title="No payments yet" action={can('fees', 'create') ? <Button size="sm" to="/fees/collect">Collect fee</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/fees/payments?q=${encodeURIComponent(r.receipt_no)}`}>
                <Avatar name={r.name} src={r.photo} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-semibold text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{r.name}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{[r.receipt_no, r.program, MODE_LABEL[r.mode] ?? labelize(r.mode)].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-0.5">
                  <span className={clsx('text-sm font-bold tabular-nums', r.status === 'success' ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 line-through')}>{formatMoney(r.amount)}</span>
                  <span className="text-[11px] text-slate-400">{formatDate(r.payment_date)}</span>
                </span>
              </RowLink>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

export function RecentNoticesCard({ rows, can }: { rows: NonNullable<DashboardLists['recent_notices']>; can: Can }) {
  return (
    <Panel title="Recent Notices" subtitle="Circulars and announcements" icon={Megaphone} action={<ViewAll to="/notices" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={Megaphone} title="No notices yet" action={can('notices', 'create') ? <Button size="sm" to="/notices?add=1">Create notice</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/notices?q=${encodeURIComponent(r.title)}`}>
                <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', r.is_pinned ? toneClasses.amber.icon : toneClasses.pink.icon)}>
                  <Megaphone className="h-4 w-4" aria-hidden />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-semibold text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{r.title}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{[labelize(r.category), `For ${labelize(r.audience).toLowerCase()}`, formatDate(r.date)].join(' · ')}</span>
                </span>
                <StatusBadge status={r.status} />
              </RowLink>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Quick actions */
interface QuickAction {
  label: string;
  icon: LucideIcon;
  to: string;
  perm: [string, 'view' | 'create'];
  tone: Tone;
}

const QUICK: QuickAction[] = [
  { label: 'Add Student', icon: GraduationCap, to: '/students/new', perm: ['students', 'create'], tone: 'green' },
  { label: 'New Admission', icon: UserPlus, to: '/admissions/new', perm: ['admissions', 'create'], tone: 'blue' },
  { label: 'Collect Fees', icon: HandCoins, to: '/fees/collect', perm: ['fees', 'create'], tone: 'amber' },
  { label: 'Mark Attendance', icon: CalendarCheck, to: '/attendance?tab=mark', perm: ['attendance', 'create'], tone: 'orange' },
  { label: 'Create Notice', icon: Megaphone, to: '/notices?add=1', perm: ['notices', 'create'], tone: 'purple' },
  { label: 'Generate ID Card', icon: IdCard, to: '/students/id-cards', perm: ['students', 'view'], tone: 'cyan' },
  { label: 'Issue Certificate', icon: BadgeCheck, to: '/certificates?add=1', perm: ['certificates', 'create'], tone: 'pink' },
  { label: 'Send Email/SMS', icon: Send, to: '/newsletter?add=1', perm: ['communication', 'create'], tone: 'navy' },
];

const MORE: QuickAction[] = [
  { label: 'Add Faculty', icon: Users, to: '/faculty?add=1', perm: ['faculty', 'create'], tone: 'purple' },
  { label: 'Create Exam', icon: ClipboardList, to: '/examination?add=1', perm: ['examination', 'create'], tone: 'blue' },
  { label: 'Add Program', icon: BookOpen, to: '/academics?tab=programs&add=1', perm: ['academics', 'create'], tone: 'navy' },
  { label: 'Create Timetable', icon: CalendarDays, to: '/timetable', perm: ['timetable', 'create'], tone: 'cyan' },
  { label: 'Add Event', icon: CalendarPlus, to: '/events?add=1', perm: ['events', 'create'], tone: 'pink' },
];

/** True when the user may use at least one quick action (the card is hidden otherwise). */
export function hasQuickActions(can: Can): boolean {
  return [...QUICK, ...MORE].some((a) => can(a.perm[0], a.perm[1]));
}

export function QuickActionsCard({ can }: { can: Can }) {
  const actions = QUICK.filter((a) => can(a.perm[0], a.perm[1]));
  const more = MORE.filter((a) => can(a.perm[0], a.perm[1]));
  if (!actions.length && !more.length) return null;
  return (
    <Panel
      title="Quick Actions"
      subtitle="Frequent tasks, one click away"
      icon={Plus}
      action={more.length ? <Dropdown label="More quick actions" trigger={<span className="inline-flex items-center gap-1">More</span>} triggerClassName="btn btn-secondary btn-xs" items={more.map((m) => ({ label: m.label, icon: m.icon, to: m.to }))} /> : undefined}
    >
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {(actions.length ? actions : more).map((a) => (
          <li key={a.label}>
            <Link
              to={a.to}
              className={clsx(
                'group flex h-full flex-col items-center justify-center gap-2 rounded-xl border border-transparent px-2 py-3.5 text-center transition duration-200 hover:-translate-y-0.5 hover:border-slate-200 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:hover:border-slate-700',
                toneClasses[a.tone].bg,
              )}
            >
              <span className={clsx('inline-flex h-10 w-10 items-center justify-center rounded-xl transition group-hover:scale-105', toneClasses[a.tone].icon)}>
                <a.icon className="h-5 w-5" aria-hidden />
              </span>
              <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">{a.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/* ------------------------------------------------------------ System status */
function StatusDot({ ok, warn }: { ok: boolean; warn?: boolean }) {
  return (
    <span className="relative inline-flex h-2.5 w-2.5" aria-hidden>
      {ok && !warn && <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60 motion-safe:animate-ping" />}
      <span className={clsx('relative inline-flex h-2.5 w-2.5 rounded-full', ok ? (warn ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-red-500')} />
    </span>
  );
}

export function SystemStatusCard({ data, can }: { data: SystemStatus; can: Can }) {
  const b = data.backup;
  const s = data.storage;
  const backupLabel = b.status === 'missing' ? 'No backup yet' : formatDate(b.last_at);
  const diskPct = s.disk_percent ?? 0;
  const tiles = [
    { key: 'server', label: 'Server', icon: Server, ok: data.server.status === 'online', warn: false, value: 'Online', sub: `PHP ${data.server.php}` },
    { key: 'db', label: 'Database', icon: Database, ok: data.database.status === 'connected', warn: false, value: data.database.status === 'connected' ? 'Connected' : 'Error', sub: data.database.size ? `${humanFileSize(data.database.size)} · ${formatNumber(data.database.tables ?? 0)} tables` : data.database.version ?? '' },
    { key: 'backup', label: 'Backup', icon: History, ok: b.status !== 'missing', warn: b.status === 'stale' || !!b.last_failed_at, value: backupLabel, sub: b.last_failed_at ? `Last attempt failed ${timeAgo(b.last_failed_at)}` : b.age_days !== null ? `${b.age_days === 0 ? 'Today' : `${b.age_days} day${b.age_days === 1 ? '' : 's'} ago`} · ${labelize(b.schedule)}` : `Schedule: ${labelize(b.schedule)}` },
  ];
  return (
    <Panel title="System Status" subtitle={`Checked ${timeAgo(data.server.time)} · ${data.server.timezone}`} icon={ShieldCheck} action={can('backup') ? <ViewAll to="/backup" label="Backups" /> : undefined}>
      <div className="grid grid-cols-2 gap-3">
        {tiles.map((t) => (
          <div key={t.key} className="rounded-xl border border-slate-100 bg-slate-50/60 p-3 dark:border-slate-800 dark:bg-slate-800/40">
            <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
              <t.icon className="h-3.5 w-3.5" aria-hidden /> {t.label}
            </p>
            <p className="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <StatusDot ok={t.ok} warn={t.warn} />
              <span className="truncate">{t.value}</span>
            </p>
            <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400" title={t.sub}>{t.sub}</p>
          </div>
        ))}
        <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3 dark:border-slate-800 dark:bg-slate-800/40">
          <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            <HardDrive className="h-3.5 w-3.5" aria-hidden /> Storage
          </p>
          <p className="mt-2 text-sm font-semibold text-slate-800 dark:text-slate-100">{s.disk_percent !== null ? `${Math.round(diskPct)}% Used` : humanFileSize(s.used)}</p>
          <ProgressBar value={diskPct} tone={diskPct > 90 ? 'red' : diskPct > 75 ? 'amber' : 'green'} className="mt-1.5 !h-1.5" label="Disk space used" />
          <p className="mt-1 truncate text-[11px] text-slate-500 dark:text-slate-400" title={`Uploads ${humanFileSize(s.uploads)} (${formatNumber(s.upload_files)} files) · app storage ${humanFileSize(s.storage)}`}>
            App files {humanFileSize(s.used)}
          </p>
        </div>
      </div>
    </Panel>
  );
}
