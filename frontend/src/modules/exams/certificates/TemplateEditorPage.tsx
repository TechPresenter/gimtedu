import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Braces, Eye, Palette, PenLine, Printer, Save, Sparkles, Trash2, UserRound, Wand2 } from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, Combobox, Field, Input, PageHeader, RichText, Select, Skeleton, Toggle, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { printUrl } from '@/lib/config';
import type { Option } from '@/lib/types';
import { CertificateFrame, TypeIcon, useCertConfig } from './shared';
import { TemplateThumb } from './TemplateThumb';
import type { CertConfig, TemplatePreview, TemplateRow } from './types';

interface Form {
  name: string;
  type: string;
  description: string;
  title: string;
  subtitle: string;
  body_html: string;
  orientation: 'portrait' | 'landscape';
  border_style: string;
  accent_color: string;
  font_style: string;
  signatory_name: string;
  signatory_designation: string;
  signatory2_name: string;
  signatory2_designation: string;
  show_photo: boolean;
  show_seal: boolean;
  show_qr: boolean;
  validity_days: string;
  is_default: boolean;
  status: 'active' | 'inactive';
}

const SWATCHES = ['#0B2A5B', '#1D4ED8', '#14532D', '#22943F', '#7C2D12', '#7A1F4B', '#4C1D95', '#0F766E', '#334155'];

const STARTER = '<p>This is to certify that <strong>{{salutation}} {{student_name}}</strong>, {{son_daughter}} of <strong>{{father_name}}</strong>, Student ID <strong>{{student_uid}}</strong>, '
  + 'is a student of the <strong>{{program}}</strong> programme of {{institute_name}}.</p><p>This certificate is issued on {{pronoun_possessive}} request{{#purpose}} for the purpose of <strong>{{purpose}}</strong>{{/purpose}}.</p>';

function emptyForm(type: string, config?: CertConfig): Form {
  const t = config?.types.find((x) => x.key === type);
  return {
    name: '', type, description: '', title: t?.label ?? 'Certificate', subtitle: '', body_html: STARTER, orientation: 'portrait', border_style: 'classic', accent_color: '#0B2A5B',
    font_style: 'serif', signatory_name: '', signatory_designation: 'Registrar', signatory2_name: '', signatory2_designation: '', show_photo: false, show_seal: true, show_qr: true,
    validity_days: t?.validity_days ? String(t.validity_days) : '', is_default: false, status: 'active',
  };
}

function fromRow(r: TemplateRow): Form {
  return {
    name: r.name, type: r.type, description: r.description ?? '', title: r.title, subtitle: r.subtitle ?? '', body_html: r.body_html ?? '', orientation: r.orientation,
    border_style: r.border_style, accent_color: r.accent_color, font_style: r.font_style, signatory_name: r.signatory_name ?? '', signatory_designation: r.signatory_designation ?? '',
    signatory2_name: r.signatory2_name ?? '', signatory2_designation: r.signatory2_designation ?? '', show_photo: !!r.show_photo, show_seal: !!r.show_seal, show_qr: !!r.show_qr,
    validity_days: r.validity_days ? String(r.validity_days) : '', is_default: !!r.is_default, status: r.status,
  };
}

/** Full-page template editor: rich-text body with a placeholder picker, design options and a live exact preview. */
export default function TemplateEditorPage() {
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const tid = isNew ? null : Number(id);
  const { data: config, error: configError } = useCertConfig();
  const record = useApi<{ row: TemplateRow }>(['crud-record', 'certificate_templates', tid], `crud/certificate_templates/${tid}`, undefined, { enabled: !!tid });

  if (configError || record.error) {
    return (
      <>
        <PageHeader title="Certificate template" breadcrumbs={[{ label: 'Certificates', to: '/certificates' }, { label: 'Templates', to: '/certificates/templates' }, { label: 'Editor' }]} />
        <Alert variant="error" title="Unable to open the template">{((configError ?? record.error) as ApiError).message}</Alert>
      </>
    );
  }
  if (!config || (tid && !record.data)) {
    return (
      <>
        <PageHeader title={isNew ? 'New template' : 'Edit template'} breadcrumbs={[{ label: 'Certificates', to: '/certificates' }, { label: 'Templates', to: '/certificates/templates' }, { label: isNew ? 'New' : 'Edit' }]} />
        <div className="grid gap-6 xl:grid-cols-2">
          <div className="space-y-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-40 w-full" />)}</div>
          <Skeleton className="h-[640px] w-full" />
        </div>
      </>
    );
  }
  return <Editor key={tid ?? 'new'} config={config} row={record.data?.row ?? null} />;
}

function Editor({ config, row }: { config: CertConfig; row: TemplateRow | null }) {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const isNew = !row;
  const canSave = isNew ? can('certificates', 'create') : can('certificates', 'edit');
  const initial = useMemo(() => (row ? fromRow(row) : emptyForm(config.types.some((t) => t.key === params.get('type')) ? String(params.get('type')) : 'bonafide', config)), [row, config, params]);
  const [form, setForm] = useState<Form>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [studentId, setStudentId] = useState<number | null>(null);
  const [studentOpt, setStudentOpt] = useState<Option[]>([]);
  const [optional, setOptional] = useState(false);
  const dirty = JSON.stringify(form) !== JSON.stringify(initial);
  const set = <K extends keyof Form>(k: K, v: Form[K]) => {
    setForm((f) => ({ ...f, [k]: v }));
    if (errors[k as string]) setErrors((e) => ({ ...e, [k as string]: '' }));
  };

  // Warn before leaving with unsaved changes (tab close / reload)
  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  // Live preview
  const draft = useDebounce(JSON.stringify({ template: { ...form, validity_days: form.validity_days || null }, student_id: studentId }), 500);
  const preview = useQuery<TemplatePreview>({
    queryKey: ['cert-template-preview', draft],
    queryFn: ({ signal }) => api.post<TemplatePreview>('certificates/templates/preview', JSON.parse(draft), { signal }).then((r) => r.data),
    placeholderData: keepPreviousData,
    retry: false,
  });
  const pv = preview.data;

  // Placeholder insertion at the caret of the rich-text editor
  const bodyWrap = useRef<HTMLDivElement>(null);
  const savedRange = useRef<Range | null>(null);
  useEffect(() => {
    const onSel = () => {
      const editor = bodyWrap.current?.querySelector('[contenteditable="true"]');
      const sel = document.getSelection();
      if (editor && sel && sel.rangeCount && editor.contains(sel.anchorNode)) savedRange.current = sel.getRangeAt(0).cloneRange();
    };
    document.addEventListener('selectionchange', onSel);
    return () => document.removeEventListener('selectionchange', onSel);
  }, []);
  const lastInput = useRef<'title' | 'subtitle' | 'body'>('body');
  const insert = (key: string) => {
    const text = optional ? `{{#${key}}}{{${key}}}{{/${key}}}` : `{{${key}}}`;
    if (lastInput.current !== 'body') {
      const k = lastInput.current;
      set(k, `${form[k]}${form[k] && !form[k].endsWith(' ') ? ' ' : ''}${text}`);
      return;
    }
    const wrap = bodyWrap.current;
    const textarea = wrap?.querySelector('textarea');
    if (textarea) {
      const s = textarea.selectionStart ?? form.body_html.length;
      const e = textarea.selectionEnd ?? s;
      set('body_html', form.body_html.slice(0, s) + text + form.body_html.slice(e));
      return;
    }
    const editor = wrap?.querySelector<HTMLElement>('[contenteditable="true"]');
    if (editor && savedRange.current) {
      editor.focus();
      const sel = document.getSelection();
      sel?.removeAllRanges();
      sel?.addRange(savedRange.current);
      document.execCommand('insertText', false, text);
      return;
    }
    set('body_html', form.body_html.replace(/(<\/p>)?\s*$/, (m) => ` ${text}${m}`));
  };

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const body = { ...form, validity_days: form.validity_days === '' ? null : Number(form.validity_days) };
      const res = row ? await api.post(`crud/certificate_templates/${row.id}`, body) : await api.post<{ id: number }>('crud/certificate_templates', body);
      toast.success(res.message || 'Template saved.');
      await invalidate('crud', 'crud-record', 'cert-config');
      navigate('/certificates/templates');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setSaving(false);
    }
  };
  const cancel = async () => {
    if (dirty && !(await confirm({ title: 'Discard changes?', message: 'Your unsaved changes to this template will be lost.', confirmText: 'Discard', danger: true }))) return;
    navigate('/certificates/templates');
  };
  const remove = async () => {
    if (!row) return;
    if (!(await confirm({ title: 'Delete template?', message: <>Delete <strong>{row.name}</strong>? This cannot be undone.</>, confirmText: 'Delete', danger: true }))) return;
    try {
      const res = await api.del(`crud/certificate_templates/${row.id}`);
      toast.success(res.message);
      await invalidate('crud', 'cert-config');
      navigate('/certificates/templates');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const startFrom = (tplId: string) => {
    const src = config.templates.find((t) => String(t.id) === tplId);
    if (!src) return;
    void api.get<{ row: TemplateRow }>(`crud/certificate_templates/${src.id}`).then((d) => {
      const f = fromRow(d.row);
      setForm((cur) => ({ ...f, name: cur.name || `${d.row.name} (variant)`, is_default: false, status: 'active' }));
      toast.info(`Copied wording and design from “${d.row.name}”.`);
    });
  };

  const typeDef = config.types.find((t) => t.key === form.type);
  const usedSet = new Set(pv?.used ?? []);
  const typeFieldKeys = new Set(typeDef?.fields ?? []);
  const groups = config.placeholders.map((g) => ({
    ...g,
    items: g.group === 'Type details' ? g.items.filter((i) => typeFieldKeys.has(i.key) || ['completion_month_year', 'internship_duration'].includes(i.key) && (typeFieldKeys.has('completion_date') || typeFieldKeys.has('internship_end'))) : g.items,
  })).filter((g) => g.items.length);

  return (
    <>
      <PageHeader
        title={isNew ? 'New certificate template' : row.name}
        description={isNew ? 'Write the certificate text with placeholders and pick a design — the preview on the right is exactly what prints.' : `${typeDef?.label ?? row.type} · ${row.issued_count} certificate${row.issued_count === 1 ? '' : 's'} generated from this template`}
        breadcrumbs={[{ label: 'Certificates', to: '/certificates' }, { label: 'Templates', to: '/certificates/templates' }, { label: isNew ? 'New' : 'Edit' }]}
        actions={
          <>
            <Button variant="secondary" icon={ArrowLeft} onClick={cancel}>Back</Button>
            <Button variant="secondary" icon={Eye} className="xl:hidden" onClick={() => document.getElementById('template-preview')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}>Preview</Button>
            {row && <Button variant="secondary" icon={Printer} onClick={() => window.open(printUrl('certificate.php', { template_id: row.id, student_id: studentId ?? undefined }), '_blank', 'noopener')}>Specimen PDF</Button>}
            {canSave && <Button icon={Save} loading={saving} onClick={save}>{isNew ? 'Create template' : 'Save changes'}</Button>}
          </>
        }
      />
      {!canSave && <Alert variant="info" className="mb-5">You can view this template but your role cannot change it.</Alert>}
      {Object.values(errors).some(Boolean) && <Alert variant="error" className="mb-5" title="Please fix the highlighted fields">{Object.values(errors).filter(Boolean).slice(0, 3).join(' ')}</Alert>}
      {row && row.issued_count > 0 && (
        <Alert variant="info" className="mb-5">Certificates already issued keep their own copy of the wording and design — changes apply to new certificates only.</Alert>
      )}

      <div className="grid gap-6 xl:grid-cols-2">
        <fieldset disabled={!canSave} className="min-w-0 space-y-6">
          <Card>
            <CardHeader title="Template" subtitle="Name, certificate type and status" icon={Wand2} />
            <div className="card-body grid gap-4 sm:grid-cols-2">
              <Field label="Template name" required error={errors.name} htmlFor="t-name">
                <Input id="t-name" value={form.name} maxLength={150} onChange={(e) => set('name', e.target.value)} invalid={!!errors.name} placeholder="e.g. Bonafide Certificate — Standard" />
              </Field>
              <Field label="Certificate type" required error={errors.type} htmlFor="t-type">
                <Select id="t-type" value={form.type} onChange={(e) => set('type', e.target.value)} options={config.types.map((t) => ({ value: t.key, label: t.label }))} invalid={!!errors.type} />
              </Field>
              <Field label="Internal description" error={errors.description} htmlFor="t-desc" className="sm:col-span-2">
                <Input id="t-desc" value={form.description} maxLength={255} onChange={(e) => set('description', e.target.value)} placeholder="Where this design is used (not printed)" />
              </Field>
              {isNew && (
                <Field label="Start from an existing template" htmlFor="t-from" hint="Copies its wording and design into this form." className="sm:col-span-2">
                  <Select id="t-from" value="" onChange={(e) => startFrom(e.target.value)} options={config.templates.map((t) => ({ value: String(t.id), label: t.name }))} placeholder="Blank starter text" />
                </Field>
              )}
              <div className="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                <Toggle checked={form.is_default} onChange={(v) => set('is_default', v)} label="Default for this type" description="Used automatically when issuing." />
                <Select inputSize="sm" value={form.status} onChange={(e) => set('status', e.target.value as Form['status'])} options={{ active: 'Active', inactive: 'Inactive' }} aria-label="Status" className="sm:!w-40" />
              </div>
              {errors.is_default && <p className="form-error sm:col-span-2">{errors.is_default}</p>}
              {errors.status && <p className="form-error sm:col-span-2">{errors.status}</p>}
            </div>
          </Card>

          <Card>
            <CardHeader title="Heading & wording" subtitle="Click a placeholder to insert it where the cursor is" icon={PenLine} />
            <div className="card-body space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Heading" required error={errors.title} htmlFor="t-title">
                  <Input id="t-title" value={form.title} maxLength={190} onFocus={() => (lastInput.current = 'title')} onChange={(e) => set('title', e.target.value)} invalid={!!errors.title} />
                </Field>
                <Field label="Sub-heading" error={errors.subtitle} htmlFor="t-subtitle">
                  <Input id="t-subtitle" value={form.subtitle} maxLength={190} onFocus={() => (lastInput.current = 'subtitle')} onChange={(e) => set('subtitle', e.target.value)} placeholder="To Whomsoever It May Concern" />
                </Field>
              </div>
              <Field label="Certificate text" required error={errors.body_html} htmlFor="t-body" aside={pv ? `${pv.words} words` : undefined}>
                <div ref={bodyWrap} onFocusCapture={() => (lastInput.current = 'body')}>
                  <RichText id="t-body" value={form.body_html} onChange={(v) => set('body_html', v)} invalid={!!errors.body_html} minHeight={230} />
                </div>
              </Field>
              <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-800/30">
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Braces className="h-3.5 w-3.5" />Placeholders</p>
                  <label className="inline-flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" className="form-checkbox" checked={optional} onChange={(e) => setOptional(e.target.checked)} />
                    Insert as optional block <code className="rounded bg-white px-1 text-[10px] dark:bg-slate-900">{'{{#key}}…{{/key}}'}</code>
                  </label>
                </div>
                <div className="max-h-64 space-y-3 overflow-y-auto pr-1">
                  {groups.map((g) => (
                    <div key={g.group}>
                      <p className="mb-1.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400">{g.group}</p>
                      <div className="flex flex-wrap gap-1.5">
                        {g.items.map((p) => (
                          <button key={p.key} type="button" title={`{{${p.key}}} — ${p.label}`} onMouseDown={(e) => e.preventDefault()} onClick={() => insert(p.key)}
                            className={clsx('rounded-lg px-2 py-1 text-[11px] font-medium ring-1 transition hover:-translate-y-px',
                              usedSet.has(p.key) ? 'bg-brand-800 text-white ring-brand-800 dark:bg-brand-500' : 'bg-white text-slate-700 ring-slate-200 hover:ring-brand-400 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700')}>
                            {p.label}
                          </button>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
                <p className="mt-2 text-[11px] text-slate-500">Highlighted placeholders are already used. A value that is empty for a student blocks issuing, unless it sits inside an optional block.</p>
              </div>
            </div>
          </Card>

          <Card>
            <CardHeader title="Design" subtitle="A4 layout, border, colour and typography" icon={Palette} />
            <div className="card-body space-y-5">
              <div>
                <p className="form-label">Page layout</p>
                <div className="grid grid-cols-2 gap-3">
                  {(['portrait', 'landscape'] as const).map((o) => (
                    <button key={o} type="button" onClick={() => set('orientation', o)} aria-pressed={form.orientation === o}
                      className={clsx('flex items-center gap-3 rounded-xl border p-3 text-left text-sm font-medium transition', form.orientation === o ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/15' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700')}>
                      <span className={clsx('block shrink-0 rounded-sm border-2 border-slate-400', o === 'portrait' ? 'h-9 w-7' : 'h-7 w-9')} />
                      <span className="text-slate-800 dark:text-slate-100">A4 {o === 'portrait' ? 'Portrait' : 'Landscape'}<span className="block text-xs font-normal text-slate-500">{o === 'portrait' ? 'Letters & formal certificates' : 'Award-style certificates'}</span></span>
                    </button>
                  ))}
                </div>
              </div>
              <div>
                <p className="form-label">Border style</p>
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                  {Object.entries(config.border_styles).map(([key, label]) => (
                    <button key={key} type="button" onClick={() => set('border_style', key)} aria-pressed={form.border_style === key} title={label}
                      className={clsx('rounded-xl border p-2 text-center text-[11px] font-medium transition', form.border_style === key ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/15' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700')}>
                      <TemplateThumb title="Title" orientation="portrait" borderStyle={key} accent={form.accent_color} className="!w-[62%] !shadow-none" />
                      <span className="mt-1.5 block truncate text-slate-700 dark:text-slate-200">{label.split(' (')[0]}</span>
                    </button>
                  ))}
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Accent colour" required error={errors.accent_color} htmlFor="t-accent">
                  <div className="flex items-center gap-2">
                    <input id="t-accent" type="color" value={form.accent_color} onChange={(e) => set('accent_color', e.target.value.toUpperCase())} className="h-10 w-12 cursor-pointer rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900" aria-label="Accent colour picker" />
                    <Input value={form.accent_color} maxLength={7} onChange={(e) => set('accent_color', e.target.value)} className="font-mono uppercase" aria-label="Accent colour hex" invalid={!!errors.accent_color} />
                  </div>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {SWATCHES.map((c) => (
                      <button key={c} type="button" onClick={() => set('accent_color', c)} aria-label={`Use ${c}`} title={c}
                        className={clsx('h-6 w-6 rounded-full ring-2 ring-offset-2 transition dark:ring-offset-slate-900', form.accent_color.toUpperCase() === c ? 'ring-brand-500' : 'ring-transparent hover:ring-slate-300')} style={{ background: c }} />
                    ))}
                  </div>
                </Field>
                <Field label="Body font" required htmlFor="t-font">
                  <Select id="t-font" value={form.font_style} onChange={(e) => set('font_style', e.target.value)} options={config.font_styles} />
                </Field>
              </div>
              <div className="grid gap-3 sm:grid-cols-3">
                <Toggle checked={form.show_qr} onChange={(v) => set('show_qr', v)} label="QR code" description="Links to verification" />
                <Toggle checked={form.show_seal} onChange={(v) => set('show_seal', v)} label="Institute seal" />
                <Toggle checked={form.show_photo} onChange={(v) => set('show_photo', v)} label="Student photo" />
              </div>
            </div>
          </Card>

          <Card>
            <CardHeader title="Signatories & validity" icon={Sparkles} />
            <div className="card-body grid gap-4 sm:grid-cols-2">
              <Field label="Signatory name" error={errors.signatory_name} htmlFor="t-s1"><Input id="t-s1" value={form.signatory_name} maxLength={150} onChange={(e) => set('signatory_name', e.target.value)} placeholder="Dr. Rajeev Saxena" /></Field>
              <Field label="Designation" error={errors.signatory_designation} htmlFor="t-s1d"><Input id="t-s1d" value={form.signatory_designation} maxLength={150} onChange={(e) => set('signatory_designation', e.target.value)} placeholder="Registrar" /></Field>
              <Field label="Second signatory (optional)" error={errors.signatory2_name} htmlFor="t-s2"><Input id="t-s2" value={form.signatory2_name} maxLength={150} onChange={(e) => set('signatory2_name', e.target.value)} placeholder="Prof. Neha Verma" /></Field>
              <Field label="Designation" error={errors.signatory2_designation} htmlFor="t-s2d"><Input id="t-s2d" value={form.signatory2_designation} maxLength={150} onChange={(e) => set('signatory2_designation', e.target.value)} placeholder="Director" /></Field>
              <Field label="Default validity" error={errors.validity_days} htmlFor="t-valid" hint="Days from the issue date. Leave blank for no expiry." className="sm:col-span-2">
                <div className="sm:w-48">
                  <Input id="t-valid" type="number" min={1} max={3650} value={form.validity_days} onChange={(e) => set('validity_days', e.target.value)} suffix="days" invalid={!!errors.validity_days} placeholder="No expiry" />
                </div>
              </Field>
            </div>
          </Card>
          {row && can('certificates', 'delete') && (
            <div className="flex justify-end">
              <Button variant="ghost" icon={Trash2} className="!text-red-600" onClick={remove} disabled={row.issued_count > 0} title={row.issued_count > 0 ? 'Used for issued certificates — mark it inactive instead' : undefined}>
                Delete template
              </Button>
            </div>
          )}
        </fieldset>

        <div id="template-preview" className="min-w-0 scroll-mt-20 xl:sticky xl:top-20 xl:self-start">
          <Card>
            <CardHeader title="Live preview" subtitle={pv ? (pv.sample ? 'Sample student · placeholder values are examples' : `Rendered for ${pv.student.name} (${pv.student.uid})`) : 'Rendering…'} icon={Eye}
              actions={typeDef ? <span className="hidden items-center gap-2 text-xs text-slate-500 sm:inline-flex"><TypeIcon type={form.type} size="sm" />{typeDef.short}</span> : undefined} />
            <div className="card-body space-y-4">
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <span className="flex shrink-0 items-center gap-1.5 text-xs font-medium text-slate-500"><UserRound className="h-3.5 w-3.5" />Preview with</span>
                <div className="flex-1">
                  <Combobox value={studentId} onChange={(v, o) => { setStudentId(v ? Number(v) : null); if (o) setStudentOpt([o]); }} optionsUrl="certificates/students" initialOptions={studentOpt}
                    placeholder="Sample student (search a real student…)" size="sm" />
                </div>
              </div>
              <div className="rounded-2xl bg-slate-100/80 p-3 sm:p-5 dark:bg-slate-800/50">
                {preview.error && !pv ? (
                  <Alert variant="error">{(preview.error as ApiError).message}</Alert>
                ) : (
                  <CertificateFrame html={pv?.html} orientation={pv?.orientation ?? form.orientation} loading={preview.isFetching} title="Template preview" maxHeight={760} />
                )}
              </div>
              {pv && pv.problems.length > 0 && <Alert variant="error" title="Template problems">{pv.problems.join(' ')}</Alert>}
              {pv && pv.missing.length > 0 && (
                <Alert variant="warning" title={pv.sample ? 'Values entered while issuing' : 'Missing for this student'}>
                  {pv.missing.map((k) => <code key={k} className="mr-1 rounded bg-white/70 px-1 text-[11px] dark:bg-slate-900/50">{`{{${k}}}`}</code>)}
                  {pv.sample ? ' are not part of the sample data.' : ' — this student could not be issued this certificate until the data exists or the text is made optional.'}
                </Alert>
              )}
              {pv && (
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="text-xs text-slate-500">Uses</span>
                  {pv.used.length ? pv.used.map((k) => <Badge key={k} color="slate">{k}</Badge>) : <span className="text-xs text-slate-400">no placeholders</span>}
                </div>
              )}
            </div>
          </Card>
        </div>
      </div>
    </>
  );
}
