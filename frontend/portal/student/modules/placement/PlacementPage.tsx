import { useState } from 'react';
import clsx from 'clsx';
import {
  Award, Briefcase, BriefcaseBusiness, CalendarClock, CheckCircle2, CircleDot, ClipboardCheck, FileText, Gift, GraduationCap, Info, MapPin, Send, ShieldAlert, Trophy, Undo2, Users, XCircle,
} from 'lucide-react';
import { Alert, Stagger, Tabs, useConfirm } from '@/components/ui';
import { formatDate, formatDateTime, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, KpiCard, KpiSkeleton, PageHead } from '../../../components/Ui';
import { Chip, CompanyLogo, ExternalA, ListSkeleton, StatusBadge, dueLabel, packageLabel, useStudent, useStudentPost } from '../../shared/ui';
import type { Application, Drive, StudentPlacement } from '../../shared/types';

function DriveCard({ d }: { d: Drive }) {
  const confirm = useConfirm();
  const apply = useStudentPost<Record<string, never>>(`placement/drives/${d.id}/apply`, { invalidate: [['placement']] });
  const pkg = packageLabel(d.package_min, d.package_max) ?? (d.stipend ? `₹${d.stipend.toLocaleString('en-IN')}/month stipend` : null);
  const onApply = async () => {
    const ok = await confirm({
      title: `Apply to ${d.company}?`,
      message: (
        <>
          You are applying for <strong>{d.title}</strong>. The placement cell will share shortlists and interview rounds here. Make sure your resume is up to date with the placement cell.
        </>
      ),
      confirmText: 'Apply now',
    });
    if (ok) apply.mutate({});
  };
  return (
    <article className="card flex h-full flex-col p-4 sm:p-5">
      <div className="flex items-start gap-3">
        <CompanyLogo src={d.logo} name={d.company} />
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">
            {d.company}
            {d.industry ? ` · ${d.industry}` : ''}
          </p>
          <h3 className="mt-0.5 font-display text-[15px] font-bold leading-snug text-slate-900 dark:text-white">{d.title}</h3>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {d.role ?? ''} · {d.job_type_label}
            {d.openings ? ` · ${d.openings} openings` : ''}
          </p>
        </div>
      </div>
      <div className="mt-3 flex flex-wrap gap-1.5">
        {pkg && <Chip icon={Award} className="!bg-emerald-50 !text-emerald-700 dark:!bg-emerald-500/10 dark:!text-emerald-300">{pkg}</Chip>}
        {d.location && <Chip icon={MapPin}>{d.location}</Chip>}
        {d.drive_date && <Chip icon={CalendarClock}>Drive {formatDate(d.drive_date)}</Chip>}
        <Chip icon={Users}>{labelize(d.mode)}</Chip>
      </div>
      <div className="mt-3 rounded-2xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/40 dark:text-slate-300">
        <p className="mb-1.5 font-semibold text-slate-800 dark:text-slate-100">Eligibility</p>
        <ul className="flex flex-wrap gap-x-3 gap-y-1">
          {d.criteria.programs.length > 0 && <li>{d.criteria.programs.join(', ')}</li>}
          {d.criteria.batch_year && <li>Batch {d.criteria.batch_year}</li>}
          {d.criteria.min_cgpa ? <li>CGPA ≥ {d.criteria.min_cgpa}</li> : null}
          {d.criteria.max_backlogs !== null && <li>{d.criteria.max_backlogs === 0 ? 'No backlogs' : `≤ ${d.criteria.max_backlogs} backlog`}</li>}
          {d.criteria.min_percentage ? <li>12th / prev. ≥ {d.criteria.min_percentage}%</li> : null}
        </ul>
        {d.rounds.length > 0 && <p className="mt-1.5 text-slate-500">Rounds: {d.rounds.join(' → ')}</p>}
      </div>
      <div className="mt-auto pt-4">
        {d.can_apply ? (
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="badge badge-green">
              <CheckCircle2 className="h-3 w-3" aria-hidden /> You are eligible
            </span>
            <div className="flex items-center gap-2">
              {d.deadline && <span className="text-[11px] font-semibold text-slate-500">{dueLabel(d.days_left, 'Closes')}</span>}
              <button type="button" className="btn btn-primary btn-sm" onClick={() => void onApply()} disabled={apply.isPending}>
                <Send className="h-4 w-4" aria-hidden /> {d.application?.status === 'withdrawn' ? 'Apply again' : 'Apply'}
              </button>
            </div>
          </div>
        ) : (
          <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
            <ShieldAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> {d.reason ?? 'Registrations are closed.'}
          </p>
        )}
      </div>
    </article>
  );
}

const RESULT_TONE: Record<string, string> = { selected: 'bg-emerald-500 text-white', cleared: 'bg-emerald-500 text-white', passed: 'bg-emerald-500 text-white', rejected: 'bg-red-500 text-white', failed: 'bg-red-500 text-white', pending: 'bg-amber-400 text-amber-950' };

function ApplicationCard({ a }: { a: Application }) {
  const confirm = useConfirm();
  const withdraw = useStudentPost<Record<string, never>>(`placement/applications/${a.id}/withdraw`, { invalidate: [['placement']] });
  const onWithdraw = async () => {
    const ok = await confirm({ title: 'Withdraw application?', message: `Your application for ${a.company} will be withdrawn. You can apply again while registrations are open.`, confirmText: 'Withdraw', danger: true });
    if (ok) withdraw.mutate({});
  };
  const byRound = new Map(a.interviews.map((i) => [i.round.toLowerCase(), i]));
  return (
    <article className="card p-4 sm:p-5">
      <div className="flex flex-wrap items-start gap-3">
        <CompanyLogo src={a.logo} name={a.company} />
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">{a.company}</p>
          <h3 className="font-display text-[15px] font-bold text-slate-900 dark:text-white">{a.title}</h3>
          <p className="text-xs text-slate-500">
            Applied {formatDate(a.applied_at)}
            {a.drive_date ? ` · drive ${formatDate(a.drive_date)}` : ''}
            {packageLabel(a.package_min, a.package_max) ? ` · ${packageLabel(a.package_min, a.package_max)}` : ''}
          </p>
        </div>
        <StatusBadge status={a.status} label={a.status_label} />
      </div>
      {a.rounds.length > 0 && a.status !== 'withdrawn' && (
        <ol className="mt-4 flex flex-wrap items-center gap-1.5" aria-label="Selection rounds">
          {a.rounds.map((r, i) => {
            const iv = byRound.get(r.toLowerCase());
            const current = a.current_round && a.current_round.toLowerCase() === r.toLowerCase();
            return (
              <li key={r} className="flex items-center gap-1.5">
                <span
                  className={clsx(
                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                    iv ? RESULT_TONE[iv.result] ?? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' : current ? 'bg-flame-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                  )}
                  title={iv ? `${r}: ${labelize(iv.result)}${iv.scheduled_at ? ` · ${formatDateTime(iv.scheduled_at)}` : ''}` : r}
                >
                  {iv?.result === 'rejected' || iv?.result === 'failed' ? <XCircle className="h-3 w-3" aria-hidden /> : iv && iv.result !== 'pending' ? <CheckCircle2 className="h-3 w-3" aria-hidden /> : <CircleDot className="h-3 w-3" aria-hidden />}
                  {r}
                </span>
                {i < a.rounds.length - 1 && <span className="h-px w-3 bg-slate-300 dark:bg-slate-700" aria-hidden />}
              </li>
            );
          })}
        </ol>
      )}
      {a.interviews.some((i) => i.result === 'pending' && i.scheduled_at) && (
        <ul className="mt-3 space-y-1.5">
          {a.interviews
            .filter((i) => i.result === 'pending' && i.scheduled_at)
            .map((i) => (
              <li key={i.round} className="flex items-center gap-2 rounded-xl bg-flame-50 px-3 py-2 text-xs text-flame-800 dark:bg-flame-500/10 dark:text-flame-200">
                <CalendarClock className="h-3.5 w-3.5" aria-hidden /> <strong>{i.round}</strong> on {formatDateTime(i.scheduled_at)}
                {i.mode ? ` · ${labelize(i.mode)}` : ''}
              </li>
            ))}
        </ul>
      )}
      {a.can_withdraw && (
        <div className="mt-3 flex justify-end">
          <button type="button" className="btn btn-ghost btn-sm text-red-600 dark:text-red-400" onClick={() => void onWithdraw()} disabled={withdraw.isPending}>
            <Undo2 className="h-4 w-4" aria-hidden /> Withdraw
          </button>
        </div>
      )}
    </article>
  );
}

export default function StudentPlacementPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentPlacement>(['placement'], 'placement');
  const [tab, setTab] = useState<'drives' | 'applications' | 'offers'>('drives');
  const eligible = data?.drives.filter((d) => d.can_apply).length ?? 0;
  const best = data?.offers.filter((o) => o.status !== 'revoked' && o.status !== 'declined').reduce((m, o) => Math.max(m, o.package_lpa ?? 0), 0) ?? 0;
  const active = data?.applications.filter((a) => !['rejected', 'withdrawn'].includes(a.status)).length ?? 0;

  return (
    <div>
      <PageHead area="student" title="Placements" subtitle="Campus drives you can apply for, your applications and offers." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Placements' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <KpiSkeleton key={i} />
            ))}
          </div>
          <ListSkeleton rows={3} h="h-48" />
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" itemClassName="h-full">
            {[
              <KpiCard key="open" label="Drives open to you" icon={BriefcaseBusiness} tone="flame" value={eligible} foot={`${data.drives.length} drive${data.drives.length === 1 ? '' : 's'} for your programme`} />,
              <KpiCard key="apps" label="Active applications" icon={ClipboardCheck} tone="navy" value={active} foot={`${data.applications.length} in total`} />,
              <KpiCard key="offers" label="Offers" icon={Gift} tone="green" value={data.offers.length} foot={data.offers.length ? 'Congratulations!' : 'Keep going!'} />,
              <KpiCard key="best" label="Best package" icon={Trophy} tone="violet" value={best ? `₹${best} LPA` : '—'} foot="From your offers" />,
            ]}
          </Stagger>

          <div className="flex flex-wrap items-center gap-2 text-xs">
            <Chip icon={GraduationCap}>CGPA {data.profile.cgpa !== null ? data.profile.cgpa.toFixed(2) : '—'}</Chip>
            <Chip icon={ShieldAlert}>{data.profile.backlogs} active backlog{data.profile.backlogs === 1 ? '' : 's'}</Chip>
            {data.profile.previous_percentage !== null && <Chip icon={FileText}>Previous qualification {data.profile.previous_percentage}%</Chip>}
            {data.profile.batch_end && <Chip icon={CalendarClock}>Batch {data.profile.batch_end}</Chip>}
          </div>

          {!data.profile.final_year && (
            <Alert variant="info" title="Campus drives are for final-year students">
              Most drives open in your final year. You can still see upcoming drives for your programme and get ready — build your resume and attend the placement trainings.
            </Alert>
          )}

          <Tabs
            variant="pills"
            value={tab}
            onChange={(k) => setTab(k as typeof tab)}
            tabs={[
              { key: 'drives', label: 'Open drives', icon: Briefcase, count: data.drives.length },
              { key: 'applications', label: 'My applications', icon: ClipboardCheck, count: data.applications.length },
              { key: 'offers', label: 'Offers', icon: Gift, count: data.offers.length },
            ]}
          />

          {tab === 'drives' ? (
            data.drives.length === 0 ? (
              <div className="card">
                <EmptyBlock icon={Briefcase} title="No open drives for your programme" text="New campus drives are announced by the placement cell. Check back soon or watch the notices." />
              </div>
            ) : (
              <Stagger className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" itemClassName="h-full">
                {data.drives.map((d) => (
                  <DriveCard key={d.id} d={d} />
                ))}
              </Stagger>
            )
          ) : tab === 'applications' ? (
            data.applications.length === 0 ? (
              <div className="card">
                <EmptyBlock icon={ClipboardCheck} title="You haven't applied to any drive yet" text="Apply from the Open drives tab when you are eligible." />
              </div>
            ) : (
              <Stagger className="grid grid-cols-1 gap-4 lg:grid-cols-2" itemClassName="h-full">
                {data.applications.map((a) => (
                  <ApplicationCard key={a.id} a={a} />
                ))}
              </Stagger>
            )
          ) : data.offers.length === 0 ? (
            <div className="card">
              <EmptyBlock icon={Gift} title="No offers yet" text="Offers from companies you are selected by will appear here." />
            </div>
          ) : (
            <Stagger className="grid grid-cols-1 gap-4 md:grid-cols-2" itemClassName="h-full">
              {data.offers.map((o) => (
                <article key={o.id} className="card relative overflow-hidden p-5">
                  <span className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 via-flame-500 to-brand-700" aria-hidden />
                  <div className="flex items-start gap-3">
                    <CompanyLogo src={o.logo} name={o.company} />
                    <div className="min-w-0 flex-1">
                      <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">{o.company}</p>
                      <h3 className="font-display text-base font-bold text-slate-900 dark:text-white">{o.role ?? 'Offer'}</h3>
                      <p className="text-xs text-slate-500">
                        Offered {formatDate(o.offer_date)}
                        {o.location ? ` · ${o.location}` : ''}
                      </p>
                    </div>
                    <StatusBadge status={o.status} label={o.status_label} />
                  </div>
                  <div className="mt-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                      <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Package</p>
                      <p className="font-display text-2xl font-extrabold text-emerald-700 dark:text-emerald-300">{o.package_lpa !== null ? `₹${o.package_lpa} LPA` : '—'}</p>
                    </div>
                    <div className="text-right text-xs text-slate-500">
                      {o.joining_date && <p>Joining {formatDate(o.joining_date)}</p>}
                      {o.offer_letter && (
                        <ExternalA href={o.offer_letter} className="text-[13px]">
                          Offer letter
                        </ExternalA>
                      )}
                    </div>
                  </div>
                </article>
              ))}
            </Stagger>
          )}
          <p className="flex items-start gap-2 px-1 text-xs text-slate-500 dark:text-slate-400">
            <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> Eligibility is checked against your latest published CGPA, backlogs and previous qualification. If something looks wrong, contact the placement cell.
          </p>
        </div>
      )}
    </div>
  );
}
