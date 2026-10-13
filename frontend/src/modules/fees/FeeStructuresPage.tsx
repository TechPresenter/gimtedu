import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { useSearchParams } from 'react-router-dom';
import { CalendarClock, Copy, FileText, Layers, ListTree, Pencil, Plus, Trash2, Users, UserPlus } from 'lucide-react';
import {
  Alert, Button, Checkbox, DescriptionList, Drawer, EmptyState, Field, Input, Modal, PageHeader, PageLoader, ProgressBar, Select, Skeleton, StatusBadge, Tabs, Textarea,
  Toggle, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useLookup } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { useFeesRefresh } from './shared';
import type { StructureDetail } from './types';

const SEMESTERS = Array.from({ length: 8 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }));

export default function FeeStructuresPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'heads' ? 'heads' : 'structures';
  const [editor, setEditor] = useState<{ id: number | null } | null>(null);
  const [viewId, setViewId] = useState<number | null>(null);
  const [assignId, setAssignId] = useState<number | null>(null);
  const [duplicate, setDuplicate] = useState<Row | null>(null);

  return (
    <>
      <PageHeader
        title="Fee Structures"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Fee Structures' }]}
        description="Program and semester-wise fee heads with installment plans. Assign a structure to raise invoices for every matching student."
        actions={<Button variant="secondary" icon={FileText} to="/fees/invoices">Invoices</Button>}
      />
      <Tabs
        className="mb-4"
        value={tab}
        onChange={(t) => setParams(t === 'heads' ? new URLSearchParams({ tab: 'heads' }) : new URLSearchParams(), { replace: true })}
        tabs={[{ key: 'structures', label: 'Fee structures', icon: Layers }, { key: 'heads', label: 'Fee heads', icon: ListTree }]}
      />
      {tab === 'heads' ? (
        <CrudTable
          key="heads"
          module="fee_heads"
          urlState
          addLabel="Add fee head"
          emptyTitle="No fee heads yet"
          emptyText="Add the components fees are made of — Admission, Tuition, Examination, Hostel, Transport, Library, Miscellaneous."
          renderers={{
            name: (r) => (
              <div className="min-w-[200px] leading-tight">
                <span className="font-semibold text-slate-900 dark:text-white">{r.name}</span>
                {r.description && <span className="block max-w-md truncate text-xs text-slate-500 dark:text-slate-400" title={r.description}>{r.description}</span>}
              </div>
            ),
          }}
          rowMenu={(r) => [{ label: 'Structures using it', icon: Layers, onClick: () => setParams(new URLSearchParams({ 'f.fee_head_id': String(r.id) }), { replace: true }) }]}
        />
      ) : (
      <CrudTable
        key="structures"
        module="fee_structures"
        urlState
        addLabel="New structure"
        emptyTitle="No fee structures yet"
        emptyText="Create a structure for each program and semester, then assign it to students to raise invoices."
        onCreate={() => setEditor({ id: null })}
        onEdit={(r) => setEditor({ id: Number(r.id) })}
        onView={(r) => setViewId(Number(r.id))}
        renderers={{
          name: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="block min-w-[230px] text-left leading-tight">
              <span className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.name}</span>
              <span className="block text-xs text-slate-500 dark:text-slate-400">{r.program_name} · {r.period_label}</span>
            </button>
          ),
        }}
        rowMenu={(r) => [
          can('fees', 'create') && r.status === 'active' && { label: 'Assign to students', icon: UserPlus, onClick: () => setAssignId(Number(r.id)) },
          can('fees', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => setDuplicate(r) },
          { label: 'View invoices', icon: FileText, to: `/fees/invoices?f.fee_structure_id=${r.id}` },
        ]}
        header={({ summary }) =>
          summary ? (
            <div className="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-800">
              {[
                ['Structures', `${formatNumber(summary.structures)} (${formatNumber(summary.active)} active)`],
                ['Programs covered', formatNumber(summary.programs)],
                ['Assigned / invoices', `${formatNumber(summary.assigned)} · ${formatNumber(summary.invoices)} invoices`],
                ['Billed · collected', `${formatMoneyShort(summary.billed)} · ${formatMoneyShort(summary.collected)}`],
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
      {editor && <StructureEditor id={editor.id} onClose={() => setEditor(null)} onSaved={(id) => { setEditor(null); setViewId(id); }} />}
      <StructureDrawer id={viewId} onClose={() => setViewId(null)} onEdit={(id) => { setViewId(null); setEditor({ id }); }} onAssign={(id) => setAssignId(id)} onDuplicate={(r) => setDuplicate(r)} />
      <AssignModal id={assignId} onClose={() => setAssignId(null)} />
      <DuplicateModal row={duplicate} onClose={() => setDuplicate(null)} onDone={(id) => { setDuplicate(null); setViewId(null); setEditor({ id }); }} />
    </>
  );
}

/* ------------------------------------------------------------------ view drawer */

function StructureDrawer({ id, onClose, onEdit, onAssign, onDuplicate }: { id: number | null; onClose: () => void; onEdit: (id: number) => void; onAssign: (id: number) => void; onDuplicate: (r: Row) => void }) {
  const { can } = useAuth();
  const { data: s, isLoading, error } = useApi<StructureDetail>(['fees-structure', id], `fees/structures/${id}`, undefined, { enabled: !!id });
  const total = s ? toNumber(s.total_amount) : 0;
  const optional = s ? s.items.filter((i) => Number(i.is_optional)).reduce((a, i) => a + toNumber(i.amount), 0) : 0;
  const rate = s && toNumber(s.invoiced_amount) > 0 ? (toNumber(s.collected_amount) / toNumber(s.invoiced_amount)) * 100 : 0;
  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      width="max-w-xl"
      title={s ? <span className="flex flex-wrap items-center gap-2">{s.name}<StatusBadge status={s.status} /></span> : 'Fee structure'}
      description={s ? `${s.program_name} · ${s.session_name}` : undefined}
      footer={
        s && (
          <div className="flex w-full flex-wrap justify-end gap-2">
            <Button size="sm" variant="secondary" icon={FileText} to={`/fees/invoices?f.fee_structure_id=${s.id}`}>Invoices</Button>
            {can('fees', 'create') && <Button size="sm" variant="secondary" icon={Copy} onClick={() => onDuplicate(s as unknown as Row)}>Duplicate</Button>}
            {can('fees', 'edit') && <Button size="sm" variant="secondary" icon={Pencil} onClick={() => onEdit(s.id)}>Edit</Button>}
            {can('fees', 'create') && s.status === 'active' && <Button size="sm" variant="success" icon={UserPlus} onClick={() => onAssign(s.id)}>Assign to students</Button>}
          </div>
        )
      }
    >
      {error ? <Alert variant="error" title="Unable to load the structure">{(error as ApiError).message}</Alert> : isLoading || !s ? (
        <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-40" /><Skeleton className="h-24" /></div>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-2.5">
            <div className="rounded-xl bg-brand-50 px-3 py-2.5 dark:bg-brand-500/10">
              <p className="text-[11px] font-medium uppercase tracking-wide text-brand-800/70 dark:text-brand-200/70">Total</p>
              <p className="font-display text-base font-bold text-brand-900 dark:text-white">{formatMoney(total)}</p>
            </div>
            <div className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/60">
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">Students invoiced</p>
              <p className="font-display text-base font-bold text-slate-900 dark:text-white">{formatNumber(s.invoiced_count)}</p>
            </div>
            <div className="rounded-xl bg-emerald-50 px-3 py-2.5 dark:bg-emerald-500/10">
              <p className="text-[11px] font-medium uppercase tracking-wide text-emerald-800/70 dark:text-emerald-200/70">Collected</p>
              <p className="font-display text-base font-bold text-emerald-800 dark:text-emerald-200">{formatMoneyShort(s.collected_amount)}</p>
            </div>
          </div>
          {toNumber(s.invoiced_count) > 0 && (
            <div className="mt-3">
              <div className="flex justify-between text-xs text-slate-500"><span>Collected {formatMoney(s.collected_amount)} of {formatMoney(s.invoiced_amount)}</span><span>{rate.toFixed(1)}%</span></div>
              <ProgressBar value={rate} tone="green" className="mt-1.5" label="Collection of this structure" />
            </div>
          )}
          <h3 className="mb-2 mt-6 text-sm font-semibold text-slate-900 dark:text-white">Fee heads</h3>
          <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <table className="w-full text-sm">
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {s.items.map((i) => (
                  <tr key={i.id}>
                    <td className="px-3 py-2">{i.head_name}{Number(i.is_optional) ? <span className="badge badge-slate ml-2">Optional</span> : null}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(i.amount)}</td>
                  </tr>
                ))}
                <tr className="bg-slate-50 font-semibold dark:bg-slate-800/60"><td className="px-3 py-2">Total (compulsory)</td><td className="px-3 py-2 text-right tabular-nums">{formatMoney(total)}</td></tr>
                {optional > 0 && <tr className="text-xs text-slate-500"><td className="px-3 py-2">Optional heads (hostellers / transport users / opted in)</td><td className="px-3 py-2 text-right tabular-nums">+ {formatMoney(optional)}</td></tr>}
              </tbody>
            </table>
          </div>
          <h3 className="mb-2 mt-6 text-sm font-semibold text-slate-900 dark:text-white">Payment schedule</h3>
          {s.installments.length ? (
            <ol className="space-y-2">
              {s.installments.map((i) => (
                <li key={i.id} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                  <span className="flex items-center gap-2"><CalendarClock className="h-4 w-4 text-brand-700 dark:text-brand-300" />{i.label ?? `Installment ${i.installment_no}`} · due {formatDate(i.due_date)}</span>
                  <span className="tabular-nums text-slate-600 dark:text-slate-300">{toNumber(i.percentage)}% · {formatMoney((total * toNumber(i.percentage)) / 100)}</span>
                </li>
              ))}
            </ol>
          ) : (
            <p className="rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">Single payment due {formatDate(s.due_date)}</p>
          )}
          <div className="mt-6">
            <DescriptionList
              items={[
                { label: 'Semester', value: s.semester_no ? `Semester ${s.semester_no}` : s.year_no ? `Year ${s.year_no}` : 'All' },
                { label: 'Late fee', value: toNumber(s.late_fee_per_day) > 0 ? `${formatMoney(s.late_fee_per_day)}/day${s.late_fee_max ? ` (max ${formatMoney(s.late_fee_max)})` : ''}` : 'Institute default' },
                { label: 'Created', value: `${formatDate(s.created_at)}${s.created_by_name ? ` by ${s.created_by_name}` : ''}` },
                { label: 'Final due date', value: formatDate(s.due_date) },
                { label: 'Description', value: s.description, full: true },
              ]}
            />
          </div>
        </>
      )}
    </Drawer>
  );
}

/* ------------------------------------------------------------------ editor */

interface ItemRow { fee_head_id: string; amount: string; is_optional: boolean }
interface InstRow { label: string; percentage: string; due_date: string }

function StructureEditor({ id, onClose, onSaved }: { id: number | null; onClose: () => void; onSaved: (id: number) => void }) {
  const toast = useToast();
  const refresh = useFeesRefresh();
  const { id: currentSession } = useAcademicSession();
  const { data: heads = [] } = useLookup('fee_heads');
  const { data: programs = [] } = useLookup('programs');
  const { data: sessions = [] } = useLookup('sessions');
  const { data: existing, isLoading } = useApi<StructureDetail>(['fees-structure', id], `fees/structures/${id}`, undefined, { enabled: !!id });
  const [values, setValues] = useState({ name: '', academic_session_id: '', program_id: '', semester_no: '', due_date: '', late_fee_per_day: '50', late_fee_max: '', description: '', status: 'active' });
  const [items, setItems] = useState<ItemRow[]>([]);
  const [usePlan, setUsePlan] = useState(true);
  const [insts, setInsts] = useState<InstRow[]>([{ label: '1st installment', percentage: '60', due_date: '' }, { label: '2nd installment', percentage: '40', due_date: '' }]);
  const [autoName, setAutoName] = useState(!id);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!id && currentSession && !values.academic_session_id) setValues((v) => ({ ...v, academic_session_id: String(currentSession) }));
  }, [id, currentSession, values.academic_session_id]);
  useEffect(() => {
    if (!id && heads.length && !items.length) {
      const pick = ['tuition', 'examination', 'library'].map((t) => heads.find((h) => h.sub === t)).filter(Boolean);
      setItems(pick.map((h) => ({ fee_head_id: String(h!.value), amount: '', is_optional: false })));
    }
  }, [id, heads, items.length]);
  useEffect(() => {
    if (!existing) return;
    setValues({
      name: existing.name, academic_session_id: String(existing.academic_session_id), program_id: String(existing.program_id), semester_no: existing.semester_no ? String(existing.semester_no) : '',
      due_date: existing.due_date ?? '', late_fee_per_day: String(toNumber(existing.late_fee_per_day)), late_fee_max: existing.late_fee_max !== null ? String(toNumber(existing.late_fee_max)) : '',
      description: existing.description ?? '', status: existing.status,
    });
    setItems(existing.items.map((i) => ({ fee_head_id: String(i.fee_head_id), amount: String(toNumber(i.amount)), is_optional: !!Number(i.is_optional) })));
    setUsePlan(existing.installments.length > 0);
    if (existing.installments.length) setInsts(existing.installments.map((i) => ({ label: i.label ?? '', percentage: String(toNumber(i.percentage)), due_date: i.due_date })));
  }, [existing]);

  // Suggested name: "BBA Semester 1 Fee 2026-27"
  useEffect(() => {
    if (!autoName) return;
    const prog = programs.find((p) => String(p.value) === values.program_id);
    const ses = sessions.find((s) => String(s.value) === values.academic_session_id);
    if (prog && ses) {
      const short = String(prog.label).split(' — ')[0];
      setValues((v) => ({ ...v, name: `${short}${v.semester_no ? ` Semester ${v.semester_no}` : ''} Fee ${ses.label}` }));
    }
  }, [autoName, programs, sessions, values.program_id, values.academic_session_id, values.semester_no]);

  const total = items.reduce((a, i) => a + (i.is_optional ? 0 : toNumber(i.amount)), 0);
  const optionalTotal = items.reduce((a, i) => a + (i.is_optional ? toNumber(i.amount) : 0), 0);
  const pctSum = insts.reduce((a, i) => a + toNumber(i.percentage), 0);
  const set = (k: keyof typeof values, v: string) => {
    setValues((s) => ({ ...s, [k]: v }));
    if (k === 'name') setAutoName(false);
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const usedHeads = useMemo(() => new Set(items.map((i) => i.fee_head_id)), [items]);

  const save = async () => {
    setSaving(true);
    setFormError(null);
    try {
      const body = {
        ...values,
        semester_no: values.semester_no || null,
        late_fee_max: values.late_fee_max === '' ? null : values.late_fee_max,
        due_date: usePlan ? null : values.due_date,
        items: items.map((i) => ({ fee_head_id: i.fee_head_id, amount: i.amount, is_optional: i.is_optional ? 1 : 0 })),
        installments: usePlan ? insts : [],
      };
      const res = await api.post<StructureDetail>(id ? `fees/structures/${id}` : 'fees/structures', body);
      toast.success(res.message);
      await refresh();
      onSaved(res.data.id);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="xl"
      title={id ? 'Edit fee structure' : 'New fee structure'}
      description="Fee heads make up the total; an installment plan splits it into due dates. Invoices already raised are not changed by edits."
      footer={
        <>
          <span className="mr-auto text-sm text-slate-600 dark:text-slate-300">Total <strong className="tabular-nums text-slate-900 dark:text-white">{formatMoney(total)}</strong>{optionalTotal > 0 && <span className="text-xs text-slate-500"> + {formatMoney(optionalTotal)} optional</span>}</span>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={save} loading={saving}>{id ? 'Save changes' : 'Create structure'}</Button>
        </>
      }
    >
      {id && isLoading ? <PageLoader /> : (
        <div className="space-y-6">
          {formError && <Alert variant="error">{formError}</Alert>}
          <div className="grid gap-4 sm:grid-cols-12">
            <Field htmlFor="fs-academic-session" label="Academic session" required error={errors.academic_session_id} className="sm:col-span-4">
              <Select id="fs-academic-session" value={values.academic_session_id} onChange={(e) => set('academic_session_id', e.target.value)} options={sessions} placeholder="Select session" invalid={!!errors.academic_session_id} />
            </Field>
            <Field htmlFor="fs-program" label="Program" required error={errors.program_id} className="sm:col-span-5">
              <Select id="fs-program" value={values.program_id} onChange={(e) => set('program_id', e.target.value)} options={programs} placeholder="Select program" invalid={!!errors.program_id} />
            </Field>
            <Field htmlFor="fs-semester" label="Semester" error={errors.semester_no} className="sm:col-span-3">
              <Select id="fs-semester" value={values.semester_no} onChange={(e) => set('semester_no', e.target.value)} options={SEMESTERS} placeholder="Yearly / all" invalid={!!errors.semester_no} />
            </Field>
            <Field htmlFor="fs-structure-name" label="Structure name" required error={errors.name} className="sm:col-span-8" hint={autoName ? 'Suggested from program, semester and session — edit to override.' : undefined}>
              <Input id="fs-structure-name" value={values.name} maxLength={150} onChange={(e) => set('name', e.target.value)} invalid={!!errors.name} />
            </Field>
            <Field htmlFor="fs-status" label="Status" required error={errors.status} className="sm:col-span-4">
              <Select id="fs-status" value={values.status} onChange={(e) => set('status', e.target.value)} options={{ active: 'Active', inactive: 'Inactive (draft)' }} />
            </Field>
          </div>

          <section>
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Fee heads</h3>
              <Button size="xs" variant="soft" icon={Plus} onClick={() => setItems((l) => [...l, { fee_head_id: '', amount: '', is_optional: false }])}>Add fee head</Button>
            </div>
            {errors.items && <Alert variant="error" className="mb-2">{errors.items}</Alert>}
            <div className="space-y-2">
              {items.map((it, k) => (
                <div key={k} className="grid grid-cols-12 items-start gap-2 rounded-xl border border-slate-200 p-2.5 dark:border-slate-800">
                  <Field error={errors[`items.${k}.fee_head_id`]} className="col-span-12 sm:col-span-6">
                    <Select
                      aria-label="Fee head"
                      value={it.fee_head_id}
                      invalid={!!errors[`items.${k}.fee_head_id`]}
                      onChange={(e) => setItems((l) => l.map((x, i) => (i === k ? { ...x, fee_head_id: e.target.value } : x)))}
                      options={heads.filter((h) => String(h.value) === it.fee_head_id || !usedHeads.has(String(h.value))).map((h) => ({ value: h.value, label: `${h.label}${h.sub ? ` (${h.sub})` : ''}` }))}
                      placeholder="Select fee head"
                    />
                  </Field>
                  <Field error={errors[`items.${k}.amount`]} className="col-span-7 sm:col-span-3">
                    <Input aria-label="Amount" type="number" min={0} step="1" prefix="₹" value={it.amount} invalid={!!errors[`items.${k}.amount`]} onChange={(e) => setItems((l) => l.map((x, i) => (i === k ? { ...x, amount: e.target.value } : x)))} />
                  </Field>
                  <div className="col-span-4 flex h-[42px] items-center sm:col-span-2">
                    <Checkbox label="Optional" checked={it.is_optional} onChange={(e) => setItems((l) => l.map((x, i) => (i === k ? { ...x, is_optional: e.target.checked } : x)))} />
                  </div>
                  <div className="col-span-1 flex h-[42px] items-center justify-end">
                    <button type="button" className="btn-icon !h-8 !w-8" aria-label="Remove fee head" onClick={() => setItems((l) => l.filter((_, i) => i !== k))}><Trash2 className="h-4 w-4" /></button>
                  </div>
                </div>
              ))}
              {!items.length && <p className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-sm text-slate-500 dark:border-slate-700">Add the fee heads that make up this structure.</p>}
            </div>
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Optional Hostel / Transport heads are billed only to hostellers / transport users; other optional heads only when “include optional” is chosen while assigning.</p>
          </section>

          <section>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <Toggle checked={usePlan} onChange={setUsePlan} label="Installment plan" description="Split the total into installments with their own due dates" />
              {usePlan && (
                <span className={clsx('badge', Math.abs(pctSum - 100) < 0.01 ? 'badge-green' : 'badge-amber')}>Shares total {pctSum.toFixed(pctSum % 1 ? 2 : 0)}%</span>
              )}
            </div>
            {usePlan ? (
              <>
                {errors.installments && <Alert variant="error" className="mb-2">{errors.installments}</Alert>}
                <div className="space-y-2">
                  {insts.map((ins, k) => (
                    <div key={k} className="grid grid-cols-12 items-start gap-2 rounded-xl border border-slate-200 p-2.5 dark:border-slate-800">
                      <Field className="col-span-12 sm:col-span-4">
                        <Input aria-label="Installment label" value={ins.label} maxLength={60} placeholder={`Installment ${k + 1}`} onChange={(e) => setInsts((l) => l.map((x, i) => (i === k ? { ...x, label: e.target.value } : x)))} />
                      </Field>
                      <Field error={errors[`installments.${k}.percentage`]} className="col-span-5 sm:col-span-2">
                        <Input aria-label="Share (%)" type="number" min={1} max={100} step="0.01" suffix="%" value={ins.percentage} invalid={!!errors[`installments.${k}.percentage`]} onChange={(e) => setInsts((l) => l.map((x, i) => (i === k ? { ...x, percentage: e.target.value } : x)))} />
                      </Field>
                      <Field error={errors[`installments.${k}.due_date`]} className="col-span-7 sm:col-span-3">
                        <Input aria-label="Due date" type="date" value={ins.due_date} invalid={!!errors[`installments.${k}.due_date`]} onChange={(e) => setInsts((l) => l.map((x, i) => (i === k ? { ...x, due_date: e.target.value } : x)))} />
                      </Field>
                      <div className="col-span-10 flex h-[42px] items-center text-sm tabular-nums text-slate-600 sm:col-span-2 dark:text-slate-300">{formatMoney((total * toNumber(ins.percentage)) / 100)}</div>
                      <div className="col-span-2 flex h-[42px] items-center justify-end sm:col-span-1">
                        <button type="button" className="btn-icon !h-8 !w-8" aria-label="Remove installment" disabled={insts.length <= 1} onClick={() => setInsts((l) => l.filter((_, i) => i !== k))}><Trash2 className="h-4 w-4" /></button>
                      </div>
                    </div>
                  ))}
                </div>
                {insts.length < 6 && (
                  <Button size="xs" variant="soft" icon={Plus} className="mt-2" onClick={() => setInsts((l) => [...l, { label: `Installment ${l.length + 1}`, percentage: String(Math.max(0, 100 - pctSum)), due_date: '' }])}>Add installment</Button>
                )}
              </>
            ) : (
              <Field htmlFor="fs-due-date" label="Due date" required error={errors.due_date} className="max-w-xs">
                <Input id="fs-due-date" type="date" value={values.due_date} invalid={!!errors.due_date} onChange={(e) => set('due_date', e.target.value)} />
              </Field>
            )}
          </section>

          <div className="grid gap-4 sm:grid-cols-12">
            <Field htmlFor="fs-late-fee-per-day" label="Late fee per day (₹)" error={errors.late_fee_per_day} className="sm:col-span-3" hint="0 = institute default">
              <Input id="fs-late-fee-per-day" type="number" min={0} step="1" value={values.late_fee_per_day} invalid={!!errors.late_fee_per_day} onChange={(e) => set('late_fee_per_day', e.target.value)} />
            </Field>
            <Field htmlFor="fs-late-fee-cap" label="Late fee cap (₹)" error={errors.late_fee_max} className="sm:col-span-3">
              <Input id="fs-late-fee-cap" type="number" min={0} step="1" value={values.late_fee_max} placeholder="No cap" invalid={!!errors.late_fee_max} onChange={(e) => set('late_fee_max', e.target.value)} />
            </Field>
            <Field htmlFor="fs-description" label="Description" error={errors.description} className="sm:col-span-6">
              <Textarea id="fs-description" rows={2} maxLength={255} value={values.description} onChange={(e) => set('description', e.target.value)} />
            </Field>
          </div>
        </div>
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------------ assign */

interface AssignPreview { matched: number; already_invoiced: number; to_create: number; total_amount: number; sample: { name: string; student_uid: string; amount: number }[]; created: number }

function AssignModal({ id, onClose }: { id: number | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useFeesRefresh();
  const { data: s } = useApi<StructureDetail>(['fees-structure', id], `fees/structures/${id}`, undefined, { enabled: !!id });
  const [semester, setSemester] = useState('');
  const [section, setSection] = useState('');
  const [includeOptional, setIncludeOptional] = useState(false);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (id) {
      setSemester('');
      setSection('');
      setIncludeOptional(false);
    }
  }, [id]);
  const sem = semester || (s?.semester_no ? String(s.semester_no) : '');
  const { data: sections = [] } = useLookup('sections', { program_id: s?.program_id, semester_no: sem || undefined }, !!s);
  const params = { semester: semester || undefined, section_id: section || undefined, include_optional: includeOptional ? 1 : undefined };
  const { data: preview, isFetching, error } = useApi<AssignPreview>(['fees-assign-preview', id, params], `fees/structures/${id}/assign`, params, { enabled: !!id });
  const hasOptional = s?.items.some((i) => Number(i.is_optional) && !['hostel', 'transport'].includes(i.head_type));

  const submit = async () => {
    setSaving(true);
    try {
      const res = await api.post(`fees/structures/${id}/assign`, { semester: semester || null, section_id: section || null, include_optional: includeOptional ? 1 : 0 });
      toast.success(res.message);
      await refresh();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={id !== null}
      onClose={onClose}
      static={saving}
      size="lg"
      title="Assign to students"
      description={s ? `${s.name} · ${formatMoney(s.total_amount)} per student` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="success" icon={UserPlus} onClick={submit} loading={saving} disabled={!preview || preview.to_create === 0 || isFetching}>
            {preview && preview.to_create > 0 ? `Generate ${formatNumber(preview.to_create)} invoice${preview.to_create === 1 ? '' : 's'}` : 'Generate invoices'}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field htmlFor="fs-students-in-semester" label="Students in semester" hint={s?.semester_no ? `Structure semester: ${s.semester_no}` : s?.year_no ? 'Yearly structure — choose the semester to bill' : 'Not tied to a semester — narrow it down if needed'}>
          <Select id="fs-students-in-semester" value={semester} onChange={(e) => { setSemester(e.target.value); setSection(''); }} options={SEMESTERS.slice(0, s?.total_semesters || 8)} placeholder={s?.semester_no ? `Semester ${s.semester_no} (structure)` : s?.year_no ? `Both semesters of year ${s.year_no}` : 'All semesters'} />
        </Field>
        <Field htmlFor="fs-section" label="Section">
          <Select id="fs-section" value={section} onChange={(e) => setSection(e.target.value)} options={sections} placeholder="All sections" />
        </Field>
        {hasOptional && (
          <div className="sm:col-span-2">
            <Checkbox label="Include optional heads" description="Adds optional (non hostel / transport) heads to every invoice." checked={includeOptional} onChange={(e) => setIncludeOptional(e.target.checked)} />
          </div>
        )}
      </div>
      <div className="mt-5">
        {error ? <Alert variant="error">{(error as ApiError).message}</Alert> : !preview ? <Skeleton className="h-28" /> : (
          <>
            <div className={clsx('grid grid-cols-2 gap-2.5 sm:grid-cols-4 transition-opacity', isFetching && 'opacity-60')}>
              <PreviewTile label="Matching students" value={formatNumber(preview.matched)} />
              <PreviewTile label="Already invoiced" value={formatNumber(preview.already_invoiced)} />
              <PreviewTile label="Invoices to create" value={formatNumber(preview.to_create)} highlight />
              <PreviewTile label="Amount to bill" value={formatMoneyShort(preview.total_amount)} highlight />
            </div>
            {preview.to_create === 0 ? (
              <EmptyState className="!py-8" icon={Users} title={preview.matched ? 'Everyone is already invoiced' : 'No matching students'} description={preview.matched ? 'Every active student in this program / semester already has an invoice from this structure.' : 'There are no active students in this program, semester and section.'} />
            ) : (
              <>
                <p className="mb-2 mt-5 text-xs font-medium uppercase tracking-wide text-slate-500">Preview ({Math.min(8, preview.to_create)} of {formatNumber(preview.to_create)})</p>
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-800">
                  {preview.sample.map((x) => (
                    <li key={x.student_uid} className="flex justify-between gap-3 px-3 py-2"><span className="truncate">{x.name} <span className="text-xs text-slate-500">{x.student_uid}</span></span><span className="tabular-nums">{formatMoney(x.amount)}</span></li>
                  ))}
                </ul>
                <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Invoices, fee-head lines and installments are created in one transaction. Students already billed for this structure are skipped; the admission fee is skipped for students who already paid it.</p>
              </>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}

function PreviewTile({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
  return (
    <div className={clsx('rounded-xl px-3 py-2.5', highlight ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100' : 'bg-slate-50 dark:bg-slate-800/60')}>
      <p className="text-[11px] font-medium uppercase tracking-wide opacity-70">{label}</p>
      <p className="font-display text-lg font-bold tabular-nums">{value}</p>
    </div>
  );
}

/* ------------------------------------------------------------------ duplicate */

function DuplicateModal({ row, onClose, onDone }: { row: Row | null; onClose: () => void; onDone: (id: number) => void }) {
  const toast = useToast();
  const refresh = useFeesRefresh();
  const { data: sessions = [] } = useLookup('sessions');
  const [sessionId, setSessionId] = useState('');
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (row) setSessionId(String(row.academic_session_id ?? ''));
  }, [row]);
  const submit = async () => {
    if (!row) return;
    setSaving(true);
    try {
      const res = await api.post<StructureDetail>(`fees/structures/${row.id}/duplicate`, { academic_session_id: sessionId || null });
      toast.success(res.message);
      await refresh();
      onDone(res.data.id);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!row}
      onClose={onClose}
      static={saving}
      title="Duplicate fee structure"
      description={row ? `Copy "${row.name}" with its fee heads and installment plan.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button icon={Copy} onClick={submit} loading={saving}>Duplicate</Button>
        </>
      }
    >
      <Field htmlFor="fs-copy-into-session" label="Copy into session" hint="Due dates shift by whole years when copying into another session. The copy starts inactive so you can review it first.">
        <Select id="fs-copy-into-session" value={sessionId} onChange={(e) => setSessionId(e.target.value)} options={sessions} />
      </Field>
    </Modal>
  );
}
