import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  Ban, BadgeCheck, CheckCircle2, Clock3, Copy, ExternalLink, FileClock, FileX, History, Link2, Mail, Pencil, Printer, QrCode, RotateCcw, ScanLine, Trash2,
  type LucideIcon,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, DescriptionList, Drawer, Dropdown, PersonCell, Skeleton, Textarea, Timeline, useConfirm, useToast, type Tone,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { printUrl } from '@/lib/config';
import { formatDate, formatDateTime, timeAgo } from '@/lib/format';
import { CertStatusBadge, CertificateFrame, TypeIcon, copyText, daysUntil } from './shared';
import { ReasonModal, ReissueModal, useRefreshCertificates, type CertRef } from './ActionModals';
import type { CertificateDetail } from './types';

const EVENT_STYLE: Record<string, { icon: LucideIcon; tone: Tone }> = {
  issued: { icon: BadgeCheck, tone: 'green' },
  requested: { icon: FileClock, tone: 'amber' },
  approved: { icon: CheckCircle2, tone: 'green' },
  printed: { icon: Printer, tone: 'navy' },
  verified: { icon: ScanLine, tone: 'cyan' },
  revoked: { icon: Ban, tone: 'red' },
  rejected: { icon: FileX, tone: 'slate' },
  reissued: { icon: RotateCcw, tone: 'purple' },
};
const RESULT_COLORS = { valid: 'green', expired: 'purple', revoked: 'red', not_found: 'slate' } as const;
const SOURCE_LABEL: Record<string, string> = { qr: 'QR scan', web: 'Website', api: 'API' };

interface Props {
  id: number | null;
  onClose: () => void;
  /** Open another certificate (reissue chain) */
  onOpen: (id: number) => void;
}

/** Certificate details: exact preview, workflow actions, reissue chain, timeline and public verification log. */
export function CertificateDrawer({ id, onClose, onOpen }: Props) {
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useRefreshCertificates();
  const { data, isLoading, error } = useApi<CertificateDetail>(['cert-detail', id], `certificates/${id}`, undefined, { enabled: !!id });
  const [reason, setReason] = useState<{ mode: 'revoke' | 'reject'; cert: CertRef } | null>(null);
  const [reissue, setReissue] = useState<CertRef | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [editRemarks, setEditRemarks] = useState(false);
  const [remarks, setRemarks] = useState('');
  const [previewKey, setPreviewKey] = useState(0);
  useEffect(() => {
    setEditRemarks(false);
  }, [id]);

  const c = data?.certificate;
  const ref: CertRef | null = c ? { id: c.id, certificate_no: c.certificate_no, type: c.type, type_label: c.type_label, student_name: c.student_name } : null;

  const run = async (key: string, fn: () => Promise<{ message: string }>) => {
    setBusy(key);
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
      setPreviewKey((k) => k + 1);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const approve = () => c && run('approve', () => api.post(`certificates/${c.id}/approve`));
  const email = () => c && run('email', () => api.post(`certificates/${c.id}/email`));
  const remove = async () => {
    if (!c) return;
    const ok = await confirm({ title: 'Delete request?', message: <>Delete <strong>{c.certificate_no}</strong>? Only pending or rejected requests can be deleted. This cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    setBusy('delete');
    try {
      const res = await api.del(`crud/certificates/${c.id}`);
      toast.success(res.message || 'Certificate request deleted.');
      await refresh();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const saveRemarks = async () => {
    if (!c) return;
    await run('remarks', () => api.post(`crud/certificates/${c.id}`, { remarks }));
    setEditRemarks(false);
  };
  const copyLink = async () => {
    if (!data) return;
    const ok = await copyText(data.verify_url);
    if (ok) toast.success('Verification link copied to the clipboard.');
    else toast.info(data.verify_url, 'Copy the verification link');
  };
  const print = () => c && window.open(printUrl('certificate.php', { id: c.id }), '_blank', 'noopener');

  const left = c ? daysUntil(c.valid_until) : null;
  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-3xl"
        title={c ? <span className="flex flex-wrap items-center gap-2"><span className="font-mono">{c.certificate_no}</span><CertStatusBadge status={c.effective_status} /></span> : 'Certificate'}
        description={c ? `${c.type_label} · ${c.student_name}` : undefined}
        footer={
          c && data ? (
            <div className="flex w-full flex-wrap items-center justify-end gap-2">
              {data.can.delete && <Button variant="ghost" size="sm" icon={Trash2} className="mr-auto !text-red-600" loading={busy === 'delete'} onClick={remove}>Delete</Button>}
              {data.can.reject && <Button variant="secondary" size="sm" icon={FileX} onClick={() => ref && setReason({ mode: 'reject', cert: ref })}>Reject</Button>}
              {data.can.approve && <Button variant="success" size="sm" icon={CheckCircle2} loading={busy === 'approve'} onClick={approve}>Approve & issue</Button>}
              {data.can.revoke && <Button variant="secondary" size="sm" icon={Ban} className="!text-red-600" onClick={() => ref && setReason({ mode: 'revoke', cert: ref })}>Revoke</Button>}
              {data.can.reissue && <Button variant="secondary" size="sm" icon={RotateCcw} onClick={() => setReissue(ref)}>Reissue</Button>}
              {c.status !== 'pending' && c.status !== 'rejected' && <Button size="sm" icon={Printer} onClick={print}>Print</Button>}
            </div>
          ) : undefined
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the certificate">{(error as ApiError).message}</Alert>
        ) : isLoading || !c || !data ? (
          <div className="space-y-4">
            <Skeleton className="mx-auto h-80 w-60" />
            <Skeleton className="h-5 w-1/2" />
            <Skeleton className="h-24 w-full" />
          </div>
        ) : (
          <div className="space-y-6">
            {c.status === 'revoked' && <Alert variant="error" title={`Revoked ${c.revoked_at ? formatDate(c.revoked_at) : ''}${c.revoked_by_name ? ` by ${c.revoked_by_name}` : ''}`}>{c.revoked_reason}</Alert>}
            {c.status === 'rejected' && <Alert variant="warning" title={`Request rejected${c.revoked_by_name ? ` by ${c.revoked_by_name}` : ''}`}>{c.revoked_reason}</Alert>}
            {c.status === 'pending' && (
              <Alert variant="warning" title="Waiting for approval">
                Requested by {c.issued_by_name ?? 'staff'} {timeAgo(c.created_at)}. {data.can.approve ? 'Review the preview and approve or reject it.' : 'An approver has been notified.'}
              </Alert>
            )}
            {c.effective_status === 'expired' && <Alert variant="info" title="Validity ended">This certificate expired on {formatDate(c.valid_until)}. Verification reports it as expired — reissue it if the student needs a current copy.</Alert>}

            <div className="rounded-2xl bg-slate-100/80 p-4 dark:bg-slate-800/50">
              <CertificateFrame key={previewKey} src={printUrl('certificate.php', { id: c.id, embed: 1, v: previewKey })} orientation={data.orientation} title={`Certificate ${c.certificate_no}`} maxHeight={560} />
              <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
                <Button size="xs" variant="secondary" icon={ExternalLink} href={data.verify_url} target="_blank">Verification page</Button>
                <Button size="xs" variant="secondary" icon={Copy} onClick={copyLink}>Copy link</Button>
                {data.can.email && <Button size="xs" variant="secondary" icon={Mail} loading={busy === 'email'} onClick={email}>E-mail student</Button>}
                <Dropdown
                  trigger={<><QrCode className="h-3.5 w-3.5" /> Security</>}
                  triggerClassName="btn btn-secondary btn-xs"
                  width="w-72"
                  header={<p className="text-xs text-slate-500">Printed at the bottom of the certificate</p>}
                  items={[{ label: c.security_code, icon: QrCode, onClick: () => void copyText(c.security_code).then((ok) => ok && toast.success('Security code copied.')) }]}
                />
              </div>
            </div>

            <section>
              <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Details</h3>
              <DescriptionList
                items={[
                  { label: 'Student', value: <PersonCell name={c.student_name} sub={`${c.student_uid} · ${c.program_short} · Sem ${c.current_semester}`} src={c.student_photo} to={`/students/${c.student_id}`} size="sm" />, full: true },
                  { label: 'Certificate', value: <span className="inline-flex items-center gap-2"><TypeIcon type={c.type} size="sm" />{c.title}</span> },
                  { label: 'Template', value: c.template_name ?? 'Deleted template (design kept with the certificate)' },
                  { label: 'Issue date', value: formatDate(c.issue_date) },
                  {
                    label: 'Valid until',
                    value: c.valid_until ? (
                      <span>
                        {formatDate(c.valid_until)}
                        {left !== null && c.status === 'issued' && <span className={clsx('ml-2 text-xs', left < 0 ? 'text-red-600' : left <= 30 ? 'text-amber-600' : 'text-slate-500')}>{left < 0 ? `expired ${-left} days ago` : `${left} days left`}</span>}
                      </span>
                    ) : 'No expiry',
                  },
                  { label: 'Purpose', value: c.purpose },
                  { label: 'Session', value: c.session_name },
                  { label: c.status === 'pending' ? 'Requested by' : 'Issued by', value: c.issued_by_name ? `${c.issued_by_name} · ${formatDateTime(c.created_at)}` : formatDateTime(c.created_at) },
                  { label: 'Approved by', value: c.approved_by_name && c.approved_at ? `${c.approved_by_name} · ${formatDateTime(c.approved_at)}` : c.status === 'pending' ? 'Awaiting approval' : null },
                  { label: 'Prints', value: c.printed_count ? `${c.printed_count} · last ${timeAgo(c.last_printed_at)}` : 'Not printed yet' },
                  { label: 'Online verifications', value: c.verified_count ? `${c.verified_count} · last ${timeAgo(c.last_verified_at)}` : 'None yet' },
                  { label: 'Security code', value: <span className="font-mono text-xs tracking-wider">{c.security_code}</span> },
                ]}
              />
            </section>

            {data.fields.length > 0 && (
              <section>
                <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">{c.type_label} details</h3>
                <dl className="grid gap-2 sm:grid-cols-2">
                  {data.fields.map((f) => (
                    <div key={f.key} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                      <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{f.label}</dt>
                      <dd className="text-sm font-medium text-slate-800 dark:text-slate-100">{f.value}</dd>
                    </div>
                  ))}
                </dl>
              </section>
            )}

            {(data.replaces || data.replaced_by.length > 0) && (
              <section>
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><Link2 className="h-4 w-4" />Reissue chain</h3>
                <div className="space-y-2">
                  {data.replaces && (
                    <button type="button" onClick={() => onOpen(data.replaces!.id)} className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-left text-sm transition hover:border-brand-300 hover:bg-brand-50/50 dark:border-slate-700 dark:hover:bg-slate-800">
                      <span>Replaces <span className="font-mono font-semibold">{data.replaces.certificate_no}</span> <span className="text-xs text-slate-500">· {formatDate(data.replaces.issue_date)}</span></span>
                      <CertStatusBadge status={data.replaces.status} />
                    </button>
                  )}
                  {data.replaced_by.map((r) => (
                    <button key={r.id} type="button" onClick={() => onOpen(r.id)} className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-left text-sm transition hover:border-brand-300 hover:bg-brand-50/50 dark:border-slate-700 dark:hover:bg-slate-800">
                      <span>Reissued as <span className="font-mono font-semibold">{r.certificate_no}</span> <span className="text-xs text-slate-500">· {formatDate(r.issue_date)}</span></span>
                      <CertStatusBadge status={r.status} />
                    </button>
                  ))}
                </div>
              </section>
            )}

            <section>
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Internal remarks</h3>
                {data.can.edit && !editRemarks && (
                  <Button size="xs" variant="ghost" icon={Pencil} onClick={() => { setRemarks(c.remarks ?? ''); setEditRemarks(true); }}>Edit</Button>
                )}
              </div>
              {editRemarks ? (
                <div className="space-y-2">
                  <Textarea rows={3} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} aria-label="Internal remarks" />
                  <div className="flex justify-end gap-2">
                    <Button size="sm" variant="secondary" onClick={() => setEditRemarks(false)}>Cancel</Button>
                    <Button size="sm" loading={busy === 'remarks'} onClick={saveRemarks}>Save remarks</Button>
                  </div>
                </div>
              ) : (
                <p className="text-sm text-slate-600 dark:text-slate-300">{c.remarks || <span className="text-slate-400">No remarks. Only staff can see remarks.</span>}</p>
              )}
            </section>

            <div className="grid gap-6 md:grid-cols-2">
              <section>
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><History className="h-4 w-4" />History</h3>
                <Timeline
                  items={data.events.map((ev) => ({
                    icon: EVENT_STYLE[ev.type]?.icon ?? Clock3,
                    tone: EVENT_STYLE[ev.type]?.tone ?? 'slate',
                    title: <span className="font-medium">{ev.title}</span>,
                    description: [ev.by, ev.note].filter(Boolean).join(' · ') || undefined,
                    time: formatDateTime(ev.at),
                  }))}
                />
              </section>
              <section>
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><ScanLine className="h-4 w-4" />Public verifications</h3>
                {data.verifications.length === 0 ? (
                  <Card className="p-4 text-center text-sm text-slate-500 shadow-none">No one has verified this certificate online yet.</Card>
                ) : (
                  <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                    {data.verifications.map((v) => (
                      <li key={v.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <div className="min-w-0">
                          <p className="font-medium text-slate-800 dark:text-slate-100">{SOURCE_LABEL[v.source] ?? v.source}</p>
                          <p className="truncate text-xs text-slate-500">{formatDateTime(v.created_at)}{v.ip_address ? ` · ${v.ip_address}` : ''}</p>
                        </div>
                        <Badge color={RESULT_COLORS[v.result as keyof typeof RESULT_COLORS] ?? 'slate'}>{v.result === 'not_found' ? 'Not found' : v.result.charAt(0).toUpperCase() + v.result.slice(1)}</Badge>
                      </li>
                    ))}
                  </ul>
                )}
                {Object.keys(data.verification_sources).length > 0 && (
                  <p className="mt-2 text-xs text-slate-500">
                    {Object.entries(data.verification_sources).map(([k, n]) => `${SOURCE_LABEL[k] ?? k}: ${n}`).join(' · ')}
                  </p>
                )}
              </section>
            </div>
            <p className="text-center text-xs text-slate-400">
              Student profile: <Link className="link" to={`/students/${c.student_id}`}>{c.student_name}</Link>
            </p>
          </div>
        )}
      </Drawer>
      <ReasonModal mode={reason?.mode ?? 'revoke'} cert={reason?.cert ?? null} onClose={() => setReason(null)} onDone={() => setPreviewKey((k) => k + 1)} />
      <ReissueModal cert={reissue} rawFields={data?.raw_fields} onClose={() => setReissue(null)} onDone={(newId) => onOpen(newId)} />
    </>
  );
}
