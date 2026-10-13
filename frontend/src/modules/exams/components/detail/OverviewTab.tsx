import { Link } from 'react-router-dom';
import {
  ArrowRight, BookOpen, CalendarCheck2, CalendarClock, CheckCircle2, ClipboardCheck, History, Percent, PenLine, Send, ShieldCheck, Sparkles, Users,
} from 'lucide-react';
import { Button, Card, CardHeader, DescriptionList, EmptyState, Reveal, StatTile, Stagger, Timeline, type TimelineItem } from '@/components/ui';
import { formatDate, formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { printUrl } from '@/lib/config';
import { MiniProgress, dateRange, timeRange, todayIso } from '../shared';
import type { ExamOverview } from '../types';

const ACTION_ICON: Record<string, TimelineItem['icon']> = { create: Sparkles, update: PenLine, submit: Send, approve: ShieldCheck, publish: CheckCircle2, print: ClipboardCheck, export: ClipboardCheck, send: Send };
const ACTION_TONE: Record<string, TimelineItem['tone']> = { create: 'blue', update: 'slate', submit: 'amber', approve: 'purple', publish: 'green', send: 'cyan' };

export function OverviewTab({ data, onTab, onVerify, onPublish }: { data: ExamOverview; onTab: (t: string) => void; onVerify: () => void; onPublish: () => void }) {
  const { exam: e, counts: c, results: r, permissions: perm } = data;
  const next: { title: string; text: string; cta: string; act?: () => void; to?: string; href?: string } = (() => {
    switch (data.current_step) {
      case 'subjects':
        return { title: 'Assign the subjects of this exam', text: 'Load the curriculum subjects of the semester or add papers one by one with their maximum and pass marks.', cta: 'Open subjects', act: () => onTab('subjects') };
      case 'schedule':
        return { title: 'Build the date sheet', text: `${c.scheduled} of ${c.subjects} papers have a date. Generate the remaining papers automatically or schedule them manually with rooms and invigilators.`, cta: 'Open schedule', act: () => onTab('schedule') };
      case 'students':
        return { title: 'Allocate eligible students', text: `${formatNumber(c.pending_candidates)} candidates are waiting. Eligibility checks attendance (min ${data.min_attendance}%) and overdue fees; you can override with a reason.`, cta: 'Allocate students', act: () => onTab('students') };
      case 'marks':
        if (!c.marks_entered && e.start_date && e.start_date > todayIso()) {
          return { title: 'Conduct the examination', text: `Papers start on ${formatDate(e.start_date)}. Print admit cards for the ${formatNumber(c.eligible)} eligible students; marks entry opens after each paper is held.`, cta: 'Print admit cards', href: printUrl('admit-card.php', { exam_id: e.id }) };
        }
        return { title: 'Enter marks', text: `${c.marks_percent}% of the marks are entered (${formatNumber(c.marks_entered)} of ${formatNumber(c.marks_expected)}). Faculty enter internal, external and practical marks subject by subject.`, cta: 'Open marks entry', to: `/marks-entry?exam=${e.id}` };
      case 'verify':
        return { title: 'Verify and lock the marks', text: 'All marks are entered. Verification locks the marks and computes totals, grades, SGPA, CGPA and ranks.', cta: perm.verify ? 'Verify marks' : 'View marks progress', act: perm.verify ? onVerify : () => onTab('marks') };
      case 'publish':
        return { title: 'Publish the results', text: `${formatNumber(r.total)} results are computed (${r.pass_percent ?? 0}% pass). Publishing makes them visible to students and notifies them.`, cta: perm.publish ? 'Publish results' : 'View results', act: perm.publish ? onPublish : undefined, to: perm.publish ? undefined : `/results?exam=${e.id}` };
      default:
        if (!e.is_final) {
          return { title: 'Results are published', text: `Published ${formatDateTime(e.published_at)}${e.published_by_name ? ` by ${e.published_by_name}` : ''}. ${formatNumber(r.total)} students · ${r.pass_percent ?? 0}% pass. Students can print a statement of marks from the result.`, cta: 'View results', to: `/results?exam=${e.id}` };
        }
        return { title: 'Results are published', text: `Published ${formatDateTime(e.published_at)}${e.published_by_name ? ` by ${e.published_by_name}` : ''}. ${data.marksheets ? `${formatNumber(data.marksheets)} marksheets issued.` : 'Generate marksheets for the students.'}`, cta: data.marksheets ? 'View results' : 'Generate marksheets', to: data.marksheets ? `/results?exam=${e.id}` : `/marksheets?exam=${e.id}` };
    }
  })();

  const timeline: TimelineItem[] = data.timeline.map((t) => ({
    icon: ACTION_ICON[t.action] ?? History,
    tone: ACTION_TONE[t.action] ?? 'slate',
    title: t.description,
    description: t.user_name ?? 'System',
    time: <span title={formatDateTime(t.created_at)}>{timeAgo(t.created_at)}</span>,
  }));

  return (
    <div className="space-y-6">
      <Stagger className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="s" label="Subjects / papers" value={`${c.subjects} / ${c.scheduled}`} icon={BookOpen} tone="blue" sub={c.subjects && c.scheduled >= c.subjects ? 'Date sheet complete' : `${Math.max(0, c.subjects - c.scheduled)} papers unscheduled`} />,
          <StatTile key="e" label="Eligible students" value={c.eligible} icon={Users} tone="green" sub={c.ineligible ? `${c.ineligible} not eligible · ${c.allocated} allocated` : `${c.allocated} allocated`} />,
          <StatTile key="m" label="Marks entered" value={`${c.marks_percent}%`} icon={PenLine} tone="amber" sub={`${formatNumber(c.marks_entered)} of ${formatNumber(c.marks_expected)} entries`} />,
          <StatTile key="p" label="Pass percentage" value={r.total ? `${r.pass_percent ?? 0}%` : '—'} icon={Percent} tone="purple" sub={r.total ? `${r.pass} pass · ${r.backlog + r.fail} backlog/fail · ${r.withheld} withheld` : 'Computed on verification'} />,
        ]}
      </Stagger>

      <Reveal>
        <div className="flex flex-col gap-4 rounded-2xl border border-brand-100 bg-gradient-to-r from-brand-50 to-white p-5 sm:flex-row sm:items-center dark:border-brand-500/20 dark:from-brand-500/10 dark:to-slate-900">
          <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-800 text-white">
            <ArrowRight className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Next step</p>
            <p className="font-display text-base font-semibold text-slate-900 dark:text-white">{next.title}</p>
            <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{next.text}</p>
          </div>
          {(next.act || next.to || next.href) && (
            <Button onClick={next.act} to={next.to} href={next.href} target={next.href ? '_blank' : undefined} iconRight={ArrowRight} className="shrink-0">
              {next.cta}
            </Button>
          )}
        </div>
      </Reveal>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Exam details" icon={ClipboardCheck} />
            <div className="p-5">
              <DescriptionList
                items={[
                  { label: 'Exam type', value: `${e.type_name}${e.is_final ? ' · produces SGPA / CGPA' : ''}` },
                  { label: 'Program / semester', value: e.cohort },
                  { label: 'Academic session', value: e.session_name },
                  { label: 'Exam dates', value: dateRange(e.start_date, e.end_date) },
                  { label: 'Result date', value: e.result_date ? formatDate(e.result_date) : null },
                  { label: 'Minimum attendance', value: e.min_attendance_percent !== null && Number(e.min_attendance_percent) === 0 ? 'No requirement' : `${data.min_attendance}%` },
                  { label: 'Created', value: `${formatDate(e.created_at)}${e.created_by_name ? ` by ${e.created_by_name}` : ''}` },
                  { label: 'Marks verified', value: e.verified_at ? `${formatDateTime(e.verified_at)}${e.verified_by_name ? ` by ${e.verified_by_name}` : ''}` : 'Not yet' },
                  { label: 'Instructions for candidates', value: e.instructions, full: true },
                ]}
              />
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title={data.next_paper ? 'Next paper' : 'Papers'} icon={CalendarClock} />
            <div className="p-5">
              {data.next_paper ? (
                <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                  <p className="text-xs font-medium text-slate-500">{formatDate(data.next_paper.exam_date)} · {timeRange(data.next_paper.start_time, data.next_paper.end_time)}</p>
                  <p className="mt-1 font-semibold text-slate-900 dark:text-white">
                    <span className="font-mono text-xs text-slate-500">{data.next_paper.subject_code}</span> {data.next_paper.subject_name}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">{data.next_paper.room_code ? `Room ${data.next_paper.room_code}` : 'Room not assigned yet'}</p>
                </div>
              ) : (
                <p className="text-sm text-slate-500">{c.scheduled ? 'All papers of this exam have been held.' : 'No papers scheduled yet.'}</p>
              )}
              <div className="mt-4 space-y-3">
                {data.progress.slice(0, 6).map((p) => (
                  <div key={p.subject_id}>
                    <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                      <span className="truncate font-medium text-slate-700 dark:text-slate-200">{p.code} · {p.name}</span>
                    </div>
                    <MiniProgress value={p.percent} tone={p.percent >= 100 ? 'green' : p.percent > 0 ? 'blue' : 'amber'} label={`${p.name} marks`} />
                  </div>
                ))}
                {!data.progress.length && <p className="text-sm text-slate-500">Marks progress appears once subjects are added.</p>}
              </div>
              <Button variant="ghost" size="sm" className="mt-3" onClick={() => onTab('marks')}>
                Marks progress →
              </Button>
            </div>
          </Card>
        </Reveal>
      </div>

      <Reveal>
        <Card>
          <CardHeader title="Activity" subtitle="Workflow history of this exam" icon={CalendarCheck2} />
          <div className="p-5">
            {timeline.length ? <Timeline items={timeline} /> : <EmptyState icon={History} title="No activity yet" description="Changes to subjects, schedule, students, marks and results are recorded here." className="py-8" />}
          </div>
        </Card>
      </Reveal>
      {r.total > 0 && (
        <p className="text-center text-xs text-slate-500">
          <Link to={`/results?exam=${e.id}`} className="font-medium text-brand-700 hover:underline dark:text-brand-300">
            Open the full result analysis →
          </Link>
        </p>
      )}
    </div>
  );
}
