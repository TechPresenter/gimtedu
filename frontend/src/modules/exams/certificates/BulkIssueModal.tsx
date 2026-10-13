import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, Files, Printer, Search, Users } from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Checkbox, Combobox, EmptyState, Field, Input, Modal, Select, Skeleton, Stepper, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useLookup } from '@/lib/queries';
import { printUrl } from '@/lib/config';
import { isoDate } from '@/lib/format';
import { TypeIcon, useCertConfig } from './shared';
import type { BulkCandidate } from './types';

interface BulkResult {
  issued: { id: number; certificate_no: string; status: string; student_id: number }[];
  skipped: { student_id: number; name: string; reason: string }[];
}

const STEPS = [
  { key: 'setup', label: 'Class & certificate' },
  { key: 'students', label: 'Select students' },
  { key: 'done', label: 'Issued' },
];

/** Issue one certificate type to a whole section (or program + semester). */
export function BulkIssueModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const { data: config } = useCertConfig();
  const programs = useLookup('programs', {}, open);
  const [step, setStep] = useState<'setup' | 'students' | 'done'>('setup');
  const [programId, setProgramId] = useState('');
  const [semester, setSemester] = useState('');
  const [sectionId, setSectionId] = useState<string | number | null>(null);
  const [type, setType] = useState('bonafide');
  const [templateId, setTemplateId] = useState('');
  const [issueDate, setIssueDate] = useState(isoDate());
  const [fields, setFields] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);
  const [candidates, setCandidates] = useState<BulkCandidate[]>([]);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [filter, setFilter] = useState('');
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<BulkResult | null>(null);

  useEffect(() => {
    if (!open) return;
    setStep('setup');
    setResult(null);
    setErrors({});
    setCandidates([]);
    setSelected(new Set());
  }, [open]);

  const typeDef = config?.types.find((t) => t.key === type);
  useEffect(() => {
    if (!config || !typeDef) return;
    const next: Record<string, string> = {};
    typeDef.fields.forEach((k) => (next[k] = config.fields[k]?.default ?? ''));
    setFields(next);
    setTemplateId('');
  }, [type, config]); // eslint-disable-line react-hooks/exhaustive-deps

  const program = programs.data?.find((p) => String(p.value) === programId);
  const templates = (config?.templates ?? []).filter((t) => t.type === type || t.type === 'custom');
  const semesters = useMemo(() => Array.from({ length: 8 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` })), []);

  const load = async () => {
    setLoading(true);
    setErrors({});
    try {
      const data = await api.get<{ students: BulkCandidate[] }>('certificates/bulk-candidates', {
        type, section_id: sectionId ?? undefined, program_id: sectionId ? undefined : programId || undefined, semester: sectionId ? undefined : semester || undefined,
      });
      setCandidates(data.students);
      // Pre-select eligible students who do not already hold this certificate (those can still be ticked manually).
      setSelected(new Set(data.students.filter((s) => s.eligible && !s.warning).map((s) => s.id)));
      setStep('students');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setLoading(false);
    }
  };

  const issue = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post<BulkResult>('certificates/bulk-issue', { student_ids: [...selected], type, template_id: templateId || null, issue_date: issueDate, fields });
      setResult(res.data);
      setStep('done');
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['crud', 'certificates'] });
      void qc.invalidateQueries({ queryKey: ['cert-summary'] });
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (Object.keys(err.errors ?? {}).some((k) => k.startsWith('fields.') || ['issue_date', 'template_id', 'type'].includes(k))) setStep('setup');
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const visible = candidates.filter((c) => !filter || `${c.name} ${c.student_uid} ${c.roll_no ?? ''}`.toLowerCase().includes(filter.toLowerCase()));
  const eligible = candidates.filter((c) => c.eligible);
  const fresh = eligible.filter((c) => !c.warning);
  const allOn = eligible.length > 0 && eligible.every((c) => selected.has(c.id));
  const issuedIds = result?.issued.filter((r) => r.status === 'issued').map((r) => r.id) ?? [];

  const footer =
    step === 'setup' ? (
      <>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button icon={Search} loading={loading} disabled={!sectionId && !programId} onClick={load}>Load students</Button>
      </>
    ) : step === 'students' ? (
      <>
        <Button variant="secondary" icon={ArrowLeft} onClick={() => setStep('setup')}>Back</Button>
        <Button icon={Files} loading={saving} disabled={selected.size === 0} onClick={issue}>
          Issue {selected.size} certificate{selected.size === 1 ? '' : 's'}
        </Button>
      </>
    ) : (
      <>
        <Button variant="secondary" onClick={onClose}>Close</Button>
        {issuedIds.length > 0 && (
          <Button icon={Printer} onClick={() => window.open(printUrl('certificate.php', { ids: issuedIds.join(',') }), '_blank', 'noopener')}>
            Print all ({issuedIds.length})
          </Button>
        )}
      </>
    );

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      static={saving}
      title="Bulk issue certificates"
      description="Generate the same certificate for every eligible student of a section — each gets its own number and QR code."
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"><Users className="h-5 w-5" /></span>}
      footer={footer}
    >
      <div className="mb-6"><Stepper steps={STEPS} current={step} /></div>
      {!config ? (
        <div className="space-y-3">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-11 w-full" />)}</div>
      ) : step === 'setup' ? (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Program" htmlFor="bulk-program" error={errors.program_id}>
              <Select id="bulk-program" value={programId} onChange={(e) => { setProgramId(e.target.value); setSectionId(null); }} options={(programs.data ?? []).map((p) => ({ value: String(p.value), label: p.label }))} placeholder="Select program…" />
            </Field>
            <Field label="Semester" htmlFor="bulk-sem">
              <Select id="bulk-sem" value={semester} onChange={(e) => { setSemester(e.target.value); setSectionId(null); }} options={semesters} placeholder="All semesters" disabled={!programId} />
            </Field>
            <Field label="Section" htmlFor="bulk-section" error={errors.section_id} hint={program ? undefined : 'Pick a program first, or search all sections.'}>
              <Combobox id="bulk-section" value={sectionId} onChange={(v) => setSectionId(v)} source="sections" params={{ program_id: programId || undefined, semester_no: semester || undefined }} placeholder="Any section" />
            </Field>
          </div>
          <div>
            <p className="form-label">Certificate type</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {config.types.filter((t) => t.key !== 'custom').map((t) => (
                <button key={t.key} type="button" onClick={() => setType(t.key)} aria-pressed={type === t.key}
                  className={clsx('flex items-center gap-2 rounded-xl border px-2.5 py-2 text-left text-[13px] font-medium transition',
                    type === t.key ? 'border-brand-600 bg-brand-50 text-brand-900 ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/15 dark:text-white' : 'border-slate-200 text-slate-700 hover:border-slate-300 dark:border-slate-700 dark:text-slate-200')}>
                  <TypeIcon type={t.key} size="sm" />{t.short}
                </button>
              ))}
            </div>
            {errors.type && <p className="form-error">{errors.type}</p>}
            {typeDef?.once && <p className="form-hint">{typeDef.short} is issued once per student — students who already have one are skipped.</p>}
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Template" htmlFor="bulk-template" error={errors.template_id} className="sm:col-span-2">
              <Select id="bulk-template" value={templateId} onChange={(e) => setTemplateId(e.target.value)} options={templates.map((t) => ({ value: String(t.id), label: t.name }))}
                placeholder={`Default — ${templates.find((t) => t.type === type && t.is_default)?.name ?? 'none'}`} />
            </Field>
            <Field label="Issue date" required htmlFor="bulk-date" error={errors.issue_date}>
              <Input id="bulk-date" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} invalid={!!errors.issue_date} />
            </Field>
          </div>
          {typeDef && typeDef.fields.length > 0 && (
            <fieldset className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
              <legend className="px-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Common {typeDef.short} details (same for every student)</legend>
              <div className="grid gap-4 sm:grid-cols-2">
                {typeDef.fields.map((k) => config.fields[k]).filter(Boolean).map((f) => (
                  <Field key={f.key} label={f.label} required={typeDef.required.includes(f.key)} error={errors[`fields.${f.key}`]} htmlFor={`bf-${f.key}`}>
                    {f.type === 'select' ? (
                      <Select id={`bf-${f.key}`} value={fields[f.key] ?? ''} onChange={(e) => setFields((s) => ({ ...s, [f.key]: e.target.value }))} options={(f.options ?? []).map((o) => ({ value: o, label: o }))} placeholder="Select…" />
                    ) : (
                      <Input id={`bf-${f.key}`} type={f.type === 'date' ? 'date' : 'text'} maxLength={f.maxlength} placeholder={f.placeholder} list={f.suggestions ? `bfl-${f.key}` : undefined}
                        value={fields[f.key] ?? ''} onChange={(e) => setFields((s) => ({ ...s, [f.key]: e.target.value }))} invalid={!!errors[`fields.${f.key}`]} />
                    )}
                    {f.suggestions && <datalist id={`bfl-${f.key}`}>{f.suggestions.map((s) => <option key={s} value={s} />)}</datalist>}
                  </Field>
                ))}
              </div>
            </fieldset>
          )}
        </div>
      ) : step === 'students' ? (
        <div>
          <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <Checkbox checked={allOn} onChange={() => setSelected(allOn ? new Set() : new Set(eligible.map((c) => c.id)))} label={`Select all eligible (${eligible.length})`}
              description={`${selected.size} of ${candidates.length} students selected${eligible.length > fresh.length ? ` · ${eligible.length - fresh.length} already hold one (not pre-selected)` : ''}`} />
            <Input inputSize="sm" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Filter by name, ID or roll no." className="sm:!w-64" aria-label="Filter students" />
          </div>
          {errors.student_ids && <Alert variant="error" className="mb-3">{errors.student_ids}</Alert>}
          {candidates.length === 0 ? (
            <EmptyState icon={Users} title="No students found" description="No active students match this section. Choose another section or program." />
          ) : (
            <ul className="max-h-[50vh] divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
              {visible.map((s) => (
                <li key={s.id} className={clsx('flex items-center gap-3 px-3 py-2.5', !s.eligible && 'opacity-60')}>
                  <input type="checkbox" className="form-checkbox" disabled={!s.eligible} checked={selected.has(s.id)} aria-label={`Select ${s.name}`}
                    onChange={() => setSelected((prev) => { const n = new Set(prev); if (n.has(s.id)) n.delete(s.id); else n.add(s.id); return n; })} />
                  <Avatar name={s.name} src={s.photo} size="sm" />
                  <div className="min-w-0 flex-1 leading-tight">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
                    <p className="truncate text-xs text-slate-500">{s.student_uid}{s.roll_no ? ` · Roll ${s.roll_no}` : ''} · {s.program_short} Sem {s.current_semester}{s.section_name ? ` · Sec ${s.section_name}` : ''}</p>
                  </div>
                  {s.reason ? <Badge color="red">{s.reason}</Badge> : s.warning ? <Badge color="amber">{s.warning}</Badge> : <Badge color="green">Eligible</Badge>}
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : result ? (
        <div>
          <div className="flex flex-col items-center py-4 text-center">
            <span className="inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 ring-8 ring-emerald-50 dark:bg-emerald-500/15 dark:ring-emerald-500/5"><CheckCircle2 className="h-7 w-7" /></span>
            <h3 className="mt-4 font-display text-lg font-bold text-slate-900 dark:text-white">
              {result.issued.length} certificate{result.issued.length === 1 ? '' : 's'} {result.issued.some((r) => r.status === 'pending') ? 'submitted for approval' : 'issued'}
            </h3>
            <p className="text-sm text-slate-500">{result.skipped.length ? `${result.skipped.length} student${result.skipped.length === 1 ? ' was' : 's were'} skipped.` : 'No students were skipped.'}</p>
          </div>
          {result.skipped.length > 0 && (
            <ul className="mt-2 divide-y divide-slate-100 rounded-xl border border-amber-200 dark:divide-slate-800 dark:border-amber-500/30">
              {result.skipped.map((s) => (
                <li key={s.student_id} className="flex flex-col gap-0.5 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                  <span className="font-medium text-slate-800 dark:text-slate-100">{s.name}</span>
                  <span className="text-xs text-amber-700 dark:text-amber-300">{s.reason}</span>
                </li>
              ))}
            </ul>
          )}
          {result.issued.length > 0 && (
            <div className="mt-4 flex flex-wrap gap-1.5">
              {result.issued.slice(0, 40).map((r) => <span key={r.id} className="rounded-md bg-slate-100 px-2 py-1 font-mono text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{r.certificate_no}</span>)}
              {result.issued.length > 40 && <span className="text-xs text-slate-500">+{result.issued.length - 40} more</span>}
            </div>
          )}
        </div>
      ) : null}
    </Modal>
  );
}
