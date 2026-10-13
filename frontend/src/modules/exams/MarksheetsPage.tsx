import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Award, Ban, FileBadge, FileCheck2, FilePlus2, Printer, QrCode, RotateCcw, ShieldCheck, Trophy } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, Field, Modal, PageHeader, Reveal, Skeleton, StatCard, Stagger, Textarea, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, type ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ResultBadge, fmtGpa } from './components/shared';
import type { MarksheetSummary } from './components/types';

export default function MarksheetsPage() {
  const [params] = useSearchParams();
  const examParam = Number(params.get('exam') ?? 0) || null;
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const [nonce, setNonce] = useState(0);
  const [busy, setBusy] = useState<number | null>(null);
  const [revoke, setRevoke] = useState<Row | null>(null);
  const summary = useApi<MarksheetSummary>(['marksheets', 'summary'], 'marksheets/summary');
  const k = summary.data?.kpis;
  // Same rules as the server (marksheets_can_issue / marksheets_can_revoke): issue / re-issue = results publish|manage,
  // revoke = results approve|manage. Faculty who only enter marks cannot do either.
  const { can } = useAuth();
  const canIssue = can('results', 'publish') || can('results', 'manage');
  const canRevoke = can('results', 'approve') || can('results', 'manage');

  const generate = async (examId: number, name: string, count: number) => {
    const ok = await confirm({ title: 'Generate marksheets?', message: <>Issue <strong>{count}</strong> marksheet{count === 1 ? '' : 's'} for <strong>{name}</strong> with unique numbers and QR verification codes. Withheld results are skipped.</>, confirmText: 'Generate' });
    if (!ok) return;
    setBusy(examId);
    try {
      const res = await api.post('marksheets/generate', { exam_id: examId });
      toast.success(res.message);
      await invalidate('marksheets', 'crud', 'results');
      setNonce((n) => n + 1);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const reissue = async (r: Row) => {
    const ok = await confirm({ title: 'Re-issue marksheet?', message: `A new marksheet number and verification code replace ${r.marksheet_no}. The old QR code stops verifying.`, confirmText: 'Re-issue' });
    if (!ok) return;
    try {
      const res = await api.post(`marksheets/${r.id}/reissue`);
      toast.success(res.message);
      await invalidate('marksheets', 'crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Marksheets"
        description="Generate, print and verify grade cards for published results — every marksheet carries a unique number and a QR verification code."
        breadcrumbs={[{ label: 'Results & Marksheet', to: '/results' }, { label: 'Marksheets' }]}
        actions={
          <>
            <Button variant="secondary" icon={Trophy} to="/results">Results</Button>
            <Button variant="secondary" icon={Award} to="/results/grades">Grading scale</Button>
          </>
        }
      />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={70}>
        {[
          <StatCard key="i" label="Issued marksheets" value={k?.issued ?? 0} icon={FileCheck2} tone="green" loading={!k} hint={`${formatNumber(k?.this_month ?? 0)} issued this month`} />,
          <StatCard key="p" label="Pending generation" value={k?.pending ?? 0} icon={FilePlus2} tone="amber" loading={!k} hint="Published results without a marksheet" />,
          <StatCard key="n" label="Not printed yet" value={k?.not_printed ?? 0} icon={Printer} tone="blue" loading={!k} hint={`${formatNumber(k?.prints ?? 0)} prints in total`} />,
          <StatCard key="r" label="Revoked" value={k?.revoked ?? 0} icon={Ban} tone="red" loading={!k} hint="QR verification reports them as revoked" />,
        ]}
      </Stagger>

      <Reveal className="mb-6">
        <Card>
          <CardHeader title="Ready to generate" subtitle="Final examinations whose results are published but some students have no marksheet yet" icon={FilePlus2} />
          {summary.error ? (
            <div className="p-5"><Alert variant="error">{(summary.error as ApiError).message}</Alert></div>
          ) : !summary.data ? (
            <div className="space-y-2 p-5">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
          ) : summary.data.pending.length ? (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {summary.data.pending.map((p) => (
                <li key={p.id} className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-slate-900 dark:text-white">{p.name}</p>
                    <p className="text-xs text-slate-500">
                      {p.cohort} · {p.session_name} · published {formatDate(p.published_at)}
                      {p.issued ? ` · ${p.issued} already issued` : ''}
                      {p.withheld ? ` · ${p.withheld} withheld` : ''}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge color="amber">{p.pending} pending</Badge>
                    {canIssue && (
                      <Button size="sm" icon={FilePlus2} loading={busy === p.id} onClick={() => generate(p.id, p.name, p.pending)}>
                        Generate
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState icon={ShieldCheck} title="Every published result has a marksheet" description="New marksheets can be generated here as soon as results are published." className="py-10" />
          )}
        </Card>
      </Reveal>

      <CrudTable
        key={`${examParam ?? 'all'}-${nonce}`}
        module="marksheets"
        title="Issued marksheets"
        urlState={!examParam}
        defaultFilters={examParam ? { exam_id: examParam } : undefined}
        emptyTitle="No marksheets found"
        emptyText="No marksheets match the filters. Generate marksheets for published results above."
        onView={(r) => window.open(printUrl('marksheet.php', { id: r.id }), '_blank', 'noopener')}
        bulkActions={[
          { label: 'Print selected', icon: Printer, onClick: (ids) => { window.open(printUrl('marksheet.php', { ids: ids.join(',') }), '_blank', 'noopener'); } },
        ]}
        toolbar={
          examParam ? (
            <Button size="sm" variant="secondary" icon={Printer} href={printUrl('marksheet.php', { exam_id: examParam })} target="_blank">
              Print all of exam
            </Button>
          ) : undefined
        }
        rowMenu={(r) => [
          { label: 'Print / download', icon: Printer, href: printUrl('marksheet.php', { id: r.id }), target: '_blank' },
          { label: 'Open result', icon: Trophy, to: `/results?exam=${r.exam_id}` },
          canRevoke && r.status === 'issued' && { label: 'divider', divider: true },
          canRevoke && r.status === 'issued' && { label: 'Revoke…', icon: Ban, danger: true, onClick: () => setRevoke(r) },
          canIssue && r.status === 'revoked' && { label: 'Re-issue', icon: RotateCcw, onClick: () => reissue(r) },
        ]}
        renderers={{
          marksheet_no: (r) => (
            <div className="leading-tight">
              <span className={clsx('inline-flex items-center gap-1 whitespace-nowrap font-mono text-xs font-semibold', r.status === 'revoked' ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-white')}>
                <QrCode className="h-3.5 w-3.5 text-slate-400" /> {r.marksheet_no}
              </span>
              <span className="block whitespace-nowrap text-[11px] text-slate-500">{r.issued_by_name ? `by ${r.issued_by_name}` : ''}</span>
            </div>
          ),
          exam_name: (r) => (
            <div className="min-w-[10rem] leading-tight">
              <p className="font-medium text-slate-800 dark:text-slate-100">{r.cohort}</p>
              <p className="text-xs text-slate-500">{r.session_name} · {String(r.exam_name).split('—').pop()?.trim()}</p>
            </div>
          ),
          sgpa: (r) => <span className="font-semibold tabular-nums">{fmtGpa(r.sgpa)}</span>,
          cgpa: (r) => <span className="tabular-nums">{fmtGpa(r.cgpa)}</span>,
          result_status: (r) => <ResultBadge status={r.result_status} />,
          printed_count: (r) => (Number(r.printed_count) ? <span className="text-xs tabular-nums" title={r.last_printed_at ? `Last printed ${formatDateTime(r.last_printed_at)}` : undefined}>{r.printed_count}×</span> : <span className="text-xs text-slate-400">Never</span>),
          status: (r) => (
            <div className="leading-tight">
              <Badge color={r.status === 'issued' ? 'green' : 'red'} dot>{r.status === 'issued' ? 'Issued' : 'Revoked'}</Badge>
              {r.status === 'revoked' && r.revoked_reason ? (
                <p className="mt-0.5 max-w-[12rem] truncate text-[11px] text-slate-500" title={r.revoked_reason}>{r.revoked_reason}</p>
              ) : (
                <p className="mt-0.5 max-w-[8.5rem] text-[11px] leading-tight text-slate-500" title={r.last_printed_at ? `Last printed ${formatDateTime(r.last_printed_at)}` : undefined}>
                  {formatDate(r.issued_at)} · {Number(r.printed_count) ? `printed ${r.printed_count}×` : 'not printed'}
                </p>
              )}
            </div>
          ),
        }}
      />
      {revoke && <RevokeModal row={revoke} onClose={() => setRevoke(null)} />}
    </>
  );
}

function RevokeModal({ row, onClose }: { row: Row; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const res = await api.post(`marksheets/${row.id}/revoke`, { reason });
      toast.success(res.message);
      await invalidate('marksheets', 'crud', 'results');
      onClose();
    } catch (e) {
      const ex = e as ApiError;
      if (ex.errors?.reason) setError(ex.errors.reason);
      else toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      title="Revoke marksheet"
      description={`${row.marksheet_no} · ${row.student_name}`}
      icon={<span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15"><FileBadge className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="danger" icon={Ban} loading={saving} onClick={save}>Revoke</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-300">The QR code on the printed copy will report this marksheet as revoked. You can re-issue it afterwards under a new number.</p>
      <Field label="Reason" required error={error} htmlFor="rv-reason" hint="e.g. Name correction, result revised after re-evaluation, duplicate issued.">
        <Textarea id="rv-reason" rows={3} value={reason} onChange={(ev) => setReason(ev.target.value)} invalid={!!error} maxLength={255} />
      </Field>
    </Modal>
  );
}
