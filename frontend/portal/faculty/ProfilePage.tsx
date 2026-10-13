import { Link } from 'react-router-dom';
import { ArrowRight, BookOpen, Briefcase, Contact, GraduationCap, History, IdCard, Info, Mail, Phone } from 'lucide-react';
import { formatDate, formatDateTime, labelize, timeAgo } from '@/lib/format';
import { AppearanceCard, LoginHistory, SecurityCard } from '../components/Account';
import { EmptyBlock, ErrorState, InfoGrid, PageHead, PortalAvatar, Section } from '../components/Ui';
import { areaPath } from '../lib/config';
import { usePortalQuery } from '../lib/hooks';
import { useFacultySession } from '../lib/session';
import { CorrectionCard } from './shared/CorrectionCard';
import type { FacultyProfile } from './types';

const v = (x: unknown) => (x === null || x === undefined || x === '' ? null : String(x));

export default function FacultyProfilePage() {
  const session = useFacultySession();
  const { data, isLoading, error, refetch } = usePortalQuery<FacultyProfile>('faculty', ['profile'], 'profile');
  const p = data?.profile;

  return (
    <div>
      <PageHead area="faculty" title="My Profile" subtitle="Your faculty record and account security." crumbs={[{ label: 'Dashboard', to: '/faculty' }, { label: 'My Profile' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !p || !data ? (
        <div className="space-y-5">
          <div className="skeleton h-44 rounded-3xl" />
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div className="skeleton h-80 rounded-2xl lg:col-span-2" />
            <div className="skeleton h-80 rounded-2xl" />
          </div>
        </div>
      ) : (
        <div className="space-y-5">
          <section className="card overflow-hidden">
            <div className="hero-navy relative h-24 sm:h-28">
              <div className="pattern-dots absolute inset-0 opacity-50" aria-hidden />
            </div>
            <div className="relative px-5 pb-5 sm:px-7">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-5">
                  <PortalAvatar name={String(p.full_name)} src={p.photo} size="xl" className="-mt-10 ring-4 ring-white sm:-mt-12 dark:ring-slate-900" />
                  <div className="min-w-0 sm:pt-3">
                    <h2 className="truncate font-display text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">{p.full_name}</h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">{[p.designation, p.department_name].filter(Boolean).join(' · ')}</p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2 lg:pt-4">
                  <span className="badge badge-navy">
                    <IdCard className="h-3.5 w-3.5" aria-hidden /> {p.employee_id}
                  </span>
                  <span className={`badge ${p.status === 'active' ? 'badge-green' : 'badge-amber'}`}>{labelize(String(p.status))}</span>
                  {p.employment_type && <span className="badge badge-slate">{labelize(String(p.employment_type))}</span>}
                </div>
              </div>
              <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {[
                  ['Joined', p.joining_date ? formatDate(p.joining_date) : null],
                  ['Experience', p.experience_years ? `${p.experience_years} years` : null],
                  ['Qualification', v(p.qualification)],
                  ['Publications', v(p.publications_count)],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/50">
                    <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{label}</dt>
                    <dd className="mt-0.5 truncate text-sm font-bold text-slate-800 dark:text-slate-100">{value ?? '—'}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </section>

          <CorrectionCard
            current={{
              name: v(p.full_name),
              dob: p.dob ? formatDate(p.dob) : null,
              phone: v(p.phone),
              alternate_phone: v(p.alternate_phone),
              email: v(p.email),
              address: [p.address, p.city, p.state, p.pincode].filter(Boolean).join(', ') || null,
              qualification: v(p.qualification),
              specialization: v(p.specialization),
              experience_years: p.experience_years ? `${p.experience_years} years` : null,
              designation: v(p.designation),
              department: v(p.department_name),
              joining_date: p.joining_date ? formatDate(p.joining_date) : null,
              employment_type: p.employment_type ? labelize(String(p.employment_type)) : null,
              bank: v(p.bank_account_masked),
            }}
          />

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div className="space-y-5 lg:col-span-2">
              <Section title="Subjects this session" icon={BookOpen} subtitle={session.app.academic_session ? `Session ${session.app.academic_session}` : undefined} bodyClassName="p-3 sm:p-4">
                {data.subjects.length === 0 ? (
                  <EmptyBlock compact icon={BookOpen} title="No subjects assigned" text="Subject allocations made by your department appear here." />
                ) : (
                  <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {data.subjects.map((s, i) => (
                      <li key={`${s.code}-${s.section ?? ''}-${i}`} className="flex items-center gap-3 rounded-xl border border-slate-200/80 px-3 py-2.5 dark:border-slate-800">
                        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-[11px] font-bold text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">S{s.semester_no}</span>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
                          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                            {s.code} · {s.program}
                            {s.section ? ` · Sec ${s.section}` : ''}
                          </p>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </Section>

              <Section title="Professional details" icon={Briefcase}>
                <InfoGrid
                  items={[
                    { label: 'Designation', value: v(p.designation) },
                    { label: 'Department', value: v(p.department_name) },
                    { label: 'Qualification', value: v(p.qualification) },
                    { label: 'Specialisation', value: v(p.specialization) },
                    { label: 'Employment type', value: p.employment_type ? labelize(String(p.employment_type)) : null },
                    { label: 'Joining date', value: p.joining_date ? formatDate(p.joining_date) : null },
                    { label: 'Research interests', value: v(p.research_interests), full: true },
                    { label: 'About', value: v(p.bio), full: true },
                  ]}
                />
              </Section>

              <Section title="Contact & personal" icon={Contact}>
                <InfoGrid
                  items={[
                    { label: 'Email', value: p.email ? <span className="inline-flex items-center gap-1.5 break-all"><Mail className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />{p.email}</span> : null },
                    { label: 'Phone', value: p.phone ? <span className="inline-flex items-center gap-1.5"><Phone className="h-3.5 w-3.5 text-slate-400" aria-hidden />{p.phone}</span> : null },
                    { label: 'Alternate phone', value: v(p.alternate_phone) },
                    { label: 'Gender', value: p.gender ? labelize(String(p.gender)) : null },
                    { label: 'Date of birth', value: p.dob ? formatDate(p.dob) : null },
                    { label: 'City / state', value: [p.city, p.state, p.pincode].filter(Boolean).join(', ') || null },
                    { label: 'Address', value: v(p.address), full: true },
                  ]}
                />
              </Section>

              <Section
                title="Payroll reference"
                icon={GraduationCap}
                subtitle="Masked for your security"
                action={
                  <Link to={areaPath('faculty', 'payroll')} className="link inline-flex items-center gap-1 text-[13px]">
                    Payroll <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                  </Link>
                }
              >
                <InfoGrid
                  items={[
                    { label: 'Bank account', value: v(p.bank_account_masked) },
                    { label: 'PAN', value: v(p.pan_masked) },
                  ]}
                />
              </Section>
            </div>

            <div className="space-y-5">
              <SecurityCard
                passwordTo={areaPath('faculty', 'change-password')}
                items={[
                  { label: 'Username', value: data.account.username },
                  { label: 'Login email', value: <span className="break-all">{data.account.email}</span> },
                  { label: 'Role', value: data.account.roles },
                  { label: 'Password changed', value: data.account.password_changed_at ? timeAgo(data.account.password_changed_at) : null },
                  { label: 'Previous sign-in', value: session.account?.last_login_at ? formatDateTime(session.account.last_login_at) : 'First sign-in' },
                ]}
                note="Always sign out when you use a shared or lab computer."
              />
              <AppearanceCard />
              <Section title="Recent sign-ins" icon={History} subtitle="If you don't recognise an entry, change your password." bodyClassName="p-3 sm:p-4">
                <LoginHistory rows={data.logins} />
              </Section>
              <p className="flex items-start gap-2 px-1 text-xs text-slate-500 dark:text-slate-400">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                Your record is visible only to you and authorised institute staff.
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
