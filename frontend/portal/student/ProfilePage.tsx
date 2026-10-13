import { useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpen, Contact, FolderOpen, GraduationCap, History, IdCard, Info, Mail, MapPin, PencilLine, Phone, UsersRound } from 'lucide-react';
import { Alert } from '@/components/ui';
import { CorrectionModal } from './shared/CorrectionModal';
import { useStudent } from './shared/ui';
import { formatDate, formatDateTime, labelize, timeAgo } from '@/lib/format';
import { AppearanceCard, LoginHistory, SecurityCard } from '../components/Account';
import { ErrorState, InfoGrid, PageHead, PortalAvatar, Section } from '../components/Ui';
import { areaPath } from '../lib/config';
import { useStudentSession } from '../lib/session';
import type { StudentProfile } from './types';

const v = (x: unknown) => (x === null || x === undefined || x === '' ? null : String(x));

export default function StudentProfilePage() {
  const session = useStudentSession();
  const { data, isLoading, error, refetch } = useStudent<StudentProfile>(['profile'], 'profile');
  const p = data?.profile;
  const support = session.app.support_email;
  const [correcting, setCorrecting] = useState(false);

  return (
    <div>
      <PageHead area="student" title="My Profile" subtitle="Your details as recorded by the institute." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'My Profile' }]} />
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
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                      {v(p.program_full_name)} · Semester {v(p.current_semester)}
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2 lg:pt-4">
                  <span className="badge badge-navy">
                    <IdCard className="h-3.5 w-3.5" aria-hidden /> {p.student_uid}
                  </span>
                  <span className={`badge ${p.status === 'active' ? 'badge-green' : 'badge-blue'}`}>{labelize(String(p.status))}</span>
                  {p.valid_until && <span className="badge badge-slate">ID valid till {formatDate(p.valid_until)}</span>}
                </div>
              </div>
              <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {[
                  ['Roll number', v(p.roll_no)],
                  ['Enrollment no.', v(p.enrollment_no)],
                  ['Admission no.', v(p.admission_no)],
                  ['Batch', v(p.batch_name)],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/50">
                    <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{label}</dt>
                    <dd className="mt-0.5 truncate text-sm font-bold text-slate-800 dark:text-slate-100">{value ?? '—'}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </section>

          <Alert variant="info">
            Spotted a mistake in your details? Send a correction request to the academic office{support ? (
              <>
                {' '}
                (or write to <a className="font-semibold underline" href={`mailto:${support}`}>{support}</a>)
              </>
            ) : null}
            . Corrections are made in the ERP and show up here automatically; you can track the request under Grievances &amp; Feedback.
            <div className="mt-3 flex flex-wrap gap-2">
              <button type="button" className="btn btn-primary btn-sm" onClick={() => setCorrecting(true)}>
                <PencilLine className="h-4 w-4" aria-hidden /> Request a correction
              </button>
              <Link to={areaPath('student', 'documents')} className="btn btn-secondary btn-sm">
                <FolderOpen className="h-4 w-4" aria-hidden /> My documents
              </Link>
            </div>
          </Alert>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div className="space-y-5 lg:col-span-2">
              <Section title="Academic details" icon={GraduationCap}>
                <InfoGrid
                  items={[
                    { label: 'Program', value: v(p.program_full_name) },
                    { label: 'Course / specialisation', value: v(p.course_name) },
                    { label: 'Department', value: v(p.department_name) },
                    { label: 'Level', value: v(p.program_level) },
                    { label: 'Semester', value: p.total_semesters ? `${p.current_semester} of ${p.total_semesters}` : v(p.current_semester) },
                    { label: 'Section', value: v(p.section_name) },
                    { label: 'Academic session', value: v(p.session_name) },
                    { label: 'Admission date', value: p.admission_date ? formatDate(p.admission_date) : null },
                    { label: 'Admission type', value: p.admission_type ? labelize(String(p.admission_type)) : null },
                    { label: 'Hostel / transport', value: [Number(p.is_hosteller) ? 'Hosteller' : 'Day scholar', Number(p.uses_transport) ? 'Uses college transport' : null].filter(Boolean).join(' · ') },
                  ]}
                />
              </Section>

              <Section title="Personal details" icon={BookOpen}>
                <InfoGrid
                  items={[
                    { label: 'Full name', value: v(p.full_name) },
                    { label: 'Gender', value: p.gender ? labelize(String(p.gender)) : null },
                    { label: 'Date of birth', value: p.dob ? `${formatDate(p.dob)}${p.age ? ` (${p.age} yrs)` : ''}` : null },
                    { label: 'Blood group', value: v(p.blood_group) },
                    { label: 'Category', value: v(p.category) },
                    { label: 'Nationality', value: v(p.nationality) },
                    { label: 'Aadhaar', value: v(p.aadhaar_masked) },
                  ]}
                />
              </Section>

              <Section title="Contact & address" icon={Contact}>
                <InfoGrid
                  items={[
                    { label: 'Mobile', value: p.mobile ? <a href={`tel:${String(p.mobile).replace(/[^\d+]/g, '')}`} className="inline-flex items-center gap-1.5 hover:text-flame-700"><Phone className="h-3.5 w-3.5 text-slate-400" aria-hidden />{p.mobile}</a> : null },
                    { label: 'Email', value: p.email ? <span className="inline-flex items-center gap-1.5 break-all"><Mail className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />{p.email}</span> : null },
                    { label: 'WhatsApp', value: v(p.whatsapp) },
                    { label: 'City / state', value: [p.city, p.state, p.pincode].filter(Boolean).join(', ') || null },
                    { label: 'Current address', value: p.address ? <span className="inline-flex items-start gap-1.5"><MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />{[p.address, p.city, p.state, p.pincode].filter(Boolean).join(', ')}</span> : null, full: true },
                    { label: 'Permanent address', value: v(p.permanent_address), full: true },
                  ]}
                />
              </Section>

              <Section title="Parents & guardian" icon={UsersRound}>
                {data.parents.length === 0 && !p.guardian_name ? (
                  <p className="text-sm text-slate-500">No parent or guardian details on record.</p>
                ) : (
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {data.parents.map((g) => (
                      <div key={g.id} className="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">
                        <div className="flex items-center justify-between gap-2">
                          <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{labelize(g.relation)}</p>
                          {g.is_emergency_contact && <span className="badge badge-flame">Emergency contact</span>}
                        </div>
                        <p className="mt-1 font-semibold text-slate-900 dark:text-white">{g.name}</p>
                        {g.occupation && <p className="text-xs text-slate-500">{g.occupation}</p>}
                        {g.phone && <p className="mt-1.5 text-[13px] text-slate-600 dark:text-slate-300">{g.phone}</p>}
                      </div>
                    ))}
                    {data.parents.length === 0 && p.guardian_name && (
                      <div className="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{v(p.guardian_relation) ?? 'Guardian'}</p>
                        <p className="mt-1 font-semibold text-slate-900 dark:text-white">{p.guardian_name}</p>
                        {p.guardian_phone && <p className="mt-1.5 text-[13px] text-slate-600 dark:text-slate-300">{p.guardian_phone}</p>}
                      </div>
                    )}
                  </div>
                )}
                {p.emergency_contact_name && (
                  <p className="mt-4 text-[13px] text-slate-600 dark:text-slate-300">
                    <span className="font-semibold">Emergency contact:</span> {p.emergency_contact_name}
                    {p.emergency_contact_phone ? ` · ${p.emergency_contact_phone}` : ''}
                  </p>
                )}
              </Section>
            </div>

            <div className="space-y-5">
              <SecurityCard
                passwordTo={areaPath('student', 'change-password')}
                items={[
                  { label: 'Username', value: data.account.username },
                  { label: 'Activated', value: data.account.activated_at ? formatDate(data.account.activated_at) : 'Not yet' },
                  { label: 'How', value: data.account.activation_method ? labelize(data.account.activation_method === 'self' ? 'self_activated' : data.account.activation_method === 'seed' ? 'institute' : data.account.activation_method) : null },
                  { label: 'Password changed', value: data.account.password_changed_at ? timeAgo(data.account.password_changed_at) : null },
                  { label: 'Previous sign-in', value: session.account?.last_login_at ? formatDateTime(session.account.last_login_at) : 'First sign-in' },
                  { label: 'Total sign-ins', value: String(data.account.login_count) },
                ]}
                note="Always sign out when you use a shared or lab computer."
              />
              <AppearanceCard />
              <Section title="Recent sign-ins" icon={History} subtitle="If you don't recognise an entry, change your password." bodyClassName="p-3 sm:p-4">
                <LoginHistory rows={data.logins} />
              </Section>
              <p className="flex items-start gap-2 px-1 text-xs text-slate-500 dark:text-slate-400">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                Your data is visible only to you and authorised institute staff.
              </p>
            </div>
          </div>
        </div>
      )}
      <CorrectionModal open={correcting} onClose={() => setCorrecting(false)} />
    </div>
  );
}
