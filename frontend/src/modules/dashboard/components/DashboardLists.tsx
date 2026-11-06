import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  Activity, Award, BadgeCheck, BedDouble, BookOpen, Bus, ImageIcon, Inbox, Mail, MessageSquareWarning, UsersRound, BriefcaseBusiness, CalendarCheck, CalendarClock, CalendarDays, CalendarPlus, CircleCheck, ClipboardList, Database, FileText, Globe, GraduationCap,
  HandCoins, HardDrive, History, IdCard, IndianRupee, Library, Megaphone, MessageCircle, Pencil, Plus, Receipt, Send, Server, Settings, ShieldCheck, Trash2, Trophy, UserCog, UserPlus,
  Pin, Users, Zap, type LucideIcon,
} from 'lucide-react';
import { Avatar, Button, Dropdown, StatusBadge } from '@/components/ui';
import { formatDate, formatMoney, formatNumber, humanFileSize, initials, labelize, timeAgo } from '@/lib/format';
import { HUES, hueAt, hueFor, type Hue } from './hues';
import { Panel, PanelEmpty, PeriodSelect, ViewAll } from './Panel';
import type { ActivityItem, DashboardLists, SystemStatus, UpcomingCategory, UpcomingItem } from './types';

type Can = (module: string, action?: 'view' | 'create' | 'edit') => boolean;

/** Photo when there is one, otherwise initials on a gradient disc in a stable hue per name. */
function HueAvatar({ name, src }: { name: string; src?: string | null }) {
  if (src) return <Avatar name={name} src={src} size="md" />;
  return (
    <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold', HUES[hueFor(name)].chip)} aria-hidden>
      {initials(name)}
    </span>
  );
}

/* ------------------------------------------------------------ Recent activities */
const MODULE_ICON: Record<string, { icon: LucideIcon; hue: Hue }> = {
  admissions: { icon: UserPlus, hue: 'indigo' }, enquiries: { icon: MessageCircle, hue: 'sky' }, students: { icon: GraduationCap, hue: 'navy' },
  faculty: { icon: Users, hue: 'violet' }, staff: { icon: Users, hue: 'violet' }, fees: { icon: IndianRupee, hue: 'teal' }, expenses: { icon: Receipt, hue: 'amber' },
  certificates: { icon: BadgeCheck, hue: 'orange' }, results: { icon: Award, hue: 'violet' }, examination: { icon: ClipboardList, hue: 'indigo' },
  attendance: { icon: CalendarCheck, hue: 'sky' }, notices: { icon: Megaphone, hue: 'rose' }, events: { icon: CalendarPlus, hue: 'rose' },
  library: { icon: Library, hue: 'amber' }, placement: { icon: BriefcaseBusiness, hue: 'teal' }, academics: { icon: BookOpen, hue: 'navy' },
  timetable: { icon: CalendarDays, hue: 'navy' }, users: { icon: UserCog, hue: 'slate' }, roles: { icon: ShieldCheck, hue: 'slate' },
  security: { icon: ShieldCheck, hue: 'rose' }, backup: { icon: Database, hue: 'slate' }, settings: { icon: Settings, hue: 'slate' },
  cms: { icon: Globe, hue: 'sky' }, blog: { icon: FileText, hue: 'sky' }, profile: { icon: UserCog, hue: 'slate' }, hostel: { icon: BedDouble, hue: 'amber' },
  transport: { icon: Bus, hue: 'orange' }, alumni: { icon: UsersRound, hue: 'rose' }, communication: { icon: Mail, hue: 'sky' }, contact_messages: { icon: Inbox, hue: 'sky' },
  feedback: { icon: MessageSquareWarning, hue: 'orange' }, media: { icon: ImageIcon, hue: 'sky' }, seo: { icon: Globe, hue: 'slate' }, messages: { icon: Mail, hue: 'navy' },
};
const ACTION_ICON: Record<string, LucideIcon> = { create: Plus, update: Pencil, delete: Trash2, approve: CircleCheck, verify: CircleCheck, publish: Send };

function activityVisual(a: ActivityItem): { icon: LucideIcon; hue: Hue } {
  const m = MODULE_ICON[a.module];
  if (a.action === 'delete') return { icon: Trash2, hue: 'rose' };
  if (m) return m;
  return { icon: ACTION_ICON[a.action] ?? Activity, hue: 'slate' };
}

export function ActivitiesCard({ items, can }: { items: ActivityItem[]; can: Can }) {
  return (
    <Panel title="Recent Activities" subtitle="Latest changes across the campus" icon={History} hue="indigo" action={can('activity_logs') ? <ViewAll to="/activity-logs" /> : undefined}>
      {items.length === 0 ? (
        <PanelEmpty icon={History} title="No recent activity" description="Admissions, payments, results and other changes will show up here." />
      ) : (
        <ol className="relative -my-1">
          <span className="absolute bottom-3 left-4 top-3 w-px bg-gradient-to-b from-slate-200 via-slate-200 to-transparent dark:from-slate-700 dark:via-slate-800" aria-hidden />
          {items.map((a) => {
            const v = activityVisual(a);
            return (
              <li key={a.id} className="relative flex items-start gap-3 py-2.5">
                <span className={clsx('relative mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900', HUES[v.hue].chip)}>
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
const CATEGORY: Record<UpcomingCategory, { label: string; hue: Hue }> = {
  admission: { label: 'Admission', hue: 'rose' },
  exam: { label: 'Examination', hue: 'indigo' },
  finance: { label: 'Finance', hue: 'teal' },
  event: { label: 'Event', hue: 'violet' },
  result: { label: 'Result', hue: 'orange' },
  placement: { label: 'Placement', hue: 'sky' },
  holiday: { label: 'Holiday', hue: 'amber' },
};

function DateTile({ date, hue }: { date: string; hue: Hue }) {
  const d = new Date(`${date}T00:00:00`);
  return (
    <span className={clsx('flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-xl leading-none', HUES[hue].chip)} aria-hidden>
      <span className="font-display text-base font-bold">{String(d.getDate()).padStart(2, '0')}</span>
      <span className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide opacity-90">{d.toLocaleDateString('en-GB', { month: 'short' })}</span>
    </span>
  );
}

export function UpcomingCard({ items, can }: { items: UpcomingItem[]; can: Can }) {
  return (
    <Panel title="Upcoming Events & Deadlines" subtitle="Next 60 days" icon={CalendarClock} hue="rose" action={can('events') ? <ViewAll to="/events" /> : undefined}>
      {items.length === 0 ? (
        <PanelEmpty icon={CalendarClock} title="Nothing scheduled" description="Exams, fee due dates, interviews, events and holidays in the next 60 days appear here." action={can('events', 'create') ? <Button size="sm" to="/events?add=1">Add event</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {items.map((it, i) => {
            const c = CATEGORY[it.category] ?? CATEGORY.event;
            return (
              <li key={`${it.date}-${i}`}>
                <Link to={it.url} className="group -mx-2 flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:hover:bg-slate-800/50">
                  <DateTile date={it.date} hue={c.hue} />
                  <span className="min-w-0 flex-1">
                    <span className="line-clamp-2 block text-[13px] font-semibold leading-snug text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{it.title}</span>
                    <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                      <span className="sr-only">{formatDate(it.date)} · </span>
                      {it.subtitle}
                      {it.time && it.time !== '00:00' ? ` · ${it.time}` : ''}
                    </span>
                  </span>
                  <span className={clsx('hidden shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold sm:inline-flex', HUES[c.hue].soft)}>{c.label}</span>
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
  // Gold / silver / bronze medals for the podium, neutral numbers after that.
  const medal = ['bg-gradient-to-br from-amber-300 to-amber-500 text-white shadow-sm shadow-amber-500/30', 'bg-gradient-to-br from-slate-300 to-slate-400 text-white shadow-sm shadow-slate-400/30', 'bg-gradient-to-br from-orange-300 to-orange-500 text-white shadow-sm shadow-orange-500/30'];
  return (
    <Panel
      title="Top Programs by Admissions"
      subtitle={mode === 'admitted' ? 'Students admitted this session' : 'Applications received this session'}
      icon={Trophy}
      hue="amber"
      action={data.applications ? <PeriodSelect label="Top programs measure" value={mode} onChange={setMode} options={[{ value: 'admitted', label: 'This Session' }, { value: 'applications', label: 'Applications' }]} /> : undefined}
    >
      {rows.length === 0 ? (
        <PanelEmpty icon={Trophy} title="No admissions this session yet" />
      ) : (
        <ol className="space-y-3.5">
          {rows.map((r, i) => {
            const h = HUES[hueAt(i)];
            const pct = (r.value / max) * 100;
            return (
              <li key={r.id} className="flex items-center gap-3">
                <span className={clsx('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', medal[i] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300')}>{i + 1}</span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-2">
                    <span className="truncate text-[13px] font-semibold text-slate-800 dark:text-slate-100" title={r.name}>
                      {r.label}
                      {r.category && <span className="ml-1 font-normal text-slate-400">({r.category})</span>}
                    </span>
                    <span className="text-sm font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.value)}</span>
                  </div>
                  <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={`${r.label}: ${r.value}`}>
                    <div className={clsx('h-full rounded-full bg-gradient-to-r transition-[width] duration-700 ease-out motion-reduce:transition-none', h.edge)} style={{ width: `${pct}%` }} />
                  </div>
                </div>
              </li>
            );
          })}
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
    <Panel title="Recent Admissions" subtitle="Latest applications received" icon={UserPlus} hue="emerald" action={<ViewAll to="/admissions/list" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={UserPlus} title="No applications yet" action={can('admissions', 'create') ? <Button size="sm" to="/admissions/new">Create admission</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/admissions/${r.id}`}>
                <HueAvatar name={r.name} src={r.photo} />
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
    <Panel title="Recent Enquiries" subtitle="Counselling and website enquiries" icon={MessageCircle} hue="sky" action={<ViewAll to="/enquiries" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={MessageCircle} title="No enquiries yet" description="Website, walk-in and phone enquiries will be listed here." />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/enquiries?q=${encodeURIComponent(r.phone || r.name)}`}>
                <HueAvatar name={r.name} />
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
    <Panel title="Recent Payments" subtitle="Latest fee receipts" icon={Receipt} hue="teal" action={<ViewAll to="/fees/payments" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={Receipt} title="No payments yet" action={can('fees', 'create') ? <Button size="sm" to="/fees/collect">Collect fee</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/fees/payments?q=${encodeURIComponent(r.receipt_no)}`}>
                <HueAvatar name={r.name} src={r.photo} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-semibold text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">{r.name}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{[r.receipt_no, r.program, MODE_LABEL[r.mode] ?? labelize(r.mode)].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-0.5">
                  <span className={clsx('rounded-full px-2 py-0.5 text-[13px] font-bold tabular-nums', r.status === 'success' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 line-through dark:bg-slate-800 dark:text-slate-400')}>{formatMoney(r.amount)}</span>
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
    <Panel title="Recent Notices" subtitle="Circulars and announcements" icon={Megaphone} hue="violet" action={<ViewAll to="/notices" />}>
      {rows.length === 0 ? (
        <PanelEmpty icon={Megaphone} title="No notices yet" action={can('notices', 'create') ? <Button size="sm" to="/notices?add=1">Create notice</Button> : undefined} />
      ) : (
        <ul className="-my-1 divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((r) => (
            <li key={r.id}>
              <RowLink to={`/notices?q=${encodeURIComponent(r.title)}`}>
                <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', r.is_pinned ? HUES.amber.chip : HUES.violet.soft)}>
                  {r.is_pinned ? <Pin className="h-4 w-4" aria-hidden /> : <Megaphone className="h-4 w-4" aria-hidden />}
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
  hue: Hue;
}

const QUICK: QuickAction[] = [
  { label: 'Add Student', icon: GraduationCap, to: '/students/new', perm: ['students', 'create'], hue: 'emerald' },
  { label: 'New Admission', icon: UserPlus, to: '/admissions/new', perm: ['admissions', 'create'], hue: 'indigo' },
  { label: 'Collect Fees', icon: HandCoins, to: '/fees/collect', perm: ['fees', 'create'], hue: 'teal' },
  { label: 'Mark Attendance', icon: CalendarCheck, to: '/attendance?tab=mark', perm: ['attendance', 'create'], hue: 'sky' },
  { label: 'Create Notice', icon: Megaphone, to: '/notices?add=1', perm: ['notices', 'create'], hue: 'violet' },
  { label: 'Generate ID Card', icon: IdCard, to: '/students/id-cards', perm: ['students', 'view'], hue: 'amber' },
  { label: 'Issue Certificate', icon: BadgeCheck, to: '/certificates?add=1', perm: ['certificates', 'create'], hue: 'rose' },
  { label: 'Send Email/SMS', icon: Send, to: '/newsletter?add=1', perm: ['communication', 'create'], hue: 'orange' },
];

const MORE: QuickAction[] = [
  { label: 'Add Faculty', icon: Users, to: '/faculty?add=1', perm: ['faculty', 'create'], hue: 'violet' },
  { label: 'Create Exam', icon: ClipboardList, to: '/examination?add=1', perm: ['examination', 'create'], hue: 'indigo' },
  { label: 'Add Program', icon: BookOpen, to: '/academics?tab=programs&add=1', perm: ['academics', 'create'], hue: 'navy' },
  { label: 'Create Timetable', icon: CalendarDays, to: '/timetable', perm: ['timetable', 'create'], hue: 'sky' },
  { label: 'Add Event', icon: CalendarPlus, to: '/events?add=1', perm: ['events', 'create'], hue: 'rose' },
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
      icon={Zap}
      hue="emerald"
      action={more.length ? <Dropdown label="More quick actions" trigger={<span className="inline-flex items-center gap-1">More</span>} triggerClassName="btn btn-secondary btn-xs !rounded-full" items={more.map((m) => ({ label: m.label, icon: m.icon, to: m.to }))} /> : undefined}
    >
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {(actions.length ? actions : more).map((a) => {
          const h = HUES[a.hue];
          return (
            <li key={a.label}>
              <Link
                to={a.to}
                className={clsx(
                  'group flex h-full flex-col items-center justify-center gap-2.5 rounded-2xl px-2 py-4 text-center outline-none transition duration-300 hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-brand-500/40 motion-reduce:transition-none motion-reduce:hover:translate-y-0',
                  h.tint, h.ring, h.glow,
                )}
              >
                <span className={clsx('inline-flex h-11 w-11 items-center justify-center rounded-full ring-4 ring-white transition duration-300 group-hover:scale-110 motion-reduce:transition-none dark:ring-slate-900', h.chip)}>
                  <a.icon className="h-5 w-5" aria-hidden />
                </span>
                <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">{a.label}</span>
              </Link>
            </li>
          );
        })}
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
  const tiles: { key: string; label: string; icon: LucideIcon; hue: Hue; ok: boolean; warn: boolean; value: string; sub: string }[] = [
    { key: 'server', label: 'Server', icon: Server, hue: 'indigo', ok: data.server.status === 'online', warn: false, value: 'Online', sub: `PHP ${data.server.php}` },
    { key: 'db', label: 'Database', icon: Database, hue: 'sky', ok: data.database.status === 'connected', warn: false, value: data.database.status === 'connected' ? 'Connected' : 'Error', sub: data.database.size ? `${humanFileSize(data.database.size)} · ${formatNumber(data.database.tables ?? 0)} tables` : data.database.version ?? '' },
    { key: 'backup', label: 'Backup', icon: History, hue: 'violet', ok: b.status !== 'missing', warn: b.status === 'stale' || !!b.last_failed_at, value: backupLabel, sub: b.last_failed_at ? `Last attempt failed ${timeAgo(b.last_failed_at)}` : b.age_days !== null ? `${b.age_days === 0 ? 'Today' : `${b.age_days} day${b.age_days === 1 ? '' : 's'} ago`} · ${labelize(b.schedule)}` : `Schedule: ${labelize(b.schedule)}` },
  ];
  const storageEdge = diskPct > 90 ? 'from-red-500 to-rose-400' : diskPct > 75 ? HUES.amber.edge : HUES.emerald.edge;
  return (
    <Panel title="System Status" subtitle={`Checked ${timeAgo(data.server.time)} · ${data.server.timezone}`} icon={ShieldCheck} hue="slate" action={can('backup') ? <ViewAll to="/backup" label="Backups" /> : undefined}>
      <div className="grid grid-cols-2 gap-3">
        {tiles.map((t) => (
          <div key={t.key} className={clsx('rounded-2xl p-3', HUES[t.hue].tint, HUES[t.hue].ring)}>
            <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
              <span className={clsx('inline-flex h-6 w-6 items-center justify-center rounded-lg', HUES[t.hue].chip)}>
                <t.icon className="h-3.5 w-3.5" aria-hidden />
              </span>
              {t.label}
            </p>
            <p className="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <StatusDot ok={t.ok} warn={t.warn} />
              <span className="truncate">{t.value}</span>
            </p>
            <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400" title={t.sub}>{t.sub}</p>
          </div>
        ))}
        <div className={clsx('rounded-2xl p-3', HUES.emerald.tint, HUES.emerald.ring)}>
          <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            <span className={clsx('inline-flex h-6 w-6 items-center justify-center rounded-lg', HUES.emerald.chip)}>
              <HardDrive className="h-3.5 w-3.5" aria-hidden />
            </span>
            Storage
          </p>
          <p className="mt-2 text-sm font-semibold text-slate-800 dark:text-slate-100">{s.disk_percent !== null ? `${Math.round(diskPct)}% Used` : humanFileSize(s.used)}</p>
          <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-white/80 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800 dark:ring-slate-700" role="progressbar" aria-valuenow={Math.round(diskPct)} aria-valuemin={0} aria-valuemax={100} aria-label="Disk space used">
            <div className={clsx('h-full rounded-full bg-gradient-to-r', storageEdge)} style={{ width: `${Math.max(0, Math.min(100, diskPct))}%` }} />
          </div>
          <p className="mt-1 truncate text-[11px] text-slate-500 dark:text-slate-400" title={`Uploads ${humanFileSize(s.uploads)} (${formatNumber(s.upload_files)} files) · app storage ${humanFileSize(s.storage)}`}>
            App files {humanFileSize(s.used)}
          </p>
        </div>
      </div>
    </Panel>
  );
}
