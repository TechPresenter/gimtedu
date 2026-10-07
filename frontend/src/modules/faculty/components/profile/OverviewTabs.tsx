import { Link } from 'react-router-dom';
import { ArrowRight, CalendarClock, ExternalLink, Globe, KeyRound, Lightbulb, MapPin, ShieldCheck, Sparkles, UserRound } from 'lucide-react';
import { Badge, Button, Card, CardHeader, DescriptionList, EmptyState, Reveal, StatusBadge } from '@/components/ui';
import { formatDate, formatDateTime, formatNumber, labelize, timeAgo } from '@/lib/format';
import { daysLabel, STAFF_CATEGORIES, type EmployeeType, type ProfilePayload } from '../../hr';
import { LeaveBalanceList } from '../LeaveBalance';

function age(dob: string | null): string {
  if (!dob) return '';
  const d = new Date(`${dob}T00:00:00`);
  const now = new Date();
  let a = now.getFullYear() - d.getFullYear();
  if (now.getMonth() < d.getMonth() || (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())) a--;
  return ` (${a} yrs)`;
}

export function OverviewTab({ type, data, onTab, onCreateLogin }: { type: EmployeeType; data: ProfilePayload; onTab: (t: string) => void; onCreateLogin?: () => void }) {
  const p = data.person;
  const interests = (p.research_interests ?? '').split(/[,;]/).map((s) => s.trim()).filter(Boolean);
  const address = [p.address, p.city, p.state, p.pincode].filter(Boolean).join(', ');
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div className="space-y-4 lg:col-span-2">
        <Reveal>
          <Card>
            <CardHeader title={type === 'faculty' ? 'About' : 'Role summary'} icon={UserRound} />
            <div className="card-body space-y-4">
              {type === 'faculty' ? (
                p.bio ? <p className="text-sm leading-relaxed text-slate-700 dark:text-slate-300">{p.bio}</p> : <p className="text-sm text-slate-500">No bio added yet. Edit the profile to add a short introduction for the website.</p>
              ) : (
                <p className="text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                  {p.full_name} works as <strong className="font-semibold text-slate-900 dark:text-white">{p.designation}</strong> in {STAFF_CATEGORIES[p.category ?? ''] ?? labelize(p.category)}
                  {p.section ? <> ({p.section})</> : null}
                  {p.department_name ? <>, attached to the {p.department_name}</> : null}
                  {p.joining_date ? <>, since {formatDate(p.joining_date)}</> : null}.
                </p>
              )}
              {interests.length > 0 && (
                <div>
                  <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500"><Lightbulb className="h-3.5 w-3.5" /> Research interests</p>
                  <div className="flex flex-wrap gap-1.5">
                    {interests.map((i) => <Badge key={i} color="navy">{i}</Badge>)}
                  </div>
                </div>
              )}
              {type === 'faculty' && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {[
                    { k: 'Qualification', v: p.qualification },
                    { k: 'Specialization', v: p.specialization },
                    { k: 'Publications', v: p.publications_count !== null && p.publications_count !== undefined ? formatNumber(p.publications_count) : null },
                    { k: 'On website', v: Number(p.show_on_website) ? 'Listed' : 'Hidden' },
                  ].map((x) => (
                    <div key={x.k} className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/50">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{x.k}</p>
                      <p className="mt-0.5 truncate text-sm font-medium text-slate-800 dark:text-slate-100" title={String(x.v ?? '')}>{x.v || '—'}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card>
            <CardHeader title="Contact & personal details" icon={MapPin} />
            <div className="card-body">
              <DescriptionList
                items={[
                  { label: type === 'faculty' ? 'Official email' : 'Email', value: p.email ? <a className="link !font-medium" href={`mailto:${p.email}`}>{p.email}</a> : null },
                  { label: 'Mobile', value: p.phone ? <a className="hover:text-brand-700" href={`tel:${p.phone.replace(/\s/g, '')}`}>{p.phone}</a> : null },
                  { label: 'Alternate phone', value: p.alternate_phone },
                  { label: 'Gender', value: p.gender ? labelize(p.gender) : null },
                  { label: 'Date of birth', value: p.dob ? `${formatDate(p.dob)}${age(p.dob)}` : null },
                  { label: 'Department', value: p.department_name },
                  { label: 'Address', value: address || null, full: true },
                ]}
              />
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="space-y-4">
        <Reveal delay={40}>
          <Card>
            <CardHeader
              title="Leave balance"
              subtitle={`Session ${data.session.name}`}
              actions={<button type="button" onClick={() => onTab('leaves')} className="link inline-flex items-center gap-1 text-xs">All leaves <ArrowRight className="h-3 w-3" /></button>}
            />
            <div className="card-body">
              <LeaveBalanceList type={type} id={p.id} />
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card>
            <CardHeader title="Upcoming leave" icon={CalendarClock} />
            <div className="p-2">
              {data.upcoming_leaves.length ? (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.upcoming_leaves.map((l) => (
                    <li key={l.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{l.leave_type_name}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{formatDate(l.from_date)}{l.to_date !== l.from_date ? ` – ${formatDate(l.to_date)}` : ''} · {daysLabel(l.days)}</p>
                      </div>
                      <StatusBadge status={l.status} />
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="px-3 py-6 text-center text-sm text-slate-500">No upcoming leave.</p>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={120}>
          <Card>
            <CardHeader title="Login account" icon={KeyRound} />
            <div className="card-body">
              {data.account ? (
                <dl className="space-y-2.5 text-sm">
                  <div className="flex justify-between gap-3"><dt className="text-slate-500">Username</dt><dd className="font-semibold text-slate-900 dark:text-white">{data.account.username}</dd></div>
                  <div className="flex justify-between gap-3"><dt className="text-slate-500">Role</dt><dd className="text-right">{data.account.roles ?? '—'}</dd></div>
                  <div className="flex justify-between gap-3"><dt className="text-slate-500">Status</dt><dd><StatusBadge status={data.account.status} /></dd></div>
                  <div className="flex justify-between gap-3"><dt className="text-slate-500">Last login</dt><dd className="text-right" title={data.account.last_login_at ? formatDateTime(data.account.last_login_at) : undefined}>{data.account.last_login_at ? timeAgo(data.account.last_login_at) : 'Never'}</dd></div>
                  {Number(data.account.must_change_password) === 1 && <p className="flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200"><ShieldCheck className="h-3.5 w-3.5" /> Password change pending at first login</p>}
                </dl>
              ) : (
                <EmptyState
                  icon={KeyRound}
                  title="No login account"
                  description="Give SmartCampus access for attendance, marks and leave requests."
                  className="!px-2 !py-4"
                  action={onCreateLogin ? <Button size="sm" icon={Sparkles} onClick={onCreateLogin}>Create login</Button> : undefined}
                />
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </div>
  );
}

export function ProfessionalTab({ type, data }: { type: EmployeeType; data: ProfilePayload }) {
  const p = data.person;
  const tenure = data.stats.tenure_years !== null ? `${formatNumber(data.stats.tenure_years, 1)} years` : null;
  const items =
    type === 'faculty'
      ? [
          { label: 'Employee ID', value: <code className="text-sm font-semibold">{p.employee_id}</code> },
          { label: 'Designation', value: p.designation },
          { label: 'Department', value: p.department_name ? <>{p.department_name}{p.is_hod_of ? <Badge color="green" className="ml-2">Head of Department</Badge> : null}</> : null },
          { label: 'Qualification', value: p.qualification },
          { label: 'Specialization', value: p.specialization },
          { label: 'Total experience', value: p.experience_years !== null && p.experience_years !== undefined ? `${formatNumber(p.experience_years, 1)} years` : null },
          { label: 'Joining date', value: p.joining_date ? formatDate(p.joining_date) : null },
          { label: 'Service at GIMT', value: tenure },
          { label: 'Employment type', value: <StatusBadge status={p.employment_type} colors={{ permanent: 'navy', contract: 'cyan', visiting: 'purple', guest: 'slate', probation: 'amber' }} /> },
          { label: 'Status', value: <StatusBadge status={p.status} /> },
          { label: 'Publications', value: p.publications_count !== null && p.publications_count !== undefined ? formatNumber(p.publications_count) : null },
          { label: 'Website listing', value: Number(p.show_on_website) ? <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><Globe className="h-3.5 w-3.5" /> Shown on public faculty page</span> : 'Hidden from website' },
          { label: 'Profile link', value: p.linkedin_url ? <a href={p.linkedin_url} target="_blank" rel="noreferrer" className="link inline-flex items-center gap-1">{p.linkedin_url.replace(/^https?:\/\/(www\.)?/, '')} <ExternalLink className="h-3 w-3" /></a> : null, full: true },
          { label: 'Research interests', value: p.research_interests, full: true },
        ]
      : [
          { label: 'Employee ID', value: <code className="text-sm font-semibold">{p.employee_id}</code> },
          { label: 'Designation', value: p.designation },
          { label: 'Category', value: STAFF_CATEGORIES[p.category ?? ''] ?? labelize(p.category) },
          { label: 'Office / section', value: p.section },
          { label: 'Department', value: p.department_name },
          { label: 'Qualification', value: p.qualification },
          { label: 'Joining date', value: p.joining_date ? formatDate(p.joining_date) : null },
          { label: 'Service at GIMT', value: tenure },
          { label: 'Employment type', value: <StatusBadge status={p.employment_type} colors={{ permanent: 'navy', contract: 'cyan', probation: 'amber', outsourced: 'purple', part_time: 'slate' }} /> },
          { label: 'Status', value: <StatusBadge status={p.status} /> },
        ];
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <Reveal className="lg:col-span-2">
        <Card>
          <CardHeader title={type === 'faculty' ? 'Professional details' : 'Employment details'} subtitle={`Last updated ${p.updated_at ? timeAgo(p.updated_at) : timeAgo(p.created_at)}`} />
          <div className="card-body">
            <DescriptionList items={items} columns={2} />
          </div>
        </Card>
      </Reveal>
      <Reveal delay={60}>
        <Card>
          <CardHeader title="Service timeline" />
          <div className="card-body">
            <ol className="relative space-y-5 border-l border-slate-200 pl-5 dark:border-slate-800">
              {p.joining_date && (
                <li>
                  <span className="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-accent-600 ring-4 ring-white dark:ring-slate-900" />
                  <p className="text-sm font-medium text-slate-800 dark:text-slate-100">Joined as {p.designation}</p>
                  <p className="text-xs text-slate-500">{formatDate(p.joining_date)}</p>
                </li>
              )}
              {p.is_hod_of && (
                <li>
                  <span className="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-brand-700 ring-4 ring-white dark:ring-slate-900" />
                  <p className="text-sm font-medium text-slate-800 dark:text-slate-100">Head of {p.is_hod_of}</p>
                  <p className="text-xs text-slate-500">Current responsibility</p>
                </li>
              )}
              <li>
                <span className="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-amber-500 ring-4 ring-white dark:ring-slate-900" />
                <p className="text-sm font-medium text-slate-800 dark:text-slate-100">Session {data.session.name}</p>
                <p className="text-xs text-slate-500">
                  {daysLabel(data.stats.leave_taken)} leave availed{type === 'faculty' ? ` · ${data.stats.subjects ?? 0} subjects` : ''}
                </p>
              </li>
            </ol>
            <div className="mt-5 rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
              Record created {formatDate(p.created_at)}.{' '}
              <Link to={`/leaves?tab=applications&q=${encodeURIComponent(p.employee_id)}`} className="link">See leave applications</Link>
            </div>
          </div>
        </Card>
      </Reveal>
    </div>
  );
}
