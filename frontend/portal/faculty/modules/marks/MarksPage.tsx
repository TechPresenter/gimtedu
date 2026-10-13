import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { BadgeCheck, CalendarClock, ClipboardPen, Eye, FileCheck2, Hourglass, Lock, NotebookPen, Pencil, Users } from 'lucide-react';
import { Alert, Stagger } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import type { MarksExam, MarksListData, MarksPaper, PaperState } from '../../shared/types';
import { Bar, MiniStat, PillTabs, fpath, relDay } from '../../shared/ui';

type Tab = 'todo' | 'upcoming' | 'done';

const STATE: Record<PaperState, { label: string; badge: string }> = {
  not_started: { label: 'Not started', badge: 'badge-red' },
  in_progress: { label: 'In progress', badge: 'badge-amber' },
  complete: { label: 'Ready to submit', badge: 'badge-flame' },
  submitted: { label: 'Submitted', badge: 'badge-green' },
  upcoming: { label: 'Paper not held yet', badge: 'badge-navy' },
  verified: { label: 'Verified · locked', badge: 'badge-slate' },
  published: { label: 'Result published', badge: 'badge-purple' },
  cancelled: { label: 'Cancelled', badge: 'badge-slate' },
};

const tabOf = (p: MarksPaper): Tab => (p.locked || p.state === 'submitted' ? 'done' : p.state === 'upcoming' ? 'upcoming' : 'todo');

function PaperRow({ exam, p, canEnter }: { exam: MarksExam; p: MarksPaper; canEnter: boolean }) {
  const pct = p.students ? Math.round((p.entered / p.students) * 100) : 0;
  const s = STATE[p.state];
  const editable = p.open && canEnter;
  const cta = !editable ? { icon: Eye, label: 'View' } : p.entered === 0 ? { icon: ClipboardPen, label: 'Enter marks' } : p.state === 'submitted' ? { icon: Pencil, label: 'Review' } : { icon: Pencil, label: 'Continue' };
  return (
    <li className="grid gap-3 px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_200px_auto] sm:items-center sm:px-5">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="badge badge-navy">{p.subject_code}</span>
          <span className={clsx('badge', s.badge)}>
            {p.locked ? <Lock className="h-3 w-3" aria-hidden /> : p.state === 'submitted' ? <BadgeCheck className="h-3 w-3" aria-hidden /> : null} {s.label}
          </span>
        </div>
        <p className="mt-1.5 truncate text-sm font-bold text-slate-900 dark:text-white">{p.subject}</p>
        <p className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
          <span className="inline-flex items-center gap-1">
            <CalendarClock className="h-3.5 w-3.5" aria-hidden />
            {p.exam_date ? `${formatDate(p.exam_date)} (${relDay(p.exam_date)})` : 'Not scheduled'}
          </span>
          <span className="inline-flex items-center gap-1">
            <Users className="h-3.5 w-3.5" aria-hidden /> Sec {p.sections.map((x) => x.name).join(', ') || '—'} · {p.students} students
          </span>
          <span>
            Max {p.max_total} · pass {p.pass_marks}
          </span>
        </p>
      </div>
      <div>
        <div className="mb-1 flex items-center justify-between text-[11px] font-semibold text-slate-500 dark:text-slate-400">
          <span>
            {p.entered}/{p.students} entered
          </span>
          <span>{p.submitted ? `${p.submitted} submitted` : `${pct}%`}</span>
        </div>
        <Bar value={pct} tone={p.state === 'submitted' || p.locked ? 'green' : p.entered ? 'flame' : 'red'} />
      </div>
      <Link to={fpath(`marks/${exam.id}/${p.subject_id}`)} className={clsx('btn btn-sm justify-self-start sm:justify-self-end', editable && p.state !== 'submitted' ? 'btn-primary' : 'btn-secondary')}>
        <cta.icon className="h-4 w-4" aria-hidden /> {cta.label}
      </Link>
    </li>
  );
}

export default function MarksPage() {
  const { data, isLoading, error, refetch } = usePortalQuery<MarksListData>('faculty', ['marks', 'list'], 'marks');
  const [tab, setTab] = useState<Tab>('todo');
  const groups = useMemo(() => {
    if (!data) return { todo: [], upcoming: [], done: [] } as Record<Tab, MarksExam[]>;
    const out: Record<Tab, MarksExam[]> = { todo: [], upcoming: [], done: [] };
    data.exams.forEach((e) => {
      (['todo', 'upcoming', 'done'] as Tab[]).forEach((t) => {
        const papers = e.papers.filter((p) => tabOf(p) === t);
        if (papers.length) out[t].push({ ...e, papers });
      });
    });
    return out;
  }, [data]);
  const count = (t: Tab) => groups[t].reduce((n, e) => n + e.papers.length, 0);
  const k = data?.kpis;

  return (
    <div>
      <PageHead
        area="faculty"
        title="Marks Entry"
        subtitle="Enter internal, external and practical marks for your papers. Marks lock once the examination office verifies them."
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'Marks Entry' }]}
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data || !k ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="skeleton h-72 rounded-2xl" />
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="o" label="Papers to complete" value={k.open} icon={NotebookPen} tone={k.open ? 'flame' : 'green'} sub={k.open ? 'open for marks entry' : 'nothing pending'} />,
              <MiniStat key="p" label="Students pending" value={k.pending_students} icon={Hourglass} tone={k.pending_students ? 'red' : 'green'} sub="marks not entered yet" />,
              <MiniStat key="s" label="Submitted" value={k.submitted} icon={FileCheck2} tone="green" sub="awaiting verification" />,
              <MiniStat key="l" label="Locked / published" value={k.locked} icon={Lock} tone="navy" sub={`${k.upcoming} upcoming paper${k.upcoming === 1 ? '' : 's'}`} />,
            ]}
          </Stagger>

          {!data.can_enter && <Alert variant="warning" title="Read-only access">Your account role does not allow marks entry. Contact the examination office if you need to enter marks.</Alert>}

          <PillTabs
            label="Papers"
            value={tab}
            onChange={setTab}
            tabs={[
              { value: 'todo', label: 'To do', count: count('todo') },
              { value: 'upcoming', label: 'Upcoming', count: count('upcoming') },
              { value: 'done', label: 'Completed', count: count('done') },
            ]}
          />

          {groups[tab].length === 0 ? (
            <div className="card">
              <EmptyBlock
                icon={tab === 'todo' ? BadgeCheck : tab === 'upcoming' ? CalendarClock : NotebookPen}
                title={tab === 'todo' ? 'No marks pending' : tab === 'upcoming' ? 'No upcoming papers' : 'Nothing completed yet'}
                text={
                  tab === 'todo'
                    ? 'Papers of your subjects appear here after the examination is held.'
                    : tab === 'upcoming'
                      ? 'Scheduled papers of your subjects appear here before the examination.'
                      : 'Papers you submit, and papers the examination office verifies or publishes, appear here.'
                }
              />
            </div>
          ) : (
            <Stagger className="space-y-4" step={50}>
              {groups[tab].map((e) => (
                <section key={e.id} className="card overflow-hidden">
                  <header className="flex flex-col gap-2 border-b border-slate-100 bg-slate-50/60 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-slate-800 dark:bg-slate-800/30">
                    <div className="min-w-0">
                      <h2 className="truncate font-display text-[15px] font-bold text-slate-900 dark:text-white">{e.name}</h2>
                      <p className="text-xs text-slate-500 dark:text-slate-400">
                        {e.type} · {e.cohort}
                        {e.start_date ? ` · ${formatDate(e.start_date)} – ${formatDate(e.end_date)}` : ''}
                      </p>
                    </div>
                    <span className={clsx('badge shrink-0 self-start sm:self-auto', e.locked ? 'badge-slate' : e.display_status === 'marks_entry' ? 'badge-flame' : 'badge-navy')}>{labelize(e.display_status)}</span>
                  </header>
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {e.papers.map((p) => (
                      <PaperRow key={p.subject_id} exam={e} p={p} canEnter={data.can_enter} />
                    ))}
                  </ul>
                </section>
              ))}
            </Stagger>
          )}
        </div>
      )}
    </div>
  );
}
