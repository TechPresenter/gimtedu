import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Award, BadgePercent, FileText, GraduationCap, HandHeart, ListChecks, Users } from 'lucide-react';
import { Alert, Button, Combobox, Field, Input, Modal, PageHeader, Select, Skeleton, Stagger, StatTile, Tabs, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useCrudList, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, labelize } from '@/lib/format';
import type { Row } from '@/lib/types';
import { InvoiceDrawer, useFeesRefresh } from './shared';

interface AwardResult { awarded: number; amount: number; skipped: string[]; details: { student_id: number; name: string; student_uid: string; amount: number }[] }

export default function ScholarshipsPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'awarded' ? 'awarded' : 'schemes';
  const [award, setAward] = useState<{ schemeId: number | null } | null>(null);
  const [invoiceId, setInvoiceId] = useState<number | null>(null);
  const { data: stats, isLoading } = useCrudList('fee_concessions', { per_page: 1 });
  const { data: schemes } = useCrudList('scholarships', { per_page: 1, f: { status: 'active' } });
  const s = stats?.summary as Record<string, number | string> | undefined;

  const setTab = (t: string, extra: Record<string, string> = {}) => {
    const next = new URLSearchParams();
    if (t === 'awarded') next.set('tab', 'awarded');
    Object.entries(extra).forEach(([k, v]) => next.set(k, v));
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="Scholarships & Discounts"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Scholarships & Discounts' }]}
        description="Scholarship, merit and concession schemes — award them to students and they are applied to their open invoices."
        actions={can('fees', 'edit') && <Button variant="success" icon={Award} onClick={() => setAward({ schemeId: null })}>Award scholarship</Button>}
      />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatTile key="schemes" label="Active schemes" icon={ListChecks} tone="navy" loading={!schemes} value={schemes?.total ?? 0} />,
          <StatTile key="students" label="Students benefitting" icon={Users} tone="blue" loading={isLoading} value={Number(s?.students ?? 0)} />,
          <StatTile key="sch" label="Scholarships awarded" icon={GraduationCap} tone="green" loading={isLoading} value={formatMoneyShort(s?.scholarship_amount ?? 0)} />,
          <StatTile key="disc" label="Discounts & waivers" icon={BadgePercent} tone="amber" loading={isLoading} value={formatMoneyShort(Number(s?.discount_amount ?? 0) + Number(s?.waiver_amount ?? 0))} />,
        ]}
      </Stagger>
      <Tabs
        className="mb-4"
        value={tab}
        onChange={(t) => setTab(t)}
        tabs={[
          { key: 'schemes', label: 'Schemes', icon: Award },
          { key: 'awarded', label: 'Awarded concessions', icon: HandHeart, count: s ? formatNumber(s.n) : undefined },
        ]}
      />
      {tab === 'schemes' ? (
        <CrudTable
          key="schemes"
          module="scholarships"
          urlState
          addLabel="Add scheme"
          emptyTitle="No scholarship schemes yet"
          emptyText="Create merit, sports, sibling or need-based schemes, then award them to students."
          renderers={{
            name: (r) => (
              <div className="min-w-[190px] leading-tight" title={r.criteria ?? undefined}>
                <span className="font-semibold text-slate-900 dark:text-white">{r.name}</span>
                <span className="block text-xs text-slate-500 dark:text-slate-400">{r.code ?? '—'}</span>
              </div>
            ),
          }}
          rowMenu={(r) => [
            can('fees', 'edit') && r.status === 'active' && { label: 'Award to students', icon: Award, onClick: () => setAward({ schemeId: Number(r.id) }) },
            { label: 'View awardees', icon: Users, onClick: () => setTab('awarded', { 'f.scholarship_id': String(r.id) }) },
          ]}
        />
      ) : (
        <CrudTable
          key="awarded"
          module="fee_concessions"
          urlState
          emptyTitle="No concessions yet"
          emptyText="Award a scholarship or add a discount from an invoice to see it here."
          addLabel="Award scholarship"
          onCreate={() => setAward({ schemeId: null })}
          renderers={{
            scheme: (r) => (
              <div className="min-w-[170px] leading-tight" title={r.reason ?? undefined}>
                <span className="font-semibold text-slate-900 dark:text-white">{r.scheme}</span>
                <span className="block text-xs text-slate-500 dark:text-slate-400">{labelize(r.type)} · {formatDate(r.created_at)}</span>
              </div>
            ),
            invoice_no: (r) => (
              <button type="button" onClick={() => setInvoiceId(Number(r.student_fee_id))} className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200">{r.invoice_no}</button>
            ),
          }}
          rowMenu={(r: Row) => [{ label: 'Open invoice', icon: FileText, onClick: () => setInvoiceId(Number(r.student_fee_id)) }]}
          header={({ summary }) =>
            summary ? (
              <div className="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-800">
                {[
                  ['Concessions', formatNumber(summary.n)],
                  ['Students', formatNumber(summary.students)],
                  ['Approved amount', formatMoney(summary.approved_amount)],
                  ['Pending approval', formatNumber(summary.pending)],
                ].map(([l, v]) => (
                  <div key={l} className="bg-white px-4 py-3 dark:bg-slate-900">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{l}</p>
                    <p className="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">{v}</p>
                  </div>
                ))}
              </div>
            ) : null
          }
        />
      )}
      {award && <AwardModal schemeId={award.schemeId} onClose={() => setAward(null)} onDone={() => { setAward(null); setTab('awarded'); }} />}
      <InvoiceDrawer id={invoiceId} onClose={() => setInvoiceId(null)} />
    </>
  );
}

function AwardModal({ schemeId, onClose, onDone }: { schemeId: number | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const refresh = useFeesRefresh();
  const { data: schemes = [] } = useLookup('scholarships');
  const [scheme, setScheme] = useState(schemeId ? String(schemeId) : '');
  const [programId, setProgramId] = useState('');
  const [students, setStudents] = useState<(string | number)[]>([]);
  const [reason, setReason] = useState('');
  const [preview, setPreview] = useState<AwardResult | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<'preview' | 'award' | null>(null);
  const { data: programs = [] } = useLookup('programs');
  const studentParams = useMemo(() => (programId ? { program_id: programId } : {}), [programId]);
  useEffect(() => setPreview(null), [scheme, students]);

  const call = async (isPreview: boolean) => {
    setBusy(isPreview ? 'preview' : 'award');
    setErrors({});
    try {
      const res = await api.post<AwardResult>(`fees/scholarships/${scheme || 0}/award`, { student_ids: students, reason, preview: isPreview ? 1 : 0 });
      if (isPreview) {
        setPreview(res.data);
      } else {
        toast.success(res.message);
        if (res.data.skipped.length) toast.warning(res.data.skipped.slice(0, 3).join('; '), `${res.data.skipped.length} skipped`);
        await refresh();
        onDone();
      }
    } catch (e) {
      const err = e as ApiError;
      setErrors({ ...(err.errors ?? {}), ...(Object.keys(err.errors ?? {}).length ? {} : { form: err.message }) });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      static={!!busy}
      size="lg"
      title="Award scholarship"
      description="The concession is applied to each student's open invoices of the current session (percentage schemes apply to tuition)."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={!!busy}>Cancel</Button>
          <Button variant="secondary" onClick={() => call(true)} loading={busy === 'preview'} disabled={!scheme || !students.length}>Preview</Button>
          <Button variant="success" icon={Award} onClick={() => call(false)} loading={busy === 'award'} disabled={!scheme || !students.length || (preview !== null && preview.awarded === 0)}>
            {preview ? `Award ${formatMoney(preview.amount)}` : 'Award'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {errors.form && <Alert variant="error">{errors.form}</Alert>}
        <Field htmlFor="award-scheme" label="Scheme" required error={errors.scholarship_id}>
          <Select id="award-scheme" value={scheme} onChange={(e) => setScheme(e.target.value)} options={schemes.map((o) => ({ value: o.value, label: `${o.label}${o.sub ? ` — ${o.sub}` : ''}` }))} placeholder="Select an active scheme" />
        </Field>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field htmlFor="award-narrow-by-program" label="Narrow by program" className="sm:col-span-1">
            <Select id="award-narrow-by-program" value={programId} onChange={(e) => setProgramId(e.target.value)} options={programs} placeholder="All programs" />
          </Field>
          <Field htmlFor="award-students" label="Students" required error={errors.student_ids} className="sm:col-span-2" hint={`${students.length} selected`}>
            <Combobox id="award-students" multiple source="students" params={studentParams} value={students} onChange={setStudents} placeholder="Search and add students…" invalid={!!errors.student_ids} />
          </Field>
        </div>
        <Field htmlFor="award-remarks" label="Remarks" error={errors.reason} hint="Shown on the invoice, e.g. the sanction order number.">
          <Input id="award-remarks" value={reason} maxLength={255} placeholder="e.g. Sanctioned by the scholarship committee (SC/2026/07)" onChange={(e) => setReason(e.target.value)} />
        </Field>
        {busy === 'preview' && <Skeleton className="h-24" />}
        {preview && (
          <div className="rounded-xl border border-slate-200 dark:border-slate-800">
            <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 text-sm dark:border-slate-800">
              <span className="font-semibold text-slate-900 dark:text-white">{preview.awarded} student{preview.awarded === 1 ? '' : 's'} · {formatMoney(preview.amount)}</span>
              {preview.skipped.length > 0 && <span className="badge badge-amber">{preview.skipped.length} skipped</span>}
            </div>
            <ul className="max-h-56 divide-y divide-slate-100 overflow-y-auto text-sm dark:divide-slate-800">
              {preview.details.map((d) => (
                <li key={d.student_id} className="flex justify-between gap-3 px-4 py-2"><span className="truncate">{d.name} <span className="text-xs text-slate-500">{d.student_uid}</span></span><span className="tabular-nums text-emerald-700 dark:text-emerald-400">{formatMoney(d.amount)}</span></li>
              ))}
              {preview.skipped.map((sk) => (
                <li key={sk} className={clsx('px-4 py-2 text-xs text-amber-700 dark:text-amber-300')}>{sk}</li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  );
}
