import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Eye, FilePlus2, Info, Printer, RefreshCw, Send, Sparkles, UserRound } from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Combobox, Field, Input, Modal, Select, Skeleton, StatusBadge, Textarea, Toggle, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { printUrl } from '@/lib/config';
import { formatDate, isoDate } from '@/lib/format';
import type { Option } from '@/lib/types';
import { CertStatusBadge, CertificateFrame, TypeIcon, useCertConfig } from './shared';
import type { CertConfig, CertFieldDef, PreviewResult, StudentCard } from './types';

export interface IssuedResult {
  id: number;
  certificate_no: string;
  status: string;
  message: string;
}

interface Props {
  open: boolean;
  onClose: () => void;
  studentId?: number | null;
  type?: string | null;
  /** Preselected template (must belong to the preselected type or be a custom template) */
  templateId?: number | null;
  onIssued?: (r: IssuedResult) => void;
  onView?: (id: number) => void;
}

/** Issue certificate dialog: student + type + template + extra fields with a live, exact A4 preview. */
export function IssueCertificateModal({ open, onClose, studentId: initialStudent, type: initialType, templateId: initialTemplate, onIssued, onView }: Props) {
  const { data: config, error: configError } = useCertConfig();
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="2xl"
      title="Issue certificate"
      description="Placeholders are filled from the student's record and results. Check the preview, then issue — a unique number and QR verification are assigned automatically."
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><FilePlus2 className="h-5 w-5" /></span>}
    >
      {configError ? (
        <Alert variant="error" title="Unable to load certificate settings">{(configError as ApiError).message}</Alert>
      ) : !config ? (
        <div className="grid gap-6 lg:grid-cols-2">
          <div className="space-y-4">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
          <Skeleton className="h-96 w-full" />
        </div>
      ) : open ? (
        <IssueForm config={config} initialStudent={initialStudent ?? null} initialType={initialType ?? null} initialTemplate={initialTemplate ?? null} onClose={onClose} onIssued={onIssued} onView={onView} />
      ) : null}
    </Modal>
  );
}

function defaultFields(config: CertConfig, type: string): Record<string, string> {
  const def = config.types.find((t) => t.key === type);
  const out: Record<string, string> = {};
  def?.fields.forEach((k) => {
    out[k] = config.fields[k]?.default ?? '';
  });
  return out;
}

function IssueForm({ config, initialStudent, initialType, initialTemplate, onClose, onIssued, onView }: { config: CertConfig; initialStudent: number | null; initialType: string | null; initialTemplate: number | null; onClose: () => void; onIssued?: (r: IssuedResult) => void; onView?: (id: number) => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const validTypes = config.types.filter((t) => t.key !== 'custom' || config.templates.some((tp) => tp.type === 'custom'));
  const [studentId, setStudentId] = useState<number | null>(initialStudent);
  const [type, setType] = useState<string>(initialType && config.types.some((t) => t.key === initialType) ? initialType : '');
  const [templateId, setTemplateId] = useState<string>(() => {
    const t = initialTemplate ? config.templates.find((x) => x.id === initialTemplate) : null;
    return t && (t.type === type || t.type === 'custom') ? String(t.id) : '';
  });
  const [issueDate, setIssueDate] = useState(isoDate());
  const [validUntil, setValidUntil] = useState('');
  const [fields, setFields] = useState<Record<string, string>>(() => (type ? defaultFields(config, type) : {}));
  const [remarks, setRemarks] = useState('');
  const [issueNow, setIssueNow] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<IssuedResult | null>(null);

  const typeDef = config.types.find((t) => t.key === type) ?? null;
  const templates = useMemo(() => config.templates.filter((t) => t.type === type || t.type === 'custom'), [config.templates, type]);
  const effectiveTemplate = templateId ? templates.find((t) => String(t.id) === templateId) : templates.find((t) => t.type === type && t.is_default) ?? templates.find((t) => t.type === type);

  const chooseType = (t: string) => {
    setType(t);
    setTemplateId('');
    setValidUntil('');
    setFields((prev) => ({ ...defaultFields(config, t), ...Object.fromEntries(Object.entries(prev).filter(([k, v]) => v && config.types.find((x) => x.key === t)?.fields.includes(k))) }));
    setErrors((e) => {
      const n = { ...e };
      delete n.type;
      delete n.template_id;
      Object.keys(n).filter((k) => k.startsWith('fields.')).forEach((k) => delete n[k]);
      return n;
    });
  };

  // Student card
  const student = useApi<StudentCard>(['cert-student', studentId], `certificates/students/${studentId}`, undefined, { enabled: !!studentId });
  const initialOptions = useMemo<Option[]>(
    () => (student.data ? [{ value: student.data.id, label: `${student.data.name} (${student.data.student_uid})`, sub: `${student.data.program_short} · Sem ${student.data.semester}` }] : []),
    [student.data],
  );

  // Live preview (debounced)
  const payload = { student_id: studentId, type, template_id: templateId || null, issue_date: issueDate, valid_until: validUntil || null, fields };
  const debounced = useDebounce(JSON.stringify(payload), 450);
  const ready = useMemo(() => {
    const d = JSON.parse(debounced) as typeof payload;
    return !!d.student_id && !!d.type;
  }, [debounced]);
  const preview = useQuery<PreviewResult>({
    queryKey: ['cert-issue-preview', debounced],
    queryFn: ({ signal }) => api.post<PreviewResult>('certificates/preview', JSON.parse(debounced), { signal }).then((r) => r.data),
    enabled: ready && !!studentId && !!type && !result,
    placeholderData: keepPreviousData,
    retry: false,
    staleTime: 30_000,
  });
  const pv = preview.data;
  const previewError = preview.error as ApiError | null;

  const setField = (k: string, v: string) => {
    setFields((f) => ({ ...f, [k]: v }));
    setErrors((e) => {
      if (!e[`fields.${k}`]) return e;
      const n = { ...e };
      delete n[`fields.${k}`];
      return n;
    });
  };

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post<{ id: number; certificate_no: string; status: string }>('certificates/issue', { ...payload, remarks: remarks || null, issue_now: issueNow });
      const r = { ...res.data, message: res.message };
      setResult(r);
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['crud', 'certificates'] });
      void qc.invalidateQueries({ queryKey: ['cert-summary'] });
      void qc.invalidateQueries({ queryKey: ['cert-student'] });
      onIssued?.(r);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
      window.setTimeout(() => document.querySelector('[role="dialog"] [aria-invalid="true"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }), 50);
    } finally {
      setSaving(false);
    }
  };

  const reset = (keepStudent: boolean) => {
    setResult(null);
    if (!keepStudent) setStudentId(null);
    setType('');
    setTemplateId('');
    setFields({});
    setValidUntil('');
    setRemarks('');
    setErrors({});
    setIssueDate(isoDate());
  };

  if (result) {
    const pending = result.status === 'pending';
    return (
      <div className="flex flex-col items-center px-4 py-10 text-center">
        <span className={clsx('inline-flex h-16 w-16 items-center justify-center rounded-full ring-8', pending ? 'bg-amber-100 text-amber-600 ring-amber-50 dark:bg-amber-500/15 dark:ring-amber-500/5' : 'bg-emerald-100 text-emerald-600 ring-emerald-50 dark:bg-emerald-500/15 dark:ring-emerald-500/5')}>
          {pending ? <Send className="h-7 w-7" /> : <CheckCircle2 className="h-8 w-8" />}
        </span>
        <h3 className="mt-5 font-display text-xl font-bold text-slate-900 dark:text-white">{pending ? 'Submitted for approval' : 'Certificate issued'}</h3>
        <p className="mt-1 font-mono text-sm font-semibold text-brand-800 dark:text-brand-200">{result.certificate_no}</p>
        <p className="mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
          {pending
            ? 'An approver has been notified. The certificate can be printed and verified once it is approved.'
            : 'The QR code on the printed certificate links to the public verification page. The student and the certificates team have been notified.'}
        </p>
        <div className="mt-6 flex flex-wrap justify-center gap-2">
          {!pending && (
            <Button icon={Printer} onClick={() => window.open(printUrl('certificate.php', { id: result.id }), '_blank', 'noopener')}>
              Print certificate
            </Button>
          )}
          {onView && (
            <Button variant="secondary" icon={Eye} onClick={() => { onClose(); onView(result.id); }}>
              View details
            </Button>
          )}
          <Button variant="secondary" icon={RefreshCw} onClick={() => reset(true)}>
            Another for this student
          </Button>
          <Button variant="ghost" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    );
  }

  const fieldDefs: CertFieldDef[] = (typeDef?.fields ?? []).map((k) => config.fields[k]).filter(Boolean);
  const approval = config.settings.approval_required;
  const generalError = errors._placeholders;

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.95fr)]">
      {/* ------------------------------------------------ Form */}
      <div className="min-w-0 space-y-5">
        <Field label="Student" required error={errors.student_id} htmlFor="cert-student">
          <Combobox
            id="cert-student"
            value={studentId}
            onChange={(v) => { setStudentId(v ? Number(v) : null); setErrors((e) => ({ ...e, student_id: '' })); }}
            optionsUrl="certificates/students"
            initialOptions={initialOptions}
            placeholder="Search name, student ID, roll or mobile…"
            invalid={!!errors.student_id}
          />
        </Field>
        {studentId && (
          <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-700 dark:bg-slate-800/40">
            {student.isLoading || !student.data ? (
              <div className="flex items-center gap-3"><Skeleton className="h-10 w-10 rounded-full" /><Skeleton className="h-4 w-48" /></div>
            ) : (
              <>
                <div className="flex items-center gap-3">
                  <Avatar name={student.data.name} src={student.data.photo} size="lg" />
                  <div className="min-w-0 flex-1 leading-tight">
                    <p className="truncate font-semibold text-slate-900 dark:text-white">{student.data.name}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {student.data.student_uid} · {student.data.program_short} · Sem {student.data.semester}/{student.data.total_semesters}
                      {student.data.section ? ` · Sec ${student.data.section}` : ''}
                    </p>
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    <StatusBadge status={student.data.status} />
                    {student.data.cgpa && <span className="text-[11px] font-medium text-slate-500">CGPA {student.data.cgpa}</span>}
                  </div>
                </div>
                {student.data.certificates.length > 0 && (
                  <div className="mt-3 flex flex-wrap gap-1.5 border-t border-slate-200 pt-2.5 dark:border-slate-700">
                    <span className="mr-1 self-center text-[11px] font-medium uppercase tracking-wide text-slate-400">Existing</span>
                    {student.data.certificates.slice(0, 6).map((c) => (
                      <span key={c.id} title={`${c.certificate_no} · ${formatDate(c.issue_date)}`} className="inline-flex items-center gap-1.5 rounded-lg bg-white px-2 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                        <TypeIcon type={c.type} size="sm" className="!h-4 !w-4 !rounded" />
                        {c.type_label.replace(' Certificate', '')}
                        <CertStatusBadge status={c.effective_status} />
                      </span>
                    ))}
                  </div>
                )}
              </>
            )}
          </div>
        )}

        <div>
          <p className="form-label">Certificate type<span className="ml-0.5 text-red-500" aria-hidden>*</span></p>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4" role="radiogroup" aria-label="Certificate type">
            {validTypes.map((t) => {
              const active = t.key === type;
              return (
                <button
                  key={t.key}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  onClick={() => chooseType(t.key)}
                  title={t.description}
                  className={clsx(
                    'flex items-center gap-2.5 rounded-xl border px-2.5 py-2 text-left text-[13px] font-medium transition hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                    active
                      ? 'border-brand-600 bg-brand-50 text-brand-900 shadow-sm ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/15 dark:text-white dark:ring-brand-400'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
                  )}
                >
                  <TypeIcon type={t.key} size="sm" />
                  <span className="leading-tight">{t.short}</span>
                </button>
              );
            })}
          </div>
          {errors.type ? <p className="form-error" role="alert">{errors.type}</p> : typeDef ? <p className="form-hint">{typeDef.description}</p> : null}
        </div>

        {typeDef && (
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Template" error={errors.template_id} htmlFor="cert-template" className="sm:col-span-3">
              <Select
                id="cert-template"
                value={templateId}
                onChange={(e) => setTemplateId(e.target.value)}
                options={templates.map((t) => ({ value: String(t.id), label: `${t.name}${t.type === 'custom' ? ' (custom)' : ''}${t.orientation === 'landscape' ? ' · landscape' : ''}` }))}
                placeholder={effectiveTemplate ? `Default — ${effectiveTemplate.name}` : 'No active template for this type'}
                invalid={!!errors.template_id}
              />
            </Field>
            <Field label="Issue date" required error={errors.issue_date} htmlFor="cert-issue-date">
              <Input id="cert-issue-date" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} invalid={!!errors.issue_date} />
            </Field>
            <Field label="Valid until" error={errors.valid_until} htmlFor="cert-valid" className="sm:col-span-2"
              hint={validUntil ? 'Overrides the template validity.' : pv?.valid_until ? `Automatic: ${formatDate(pv.valid_until)} (template validity)` : 'No expiry unless you set a date.'}>
              <Input id="cert-valid" type="date" value={validUntil} min={issueDate} onChange={(e) => setValidUntil(e.target.value)} invalid={!!errors.valid_until} />
            </Field>
          </div>
        )}

        {typeDef && fieldDefs.length > 0 && (
          <fieldset className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
            <legend className="px-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{typeDef.short} details</legend>
            <div className="grid gap-4 sm:grid-cols-2">
              {fieldDefs.map((f) => {
                const err = errors[`fields.${f.key}`] ?? (pv?.field_errors as Record<string, string> | undefined)?.[`fields.${f.key}`];
                const wide = f.type === 'text' && (f.maxlength ?? 0) > 120;
                return (
                  <Field key={f.key} label={f.label} required={typeDef.required.includes(f.key)} error={errors[`fields.${f.key}`] ? errors[`fields.${f.key}`] : undefined}
                    hint={!errors[`fields.${f.key}`] && err ? <span className="text-amber-600 dark:text-amber-400">{err}</span> : f.help} htmlFor={`cf-${f.key}`} className={clsx(wide && 'sm:col-span-2')}>
                    {f.type === 'select' ? (
                      <Select id={`cf-${f.key}`} value={fields[f.key] ?? ''} onChange={(e) => setField(f.key, e.target.value)} options={(f.options ?? []).map((o) => ({ value: o, label: o }))}
                        placeholder={typeDef.required.includes(f.key) ? 'Select…' : f.key === 'division' ? 'Derive from CGPA' : 'Select…'} invalid={!!errors[`fields.${f.key}`]} />
                    ) : f.type === 'date' ? (
                      <Input id={`cf-${f.key}`} type="date" value={fields[f.key] ?? ''} onChange={(e) => setField(f.key, e.target.value)} invalid={!!errors[`fields.${f.key}`]} />
                    ) : (
                      <>
                        <Input id={`cf-${f.key}`} value={fields[f.key] ?? ''} maxLength={f.maxlength} placeholder={f.placeholder} list={f.suggestions ? `cfl-${f.key}` : undefined}
                          onChange={(e) => setField(f.key, e.target.value)} invalid={!!errors[`fields.${f.key}`]} />
                        {f.suggestions && (
                          <datalist id={`cfl-${f.key}`}>
                            {f.suggestions.map((s) => <option key={s} value={s} />)}
                          </datalist>
                        )}
                      </>
                    )}
                  </Field>
                );
              })}
            </div>
            {fieldDefs.some((f) => f.suggestions) && (
              <div className="mt-3 flex flex-wrap gap-1.5">
                {fieldDefs.filter((f) => f.suggestions).flatMap((f) => (f.suggestions ?? []).slice(0, 5).map((s) => (
                  <button key={`${f.key}-${s}`} type="button" onClick={() => setField(f.key, s)}
                    className={clsx('rounded-full px-2.5 py-1 text-[11px] font-medium ring-1 transition', fields[f.key] === s ? 'bg-brand-800 text-white ring-brand-800' : 'bg-white text-slate-600 ring-slate-200 hover:ring-brand-400 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700')}>
                    {s}
                  </button>
                )))}
              </div>
            )}
          </fieldset>
        )}

        {typeDef && (
          <Field label="Internal remarks" error={errors.remarks} htmlFor="cert-remarks" aside={`${remarks.length}/255`} hint="Visible to staff only.">
            <Textarea id="cert-remarks" rows={2} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="e.g. Requested at the front office, fee receipt RCPT-2026-00412" />
          </Field>
        )}

        {typeDef && approval && (
          config.can_approve ? (
            <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-3.5 dark:border-amber-500/30 dark:bg-amber-500/10">
              <Toggle checked={issueNow} onChange={setIssueNow} label="Approve and issue immediately" description={issueNow ? 'You can approve certificates, so this one is issued straight away.' : 'Keep it pending so another approver reviews it.'} />
            </div>
          ) : (
            <Alert variant="info" title="Approval required">This certificate will be sent to an approver. It can be printed once approved.</Alert>
          )
        )}
        {generalError && <Alert variant="error">{generalError}</Alert>}
      </div>

      {/* ------------------------------------------------ Preview */}
      <div className="min-w-0 lg:sticky lg:top-0 lg:self-start">
        <div className="mb-2 flex items-center justify-between gap-2">
          <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"><Sparkles className="h-3.5 w-3.5" /> Live preview</p>
          {pv && <Badge color="slate">{pv.template.name}</Badge>}
        </div>
        {!studentId || !type ? (
          <div className="mx-auto flex aspect-[210/297] w-full max-w-[452px] flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-200 p-6 text-center dark:border-slate-700">
            <UserRound className="h-9 w-9 text-slate-300 dark:text-slate-600" />
            <p className="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Choose a student and a certificate type</p>
            <p className="mt-1 max-w-xs text-xs text-slate-500">The certificate is rendered exactly as it will print, with every placeholder filled from the student's record.</p>
          </div>
        ) : previewError && !pv ? (
          <Alert variant="error" title="Preview unavailable">{previewError.message}</Alert>
        ) : (
          <CertificateFrame html={pv?.html} orientation={pv?.orientation ?? effectiveTemplate?.orientation ?? 'portrait'} loading={preview.isFetching} title="Certificate preview" maxHeight={640} />
        )}
        {pv && (pv.warnings.length > 0 || pv.missing.length > 0) && (
          <div className="mt-3 space-y-2">
            {pv.missing.length > 0 && (
              <Alert variant="warning" title="Details missing">
                {pv.missing.map((m) => m.label).join(', ')} {pv.missing.length === 1 ? 'is' : 'are'} highlighted in the preview. Fill {pv.missing.length === 1 ? 'it' : 'them'} in before issuing.
              </Alert>
            )}
            {pv.warnings.map((w) => <Alert key={w} variant="info">{w}</Alert>)}
          </div>
        )}
        <p className="mt-3 flex items-start gap-1.5 text-[11px] text-slate-500 dark:text-slate-400"><Info className="mt-px h-3.5 w-3.5 shrink-0" />The number shown as ••••• is assigned when the certificate is issued.</p>
      </div>

      {/* ------------------------------------------------ Actions */}
      <div className="sticky -bottom-5 z-10 -mx-5 -mb-5 flex flex-col-reverse gap-2 border-t border-slate-100 bg-white/95 px-5 py-3.5 backdrop-blur sm:flex-row sm:items-center sm:justify-end lg:col-span-2 dark:border-slate-800 dark:bg-slate-900/95">
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button icon={approval && !(config.can_approve && issueNow) ? Send : CheckCircle2} loading={saving} disabled={!studentId || !type} onClick={submit}>
          {approval && !(config.can_approve && issueNow) ? 'Submit for approval' : `Issue ${typeDef ? typeDef.label : 'certificate'}`}
        </Button>
      </div>
    </div>
  );
}
