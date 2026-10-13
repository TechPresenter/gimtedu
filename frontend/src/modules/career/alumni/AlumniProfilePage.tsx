import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowLeft, Award, BadgeCheck, Briefcase, CalendarDays, CalendarHeart, ExternalLink, GraduationCap, HandCoins, Handshake, Mail, MapPin, MoreHorizontal, Pencil, Phone,
  Printer, Sparkles, Trophy, UserMinus, UserPlus, UserRound, UsersRound,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, CardSkeleton, DescriptionList, Dropdown, EmptyState, PageHeader, Reveal, StatusBadge, Tabs, Timeline, toneClasses,
  useToast, type TimelineItem, type Tone,
} from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatMoney, formatMoneyShort, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { donationRenderers, jobRenderers, mentorshipRenderers, receiptLabel, storyRenderers } from './renderers';
import { EMPLOYMENT_COLORS, EVENT_MODE_LABELS, EVENT_TYPE_COLORS, EVENT_TYPE_LABELS, FixedCrudFormModal, REG_STATUS_COLORS, REG_STATUS_LABELS, statsKey, useRefreshModules } from './shared';

interface Profile {
  alumni: Row & { id: number; name: string; batch_year: number; is_mentor: boolean; is_verified: boolean; show_on_website: boolean; employment_label: string; years_since: number };
  stats: { events_registered: number; events_attended: number; donations_total: number; donations_count: number; mentees_active: number; mentees_total: number; jobs_posted: number; stories: number };
  events: Row[];
  donations: Row[];
  mentorships: Row[];
  jobs: Row[];
  stories: Row[];
  timeline: { type: string; date: string | null; title: string; sub: string | null }[];
}

const TABS = ['overview', 'mentorships', 'donations', 'events', 'jobs', 'stories'] as const;
type Tab = (typeof TABS)[number];
const timelineStyle: Record<string, { icon: typeof Award; tone: Tone }> = {
  event: { icon: CalendarHeart, tone: 'orange' }, donation: { icon: HandCoins, tone: 'green' }, mentorship: { icon: Handshake, tone: 'purple' },
  job: { icon: Briefcase, tone: 'cyan' }, story: { icon: Trophy, tone: 'amber' }, joined: { icon: UsersRound, tone: 'blue' }, graduated: { icon: GraduationCap, tone: 'navy' },
};

export default function AlumniProfilePage() {
  const { id: idParam } = useParams();
  const id = Number(idParam);
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useRefreshModules();
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview';
  const [editOpen, setEditOpen] = useState(false);
  const [adding, setAdding] = useState<null | 'alumni_donations' | 'alumni_mentorships' | 'alumni_jobs' | 'alumni_success_stories'>(null);
  const { data: meta } = useCrudMeta('alumni');
  const { data, isLoading, error } = useApi<Profile>(statsKey('alumni', `profile-${id}`), `alumni/${id}/profile`, undefined, { enabled: Number.isFinite(id) && id > 0 });
  const a = data?.alumni;
  const s = data?.stats;
  const setTab = (t: string) => setParams(t === 'overview' ? {} : { tab: t }, { replace: true });
  const refreshAll = () => void refresh('alumni', 'alumni_donations', 'alumni_mentorships', 'alumni_jobs', 'alumni_success_stories', 'alumni_events');

  const [verifying, setVerifying] = useState(false);
  const bulkAction = async (action: 'mentor_on' | 'mentor_off' | 'verify', message?: string) => {
    if (!a) return;
    try {
      const res = await api.post('crud/alumni/bulk', { action, ids: [a.id] });
      toast.success(message ?? res.message);
      await refresh('alumni');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const toggleMentor = () => bulkAction(a?.is_mentor ? 'mentor_off' : 'mentor_on');
  const verify = async () => {
    setVerifying(true);
    await bulkAction('verify', 'Profile marked verified.');
    setVerifying(false);
  };

  if (!Number.isFinite(id) || id <= 0 || (error && (error as ApiError).status === 404)) {
    return (
      <>
        <PageHeader title="Alumni profile" breadcrumbs={[{ label: 'Alumni', to: '/alumni' }, { label: 'Not found' }]} />
        <Card>
          <EmptyState icon={UserRound} title="Alumni profile not found" description="The profile may have been deleted or the link is incorrect." action={<Button to="/alumni" icon={ArrowLeft}>Back to directory</Button>} />
        </Card>
      </>
    );
  }

  const fixedContext = a && (
    <div className="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm dark:bg-slate-800/60">
      <Avatar name={a.name} src={a.photo as string | null} size="sm" />
      <span className="font-semibold text-slate-900 dark:text-white">{a.name}</span>
      <span className="truncate text-slate-500">Batch {a.batch_year}{a.company ? ` · ${a.company}` : ''}</span>
    </div>
  );
  const fixedField = adding === 'alumni_jobs' ? 'posted_by_alumni_id' : 'alumni_id';
  const addTitles = { alumni_donations: 'Record donation', alumni_mentorships: 'Assign a mentee', alumni_jobs: 'Post a job opportunity', alumni_success_stories: 'Add success story' };

  return (
    <>
      <PageHeader
        title={a?.name ?? 'Alumni profile'}
        description={a ? [a.designation, a.company].filter(Boolean).join(' at ') || a.employment_label : 'Loading profile…'}
        breadcrumbs={[{ label: 'Alumni', to: '/alumni' }, { label: a?.name ?? 'Profile' }]}
        actions={
          <>
            <Button variant="secondary" icon={ArrowLeft} to="/alumni">Directory</Button>
            {a && can('alumni', 'edit') && <Button icon={Pencil} onClick={() => setEditOpen(true)}>Edit profile</Button>}
            {a && (can('alumni', 'create') || can('alumni', 'edit')) && (
              <Dropdown
                label="More actions"
                trigger={<><MoreHorizontal className="h-4 w-4" /><span className="hidden sm:inline">More</span></>}
                items={[
                  can('alumni', 'create') && a.status === 'active' && { label: 'Record donation', icon: HandCoins, onClick: () => setAdding('alumni_donations') },
                  can('alumni', 'create') && a.status === 'active' && { label: 'Assign a mentee', icon: UserPlus, onClick: () => setAdding('alumni_mentorships') },
                  can('alumni', 'create') && { label: 'Post a job opportunity', icon: Briefcase, onClick: () => setAdding('alumni_jobs') },
                  can('alumni', 'create') && { label: 'Add success story', icon: Trophy, onClick: () => setAdding('alumni_success_stories') },
                  can('alumni', 'edit') && a.status === 'active' && { divider: true, label: '' },
                  can('alumni', 'edit') && a.status === 'active' && { label: a.is_mentor ? 'Remove from mentor pool' : 'Add to mentor pool', icon: a.is_mentor ? UserMinus : Handshake, onClick: toggleMentor },
                  can('alumni', 'edit') && !a.is_verified && { label: 'Mark verified', icon: BadgeCheck, onClick: verify },
                ]}
              />
            )}
          </>
        }
      />
      {error && (error as ApiError).status !== 404 ? <Alert variant="error" title="Unable to load this profile" className="mb-6">{(error as Error).message}</Alert> : null}
      {a && !a.is_verified && (
        <Alert
          variant="warning"
          title="This profile has not been verified yet"
          className="mb-6"
          action={can('alumni', 'edit') ? <Button size="sm" variant="secondary" icon={BadgeCheck} loading={verifying} onClick={verify}>Mark verified</Button> : undefined}
        >
          {a.student_id ? 'Linked to a student record — confirm the current career details before verifying.' : 'Added manually or registered on the website — check the batch, program and contact details against institute records before verifying.'}
        </Alert>
      )}

      {isLoading || !a || !s ? (
        <div className="space-y-6"><CardSkeleton lines={5} /><CardSkeleton lines={6} /></div>
      ) : (
        <>
          <Reveal>
            <Card className="mb-6 overflow-hidden">
              <div className="h-20 bg-gradient-to-r from-brand-900 via-brand-800 to-brand-600 sm:h-24" aria-hidden />
              <div className="flex flex-col gap-5 px-5 pb-5 lg:flex-row lg:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                  <Avatar name={a.name} src={a.photo as string | null} size="xl" className="-mt-12 !h-24 !w-24 shrink-0 text-2xl ring-4 ring-white dark:ring-slate-900" />
                  <div className="min-w-0 sm:pt-3">
                    <h2 className="flex flex-wrap items-center gap-2 font-display text-xl font-bold text-slate-900 dark:text-white">
                      {a.name}
                      {a.is_verified && <BadgeCheck className="h-5 w-5 text-brand-600" aria-label="Verified profile" />}
                    </h2>
                    <p className="text-sm text-slate-600 dark:text-slate-300">{[a.designation, a.company].filter(Boolean).join(' · ') || a.employment_label}</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      <Badge color="navy">Batch {a.batch_year}</Badge>
                      {a.program_short && <Badge color="blue">{String(a.program_short)}</Badge>}
                      <StatusBadge status={String(a.employment_status)} label={a.employment_label} colors={EMPLOYMENT_COLORS} />
                      {a.is_mentor && <Badge color="purple"><Handshake className="h-3 w-3" /> Mentor</Badge>}
                      {a.show_on_website && <Badge color="cyan">On website</Badge>}
                      {a.status !== 'active' && <Badge color="red">Inactive</Badge>}
                    </div>
                  </div>
                </div>
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:mt-4 lg:w-[30rem]">
                  {[
                    { label: 'Events attended', value: formatNumber(s.events_attended), tone: 'orange' as Tone },
                    { label: 'Donated', value: formatMoneyShort(s.donations_total), tone: 'green' as Tone },
                    { label: 'Mentees', value: formatNumber(s.mentees_total), tone: 'purple' as Tone },
                    { label: 'Jobs posted', value: formatNumber(s.jobs_posted), tone: 'cyan' as Tone },
                  ].map((x) => (
                    <div key={x.label} className={clsx('rounded-xl px-3 py-2.5', toneClasses[x.tone].bg)}>
                      <dt className="truncate text-[11px] font-medium text-slate-500 dark:text-slate-400">{x.label}</dt>
                      <dd className={clsx('font-display text-lg font-bold', toneClasses[x.tone].text)}>{x.value}</dd>
                    </div>
                  ))}
                </dl>
              </div>
              <div className="flex flex-wrap gap-x-6 gap-y-2 border-t border-slate-100 px-5 py-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">
                {a.email && <a href={`mailto:${a.email}`} className="inline-flex items-center gap-1.5 hover:text-brand-700"><Mail className="h-4 w-4 text-slate-400" />{String(a.email)}</a>}
                {a.phone && <a href={`tel:${String(a.phone).replace(/\s/g, '')}`} className="inline-flex items-center gap-1.5 hover:text-brand-700"><Phone className="h-4 w-4 text-slate-400" />{String(a.phone)}</a>}
                {(a.city || a.country) && <span className="inline-flex items-center gap-1.5"><MapPin className="h-4 w-4 text-slate-400" />{[a.city, a.country].filter(Boolean).join(', ')}</span>}
                {a.linkedin_url && <a href={String(a.linkedin_url)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 hover:text-brand-700"><ExternalLink className="h-4 w-4 text-slate-400" />LinkedIn profile</a>}
                {a.student_id && can('students') && <Link to={`/students/${a.student_id}`} className="inline-flex items-center gap-1.5 hover:text-brand-700"><GraduationCap className="h-4 w-4 text-slate-400" />Student record {String(a.student_uid ?? '')}</Link>}
              </div>
            </Card>
          </Reveal>

          <Tabs
            className="mb-5"
            value={tab}
            onChange={setTab}
            tabs={[
              { key: 'overview', label: 'Overview', icon: UserRound },
              { key: 'mentorships', label: 'Mentorships', icon: Handshake, count: data.mentorships.length },
              { key: 'donations', label: 'Donations', icon: HandCoins, count: data.donations.length },
              { key: 'events', label: 'Events', icon: CalendarDays, count: s.events_registered },
              { key: 'jobs', label: 'Jobs posted', icon: Briefcase, count: s.jobs_posted },
              { key: 'stories', label: 'Stories', icon: Trophy, count: s.stories },
            ]}
          />

          {tab === 'overview' && (
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
              <div className="space-y-6 xl:col-span-2">
                <Card>
                  <CardHeader title="About" icon={Sparkles} />
                  <div className="card-body !pt-0 space-y-4">
                    {a.bio ? <p className="whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-200">{String(a.bio)}</p> : <p className="text-sm text-slate-500">No bio added yet.</p>}
                    {a.achievements && (
                      <div className="flex gap-3 rounded-xl bg-amber-50/70 p-3 dark:bg-amber-500/10">
                        <Award className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                        <p className="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{String(a.achievements)}</p>
                      </div>
                    )}
                  </div>
                </Card>
                <Card>
                  <CardHeader title="Career & education" icon={Briefcase} />
                  <div className="card-body !pt-0">
                    <DescriptionList
                      items={[
                        { label: 'Career status', value: a.employment_label },
                        { label: 'Industry', value: a.industry as string },
                        { label: 'Company', value: a.company as string },
                        { label: 'Designation', value: a.designation as string },
                        { label: 'Program', value: a.program_name as string },
                        { label: 'Department', value: a.department_name as string },
                        { label: 'Batch (passing year)', value: `${a.batch_year} · ${a.years_since} year${a.years_since === 1 ? '' : 's'} since graduation` },
                        { label: 'Enrollment no.', value: a.enrollment_no as string },
                        { label: 'Profile added', value: formatDate(a.created_at) },
                        { label: 'Last updated', value: a.updated_at ? formatDateTime(a.updated_at) : null },
                      ]}
                    />
                  </div>
                </Card>
              </div>
              <Card>
                <CardHeader title="Engagement timeline" subtitle="Events, donations, mentoring and more" icon={CalendarHeart} />
                <div className="card-body !pt-0">
                  <Timeline
                    items={data.timeline.map<TimelineItem>((t) => ({
                      icon: timelineStyle[t.type]?.icon,
                      tone: timelineStyle[t.type]?.tone,
                      title: t.title,
                      description: t.sub ?? undefined,
                      time: t.date ? formatDate(t.date) : undefined,
                    }))}
                  />
                </div>
              </Card>
            </div>
          )}

          {tab === 'mentorships' && (
            <CrudTable module="alumni_mentorships" renderers={mentorshipRenderers} scope={{ alumni_id: a.id }} title="Mentees" hideColumns={['mentor_name']} addLabel="Assign a mentee"
              onCreate={() => setAdding('alumni_mentorships')} onSaved={refreshAll} emptyTitle="No mentees yet" emptyText="Pair this alumni with a final-year student." />
          )}
          {tab === 'donations' && (
            <CrudTable module="alumni_donations" renderers={donationRenderers} scope={{ alumni_id: a.id }} title="Donations" description={`${formatMoney(s.donations_total)} received across ${s.donations_count} donation${s.donations_count === 1 ? '' : 's'}`}
              hideColumns={['donor_name']} addLabel="Record donation" onCreate={() => setAdding('alumni_donations')} onSaved={refreshAll}
              rowMenu={(r) => [{ label: receiptLabel(r.status), icon: Printer, onClick: () => window.open(printUrl('donation-receipt.php', { id: Number(r.id) }), '_blank') }]}
              emptyTitle="No donations yet" emptyText="Record a donation from this alumni." />
          )}
          {tab === 'events' && (
            <Card>
              <CardHeader title="Event participation" subtitle={`${s.events_attended} attended · ${s.events_registered} registered`} icon={CalendarHeart}
                actions={<Link to="/alumni/events" className="link text-xs">All events</Link>} />
              {data.events.length === 0 ? (
                <EmptyState icon={CalendarHeart} title="No event registrations" description="Register this alumni for an event from the Alumni Events page." action={<Button size="sm" to="/alumni/events" icon={CalendarDays}>Go to events</Button>} />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.events.map((e) => (
                    <li key={String(e.registration_id)} className="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center">
                      <div className="flex shrink-0 flex-col items-center justify-center rounded-xl bg-brand-50 px-3 py-1.5 text-center dark:bg-brand-500/10 sm:w-16">
                        <span className="text-[10px] font-semibold uppercase text-brand-700 dark:text-brand-300">{new Date(String(e.event_date).replace(' ', 'T')).toLocaleDateString('en-GB', { month: 'short' })}</span>
                        <span className="font-display text-lg font-bold leading-none text-brand-900 dark:text-white">{new Date(String(e.event_date).replace(' ', 'T')).getDate()}</span>
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{String(e.title)}</p>
                        <p className="truncate text-xs text-slate-500">{formatDateTime(e.event_date)} · {EVENT_MODE_LABELS[String(e.mode)] ?? String(e.mode)}{e.venue ? ` · ${e.venue}` : ''}</p>
                      </div>
                      <div className="flex gap-1.5">
                        <StatusBadge status={String(e.type)} label={EVENT_TYPE_LABELS[String(e.type)]} colors={EVENT_TYPE_COLORS} dot={false} />
                        <StatusBadge status={String(e.registration_status)} label={REG_STATUS_LABELS[String(e.registration_status)]} colors={REG_STATUS_COLORS} />
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}
          {tab === 'jobs' && (
            <CrudTable module="alumni_jobs" renderers={jobRenderers} scope={{ posted_by_alumni_id: a.id }} title="Job opportunities posted" hideColumns={['poster_name']} addLabel="Post a job"
              onCreate={() => setAdding('alumni_jobs')} onSaved={refreshAll} emptyTitle="No jobs posted" emptyText="Share an opening on behalf of this alumni." />
          )}
          {tab === 'stories' && (
            <CrudTable module="alumni_success_stories" renderers={storyRenderers} scope={{ alumni_id: a.id }} title="Success stories" hideColumns={['alumni_name']} addLabel="Add story"
              onCreate={() => setAdding('alumni_success_stories')} onSaved={refreshAll} emptyTitle="No success stories" emptyText="Write this alumni's story for the website and newsletters." />
          )}
        </>
      )}

      <CrudFormModal open={editOpen} onClose={() => setEditOpen(false)} meta={meta} module="alumni" id={id} onSaved={refreshAll} />
      <FixedCrudFormModal
        open={!!adding && !!a}
        onClose={() => setAdding(null)}
        module={adding ?? 'alumni_donations'}
        fixed={{ [fixedField]: a?.id }}
        title={adding ? addTitles[adding] : undefined}
        context={fixedContext}
        onSaved={refreshAll}
      />
    </>
  );
}
