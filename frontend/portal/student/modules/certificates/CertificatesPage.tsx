import { useEffect, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { BadgeCheck, CheckCircle2, Clock, FilePlus2, FileText, Hourglass, Info, ScrollText, ShieldCheck, XCircle } from 'lucide-react';
import { Button, Field, Input, Modal, Stagger, Textarea } from '@/components/ui';
import { formatDate, timeAgo } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, Section } from '../../../components/Ui';
import { fieldErrors } from '../../../lib/errors';
import { ExternalA, ListSkeleton, QrCode, StatusBadge, useStudent, useStudentPost } from '../../shared/ui';
import type { StudentCertificates } from '../../shared/types';

function RequestModal({ open, onClose, data }: { open: boolean; onClose: () => void; data: StudentCertificates }) {
  const first = data.requestable.find((t) => t.available)?.type ?? '';
  const [form, setForm] = useState({ type: first, purpose: '', note: '' });
  useEffect(() => {
    if (open) setForm((f) => (data.requestable.some((t) => t.type === f.type && t.available) ? f : { ...f, type: first }));
  }, [open, first, data.requestable]);
  const post = useStudentPost<typeof form>('certificates/request', {
    invalidate: [['certificates'], ['overview']],
    onSuccess: () => {
      setForm({ type: first, purpose: '', note: '' });
      onClose();
    },
  });
  const errors = fieldErrors(post.error);
  const chosen = data.requestable.find((t) => t.type === form.type);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    post.mutate(form);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Request a certificate"
      description="Your request goes to the academic office for approval."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="cert-request" loading={post.isPending} icon={FilePlus2} disabled={!chosen?.available}>
            Send request
          </Button>
        </>
      }
    >
      <form id="cert-request" onSubmit={submit} className="space-y-4" noValidate>
        <fieldset>
          <legend className="form-label">Certificate</legend>
          <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
            {data.requestable.map((t) => (
              <label
                key={t.type}
                className={clsx(
                  'flex cursor-pointer flex-col rounded-2xl border p-3.5 transition',
                  form.type === t.type ? 'border-flame-500 bg-flame-50/60 ring-2 ring-flame-500/20 dark:bg-flame-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700',
                  !t.available && 'cursor-not-allowed opacity-60',
                )}
              >
                <span className="flex items-center gap-2">
                  <input type="radio" name="type" value={t.type} checked={form.type === t.type} disabled={!t.available} onChange={() => setForm((f) => ({ ...f, type: t.type }))} className="h-4 w-4 accent-flame-600" />
                  <span className="text-sm font-semibold text-slate-900 dark:text-white">{t.label}</span>
                </span>
                <span className="mt-1 text-xs text-slate-500 dark:text-slate-400">{t.description}</span>
                {!t.available && t.reason && <span className="mt-1.5 text-xs font-medium text-amber-700 dark:text-amber-300">{t.reason}</span>}
              </label>
            ))}
          </div>
          {errors.type && <p className="form-error">{errors.type}</p>}
        </fieldset>
        <Field label="Purpose" required={chosen?.purpose_required} error={errors.purpose} htmlFor="cr-purpose" hint="Printed on the certificate, e.g. 'Opening a bank account'." aside={`${form.purpose.length}/150`}>
          <Input id="cr-purpose" list="cr-purposes" value={form.purpose} onChange={(e) => setForm((f) => ({ ...f, purpose: e.target.value }))} maxLength={150} invalid={!!errors.purpose} placeholder="Choose or type…" />
          <datalist id="cr-purposes">
            {data.purposes.map((p) => (
              <option key={p} value={p} />
            ))}
          </datalist>
        </Field>
        <Field label="Note to the office" error={errors.note} htmlFor="cr-note" aside={`${form.note.length}/200`}>
          <Textarea id="cr-note" rows={2} value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} maxLength={200} placeholder="Optional — e.g. needed by Friday" invalid={!!errors.note} />
        </Field>
      </form>
    </Modal>
  );
}

export default function StudentCertificatesPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentCertificates>(['certificates'], 'certificates');
  const [open, setOpen] = useState(false);
  const canRequest = !!data?.requestable.some((t) => t.available);

  return (
    <div>
      <PageHead
        area="student"
        title="Certificates"
        subtitle="Certificates issued to you, with online verification, and new requests."
        crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Certificates' }]}
        actions={
          data && data.requestable.length > 0 ? (
            <button type="button" className="btn btn-primary btn-sm" onClick={() => setOpen(true)} disabled={!canRequest}>
              <FilePlus2 className="h-4 w-4" aria-hidden /> Request certificate
            </button>
          ) : undefined
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <ListSkeleton rows={3} h="h-40" />
      ) : (
        <div className="space-y-5">
          <Section title="Issued to you" subtitle="Anyone can verify these online by scanning the QR code" icon={BadgeCheck}>
            {data.issued.length === 0 ? (
              <EmptyBlock
                compact
                icon={ScrollText}
                title="No certificates issued yet"
                text="Bonafide, character and other certificates issued by the institute appear here with a verification link."
                action={
                  canRequest ? (
                    <button type="button" className="btn btn-soft btn-sm" onClick={() => setOpen(true)}>
                      <FilePlus2 className="h-4 w-4" aria-hidden /> Request one
                    </button>
                  ) : undefined
                }
              />
            ) : (
              <Stagger className="grid grid-cols-1 gap-4 lg:grid-cols-2" itemClassName="h-full">
                {data.issued.map((c) => (
                  <article key={c.id} className="flex h-full gap-4 rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">
                    {c.status === 'issued' && c.verify_url ? (
                      <QrCode text={c.verify_url} className="h-24 w-24 shrink-0 ring-1 ring-slate-200 dark:ring-slate-700" label={`Verification QR for ${c.certificate_no}`} />
                    ) : (
                      <span className="inline-flex h-24 w-24 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800">
                        <XCircle className="h-8 w-8" aria-hidden />
                      </span>
                    )}
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge status={c.status} label={c.status_label} />
                        <span className="text-[11px] font-semibold text-slate-400">{c.certificate_no}</span>
                      </div>
                      <h3 className="mt-1.5 font-display text-[15px] font-bold text-slate-900 dark:text-white">{c.type_label}</h3>
                      {c.purpose && <p className="text-xs text-slate-500">For: {c.purpose}</p>}
                      <p className="mt-1 text-xs text-slate-500">
                        Issued {formatDate(c.issue_date)}
                        {c.valid_until ? ` · valid till ${formatDate(c.valid_until)}` : ''}
                      </p>
                      {c.security_code && (
                        <p className="mt-1 text-[11px] text-slate-400">
                          Security code <span className="font-mono font-semibold text-slate-600 dark:text-slate-300">{c.security_code}</span>
                        </p>
                      )}
                      {c.revoked_reason && <p className="mt-1 text-xs font-medium text-red-600">{c.revoked_reason}</p>}
                      {c.verify_url && (
                        <div className="mt-2">
                          <ExternalA href={c.verify_url} className="text-[13px]">
                            Verify online
                          </ExternalA>
                        </div>
                      )}
                    </div>
                  </article>
                ))}
              </Stagger>
            )}
            {data.issued.length > 0 && (
              <p className="mt-4 flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> Collect the signed, stamped copy from the academic office. Share the verification link with banks, embassies or employers.
              </p>
            )}
          </Section>

          <Section title="My requests" subtitle="Requests waiting for approval or declined" icon={Hourglass} bodyClassName="p-2 sm:p-3">
            {data.requests.length === 0 ? (
              <EmptyBlock compact icon={Clock} title="No pending requests" />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.requests.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-start gap-3 px-2 py-3">
                    <span className={clsx('mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', r.status === 'pending' ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300')}>
                      {r.status === 'pending' ? <Hourglass className="h-4 w-4" aria-hidden /> : <XCircle className="h-4 w-4" aria-hidden />}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-900 dark:text-white">{r.type_label}</p>
                      <p className="text-xs text-slate-500">
                        {r.certificate_no} · requested {timeAgo(r.requested_at)}
                        {r.purpose ? ` · ${r.purpose}` : ''}
                      </p>
                      {r.reason && <p className="mt-1 text-xs font-medium text-red-600 dark:text-red-400">Reason: {r.reason}</p>}
                    </div>
                    <StatusBadge status={r.status} label={r.status_label} />
                  </li>
                ))}
              </ul>
            )}
          </Section>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {[
              { icon: FileText, title: 'Request', text: 'Choose the certificate and tell us the purpose.' },
              { icon: ShieldCheck, title: 'Approval', text: 'The academic office checks your record and approves it.' },
              { icon: CheckCircle2, title: 'Collect & verify', text: 'It shows up here with a QR code; collect the signed copy.' },
            ].map((s, i) => (
              <div key={s.title} className="flex gap-3 rounded-2xl border border-dashed border-slate-300 p-3.5 dark:border-slate-700">
                <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-900 text-xs font-bold text-white dark:bg-brand-600">{i + 1}</span>
                <div>
                  <p className="text-sm font-semibold text-slate-900 dark:text-white">{s.title}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">{s.text}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
      {data && <RequestModal open={open} onClose={() => setOpen(false)} data={data} />}
    </div>
  );
}
