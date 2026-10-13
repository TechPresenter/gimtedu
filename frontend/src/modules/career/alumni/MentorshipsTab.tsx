import { Link } from 'react-router-dom';
import { CalendarCheck, CheckCircle2, GraduationCap, Handshake, Target, UserCheck, UsersRound } from 'lucide-react';
import { Alert, Avatar, Card, CardHeader, EmptyState, Reveal, Skeleton, Stagger, StatTile } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { mentorshipRenderers } from './renderers';
import { BarList, LinkedCrudTable, statsKey } from './shared';

interface MentorshipStats {
  kpis: { active: number; completed: number; cancelled: number; mentors_engaged: number; mentees: number; sessions: number; mentor_pool: number; mentors_available: number };
  areas: { label: string; total: number }[];
  top_mentors: (Row & { mentees: number; active: number; sessions: number })[];
}

export function MentorshipsTab() {
  const { data, isLoading, error } = useApi<MentorshipStats>(statsKey('alumni_mentorships'), 'alumni/mentorships/stats');
  const k = data?.kpis;
  return (
    <>
      {error ? <Alert variant="error" title="Unable to load mentorship statistics" className="mb-6">{(error as Error).message}</Alert> : null}
      <Stagger className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5" step={50}>
        {[
          <StatTile key="a" label="Active mentorships" icon={Handshake} tone="green" loading={isLoading} value={k?.active ?? 0} sub={k ? `${k.mentees} students` : undefined} />,
          <StatTile key="m" label="Mentors engaged" icon={UserCheck} tone="purple" loading={isLoading} value={k?.mentors_engaged ?? 0} sub={k ? `of ${k.mentor_pool} mentors` : undefined} />,
          <StatTile key="av" label="Mentors available" icon={UsersRound} tone="blue" loading={isLoading} value={k?.mentors_available ?? 0} sub="Under 3 mentees" />,
          <StatTile key="s" label="Sessions held" icon={CalendarCheck} tone="orange" loading={isLoading} value={k?.sessions ?? 0} sub="All mentorships" />,
          <StatTile key="c" label="Completed" icon={CheckCircle2} tone="navy" loading={isLoading} value={k?.completed ?? 0} sub={k ? `${k.cancelled} cancelled` : undefined} />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Most active mentors" subtitle="Alumni guiding the most students" icon={GraduationCap} />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="grid gap-3 sm:grid-cols-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-16" />)}</div>
              ) : data.top_mentors.length === 0 ? (
                <EmptyState icon={Handshake} title="No mentorships yet" description="Pair an alumni mentor with a student using “Add Mentorship” below." className="py-8" />
              ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                  {data.top_mentors.map((m) => (
                    <Link key={String(m.id)} to={`/alumni/${m.id}`} className="flex items-center gap-3 rounded-xl border border-slate-200/80 p-3 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft dark:border-slate-800 dark:hover:border-brand-500/40">
                      <Avatar name={String(m.name)} src={m.photo as string | null} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{String(m.name)}</span>
                        <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{[m.designation, m.company].filter(Boolean).join(' · ') || `Batch ${m.batch_year}`}</span>
                      </span>
                      <span className="shrink-0 text-right">
                        <span className="block font-display text-lg font-bold leading-none text-brand-800 dark:text-brand-200">{m.mentees}</span>
                        <span className="text-[11px] text-slate-500">{m.active} active · {formatNumber(m.sessions)} sess.</span>
                      </span>
                    </Link>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80} className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Focus areas" subtitle="What students are being mentored on" icon={Target} />
            <div className="card-body !pt-1">
              {isLoading || !data ? (
                <div className="space-y-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-8" />)}</div>
              ) : data.areas.length === 0 ? (
                <EmptyState icon={Target} title="No focus areas yet" className="py-8" />
              ) : (
                <BarList items={data.areas.map((a) => ({ key: a.label, label: a.label, value: a.total }))} tone="bg-violet-500" />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <LinkedCrudTable
        module="alumni_mentorships"
        renderers={mentorshipRenderers}
        addLabel="Add mentorship"
        emptyTitle="No mentorships yet"
        emptyText="Pair alumni mentors with final-year students for career guidance, interview preparation and more."
      />
    </>
  );
}
