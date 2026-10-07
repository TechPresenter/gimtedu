import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, BookOpen, CheckCircle2, ClipboardSignature, FileText, GraduationCap, Hash, Megaphone, MessageCircle, Phone, Save, ShieldCheck, Upload, User, Users, type LucideIcon,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, Checkbox, FileUpload, PageHeader, PageLoader, Skeleton, StatusBadge, useToast,
} from '@/components/ui';
import { CrudFormFields, useCrudForm, type CrudField } from '@/components/crud';
import { api, ApiError, toFormData } from '@/lib/api';
import { useApi, useCrudMeta, useInvalidate } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatMoney, humanFileSize } from '@/lib/format';
import { prefersReducedMotion } from '@/components/ui';
import { StageBadge } from './shared';

interface Prefill {
  enquiry: { id: number; name: string; phone: string; program: string | null; source: string };
  values: Record<string, unknown>;
}
interface FormMeta {
  next_application_no: string;
  default_fee: number;
  doc_types: { value: string; label: string; required: boolean }[];
  max_upload: number;
  accept: string;
}
interface Duplicate {
  type: 'application' | 'enquiry' | 'student';
  id: number;
  name: string;
  ref: string;
  detail: string;
  status: string;
  url: string;
  match: 'phone' | 'email';
}
interface ExistingDoc {
  id: number;
  doc_type: string;
  label: string;
  status: string;
  original_name: string | null;
  size_bytes: number | null;
  remarks: string | null;
}
interface ProfileLite {
  admission: { id: number; application_no: string; stage: string; full_name: string; student_id: number | null; session_name: string | null };
  documents: ExistingDoc[];
}

const SECTION_ICONS: Record<string, LucideIcon> = {
  sec_personal: User, sec_contact: Phone, sec_parents: Users, sec_education: BookOpen, sec_program: GraduationCap, sec_source: Megaphone, sec_documents: FileText, sec_declaration: ClipboardSignature,
};

interface Group {
  key: string;
  label: string;
  help?: string;
  fields: CrudField[];
}

const DECLARATION =
  'I declare that the information given in this application is true and complete to the best of my knowledge. I understand that the admission is provisional until all documents are verified and the admission fee is paid, and that any false information may lead to cancellation of the admission.';

export default function AdmissionFormPage() {
  const { id: idParam } = useParams();
  const id = idParam ? Number(idParam) : null;
  const [params] = useSearchParams();
  const enquiryId = !id ? Number(params.get('enquiry')) || null : null;
  const navigate = useNavigate();
  const toast = useToast();
  const invalidate = useInvalidate();
  const { data: meta, error: metaError } = useCrudMeta('admissions');
  const { data: prefill, error: prefillError } = useApi<Prefill>(['enq-prefill', enquiryId], `enquiries/${enquiryId}/prefill`, undefined, { enabled: !!enquiryId, retry: false });
  const { data: existing } = useApi<ProfileLite>(['adm-profile', id], `admissions/${id}/profile`, undefined, { enabled: !!id });
  const waitingPrefill = !!enquiryId && !prefill && !prefillError;
  const defaults = useMemo(() => (prefill?.values ? { ...prefill.values } : undefined), [prefill]);
  const state = useCrudForm({ meta: waitingPrefill ? undefined : meta, module: 'admissions', id, defaults });
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [docFiles, setDocFiles] = useState<Record<string, File | null>>({});
  const [active, setActive] = useState('sec_personal');

  const programId = state.values.program_id ? String(state.values.program_id) : '';
  const sessionId = state.values.academic_session_id ? String(state.values.academic_session_id) : '';
  const { data: formMeta } = useApi<FormMeta>(['adm-form-meta', programId, sessionId], 'admissions/form-meta', { program_id: programId || undefined, session_id: sessionId || undefined });

  // Duplicate detection by phone / email
  const phone = String(state.values.phone ?? '');
  const email = String(state.values.email ?? '');
  const dPhone = useDebounce(phone.replace(/\D/g, '').length >= 10 ? phone : '', 500);
  const dEmail = useDebounce(/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) ? email : '', 500);
  const { data: duplicates = [] } = useApi<Duplicate[]>(['adm-dup', dPhone, dEmail, id], 'admissions/duplicates', { phone: dPhone || undefined, email: dEmail || undefined, exclude: id ?? undefined, exclude_enquiry: enquiryId ?? undefined }, { enabled: !!(dPhone || dEmail) });

  const groups = useMemo<Group[]>(() => {
    const out: Group[] = [];
    state.visibleFields.forEach((f) => {
      if (f.type === 'section') {
        out.push({ key: f.name || `sec${out.length}`, label: f.label, help: f.help, fields: [] });
        return;
      }
      if (f.name === 'declaration_accepted') return;
      if (!out.length) out.push({ key: 'sec_general', label: 'Details', fields: [] });
      out[out.length - 1].fields.push(f);
    });
    return out;
  }, [state.visibleFields]);

  const navItems = useMemo(() => {
    const items = groups.filter((g) => g.key !== 'sec_declaration').map((g) => ({ key: g.key, label: g.label, fields: g.fields }));
    items.push({ key: 'sec_documents', label: 'Documents', fields: [] });
    items.push({ key: 'sec_declaration', label: 'Declaration', fields: [] });
    return items;
  }, [groups]);

  const sectionStatus = (key: string, fields: CrudField[]) => {
    if (key === 'sec_documents') {
      const req = (formMeta?.doc_types ?? []).filter((d) => d.required);
      const have = req.filter((d) => docFiles[d.value] || existing?.documents.some((x) => x.doc_type === d.value)).length;
      return { done: req.length > 0 && have === req.length, errors: 0, label: `${have}/${req.length}` };
    }
    if (key === 'sec_declaration') return { done: !!state.values.declaration_accepted || !!id, errors: state.errors.declaration_accepted ? 1 : 0, label: '' };
    const req = fields.filter((f) => f.required);
    const filled = req.filter((f) => state.values[f.name] !== '' && state.values[f.name] !== null && state.values[f.name] !== undefined).length;
    const errors = fields.filter((f) => state.errors[f.name]).length;
    return { done: filled === req.length, errors, label: req.length ? `${filled}/${req.length}` : '' };
  };

  // Scroll-spy for the section navigation
  const observer = useRef<IntersectionObserver | null>(null);
  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return;
    observer.current = new IntersectionObserver(
      (entries) => {
        const vis = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (vis[0]) setActive(vis[0].target.id);
      },
      { rootMargin: '-90px 0px -60% 0px' },
    );
    navItems.forEach((n) => {
      const el = document.getElementById(n.key);
      if (el) observer.current?.observe(el);
    });
    return () => observer.current?.disconnect();
  }, [navItems, state.loading]);

  const jump = (key: string) => {
    document.getElementById(key)?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
  };
  const focusFirstError = (errors: Record<string, string>) => {
    const order = [...state.visibleFields.map((f) => f.name), 'declaration_accepted'];
    const first = order.find((n) => errors[n]);
    if (!first) return;
    const el = document.getElementById(first === 'declaration_accepted' ? 'declaration-check' : `f-${first}`);
    el?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
    window.setTimeout(() => (el as HTMLElement | null)?.focus?.(), 350);
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!meta) return;
    const clientErrors: Record<string, string> = {};
    state.visibleFields.forEach((f) => {
      if (!f.required || ['section', 'image', 'file'].includes(f.type)) return;
      const v = state.values[f.name];
      if (v === '' || v === null || v === undefined) clientErrors[f.name] = `${f.label} is required.`;
    });
    if (!id && !state.values.declaration_accepted) clientErrors.declaration_accepted = 'The applicant must accept the declaration.';
    if (Object.keys(clientErrors).length) {
      state.setErrors(clientErrors);
      setFormError('Please fill in the highlighted fields.');
      focusFirstError(clientErrors);
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const payload: Record<string, unknown> = { __form: 1 };
      state.visibleFields.forEach((f) => {
        if (['section', 'image', 'file'].includes(f.type)) return;
        if (f.readonly_on_edit && id) return;
        payload[f.name] = state.values[f.name];
      });
      if (enquiryId) payload.enquiry_id = enquiryId;
      Object.entries(state.files).forEach(([k, fl]) => fl && (payload[k] = fl));
      Object.entries(state.removed).forEach(([k, r]) => r && (payload[`${k}__remove`] = 1));
      const hasFiles = Object.values(state.files).some(Boolean);
      const res = await api.post<{ id: number; row: Record<string, unknown> | null }>(id ? `crud/admissions/${id}` : 'crud/admissions', hasFiles ? toFormData(payload) : payload);
      const newId = res.data.id;
      const failed: string[] = [];
      for (const [type, file] of Object.entries(docFiles)) {
        if (!file) continue;
        try {
          await api.post(`admissions/${newId}/documents`, toFormData({ doc_type: type, file }));
        } catch (err) {
          failed.push(`${formMeta?.doc_types.find((d) => d.value === type)?.label ?? type}: ${(err as ApiError).message}`);
        }
      }
      toast.success(id ? 'Application updated successfully.' : `Application ${String(res.data.row?.application_no ?? '')} created successfully.`);
      if (failed.length) toast.warning(failed.join(' '), 'Some documents were not uploaded');
      await invalidate('crud', 'adm-profile', 'adm-board', 'adm-dashboard', 'enq-summary');
      navigate(`/admissions/${newId}${failed.length ? '?tab=documents' : ''}`);
    } catch (err) {
      const e2 = err as ApiError;
      state.setErrors(e2.errors ?? {});
      setFormError(e2.message || 'Unable to save the application. Please try again.');
      if (Object.keys(e2.errors ?? {}).length) focusFirstError(e2.errors);
      else toast.error(e2.message || 'Unable to save the application. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const title = id ? 'Edit application' : 'New application';
  const appNo = id ? existing?.admission.application_no : formMeta?.next_application_no;
  if (metaError) {
    return (
      <>
        <PageHeader title={title} breadcrumbs={[{ label: 'Admissions', to: '/admissions' }, { label: title }]} />
        <Alert variant="error" title="Unable to load the application form">{(metaError as ApiError).message}</Alert>
      </>
    );
  }

  return (
    <form onSubmit={submit} noValidate>
      <PageHeader
        title={title}
        description={id ? (existing ? <span className="inline-flex flex-wrap items-center gap-2"><span className="font-mono">{existing.admission.application_no}</span> · {existing.admission.full_name} <StageBadge stage={existing.admission.stage} /></span> : 'Loading…') : 'Capture the applicant’s details, program choice and documents. Fields marked * are required.'}
        breadcrumbs={[{ label: 'Admissions', to: '/admissions' }, { label: 'Applications', to: '/admissions/list' }, ...(id && existing ? [{ label: existing.admission.full_name, to: `/admissions/${id}` }] : []), { label: id ? 'Edit' : 'New application' }]}
        actions={
          <>
            <Button variant="secondary" onClick={() => (id ? navigate(`/admissions/${id}`) : navigate(-1))} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" variant="success" icon={Save} loading={saving} disabled={state.loading || !meta}>
              {id ? 'Save changes' : 'Submit application'}
            </Button>
          </>
        }
      />

      {prefill && (
        <Alert variant="info" className="mb-5" title={`Converting enquiry #${prefill.enquiry.id} — ${prefill.enquiry.name}`}>
          Details from the enquiry have been filled in{prefill.enquiry.program ? ` (interested in ${prefill.enquiry.program})` : ''}. The enquiry will be marked as converted when the application is submitted.
        </Alert>
      )}
      {prefillError && (
        <Alert variant="warning" className="mb-5" title="Enquiry could not be loaded">
          {(prefillError as ApiError).message}
        </Alert>
      )}
      {existing?.admission.student_id && (
        <Alert variant="info" className="mb-5" title="This applicant is already enrolled">
          Program, specialisation and session are locked because a student record was created from this application.
        </Alert>
      )}

      {state.loading || !meta || waitingPrefill ? (
        <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
          <Skeleton className="hidden h-96 lg:block" />
          <div className="space-y-6">
            <Card className="p-5"><Skeleton className="mb-4 h-5 w-48" /><div className="grid gap-4 sm:grid-cols-3">{Array.from({ length: 9 }).map((_, i) => <Skeleton key={i} className="h-10" />)}</div></Card>
            <PageLoader label="Loading form…" />
          </div>
        </div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
          {/* Section navigation */}
          <aside className="hidden lg:block">
            <div className="sticky top-20 space-y-4">
              <Card className="p-4">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><Hash className="h-3.5 w-3.5" /> Application no</p>
                {appNo ? <p className="mt-1 font-mono text-sm font-bold text-brand-900 dark:text-white">{appNo}</p> : <Skeleton className="mt-1 h-5 w-36" />}
                {!id && <p className="mt-0.5 text-[11px] text-slate-500">Generated automatically on submit</p>}
                {formMeta && programId && (
                  <div className="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <p className="text-[11px] text-slate-500">Admission fee for this program</p>
                    <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{formatMoney(formMeta.default_fee)}</p>
                  </div>
                )}
              </Card>
              <nav aria-label="Form sections" className="card p-2">
                <ol className="space-y-0.5">
                  {navItems.map((n, i) => {
                    const st = sectionStatus(n.key, n.fields);
                    const Icon = SECTION_ICONS[n.key] ?? FileText;
                    return (
                      <li key={n.key}>
                        <button type="button" onClick={() => jump(n.key)} aria-current={active === n.key ? 'step' : undefined}
                          className={clsx('flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] transition', active === n.key ? 'bg-brand-50 font-semibold text-brand-900 dark:bg-brand-500/15 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800')}>
                          <span className={clsx('inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                            st.errors ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : st.done ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                            {st.errors ? '!' : st.done ? <CheckCircle2 className="h-3.5 w-3.5" /> : i + 1}
                          </span>
                          <Icon className="h-3.5 w-3.5 shrink-0 opacity-60" aria-hidden />
                          <span className="min-w-0 flex-1 truncate">{n.label}</span>
                          {st.label && <span className="text-[10.5px] tabular-nums text-slate-400">{st.label}</span>}
                        </button>
                      </li>
                    );
                  })}
                </ol>
              </nav>
            </div>
          </aside>

          <div className="min-w-0 space-y-6">
            {formError && (
              <Alert variant="error" title="The application was not saved">
                {formError}
              </Alert>
            )}
            {groups.filter((g) => g.key !== 'sec_declaration').map((g) => {
              const Icon = SECTION_ICONS[g.key] ?? FileText;
              return (
                <Card key={g.key} id={g.key} className="scroll-mt-24">
                  <CardHeader title={g.label} subtitle={g.help} icon={Icon} />
                  <div className="card-body space-y-4">
                    {g.key === 'sec_contact' && duplicates.length > 0 && <DuplicateAlert items={duplicates} />}
                    <CrudFormFields state={state} fields={g.fields} id={id} />
                  </div>
                </Card>
              );
            })}

            <Card id="sec_documents" className="scroll-mt-24">
              <CardHeader title="Documents" subtitle="Upload clear scans (PDF or image). Documents are stored privately and verified by the admission office." icon={Upload} />
              <div className="card-body">
                {!formMeta ? (
                  <Skeleton className="h-40" />
                ) : (
                  <ul className="grid gap-3 md:grid-cols-2">
                    {formMeta.doc_types.map((d) => {
                      const ex = existing?.documents.filter((x) => x.doc_type === d.value) ?? [];
                      const verified = ex.some((x) => x.status === 'verified');
                      return (
                        <li key={d.value} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                          <div className="mb-2 flex items-center gap-2">
                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-100">{d.label}</span>
                            {d.required && <Badge color="navy">Required</Badge>}
                            {ex[0] && <StatusBadge status={ex[0].status} />}
                          </div>
                          {ex[0] && (
                            <p className="mb-2 truncate text-xs text-slate-500">
                              On file: {ex[0].original_name ?? 'document'}{ex[0].size_bytes ? ` · ${humanFileSize(ex[0].size_bytes)}` : ''}
                              {ex[0].status === 'rejected' && ex[0].remarks ? <span className="text-red-600"> — {ex[0].remarks}</span> : null}
                            </p>
                          )}
                          {verified && d.value !== 'other' ? (
                            <p className="flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-400"><ShieldCheck className="h-3.5 w-3.5" /> Verified — manage it from the applicant profile.</p>
                          ) : (
                            <FileUpload compact file={docFiles[d.value] ?? null} onFile={(f) => setDocFiles((s) => ({ ...s, [d.value]: f }))} accept={formMeta.accept} maxSize={formMeta.max_upload} onError={(m) => toast.error(m)} id={`doc-${d.value}`} />
                          )}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>
            </Card>

            <Card id="sec_declaration" className="scroll-mt-24">
              <CardHeader title="Declaration" icon={ClipboardSignature} />
              <div className="card-body">
                <div className={clsx('rounded-xl border p-4', state.errors.declaration_accepted ? 'border-red-300 bg-red-50/50 dark:border-red-500/40 dark:bg-red-500/5' : 'border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-800/40')}>
                  <Checkbox
                    id="declaration-check"
                    checked={!!state.values.declaration_accepted}
                    onChange={(e) => state.setValue('declaration_accepted', e.target.checked)}
                    label="I accept the declaration"
                    description={DECLARATION}
                    aria-invalid={!!state.errors.declaration_accepted || undefined}
                  />
                </div>
                {state.errors.declaration_accepted && (
                  <p className="form-error" role="alert"><AlertTriangle className="h-3.5 w-3.5" />{state.errors.declaration_accepted}</p>
                )}
              </div>
            </Card>

            <div className="sticky bottom-0 z-10 -mx-4 flex flex-col gap-3 border-t border-slate-200 bg-white/90 px-4 py-3 backdrop-blur sm:mx-0 sm:flex-row sm:items-center sm:justify-between sm:rounded-2xl sm:border dark:border-slate-800 dark:bg-slate-900/90">
              <p className="text-xs text-slate-500">
                {id ? 'Changes are logged on the application timeline.' : 'The applicant receives an acknowledgement email with the application number.'}
              </p>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => (id ? navigate(`/admissions/${id}`) : navigate('/admissions/list'))} disabled={saving}>
                  Cancel
                </Button>
                <Button type="submit" variant="success" icon={Save} loading={saving}>
                  {id ? 'Save changes' : 'Submit application'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </form>
  );
}

function DuplicateAlert({ items }: { items: Duplicate[] }) {
  return (
    <Alert variant="warning" title="Possible duplicate applicant">
      <p>We found records with the same {items.some((i) => i.match === 'phone') ? 'mobile number' : 'email'}. Check before submitting a new application:</p>
      <ul className="mt-2 space-y-1">
        {items.map((d) => (
          <li key={`${d.type}-${d.id}`} className="flex flex-wrap items-center gap-x-2 text-xs">
            {d.type === 'enquiry' ? <MessageCircle className="h-3.5 w-3.5" /> : d.type === 'student' ? <GraduationCap className="h-3.5 w-3.5" /> : <FileText className="h-3.5 w-3.5" />}
            <Link to={d.url} target="_blank" className="font-semibold underline">{d.name}</Link>
            <span className="font-mono">{d.ref}</span>
            <span className="opacity-80">· {d.detail} · matched on {d.match}</span>
          </li>
        ))}
      </ul>
    </Alert>
  );
}
