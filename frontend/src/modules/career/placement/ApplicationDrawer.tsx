import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { BadgeCheck, CalendarClock, CheckCircle2, CircleDashed, ClipboardCheck, Download, ExternalLink, Pencil, Trash2, UserX, XCircle } from 'lucide-react';
import { Alert, Badge, Button, DescriptionList, Drawer, Dropdown, IconButton, PageLoader, PersonCell, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime } from '@/lib/format';
import type { ApplicationDetail } from './types';
import { APP_STATUS, APP_STATUS_COLORS, CompanyLogo, INTERVIEW_MODES, OFFER_STATUS, OFFER_STATUS_COLORS, ROUND_RESULT, ROUND_RESULT_COLORS, ctcRange, lpa, privateFileUrl, usePlacementRefresh } from './shared';
import { RecordRoundModal } from './RecordRoundModal';
import { APP_ACTIONS, ApplicationActionModal, type AppAction, type PendingBulk } from './applicationBulk';
import { IssueOfferModal, OFFER_ACTIONS, OFFER_TRANSITIONS, OfferStatusModal, type OfferPreset, type OfferTarget } from './offers';

const resultIcon = { pending: CircleDashed, cleared: CheckCircle2, failed: XCircle, absent: UserX } as const;

/** Application detail: student profile, round-by-round progress, interviews and the offer. */
export function ApplicationDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const { data, isLoading, error } = useApi<ApplicationDetail>(['placement', 'application', id], `placement/applications/${id}`, undefined, { enabled: !!id });
  const interviewMeta = useCrudMeta('placement_interviews');
  const appMeta = useCrudMeta('placement_applications');
  const [recording, setRecording] = useState(false);
  const [editRound, setEditRound] = useState<number | null>(null);
  const [editApp, setEditApp] = useState(false);
  const [pending, setPending] = useState<PendingBulk | null>(null);
  const [offerPreset, setOfferPreset] = useState<OfferPreset | null>(null);
  const [offerChange, setOfferChange] = useState<{ target: OfferTarget; status: string } | null>(null);
  const canEdit = can('placement', 'edit');
  const a = data?.application;
  const s = data?.student;
  const closed = a ? ['selected', 'rejected', 'withdrawn'].includes(a.status) : true;
  const hasValidOffer = !!data?.offer && ['offered', 'accepted', 'joined'].includes(data.offer.status);

  const removeRound = async (iid: number, name: string) => {
    const ok = await confirm({ title: 'Delete this round?', message: <>Delete the <strong>{name}</strong> record? The application status is recalculated from the remaining rounds.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/placement_interviews/${iid}`);
      toast.success(res.message);
      await refresh('placement_applications', 'placement_interviews');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const actions: AppAction[] = a
    ? (['shortlist', 'select', 'hold', 'reject', 'withdraw', 'reopen'] as AppAction[]).filter((x) => {
        if (x === 'shortlist') return a.status === 'applied';
        if (x === 'select') return !['selected', 'withdrawn'].includes(a.status);
        if (x === 'hold') return !closed && a.status !== 'on_hold';
        if (x === 'reject') return !['rejected', 'withdrawn'].includes(a.status) && !hasValidOffer;
        if (x === 'withdraw') return !['withdrawn', 'selected'].includes(a.status);
        return ['rejected', 'withdrawn', 'on_hold'].includes(a.status);
      })
    : [];

  const issueOffer = () => {
    if (!a || !s) return;
    setOfferPreset({
      student_id: a.student_id, student_name: a.student_name, student_sub: `${a.student_uid} · ${s.program_short}`, student_photo: a.student_photo,
      company_id: a.company_id, company_name: a.company_name, company_logo: a.company_logo, drive_id: a.drive_id, job_role: a.job_role.split(',')[0].trim(),
      package_lpa: a.package_max ?? a.package_min, location: a.drive_location?.split('/')[0].trim() ?? '',
    });
  };

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-2xl"
        title={a ? a.student_name : 'Application'}
        description={a ? `${a.company_name} — ${a.job_role}` : undefined}
        footer={
          a && (
            <div className="flex w-full flex-wrap items-center justify-end gap-2">
              {a.resume_path && (
                <Button variant="ghost" size="sm" icon={Download} href={privateFileUrl(a.resume_path, `Resume-${a.student_uid}`)} target="_blank">Resume</Button>
              )}
              {canEdit && (
                <>
                  <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditApp(true)}>Edit</Button>
                  {actions.length > 0 && (
                    <Dropdown trigger={<>Status</>} items={actions.map((x) => ({ label: APP_ACTIONS[x].label, icon: APP_ACTIONS[x].icon, danger: APP_ACTIONS[x].danger, onClick: () => setPending({ action: x, ids: [a.id] }) }))} align="right" />
                  )}
                  {a.status === 'selected' && !data?.offer && can('placement', 'create') && (
                    <Button size="sm" variant="success" icon={BadgeCheck} onClick={issueOffer}>Record offer</Button>
                  )}
                  {a.status !== 'withdrawn' && !hasValidOffer && !(a.status === 'selected' && !data?.next_round) && (
                    <Button size="sm" icon={ClipboardCheck} onClick={() => setRecording(true)}>Record round</Button>
                  )}
                </>
              )}
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the application">{(error as Error).message}</Alert>
        ) : isLoading || !data || !a ? (
          <PageLoader label="Loading application…" />
        ) : (
          <div className="space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <PersonCell name={a.student_name} src={a.student_photo} sub={s ? `${a.student_uid} · ${s.program_short} · Sem ${s.current_semester}` : a.student_uid} />
              <div className="flex flex-wrap gap-1.5">
                <StatusBadge status={a.status} label={APP_STATUS[a.status]} colors={APP_STATUS_COLORS} />
                {a.current_round && !['selected'].includes(a.status) && <Badge color="slate">{a.current_round}</Badge>}
              </div>
            </div>

            {/* Round progress */}
            {data.rounds.length > 0 && (
              <section aria-label="Selection rounds">
                <ol className="flex flex-wrap items-center gap-2">
                  {data.rounds.map((r, i) => {
                    const iv = [...data.interviews].reverse().find((x) => x.round_name.toLowerCase() === r.toLowerCase());
                    const state = iv?.result ?? (a.current_round?.toLowerCase() === r.toLowerCase() && !closed ? 'current' : 'todo');
                    return (
                      <li key={r} className="flex items-center gap-2">
                        <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset',
                          state === 'cleared' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'
                            : state === 'failed' || state === 'absent' ? 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30'
                              : state === 'pending' || state === 'current' ? 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30'
                                : 'bg-slate-50 text-slate-500 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700')}>
                          <span className="tabular-nums">{i + 1}.</span> {r}
                        </span>
                        {i < data.rounds.length - 1 && <span className="h-px w-3 bg-slate-300 dark:bg-slate-700" aria-hidden />}
                      </li>
                    );
                  })}
                </ol>
              </section>
            )}

            {/* Offer */}
            {data.offer ? (
              <section className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/[.07]">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Offer</p>
                    <p className="mt-0.5 font-display text-xl font-bold text-slate-900 dark:text-white">{lpa(data.offer.package_lpa)}</p>
                    <p className="text-sm text-slate-600 dark:text-slate-300">{data.offer.job_role}{data.offer.location ? ` · ${data.offer.location}` : ''}</p>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Offered {formatDate(data.offer.offer_date)}{data.offer.joining_date ? ` · joining ${formatDate(data.offer.joining_date)}` : ''}</p>
                  </div>
                  <div className="flex flex-col items-end gap-2">
                    <StatusBadge status={data.offer.status} label={OFFER_STATUS[data.offer.status]} colors={OFFER_STATUS_COLORS} />
                    <div className="flex flex-wrap justify-end gap-1.5">
                      {data.offer.offer_letter && (
                        <Button size="xs" variant="secondary" icon={Download} href={privateFileUrl(data.offer.offer_letter, `Offer-${a.student_uid}`)} target="_blank">Letter</Button>
                      )}
                      {canEdit && (OFFER_TRANSITIONS[data.offer.status] ?? []).slice(0, 2).map((st) => (
                        <Button key={st} size="xs" variant={st === 'accepted' ? 'success' : 'secondary'} icon={OFFER_ACTIONS[st].icon}
                          onClick={() => setOfferChange({ target: { id: data.offer!.id, status: data.offer!.status, student_name: a.student_name, company_name: a.company_name, package_lpa: data.offer!.package_lpa }, status: st })}>
                          {OFFER_ACTIONS[st].label}
                        </Button>
                      ))}
                    </div>
                  </div>
                </div>
              </section>
            ) : a.status === 'selected' ? (
              <Alert variant="success" title="Selected by the company" action={can('placement', 'create') ? <Button size="sm" variant="success" icon={BadgeCheck} onClick={issueOffer}>Record offer</Button> : undefined}>
                Record the offer details to count the student as placed.
              </Alert>
            ) : null}

            <section>
              <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Drive</h3>
              <Link to={`/placement/drives/${a.drive_id}`} className="flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-700">
                <CompanyLogo name={a.company_name} src={a.company_logo} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{a.drive_title}</p>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">{a.company_name} · {ctcRange(a.package_min, a.package_max)} · {formatDate(a.drive_date)}</p>
                </div>
                <ExternalLink className="h-4 w-4 shrink-0 text-slate-400" />
              </Link>
            </section>

            {s && (
              <section>
                <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Academic profile</h3>
                <DescriptionList
                  columns={3}
                  items={[
                    { label: 'Program', value: s.program_name },
                    { label: 'Batch', value: s.batch_name },
                    { label: 'CGPA', value: s.cgpa ?? 'Not published' },
                    { label: 'Active backlogs', value: s.backlogs ?? '0' },
                    { label: 'Previous %', value: s.previous_percentage ? `${Number(s.previous_percentage)}%` : null },
                    { label: 'Applied on', value: formatDate(a.applied_at) },
                    { label: 'Email', value: s.email ? <a className="link !font-normal" href={`mailto:${s.email}`}>{s.email}</a> : null },
                    { label: 'Mobile', value: s.mobile },
                    { label: 'Other offers', value: data.other_offers.length ? data.other_offers.map((o) => `${o.company_name} (${lpa(o.package_lpa)}, ${o.status})`).join('; ') : 'None' },
                    ...(a.remarks ? [{ label: 'Remarks', value: a.remarks, full: true }] : []),
                  ]}
                />
                <Link to={`/students/${s.id}`} className="link mt-3 inline-flex items-center gap-1 text-xs">Open student profile <ExternalLink className="h-3 w-3" /></Link>
              </section>
            )}

            <section>
              <div className="mb-3 flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Interview rounds</h3>
                {canEdit && a.status !== 'withdrawn' && !hasValidOffer && (
                  <Button size="xs" variant="secondary" icon={ClipboardCheck} onClick={() => setRecording(true)}>Record round</Button>
                )}
              </div>
              {!data.interviews.length ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center dark:border-slate-700">
                  <CalendarClock className="mx-auto h-6 w-6 text-slate-400" />
                  <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">No rounds recorded yet.</p>
                  <p className="text-xs text-slate-500">{data.next_round ? `Next: ${data.next_round}` : 'Record rounds as the drive progresses.'}</p>
                </div>
              ) : (
                <ol className="relative space-y-4 before:absolute before:bottom-2 before:left-4 before:top-2 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
                  {data.interviews.map((iv) => {
                    const Icon = resultIcon[iv.result as keyof typeof resultIcon] ?? CircleDashed;
                    return (
                      <li key={iv.id} className="relative flex gap-3">
                        <span className={clsx('relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900',
                          iv.result === 'cleared' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : iv.result === 'pending' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' : iv.result === 'absent' ? 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300')}>
                          <Icon className="h-4 w-4" />
                        </span>
                        <div className="min-w-0 flex-1 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">{iv.round_name}</p>
                            <div className="flex items-center gap-1">
                              {iv.score !== null && <span className="text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">{iv.score}/100</span>}
                              <StatusBadge status={iv.result} label={ROUND_RESULT[iv.result]} colors={ROUND_RESULT_COLORS} dot={false} />
                              {canEdit && (
                                <>
                                  <IconButton size="sm" icon={Pencil} label="Edit round" onClick={() => setEditRound(iv.id)} />
                                  {can('placement', 'delete') && <IconButton size="sm" icon={Trash2} label="Delete round" tone="danger" onClick={() => void removeRound(iv.id, iv.round_name)} />}
                                </>
                              )}
                            </div>
                          </div>
                          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {formatDateTime(iv.scheduled_at)}{iv.mode ? ` · ${INTERVIEW_MODES[iv.mode] ?? iv.mode}` : ''}{iv.panel ? ` · ${iv.panel}` : ''}
                          </p>
                          {iv.feedback && <p className="mt-1.5 text-sm text-slate-700 dark:text-slate-200">{iv.feedback}</p>}
                        </div>
                      </li>
                    );
                  })}
                </ol>
              )}
            </section>
          </div>
        )}
      </Drawer>

      <RecordRoundModal detail={data ?? null} open={recording && !!data} onClose={() => setRecording(false)} />
      <CrudFormModal open={editRound !== null} onClose={() => setEditRound(null)} meta={interviewMeta.data} module="placement_interviews" id={editRound}
        onSaved={() => void refresh('placement_applications', 'placement_interviews')} />
      <CrudFormModal open={editApp && !!a} onClose={() => setEditApp(false)} meta={appMeta.data} module="placement_applications" id={a ? a.id : null}
        onSaved={() => void refresh('placement_applications')} />
      <ApplicationActionModal pending={pending} onClose={() => setPending(null)} />
      <IssueOfferModal preset={offerPreset} onClose={() => setOfferPreset(null)} />
      <OfferStatusModal target={offerChange?.target ?? null} status={offerChange?.status ?? null} onClose={() => setOfferChange(null)} />
    </>
  );
}
