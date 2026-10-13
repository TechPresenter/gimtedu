import { useState } from 'react';
import clsx from 'clsx';
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Badge, Button, DataTable, Field, IconButton, Input, Modal, PageLoader, Select, StatusBadge, Textarea, Toggle, toneClasses, useConfirm, useToast, type Column } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatNumber, labelize } from '@/lib/format';
import { leaveChip, leaveTone, type LeaveType } from '../hr';

const COLORS = ['blue', 'green', 'amber', 'purple', 'pink', 'cyan', 'red', 'orange', 'navy', 'slate'];
const blank = { code: '', name: '', annual_quota: '0', applies_to: 'all', gender: '', is_paid: true, max_consecutive: '', color: 'blue', description: '', status: 'active', sort_order: '0' };
type FormState = typeof blank;

/** Leave policy: list + create / edit / delete leave types (faculty:manage). */
export function LeaveTypesModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data, isLoading, error } = useApi<{ rows: LeaveType[]; can_manage: boolean; weekly_offs: number[] }>(['leave-types'], 'leaves/types', undefined, { enabled: open });
  const [editing, setEditing] = useState<LeaveType | 'new' | null>(null);
  const [form, setForm] = useState<FormState>(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const canManage = !!data?.can_manage;

  const refresh = () => Promise.all(['leave-types', 'leaves-summary', 'leave-balances', 'leave-balance', 'crud-meta'].map((k) => qc.invalidateQueries({ queryKey: [k] })));
  const startEdit = (t: LeaveType | 'new') => {
    setErrors({});
    setEditing(t);
    setForm(
      t === 'new'
        ? { ...blank, sort_order: String((data?.rows.length ?? 0) + 1) }
        : { code: t.code, name: t.name, annual_quota: String(t.annual_quota), applies_to: t.applies_to, gender: t.gender ?? '', is_paid: t.is_paid, max_consecutive: t.max_consecutive ? String(t.max_consecutive) : '', color: t.color ?? 'slate', description: t.description ?? '', status: t.status, sort_order: String(t.sort_order) },
    );
  };
  const set = (k: keyof FormState, v: string | boolean) => {
    setForm((f) => ({ ...f, [k]: v }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const save = async () => {
    setSaving(true);
    try {
      const body = { ...form, max_consecutive: form.max_consecutive || null };
      const res = editing && editing !== 'new' ? await api.post(`leaves/types/${editing.id}`, body) : await api.post('leaves/types', body);
      toast.success(res.message);
      await refresh();
      setEditing(null);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  const remove = async (t: LeaveType) => {
    const ok = await confirm({ title: `Delete "${t.name}"?`, message: 'Only leave types that were never used can be deleted. Used types can be marked inactive instead.', confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`leaves/types/${t.id}`);
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const columns: Column<LeaveType>[] = [
    {
      key: 'name', header: 'Leave type',
      render: (t) => (
        <div className="flex items-center gap-2.5">
          <span className={clsx('h-2.5 w-2.5 shrink-0 rounded-full', toneClasses[leaveTone(t.color)].bar)} />
          <div className="min-w-0 leading-tight">
            <p className="font-semibold text-slate-900 dark:text-white">{t.name}</p>
            <p className="text-xs text-slate-500"><code>{t.code}</code>{t.max_consecutive ? ` · max ${t.max_consecutive} days at a time` : ''}</p>
          </div>
        </div>
      ),
    },
    { key: 'quota', header: 'Quota / session', align: 'center', render: (t) => (t.annual_quota > 0 ? `${formatNumber(t.annual_quota, t.annual_quota % 1 ? 1 : 0)} days` : <span className="text-slate-400">No limit</span>) },
    { key: 'applies', header: 'Applies to', render: (t) => (t.gender ? `${t.applies_to === 'all' ? '' : `${labelize(t.applies_to)} · `}${labelize(t.gender)} only` : t.applies_to === 'all' ? 'Everyone' : labelize(t.applies_to)) },
    { key: 'paid', header: 'Paid', align: 'center', render: (t) => (t.is_paid ? <Badge color="green">Paid</Badge> : <Badge color="red">LOP</Badge>) },
    { key: 'usage', header: 'Used (session)', align: 'right', render: (t) => <span className="whitespace-nowrap">{formatNumber(t.session_days ?? 0, (t.session_days ?? 0) % 1 ? 1 : 0)} d · {t.applications ?? 0} apps</span> },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
  ];

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="xl"
      title={editing ? (editing === 'new' ? 'New leave type' : `Edit ${editing.name}`) : 'Leave policy'}
      description={editing ? 'Quota is counted per academic session (July – June). Set 0 for no fixed limit.' : `Leave types, yearly quotas and rules. Weekly off: ${(data?.weekly_offs ?? [0]).map((d) => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][d]).join(', ')}; holidays come from the academic calendar.`}
      footer={
        editing ? (
          <>
            <Button variant="secondary" icon={ArrowLeft} onClick={() => setEditing(null)} disabled={saving}>Back</Button>
            <Button onClick={save} loading={saving}>{editing === 'new' ? 'Create leave type' : 'Save changes'}</Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>Close</Button>
            {canManage && <Button icon={Plus} onClick={() => startEdit('new')}>Add leave type</Button>}
          </>
        )
      }
    >
      {isLoading ? (
        <PageLoader />
      ) : error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : editing ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
          <Field className="sm:col-span-8" label="Name" required htmlFor="lt-name" error={errors.name}>
            <Input id="lt-name" value={form.name} onChange={(e) => set('name', e.target.value)} maxLength={80} invalid={!!errors.name} placeholder="e.g. Casual Leave" />
          </Field>
          <Field className="sm:col-span-4" label="Code" required htmlFor="lt-code" error={errors.code} hint="Short key, e.g. casual">
            <Input id="lt-code" value={form.code} onChange={(e) => set('code', e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''))} maxLength={30} invalid={!!errors.code} />
          </Field>
          <Field className="sm:col-span-4" label="Quota per session (days)" required htmlFor="lt-quota" error={errors.annual_quota}>
            <Input id="lt-quota" type="number" min={0} max={365} step={0.5} value={form.annual_quota} onChange={(e) => set('annual_quota', e.target.value)} invalid={!!errors.annual_quota} />
          </Field>
          <Field className="sm:col-span-4" label="Max days per application" htmlFor="lt-max" error={errors.max_consecutive} hint="Optional">
            <Input id="lt-max" type="number" min={1} max={365} value={form.max_consecutive} onChange={(e) => set('max_consecutive', e.target.value)} invalid={!!errors.max_consecutive} />
          </Field>
          <Field className="sm:col-span-4" label="Applies to" required htmlFor="lt-applies" error={errors.applies_to}>
            <Select id="lt-applies" value={form.applies_to} onChange={(e) => set('applies_to', e.target.value)} options={[{ value: 'all', label: 'Everyone' }, { value: 'faculty', label: 'Faculty only' }, { value: 'staff', label: 'Staff only' }]} />
          </Field>
          <Field className="sm:col-span-4" label="Gender" htmlFor="lt-gender" error={errors.gender} hint="For maternity / paternity leave">
            <Select id="lt-gender" value={form.gender} onChange={(e) => set('gender', e.target.value)} options={[{ value: 'female', label: 'Female only' }, { value: 'male', label: 'Male only' }]} placeholder="Any" />
          </Field>
          <Field className="sm:col-span-8" label="Colour" error={errors.color}>
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Colour">
              {COLORS.map((c) => (
                <button key={c} type="button" role="radio" aria-checked={form.color === c} onClick={() => set('color', c)} className={clsx('rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset transition', leaveChip[leaveTone(c)], form.color === c ? 'ring-2 ring-offset-2 ring-offset-white dark:ring-offset-slate-900' : 'opacity-70 hover:opacity-100')}>
                  {labelize(c)}
                </button>
              ))}
            </div>
          </Field>
          <Field className="sm:col-span-4" label="Paid leave" htmlFor="lt-paid">
            <Toggle id="lt-paid" checked={form.is_paid} onChange={(v) => set('is_paid', v)} label={form.is_paid ? 'Paid' : 'Loss of pay'} />
          </Field>
          <Field className="sm:col-span-4" label="Status" htmlFor="lt-status" error={errors.status}>
            <Select id="lt-status" value={form.status} onChange={(e) => set('status', e.target.value)} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
          </Field>
          <Field className="sm:col-span-4" label="Sort order" htmlFor="lt-sort" error={errors.sort_order}>
            <Input id="lt-sort" type="number" min={0} max={999} value={form.sort_order} onChange={(e) => set('sort_order', e.target.value)} />
          </Field>
          <Field className="sm:col-span-12" label="Description" htmlFor="lt-desc" error={errors.description}>
            <Textarea id="lt-desc" rows={2} maxLength={255} value={form.description} onChange={(e) => set('description', e.target.value)} placeholder="Shown when applying" />
          </Field>
        </div>
      ) : (
        <div className="-mx-5 -my-5">
          <DataTable<LeaveType>
            columns={columns}
            rows={data?.rows ?? []}
            dense
            caption="Leave types"
            actions={
              canManage
                ? (t) => (
                    <div className="flex justify-end gap-0.5">
                      <IconButton size="sm" icon={Pencil} label={`Edit ${t.name}`} tone="primary" onClick={() => startEdit(t)} />
                      <IconButton size="sm" icon={Trash2} label={`Delete ${t.name}`} tone="danger" onClick={() => remove(t)} />
                    </div>
                  )
                : undefined
            }
          />
          {!canManage && <p className="px-5 py-3 text-xs text-slate-500">Only users with "manage" rights on Faculty & Staff can change the leave policy.</p>}
        </div>
      )}
    </Modal>
  );
}
