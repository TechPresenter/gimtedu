import clsx from 'clsx';
import {
  Activity, BookOpen, Briefcase, Bus, CalendarCheck, FileCheck2, GraduationCap, Hotel, Phone, ReceiptIndianRupee, ShieldAlert, Trophy, UserRound, type LucideIcon,
} from 'lucide-react';
import { Card, CardBody, CardHeader, DescriptionList, ProgressBar, Reveal, Stagger, Timeline, toneClasses, type Tone } from '@/components/ui';
import { formatDate, formatMoney, formatMoneyShort, labelize, timeAgo } from '@/lib/format';
import { useAuth } from '@/lib/auth';
import { ADMISSION_TYPES } from '../constants';
import type { TabProps } from './shared';

export default function OverviewTab({ profile, onTab }: TabProps) {
  const { can } = useAuth();
  const { student: s, stats, parents, recent_activity: activity, academic_history: history } = profile;
  const att = stats.attendance;
  const attPct = att?.percent ?? null;
  const cards: { key: string; tab: string; icon: LucideIcon; tone: Tone; label: string; value: string; sub: string; progress?: number; warn?: boolean }[] = [];
  if (att) {
    cards.push({ key: 'att', tab: 'attendance', icon: CalendarCheck, tone: attPct !== null && attPct < att.threshold ? 'red' : 'green', label: 'Attendance', value: attPct !== null ? `${attPct}%` : '—',
      sub: att.total ? `${att.attended} of ${att.total} classes · min ${att.threshold}%` : 'No attendance marked yet', progress: attPct ?? 0, warn: attPct !== null && attPct < att.threshold });
  }
  if (stats.fees) {
    cards.push({ key: 'fee', tab: 'fees', icon: ReceiptIndianRupee, tone: stats.fees.overdue > 0 ? 'red' : 'blue', label: 'Fee balance', value: formatMoneyShort(stats.fees.balance),
      sub: stats.fees.invoices ? `${formatMoneyShort(stats.fees.paid)} paid of ${formatMoneyShort(stats.fees.net)}${stats.fees.next_due ? ` · due ${formatDate(stats.fees.next_due)}` : ''}` : 'No invoices raised yet',
      progress: stats.fees.net ? (stats.fees.paid * 100) / stats.fees.net : undefined, warn: stats.fees.overdue > 0 });
  }
  if (stats.gpa) {
    cards.push({ key: 'gpa', tab: 'results', icon: Trophy, tone: 'amber', label: 'CGPA', value: stats.gpa.cgpa !== null ? stats.gpa.cgpa.toFixed(2) : '—',
      sub: stats.gpa.semester ? `Up to semester ${stats.gpa.semester}${stats.gpa.backlogs ? ` · ${stats.gpa.backlogs} backlog(s)` : ''}` : 'Results not declared yet' });
  }
  cards.push({ key: 'doc', tab: 'documents', icon: FileCheck2, tone: stats.documents.rejected ? 'orange' : 'purple', label: 'Documents', value: `${stats.documents.verified}/${stats.documents.total}`,
    sub: stats.documents.total ? `${stats.documents.pending} pending · ${stats.documents.rejected} rejected` : 'Nothing uploaded yet', progress: stats.documents.total ? (stats.documents.verified * 100) / stats.documents.total : 0 });
  if (stats.library) {
    cards.push({ key: 'lib', tab: 'library', icon: BookOpen, tone: stats.library.overdue ? 'red' : 'cyan', label: 'Library', value: `${stats.library.issued} issued`,
      sub: stats.library.member ? `${stats.library.member.membership_no}${stats.library.overdue ? ` · ${stats.library.overdue} overdue` : ''}` : 'Not a library member yet', warn: stats.library.overdue > 0 });
  }
  if (stats.hostel) {
    const h = stats.hostel;
    cards.push({ key: 'hos', tab: 'hostel', icon: Hotel, tone: 'purple', label: 'Hostel', value: `Room ${h.room_no}`, sub: `${h.hostel_name}${h.bed_no ? ` · Bed ${h.bed_no}` : ''}` });
  } else if (can('hostel', 'view')) {
    cards.push({ key: 'hos', tab: 'hostel', icon: Hotel, tone: 'slate', label: 'Hostel', value: s.is_hosteller ? 'Not allotted' : 'Day scholar', sub: s.is_hosteller ? 'Hostel requested — no room yet' : 'Does not stay on campus' });
  }
  if (stats.transport) {
    cards.push({ key: 'tr', tab: 'transport', icon: Bus, tone: 'amber', label: 'Transport', value: stats.transport.route_code, sub: `${stats.transport.route_name}${stats.transport.stop_name ? ` · ${stats.transport.stop_name}` : ''}` });
  } else if (can('transport', 'view')) {
    cards.push({ key: 'tr', tab: 'transport', icon: Bus, tone: 'slate', label: 'Transport', value: s.uses_transport ? 'Not allotted' : 'Not opted', sub: s.uses_transport ? 'Bus requested — no route yet' : 'Not using college buses' });
  }
  if (stats.placement) {
    cards.push({ key: 'pl', tab: 'placement', icon: Briefcase, tone: 'green', label: 'Placement', value: stats.placement.offers ? `${stats.placement.offers} offer${stats.placement.offers > 1 ? 's' : ''}` : `${stats.placement.applications} applied`,
      sub: stats.placement.best_package ? `Best package ${stats.placement.best_package} LPA` : stats.placement.applications ? 'Awaiting results' : 'No applications yet' });
  }

  const admissionType = ADMISSION_TYPES.find((a) => a.value === s.admission_type)?.label ?? labelize(s.admission_type);
  return (
    <div className="grid gap-5 xl:grid-cols-3">
      <div className="min-w-0 space-y-5 xl:col-span-2">
        <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full" step={50}>
          {cards.map((c) => (
            <button key={c.key} type="button" onClick={() => onTab(c.tab)}
              className={clsx('group flex h-full w-full flex-col rounded-2xl border border-slate-200/70 p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-card focus-visible:ring-2 focus-visible:ring-brand-400 dark:border-slate-800', toneClasses[c.tone].bg)}>
              <div className="flex items-center justify-between">
                <span className={clsx('inline-flex h-9 w-9 items-center justify-center rounded-xl', toneClasses[c.tone].icon)}>
                  <c.icon className="h-[18px] w-[18px]" />
                </span>
                {c.warn && <ShieldAlert className="h-4 w-4 text-red-500" aria-label="Needs attention" />}
              </div>
              <p className="mt-3 text-xs font-medium text-slate-500 dark:text-slate-400">{c.label}</p>
              <p className={clsx('truncate font-display text-xl font-bold', c.warn ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white')}>{c.value}</p>
              {c.progress !== undefined && <ProgressBar value={c.progress} tone={c.warn ? 'red' : c.tone === 'slate' ? 'blue' : c.tone} className="mt-2 !h-1.5" label={`${c.label} progress`} />}
              <p className="mt-1.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{c.sub}</p>
            </button>
          ))}
        </Stagger>

        <Reveal>
          <Card>
            <CardHeader title="Key facts" subtitle="Enrolment summary" icon={GraduationCap} />
            <CardBody>
              <DescriptionList columns={3} items={[
                { label: 'Program', value: s.program_full_name },
                { label: 'Specialization', value: s.course_name },
                { label: 'Department', value: s.department_name },
                { label: 'Batch', value: s.batch_name },
                { label: 'Semester / Section', value: `Semester ${s.current_semester} of ${s.total_semesters}${s.section_name ? ` · Section ${s.section_name}` : ''}` },
                { label: 'Admission session', value: s.session_name },
                { label: 'Admission date', value: s.admission_date ? formatDate(s.admission_date) : null },
                { label: 'Admission type', value: admissionType },
                { label: 'Previous qualification', value: s.previous_qualification ? `${s.previous_qualification}${s.previous_percentage ? ` · ${Number(s.previous_percentage).toFixed(1)}%` : ''}` : null },
              ]} />
            </CardBody>
          </Card>
        </Reveal>

        <Reveal delay={60}>
          <Card>
            <CardHeader title="Academic journey" subtitle="Semester-wise progress" icon={Activity} actions={<button type="button" className="link text-xs" onClick={() => onTab('academic')}>View history →</button>} />
            <CardBody>
              <ol className="flex gap-2 overflow-x-auto pb-1 scrollbar-none">
                {Array.from({ length: s.total_semesters }, (_, i) => i + 1).map((n) => {
                  const h = [...history].reverse().find((x) => x.semester_no === n);
                  const current = n === s.current_semester && s.status === 'active';
                  const done = !!h && h.status !== 'studying';
                  return (
                    <li key={n} className={clsx('min-w-[5.5rem] flex-1 rounded-xl border px-3 py-2.5 text-center transition',
                      current ? 'border-brand-300 bg-brand-50 dark:border-brand-500/40 dark:bg-brand-500/10' : done ? 'border-accent-200 bg-accent-50/60 dark:border-accent-500/30 dark:bg-accent-500/10' : 'border-dashed border-slate-200 dark:border-slate-700')}>
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Sem {n}</p>
                      <p className={clsx('mt-0.5 font-display text-base font-bold', current ? 'text-brand-800 dark:text-brand-200' : done ? 'text-accent-700 dark:text-accent-300' : 'text-slate-300 dark:text-slate-600')}>
                        {h?.sgpa ? Number(h.sgpa).toFixed(2) : current ? 'Now' : done ? '✓' : '—'}
                      </p>
                      <p className="truncate text-[10px] text-slate-500">{h?.session_name ?? (current ? 'In progress' : 'Upcoming')}</p>
                    </li>
                  );
                })}
              </ol>
            </CardBody>
          </Card>
        </Reveal>
      </div>

      <div className="min-w-0 space-y-5">
        <Reveal from="right">
          <Card>
            <CardHeader title="Parents & contacts" icon={UserRound} actions={<button type="button" className="link text-xs" onClick={() => onTab('personal')}>Details →</button>} />
            <CardBody className="space-y-3">
              {parents.length === 0 && <p className="text-sm text-slate-500">No parent or guardian recorded.</p>}
              {parents.map((p) => (
                <div key={p.id} className="flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{p.name}</p>
                    <p className="text-xs capitalize text-slate-500">{p.relation === 'guardian' && s.guardian_relation ? s.guardian_relation : p.relation}{p.occupation ? ` · ${p.occupation}` : ''}{p.is_emergency_contact && <span className="ml-1.5 rounded bg-red-50 px-1.5 py-px text-[10px] font-semibold normal-case text-red-600 dark:bg-red-500/10 dark:text-red-300">Emergency</span>}</p>
                  </div>
                  {p.phone && (
                    <a href={`tel:${p.phone.replace(/\s/g, '')}`} className="btn-icon shrink-0" aria-label={`Call ${p.name}`} title={p.phone}>
                      <Phone className="h-4 w-4" />
                    </a>
                  )}
                </div>
              ))}
              {s.emergency_contact_phone && !parents.some((p) => p.is_emergency_contact && p.phone === s.emergency_contact_phone) && (
                <div className="rounded-xl bg-red-50/70 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">
                  Emergency: <strong>{s.emergency_contact_name}</strong> · {s.emergency_contact_phone}
                </div>
              )}
            </CardBody>
          </Card>
        </Reveal>
        <Reveal from="right" delay={80}>
          <Card>
            <CardHeader title="Recent activity" icon={Activity} actions={<button type="button" className="link text-xs" onClick={() => onTab('activity')}>All →</button>} />
            <CardBody>
              <Timeline
                empty="No changes recorded for this student yet."
                items={activity.map((a) => ({
                  tone: a.action === 'create' ? 'green' : a.action === 'delete' || a.action === 'reject' ? 'red' : a.action === 'status' ? 'amber' : 'blue',
                  title: a.description,
                  time: `${a.user_name ?? 'System'} · ${timeAgo(a.created_at)}`,
                }))}
              />
            </CardBody>
          </Card>
        </Reveal>
        {stats.fees && stats.fees.overdue > 0 && (
          <Reveal from="right" delay={120}>
            <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
              <p className="font-semibold">Overdue fees: {formatMoney(stats.fees.overdue)}</p>
              <p className="mt-0.5 text-xs">Follow up with the student or guardian before the next exam allocation.</p>
            </div>
          </Reveal>
        )}
      </div>
    </div>
  );
}

