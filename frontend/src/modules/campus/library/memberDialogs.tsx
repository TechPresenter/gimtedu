import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Ban, BookUp, CheckCircle2, IdCard, IndianRupee, Pencil, UsersRound } from 'lucide-react';
import {
  Alert, Button, Combobox, Drawer, Field, Input, Modal, PageLoader, RadioGroup, Select, Skeleton, Textarea, Timeline, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, isoDate } from '@/lib/format';
import type { CrudRecordPayload } from '@/components/crud';
import { CollectFinesDialog, useLoanActions } from './dialogs';
import { FINE_REASON_LABELS, LoanRow, LoanStateBadge, MEMBER_TYPES, MemberSummary, membershipState, openPrint, useLibraryRefresh } from './shared';
import type { MemberCardPayload, MemberType, RulesPayload } from './types';

/* ---------------------------------------------------------------- Add / edit member */

interface MemberForm {
  member_type: MemberType;
  person_id: string | number | null;
  membership_no: string;
  max_books: string;
  loan_days: string;
  valid_until: string;
  status: string;
  remarks: string;
}

export function MemberFormDialog({ open, id, onClose, onSaved }: { open: boolean; id: number | null; onClose: () => void; onSaved?: (id: number) => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const rules = useApi<RulesPayload>(['library', 'rules'], 'library/rules', undefined, { enabled: open });
  const [v, setV] = useState<MemberForm>({ member_type: 'student', person_id: null, membership_no: '', max_books: '', loan_days: '', valid_until: '', status: 'active', remarks: '' });
  const [personLabel, setPersonLabel] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    if (!id) {
      setV({ member_type: 'student', person_id: null, membership_no: '', max_books: '', loan_days: '', valid_until: '', status: 'active', remarks: '' });
      setPersonLabel('');
      return;
    }
    setLoading(true);
    api
      .get<CrudRecordPayload>(`crud/library_members/${id}`)
      .then((p) => {
        const r = p.row as Record<string, unknown>;
        setV({
          member_type: r.member_type as MemberType, person_id: null, membership_no: String(r.membership_no ?? ''), max_books: String(r.max_books ?? ''), loan_days: String(r.loan_days ?? ''),
          valid_until: String(r.valid_until ?? ''), status: String(r.status ?? 'active'), remarks: String(r.remarks ?? ''),
        });
        setPersonLabel(`${r.member_name ?? ''}${r.member_info ? ` — ${r.member_info}` : ''}`);
      })
      .catch((e: ApiError) => setFormError(e.message))
      .finally(() => setLoading(false));
  }, [open, id]);

  const set = <K extends keyof MemberForm>(k: K, val: MemberForm[K]) => {
    setV((s) => ({ ...s, [k]: val }));
    setErrors((e) => ({ ...e, [k]: '', ...(k === 'person_id' ? { student_id: '', faculty_id: '', staff_id: '' } : {}) }));
  };
  const defaults = rules.data?.rules.types[v.member_type];
  const personKey = `${v.member_type}_id`;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!id && !v.person_id) {
      setErrors({ [personKey]: `Select the ${v.member_type} to enroll.` });
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const body: Record<string, unknown> = { max_books: v.max_books, loan_days: v.loan_days, valid_until: v.valid_until, status: v.status, remarks: v.remarks };
      if (!id) {
        body.member_type = v.member_type;
        body[personKey] = v.person_id;
        body.membership_no = v.membership_no;
      }
      const res = await api.post<{ id: number }>(id ? `crud/library_members/${id}` : 'crud/library_members', body);
      toast.success(res.message);
      await refresh();
      onSaved?.(res.data.id);
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      title={id ? 'Edit membership' : 'Add library member'}
      description={id ? 'Update borrowing limits, validity and status.' : 'Enroll a student, faculty member or staff member. The membership number is generated automatically.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="lib-member-form" loading={saving} disabled={loading}>
            {id ? 'Save changes' : 'Create member'}
          </Button>
        </>
      }
    >
      {loading ? (
        <PageLoader label="Loading member…" />
      ) : (
        <form id="lib-member-form" onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-6">
          {formError && <Alert variant="error" className="sm:col-span-6">{formError}</Alert>}
          {id ? (
            <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm sm:col-span-6 dark:bg-slate-800/60">
              <p className="font-semibold text-slate-900 dark:text-white">{personLabel}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                <span className="font-mono">{v.membership_no}</span> · {MEMBER_TYPES.find((t) => t.value === v.member_type)?.label}
              </p>
            </div>
          ) : (
            <>
              <Field label="Member type" required className="sm:col-span-6" error={errors.member_type}>
                <RadioGroup
                  name="member_type"
                  value={v.member_type}
                  onChange={(t) => {
                    set('member_type', t as MemberType);
                    set('person_id', null);
                  }}
                  options={MEMBER_TYPES}
                />
              </Field>
              <Field label={MEMBER_TYPES.find((t) => t.value === v.member_type)?.label ?? 'Person'} required htmlFor="lm-person" className="sm:col-span-4" error={errors[personKey]} hint="Only people who are not yet members are listed">
                <Combobox
                  key={v.member_type}
                  id="lm-person"
                  value={v.person_id}
                  onChange={(val) => set('person_id', val)}
                  optionsUrl="library/members/candidates"
                  params={{ type: v.member_type }}
                  placeholder={`Search ${v.member_type} by name or ID…`}
                  invalid={!!errors[personKey]}
                />
              </Field>
              <Field label="Membership no" htmlFor="lm-no" className="sm:col-span-2" error={errors.membership_no}>
                <Input id="lm-no" value={v.membership_no} onChange={(e) => set('membership_no', e.target.value.toUpperCase())} placeholder="Auto" maxLength={30} invalid={!!errors.membership_no} />
              </Field>
            </>
          )}
          <Field label="Books allowed" htmlFor="lm-max" className="sm:col-span-2" error={errors.max_books} hint={defaults ? `Default ${defaults.max_books}` : undefined}>
            <Input id="lm-max" type="number" min={1} max={30} value={v.max_books} onChange={(e) => set('max_books', e.target.value)} placeholder={defaults ? String(defaults.max_books) : ''} invalid={!!errors.max_books} />
          </Field>
          <Field label="Loan period (days)" htmlFor="lm-days" className="sm:col-span-2" error={errors.loan_days} hint={defaults ? `Default ${defaults.loan_days}` : undefined}>
            <Input id="lm-days" type="number" min={1} max={365} value={v.loan_days} onChange={(e) => set('loan_days', e.target.value)} placeholder={defaults ? String(defaults.loan_days) : ''} invalid={!!errors.loan_days} />
          </Field>
          <Field label="Valid until" htmlFor="lm-valid" className="sm:col-span-2" error={errors.valid_until} hint={!v.valid_until ? 'Blank = end of session' : undefined}>
            <Input id="lm-valid" type="date" value={v.valid_until} onChange={(e) => set('valid_until', e.target.value)} invalid={!!errors.valid_until} />
          </Field>
          <Field label="Status" htmlFor="lm-status" className="sm:col-span-2" error={errors.status}>
            <Select id="lm-status" value={v.status} onChange={(e) => set('status', e.target.value)} options={[{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }, { value: 'expired', label: 'Expired' }]} />
          </Field>
          <Field label="Remarks" htmlFor="lm-remarks" className="sm:col-span-4" error={errors.remarks}>
            <Input id="lm-remarks" value={v.remarks} onChange={(e) => set('remarks', e.target.value)} maxLength={255} placeholder="Optional note (e.g. reason for suspension)" />
          </Field>
        </form>
      )}
    </Modal>
  );
}

/* ---------------------------------------------------------------- Bulk enroll */

type Target = 'section' | 'program' | 'all_students' | 'faculty' | 'staff';

export function EnrollDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const [target, setTarget] = useState<Target>('section');
  const [programId, setProgramId] = useState<string | number | null>(null);
  const [sectionId, setSectionId] = useState<string | number | null>(null);
  const [semester, setSemester] = useState('');
  const [maxBooks, setMaxBooks] = useState('');
  const [loanDays, setLoanDays] = useState('');
  const [validUntil, setValidUntil] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const programs = useLookup('programs', {}, open);
  const sections = useLookup('sections', programId ? { program_id: programId } : {}, open && target === 'section');

  useEffect(() => {
    if (open) {
      setTarget('section');
      setProgramId(null);
      setSectionId(null);
      setSemester('');
      setMaxBooks('');
      setLoanDays('');
      setValidUntil('');
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  const ready = target === 'section' ? !!sectionId : target === 'program' ? !!programId : true;
  const preview = useApi<{ total: number; new: number; already: number; defaults: { max_books: number; loan_days: number; valid_until: string }; type: MemberType }>(
    ['library', 'enroll-preview', target, programId, sectionId, semester],
    'library/members/enroll-preview',
    { target, program_id: programId ?? undefined, section_id: sectionId ?? undefined, semester: semester || undefined },
    { enabled: open && ready },
  );
  const p = preview.data;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.post('library/members/enroll', { target, program_id: programId, section_id: sectionId, semester, max_books: maxBooks, loan_days: loanDays, valid_until: validUntil });
      toast.success(res.message);
      await refresh();
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200">
          <UsersRound className="h-5 w-5" />
        </span>
      }
      title="Bulk enroll members"
      description="Create library memberships for a whole section, program or staff group in one step. Existing members are skipped."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="lib-enroll-form" loading={saving} disabled={!ready || !p || p.new === 0}>
            {p && p.new > 0 ? `Enroll ${p.new} member${p.new === 1 ? '' : 's'}` : 'Enroll'}
          </Button>
        </>
      }
    >
      <form id="lib-enroll-form" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <Field label="Who to enroll" required error={errors.target}>
          <RadioGroup
            name="target"
            value={target}
            onChange={(t) => setTarget(t as Target)}
            options={[
              { value: 'section', label: 'A section' },
              { value: 'program', label: 'A program' },
              { value: 'all_students', label: 'All students' },
              { value: 'faculty', label: 'All faculty' },
              { value: 'staff', label: 'All staff' },
            ]}
          />
        </Field>
        {(target === 'section' || target === 'program') && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Program" required={target === 'program'} htmlFor="en-program" error={errors.program_id}>
              <Combobox id="en-program" value={programId} onChange={(val) => { setProgramId(val); setSectionId(null); }} options={programs.data ?? []} placeholder="Select program…" />
            </Field>
            {target === 'section' ? (
              <Field label="Section" required htmlFor="en-section" error={errors.section_id} hint={programId ? undefined : 'Pick a program to narrow the list'}>
                <Combobox id="en-section" value={sectionId} onChange={setSectionId} options={sections.data ?? []} placeholder="Select section…" />
              </Field>
            ) : (
              <Field label="Semester" htmlFor="en-sem" error={errors.semester} hint="Blank = all semesters">
                <Select id="en-sem" value={semester} onChange={(e) => setSemester(e.target.value)} placeholder="All semesters" options={Array.from({ length: 10 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }))} />
              </Field>
            )}
          </div>
        )}
        <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
          {!ready ? (
            <p className="text-sm text-slate-500 dark:text-slate-400">Choose the {target === 'section' ? 'section' : 'program'} to see how many people will be enrolled.</p>
          ) : !p ? (
            <Skeleton className="h-10 w-full" />
          ) : (
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
              <span>
                <strong className="font-display text-2xl text-brand-800 dark:text-brand-200">{p.new}</strong> <span className="text-slate-600 dark:text-slate-300">new members</span>
              </span>
              <span className="text-slate-500 dark:text-slate-400">
                {p.already} already enrolled · {p.total} in the group
              </span>
              {p.new === 0 && <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400"><CheckCircle2 className="h-4 w-4" /> Everyone here is already a member</span>}
            </div>
          )}
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Books allowed" htmlFor="en-max" error={errors.max_books} hint={p ? `Default ${p.defaults.max_books}` : undefined}>
            <Input id="en-max" type="number" min={1} max={30} value={maxBooks} onChange={(e) => setMaxBooks(e.target.value)} placeholder={p ? String(p.defaults.max_books) : ''} invalid={!!errors.max_books} />
          </Field>
          <Field label="Loan period (days)" htmlFor="en-days" error={errors.loan_days} hint={p ? `Default ${p.defaults.loan_days}` : undefined}>
            <Input id="en-days" type="number" min={1} max={365} value={loanDays} onChange={(e) => setLoanDays(e.target.value)} placeholder={p ? String(p.defaults.loan_days) : ''} invalid={!!errors.loan_days} />
          </Field>
          <Field label="Valid until" htmlFor="en-valid" error={errors.valid_until} hint={p ? `Default ${formatDate(p.defaults.valid_until)}` : undefined}>
            <Input id="en-valid" type="date" min={isoDate()} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} invalid={!!errors.valid_until} />
          </Field>
        </div>
      </form>
    </Modal>
  );
}

/* ---------------------------------------------------------------- Suspend / activate */

export interface StatusTarget {
  ids: number[];
  mode: 'suspended' | 'active';
  label: string;
  clear?: () => void;
}

export function MemberStatusDialog({ target, onClose }: { target: StatusTarget | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const [remarks, setRemarks] = useState('');
  const [validUntil, setValidUntil] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (target) {
      setRemarks('');
      setValidUntil('');
      setErrors({});
    }
  }, [target]);
  const suspend = target?.mode === 'suspended';

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!target) return;
    if (suspend && remarks.trim().length < 3) {
      setErrors({ remarks: 'Enter the reason for suspending the membership.' });
      return;
    }
    setSaving(true);
    try {
      const res = await api.post('library/members/status', { ids: target.ids, status: target.mode, remarks, valid_until: validUntil });
      toast.success(res.message);
      target.clear?.();
      await refresh();
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(Object.keys(ex.errors ?? {}).length ? ex.errors : { form: ex.message });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!target}
      onClose={onClose}
      static={saving}
      size="sm"
      title={suspend ? 'Suspend membership' : 'Activate membership'}
      description={target?.label}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="lib-status-form" variant={suspend ? 'danger' : 'primary'} loading={saving}>
            {suspend ? 'Suspend' : 'Activate'}
          </Button>
        </>
      }
    >
      <form id="lib-status-form" onSubmit={submit} noValidate className="space-y-4">
        {errors.form && <Alert variant="error">{errors.form}</Alert>}
        {suspend ? (
          <p className="text-sm text-slate-600 dark:text-slate-300">Suspended members cannot borrow until reactivated. Books they already have stay on loan.</p>
        ) : (
          <p className="text-sm text-slate-600 dark:text-slate-300">Expired memberships are extended to the end of the session unless you choose a date.</p>
        )}
        <Field label={suspend ? 'Reason' : 'Note'} required={suspend} htmlFor="ms-remarks" error={errors.remarks}>
          <Textarea id="ms-remarks" rows={2} value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} invalid={!!errors.remarks} placeholder={suspend ? 'e.g. Repeated late returns' : 'Optional'} />
        </Field>
        {!suspend && (
          <Field label="Valid until" htmlFor="ms-valid" error={errors.valid_until}>
            <Input id="ms-valid" type="date" min={isoDate()} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} invalid={!!errors.valid_until} />
          </Field>
        )}
      </form>
    </Modal>
  );
}

/* ---------------------------------------------------------------- Member drawer (card) */

export function MemberDrawer({ memberId, onClose, onEdit, onStatus }: { memberId: number | null; onClose: () => void; onEdit: (id: number) => void; onStatus: (t: StatusTarget) => void }) {
  const { can } = useAuth();
  const q = useApi<MemberCardPayload>(['library', 'member', memberId], `library/members/${memberId}`, undefined, { enabled: !!memberId });
  const loanActions = useLoanActions(() => void q.refetch());
  const [fineSel, setFineSel] = useState<{ ids: number[]; total: number; members: number } | null>(null);
  const d = q.data;
  const state = d ? membershipState(d.member) : null;

  return (
    <Drawer
      open={!!memberId}
      onClose={onClose}
      width="max-w-2xl"
      title="Member card"
      description={d ? `${d.member.membership_no} · member since ${formatDate(d.member.created_at)}` : undefined}
      footer={
        d && (
          <div className="flex w-full flex-wrap justify-end gap-2">
            <Button variant="secondary" icon={IdCard} onClick={() => openPrint('cards', { ids: d.member.id })}>
              Print card
            </Button>
            {can('library', 'edit') && (
              <>
                <Button variant="secondary" icon={Pencil} onClick={() => onEdit(d.member.id)}>
                  Edit
                </Button>
                {d.member.status === 'suspended' || state === 'expired' ? (
                  <Button variant="secondary" icon={CheckCircle2} onClick={() => onStatus({ ids: [d.member.id], mode: 'active', label: `${d.member.name} (${d.member.membership_no})` })}>
                    Activate
                  </Button>
                ) : (
                  <Button variant="secondary" icon={Ban} className="text-red-600 dark:text-red-400" onClick={() => onStatus({ ids: [d.member.id], mode: 'suspended', label: `${d.member.name} (${d.member.membership_no})` })}>
                    Suspend
                  </Button>
                )}
              </>
            )}
            {can('library', 'create') && (
              <Button icon={BookUp} to={`/library/circulation?tab=issue&member=${d.member.id}`}>
                Issue book
              </Button>
            )}
          </div>
        )
      }
    >
      {!d ? (
        q.error ? <Alert variant="error">{(q.error as ApiError).message}</Alert> : <PageLoader label="Loading member…" />
      ) : (
        <div className="space-y-6">
          <MemberSummary member={d.member} blocks={d.blocks} />
          <div className="grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
            {[
              ['Borrowed (all time)', d.stats.borrowed],
              ['Returned late', d.stats.returned_late],
              ['Lost', d.stats.lost],
              ['Fines paid', formatMoney(d.stats.fines_paid)],
            ].map(([l, val]) => (
              <div key={String(l)} className="rounded-xl border border-slate-200 px-2 py-2.5 dark:border-slate-800">
                <p className="font-display text-base font-bold text-slate-900 dark:text-white">{val}</p>
                <p className="text-[11px] text-slate-500 dark:text-slate-400">{l}</p>
              </div>
            ))}
          </div>

          <section>
            <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Current loans ({d.loans.length})</h3>
            {d.loans.length ? (
              <ul className="space-y-2">
                {d.loans.map((l) => (
                  <LoanRow
                    key={l.id}
                    loan={l}
                    busy={loanActions.busy}
                    onReturn={loanActions.canEdit ? loanActions.openReturn : undefined}
                    onRenew={loanActions.canEdit ? loanActions.renew : undefined}
                    onLost={loanActions.canEdit ? loanActions.openLost : undefined}
                  />
                ))}
              </ul>
            ) : (
              <p className="rounded-xl border border-dashed border-slate-200 px-4 py-4 text-center text-sm text-slate-500 dark:border-slate-700">No books issued right now.</p>
            )}
          </section>

          <section>
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Unpaid fines ({d.fines.length})</h3>
              {d.fines.length > 0 && loanActions.canEdit && (
                <Button size="xs" variant="success" icon={IndianRupee} onClick={() => setFineSel({ ids: d.fines.map((f) => f.id), total: d.fines.reduce((a, f) => a + f.amount, 0), members: 1 })}>
                  Collect {formatMoney(d.member.unpaid_fines)}
                </Button>
              )}
            </div>
            {d.fines.length ? (
              <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                {d.fines.map((f) => (
                  <li key={f.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                    <div className="min-w-0">
                      <p className="truncate font-medium text-slate-800 dark:text-slate-100">{FINE_REASON_LABELS[f.reason] ?? f.reason}{f.days_overdue ? ` · ${f.days_overdue} days` : ''}</p>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">{f.book_title ?? f.remarks ?? '—'} · {formatDate(f.created_at)}</p>
                    </div>
                    <span className="shrink-0 font-semibold tabular-nums text-red-600 dark:text-red-400">{formatMoney(f.amount, 2)}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-slate-500 dark:text-slate-400">No dues. </p>
            )}
          </section>

          <section>
            <div className="mb-3 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Borrowing history</h3>
              <Link to={`/library/circulation?tab=history&q=${encodeURIComponent(d.member.membership_no)}`} className="link text-xs">
                Full history
              </Link>
            </div>
            <Timeline
              empty="No books borrowed yet."
              items={d.history.map((h) => ({
                icon: BookUp,
                tone: h.state === 'overdue' ? 'red' : h.state === 'returned' ? 'green' : h.state === 'lost' ? 'slate' : h.state === 'returned_late' ? 'amber' : 'blue',
                title: (
                  <span className="flex flex-wrap items-center gap-2">
                    <Link to={`/library/books/${h.book_id}`} className="font-medium hover:text-brand-700 dark:hover:text-brand-300">{h.title}</Link>
                    <LoanStateBadge state={h.state} />
                  </span>
                ),
                description: `${h.accession_no} · issued ${formatDate(h.issue_date)} · due ${formatDate(h.due_date)}${h.return_date ? ` · returned ${formatDate(h.return_date)}` : ''}${h.fine_amount > 0 ? ` · fine ${formatMoney(h.fine_amount)}` : ''}`,
              }))}
            />
          </section>
        </div>
      )}
      <CollectFinesDialog selection={fineSel} onClose={() => setFineSel(null)} onDone={() => void q.refetch()} />
      {loanActions.dialogs}
    </Drawer>
  );
}
