import clsx from 'clsx';
import {
  AlertTriangle,
  BadgeCheck,
  Braces,
  CheckCircle2,
  ChevronDown,
  CircleAlert,
  Copy,
  FileText,
  Film,
  GitBranchPlus,
  Image as ImageIcon,
  Info,
  KeyRound,
  Library,
  Loader2,
  MoreHorizontal,
  PanelTop,
  Save,
  Send,
  ShieldCheck,
  Trash2,
  Type,
  Upload,
  X,
  XCircle,
  Archive,
  ArchiveRestore,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react';
import { useBlocker, useLocation, useNavigate, useParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardBody,
  CardHeader,
  Dropdown,
  EmptyState,
  Field,
  Input,
  PageHeader,
  RadioGroup,
  Select,
  Skeleton,
  Toggle,
  useConfirm,
  useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { humanFileSize, timeAgo } from '@/lib/format';
import { ModeBanner, WaText, waUploadMedia, useWaRefresh, type WaHeaderType, type WaTemplateCategory } from '../shared';
import { BodyEditor, VariablePalette, VariablePicker, type BodyEditorHandle } from './BodyEditor';
import { ButtonsEditor } from './ButtonsEditor';
import { TemplatePreview } from './TemplatePreview';
import { tplMenuItems, useTplActions } from './actions';
import {
  CATEGORY_TONE,
  STATUS_COLORS,
  STATUS_LABELS,
  designerFromDetail,
  designerPayload,
  emptyDesigner,
  extractKeys,
  isLinkValuedKey,
  metaLength,
  previewData,
  usedKeys,
  useTplDetail,
  useTplMeta,
} from './lib';
import type { DesignerState, TplDetail, TplIssue, TplMeta, TplSaveResult, TplValidation, TplVariables } from './types';

const CRUMBS = [
  { label: 'Dashboard', to: '/' },
  { label: 'WhatsApp', to: '/whatsapp' },
  { label: 'Template Library', to: '/whatsapp/templates' },
];

const HEADER_TYPES: { key: WaHeaderType; label: string; icon: typeof Type }[] = [
  { key: 'none', label: 'None', icon: X },
  { key: 'text', label: 'Text', icon: Type },
  { key: 'image', label: 'Image', icon: ImageIcon },
  { key: 'video', label: 'Video', icon: Film },
  { key: 'document', label: 'Document', icon: FileText },
];

const CATEGORY_ICON: Record<WaTemplateCategory, typeof Type> = { UTILITY: BadgeCheck, MARKETING: Send, AUTHENTICATION: KeyRound };

/** Field key from the server → element id to focus from the compliance checklist. */
function fieldElementId(field: string): string {
  if (field.startsWith('buttons.')) {
    const [, i, f] = field.split('.');
    return f ? `btn-${i}-${f === 'type' ? 'text' : f === 'example' ? 'code' : f === 'phone_number' ? 'phone' : f}` : 'tpl-buttons';
  }
  if (field.startsWith('samples.')) return `tpl-sample-${field.slice(8)}`;
  if (field.startsWith('otp.')) return `tpl-otp-${field.slice(4)}`;
  return (
    {
      name: 'tpl-name',
      display_name: 'tpl-display',
      category: 'tpl-category',
      language: 'tpl-language',
      header_type: 'tpl-header',
      header_text: 'tpl-header-text',
      header_media_url: 'tpl-header-media',
      body: 'tpl-body',
      footer: 'tpl-footer',
      buttons: 'tpl-buttons',
    } as Record<string, string>
  )[field] ?? 'tpl-body';
}

function focusField(field: string) {
  const el = document.getElementById(fieldElementId(field));
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  if ('focus' in el) (el as HTMLElement).focus({ preventScroll: true });
}

/** Light name normalisation while typing (keeps a trailing underscore); full slug on blur / save. */
const typingSlug = (s: string) => s.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/_+/g, '_').replace(/^_+/, '').slice(0, 190);

export default function TemplateDesignerPage() {
  const { id: idParam } = useParams();
  const id = idParam && /^\d+$/.test(idParam) ? Number(idParam) : null;
  const meta = useTplMeta();
  const detail = useTplDetail(id);

  if (meta.error || detail.error) {
    const err = (meta.error ?? detail.error) as ApiError;
    return (
      <>
        <PageHeader title="Template Designer" breadcrumbs={[...CRUMBS, { label: 'Designer' }]} />
        {err.status === 404 ? (
          <Card>
            <EmptyState icon={FileText} title="Template not found" description="It may have been deleted. Open the library to pick another template." action={<Button to="/whatsapp/templates" icon={Library}>Template Library</Button>} />
          </Card>
        ) : (
          <Alert variant="error" title="Unable to load the designer" action={<Button size="sm" variant="secondary" onClick={() => void (meta.error ? meta.refetch() : detail.refetch())}>Retry</Button>}>
            {err.message}
          </Alert>
        )}
      </>
    );
  }
  if (!meta.data || (id && !detail.data)) {
    return (
      <>
        <PageHeader title="Template Designer" breadcrumbs={[...CRUMBS, { label: 'Designer' }]} />
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_400px]">
          <div className="space-y-4">
            {[0, 1, 2].map((i) => (
              <Card key={i}>
                <CardBody className="space-y-3">
                  <Skeleton className="h-5 w-40" />
                  <Skeleton className="h-10 w-full" />
                  <Skeleton className="h-24 w-full" />
                </CardBody>
              </Card>
            ))}
          </div>
          <Skeleton className="h-[600px] w-full rounded-[2.6rem]" />
        </div>
      </>
    );
  }
  if (detail.data && detail.data.template.status !== 'draft') {
    return <LockedTemplate key={`locked-${id}`} meta={meta.data} detail={detail.data} />;
  }
  return <Designer key={id ?? 'new'} meta={meta.data} detail={detail.data ?? null} />;
}

/* ================================================================== editable designer (new / draft) */

function Designer({ meta, detail }: { meta: TplMeta; detail: TplDetail | null }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const location = useLocation();
  const refresh = useWaRefresh();
  const actions = useTplActions({ onDeleted: () => navigate('/whatsapp/templates') });
  const id = detail?.template.id ?? null;
  const L = meta.limits;

  const initial = useMemo(() => (detail ? designerFromDetail(detail) : emptyDesigner(meta.languages[meta.default_language] ? meta.default_language : 'en')), [detail, meta]);
  const [s, setS] = useState<DesignerState>(initial);
  const snapshot = useRef(JSON.stringify(initial));
  const [nameTouched, setNameTouched] = useState(!!detail);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [attempted, setAttempted] = useState(false);
  const [showVars, setShowVars] = useState(false);
  const [saving, setSaving] = useState<'save' | 'submit' | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const bodyRef = useRef<BodyEditorHandle>(null);

  const dirty = JSON.stringify(s) !== snapshot.current;
  const canSave = id ? can('whatsapp_templates', 'edit') : can('whatsapp_templates', 'create');
  const isAuth = s.category === 'AUTHENTICATION';
  const keys = usedKeys(s);
  const catalog = useMemo(() => new Map(meta.catalog.map((v) => [v.key, v])), [meta.catalog]);

  // ---- unsaved-changes guard
  const dirtyRef = useRef(dirty);
  dirtyRef.current = dirty;
  const allowNav = useRef(false);
  const blocker = useBlocker(({ currentLocation, nextLocation }) => !allowNav.current && dirtyRef.current && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    void confirm({ title: 'Leave without saving?', message: 'Your changes to this template have not been saved.', confirmText: 'Discard changes', danger: true }).then((ok) =>
      ok ? blocker.proceed() : blocker.reset(),
    );
  }, [blocker, confirm]);
  useEffect(() => {
    allowNav.current = false;
  }, [location.pathname]);
  useEffect(() => {
    if (!dirty) return;
    const onBefore = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', onBefore);
    return () => window.removeEventListener('beforeunload', onBefore);
  }, [dirty]);

  // ---- live server compliance check (debounced)
  const payload = useMemo(() => designerPayload(s), [s]);
  const debounced = useDebounce(JSON.stringify(payload), 450);
  const validation = useQuery<TplValidation>({
    queryKey: ['wa', 'templates', 'validate', id, debounced],
    queryFn: ({ signal }) => api.post<TplValidation>('whatsapp/templates/validate', { ...JSON.parse(debounced), id }, { signal }).then((r) => r.data),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    retry: false,
  });
  const issues: TplIssue[] = validation.data?.issues ?? [];
  const checking = validation.isFetching || debounced !== JSON.stringify(payload);

  // Inline errors appear for fields the user has touched (or everywhere once a save / submit was attempted, or for an
  // existing draft); the compliance card always lists everything.
  const [touched, setTouched] = useState<Set<string>>(() => new Set(detail ? ['*'] : []));
  const touch = (...f: string[]) => setTouched((t) => (f.every((x) => t.has(x)) ? t : new Set([...t, ...f])));
  const shows = (i: TplIssue) => {
    if (i.level === 'submit') return attempted;
    if (i.level !== 'error') return false;
    return attempted || touched.has('*') || touched.has(i.field) || touched.has(i.field.split('.')[0]);
  };
  const fieldError = (f: string): string | undefined => {
    if (errors[f]) return errors[f];
    return issues.find((i) => i.field === f && shows(i))?.message;
  };
  const errorMap: Record<string, string> = {};
  for (const i of issues) if (shows(i) && !errorMap[i.field]) errorMap[i.field] = i.message;
  Object.assign(errorMap, errors);

  const set = <K extends keyof DesignerState>(k: K, v: DesignerState[K]) => {
    setS((p) => {
      const next = { ...p, [k]: v };
      if (k === 'display_name' && !nameTouched && !id) next.name = typingSlug(String(v)).replace(/_+$/, '');
      return next;
    });
    touch(k as string, ...(k === 'display_name' && !nameTouched && !id ? ['name'] : []));
    if (errors[k as string]) setErrors(({ [k as string]: _omit, ...rest }) => rest);
  };
  const setSample = (k: string, v: string) => {
    setS((p) => ({ ...p, samples: { ...p.samples, [k]: v } }));
    touch(`samples.${k}`);
    if (errors[`samples.${k}`]) setErrors(({ [`samples.${k}`]: _omit, ...rest }) => rest);
  };
  // variables typed by hand: pre-fill the catalogue sample once (an emptied sample stays empty)
  const keySig = keys.join(',');
  useEffect(() => {
    const fill: Record<string, string> = {};
    for (const k of keySig ? keySig.split(',') : []) {
      const sample = catalog.get(k)?.sample;
      if (sample && s.samples[k] === undefined) fill[k] = sample;
    }
    if (Object.keys(fill).length) setS((p) => ({ ...p, samples: { ...fill, ...p.samples } }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [keySig, catalog]);

  const onVarInserted = (k: string, sample: string) => {
    if (sample && !s.samples[k]) setS((p) => ({ ...p, samples: { ...p.samples, [k]: p.samples[k] || sample } }));
  };

  const setCategory = (c: WaTemplateCategory) => {
    setS((p) => {
      if (c === 'AUTHENTICATION') {
        return { ...p, category: c, header_type: 'none', button_type: 'none', buttons: [], samples: { ...p.samples, otp_code: p.samples.otp_code || '482913' } };
      }
      const buttons = c === 'MARKETING' ? p.buttons : p.buttons.filter((b) => b.type !== 'COPY_CODE');
      return { ...p, category: c, buttons, button_type: buttons.length ? p.button_type : 'none' };
    });
  };

  // ---- header media upload
  const onFile = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (file) void uploadFile(file);
  };
  const uploadFile = async (file: File) => {
    if (!['image', 'video', 'document'].includes(s.header_type)) return;
    const spec = L.media[s.header_type as 'image' | 'video' | 'document'];
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!spec.ext.includes(ext) && !spec.mime.includes(file.type)) {
      setErrors((x) => ({ ...x, header_media_url: `${spec.label} headers accept ${spec.ext.filter((v) => v !== 'jpeg').map((v) => v.toUpperCase()).join(' / ')} files only.` }));
      return;
    }
    if (file.size > spec.max_mb * 1048576) {
      setErrors((x) => ({ ...x, header_media_url: `${spec.label} headers can be at most ${spec.max_mb} MB (this file is ${humanFileSize(file.size)}).` }));
      return;
    }
    setUploading(true);
    try {
      const up = await waUploadMedia(file, s.header_type as 'image' | 'video' | 'document');
      setS((p) => ({ ...p, header_media_url: up.url, header_media_name: up.name || file.name }));
      setErrors(({ header_media_url: _omit, ...rest }) => rest);
    } catch (err) {
      setErrors((x) => ({ ...x, header_media_url: (err as ApiError).message }));
    } finally {
      setUploading(false);
    }
  };

  // ---- save / submit
  const save = async (mode: 'save' | 'submit' = 'save'): Promise<number | null> => {
    setSaving(mode);
    touch('*');
    try {
      const body = { ...payload, name: payload.name ? String(payload.name).replace(/_+$/, '') : '' };
      const res = id ? await api.put<TplSaveResult>(`whatsapp/templates/${id}`, body) : await api.post<TplSaveResult>('whatsapp/templates', body);
      const saved = res.data;
      snapshot.current = JSON.stringify(s);
      setErrors({});
      await refresh('templates');
      if (mode === 'save') toast.success(res.message || 'Draft saved.');
      if (!id) {
        allowNav.current = true;
        navigate(`/whatsapp/templates/designer/${saved.id}`, { replace: true });
      }
      return saved.id;
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
      const first = Object.keys(err.errors ?? {})[0];
      if (first) setTimeout(() => focusField(first), 50);
      return null;
    } finally {
      setSaving(null);
    }
  };

  const submit = async () => {
    setAttempted(true);
    const blocking = issues.filter((i) => i.level !== 'warning');
    if (blocking.length) {
      toast.error('Fix the highlighted items before submitting to Interakt.');
      focusField(blocking[0].field);
      return;
    }
    let tid = id;
    if (!tid || dirty) tid = await save('submit');
    if (!tid) return;
    const r = await actions.submit({ id: tid, name: s.name, display_name: s.display_name || null, status: 'draft', interakt_id: null, submitted_at: null, category: s.category }, (errs) => {
      setErrors(errs);
      const first = Object.keys(errs)[0];
      if (first) setTimeout(() => focusField(first), 50);
    });
    if (r.ok) {
      allowNav.current = true;
      await refresh('templates');
    }
  };

  const preview = previewData(s, s.samples, showVars);
  const counts = { error: issues.filter((i) => i.level === 'error').length, submit: issues.filter((i) => i.level === 'submit').length, warning: issues.filter((i) => i.level === 'warning').length };
  const title = id ? `Edit: ${s.display_name || s.name || 'template'}` : 'New template';

  return (
    <>
      <PageHeader
        title={title}
        description="Design a WhatsApp template, preview it on a phone and submit it to Interakt for Meta approval."
        breadcrumbs={[...CRUMBS, { label: id ? s.name || 'Draft' : 'New template' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {dirty && <span className="hidden text-xs font-medium text-amber-600 sm:inline dark:text-amber-300">Unsaved changes</span>}
            {canSave && (
              <Button variant="secondary" icon={Save} onClick={() => void save('save')} loading={saving === 'save'} disabled={!!saving || uploading}>
                Save draft
              </Button>
            )}
            {can('whatsapp_templates', 'approve') && (
              <Button icon={Send} onClick={() => void submit()} loading={saving === 'submit' || actions.busy === `submit-${id}`} disabled={!!saving || uploading || (!canSave && dirty)}>
                Submit for approval
              </Button>
            )}
            {id && (
              <Dropdown
                label="More actions"
                triggerClassName="btn btn-secondary px-3"
                trigger={<MoreHorizontal size={16} aria-hidden />}
                items={[
                  { label: 'Open in library', icon: Library, to: `/whatsapp/templates?view=${id}` },
                  can('whatsapp_templates', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => void actions.duplicate({ id, name: s.name, display_name: s.display_name, status: 'draft', interakt_id: null, submitted_at: null, category: s.category }) },
                  can('whatsapp_templates', 'edit') && {
                    label: 'Archive',
                    icon: Archive,
                    onClick: () =>
                      void actions.archive({ id, name: s.name, display_name: s.display_name, status: 'draft', interakt_id: null, submitted_at: null, category: s.category }).then((r) => {
                        if (r.ok) {
                          allowNav.current = true;
                          navigate('/whatsapp/templates');
                        }
                      }),
                  },
                  can('whatsapp_templates', 'delete') && {
                    label: 'Delete draft',
                    icon: Trash2,
                    danger: true,
                    divider: true,
                    onClick: () => {
                      allowNav.current = true;
                      void actions.remove({ id, name: s.name, display_name: s.display_name, status: 'draft', interakt_id: null, submitted_at: null, category: s.category }).then((r) => {
                        if (!r.ok) allowNav.current = false;
                      });
                    },
                  },
                ]}
              />
            )}
          </div>
        }
      />
      <ModeBanner />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_400px]">
        {/* ------------------------------------------------ editor */}
        <div className="min-w-0 space-y-5">
          <Card>
            <CardHeader title="Template details" subtitle="Name, category and language decide how Meta reviews and bills the template." icon={FileText} />
            <CardBody className="space-y-4">
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Display name" htmlFor="tpl-display" error={fieldError('display_name')} hint="Shown in GIMT only.">
                  <Input id="tpl-display" value={s.display_name} maxLength={L.display_name} onChange={(e) => set('display_name', e.target.value)} placeholder="Fee due reminder" />
                </Field>
                <Field
                  label="Template name"
                  required
                  htmlFor="tpl-name"
                  error={fieldError('name')}
                  hint={!fieldError('name') && s.name ? <NameStatus checking={checking} ok={!issues.some((i) => i.field === 'name')} /> : 'Lowercase letters, numbers and underscores — the code name Interakt sends with.'}
                >
                  <Input
                    id="tpl-name"
                    value={s.name}
                    maxLength={L.name}
                    invalid={!!fieldError('name')}
                    className="font-mono"
                    placeholder="fee_due_reminder"
                    onChange={(e) => {
                      setNameTouched(true);
                      set('name', typingSlug(e.target.value));
                    }}
                    onBlur={() => set('name', s.name.replace(/_+$/, ''))}
                  />
                </Field>
              </div>
              <Field label="Description" htmlFor="tpl-description" hint="When this template is used — helps your team pick the right one.">
                <Input id="tpl-description" value={s.description} maxLength={255} onChange={(e) => set('description', e.target.value)} placeholder="Sent 3 days before a fee instalment is due" />
              </Field>

              <Field label="Category" required error={fieldError('category')}>
                <div id="tpl-category" className="grid gap-2.5 md:grid-cols-3" role="radiogroup" aria-label="Category" tabIndex={-1}>
                  {(Object.keys(meta.categories) as WaTemplateCategory[]).map((c) => {
                    const Icon = CATEGORY_ICON[c];
                    const active = s.category === c;
                    return (
                      <button
                        key={c}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={meta.categories[c].label}
                        aria-describedby={`tpl-cat-desc-${c}`}
                        onClick={() => setCategory(c)}
                        className={clsx(
                          'group flex h-full flex-col rounded-xl border p-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                          active
                            ? 'border-brand-600 bg-brand-50/70 ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/10 dark:ring-brand-400'
                            : 'border-slate-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-soft dark:border-slate-700 dark:hover:border-slate-600',
                        )}
                      >
                        <span className="mb-1.5 flex items-center gap-2">
                          <span className={clsx('flex h-7 w-7 items-center justify-center rounded-lg', active ? 'bg-brand-900 text-white dark:bg-brand-500' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300')}>
                            <Icon size={15} aria-hidden />
                          </span>
                          <span className="font-semibold text-slate-900 dark:text-white">{meta.categories[c].label}</span>
                        </span>
                        <span id={`tpl-cat-desc-${c}`} className="text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                          {meta.categories[c].description}
                        </span>
                        <span className="mt-1.5 text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">{meta.categories[c].hint}</span>
                      </button>
                    );
                  })}
                </div>
              </Field>
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Language" required htmlFor="tpl-language" error={fieldError('language')} hint="One template per language; the same name can exist in several languages.">
                  <Select
                    id="tpl-language"
                    value={s.language}
                    onChange={(e) => set('language', e.target.value)}
                    options={Object.entries(meta.languages).map(([value, l]) => ({ value, label: `${l.label} (${value})` }))}
                  />
                </Field>
              </div>
            </CardBody>
          </Card>

          {isAuth ? (
            <OtpCard s={s} set={set} setSample={setSample} fieldError={fieldError} meta={meta} />
          ) : (
            <>
              <Card>
                <CardHeader title="Header" subtitle="Optional title line or media shown above the message." icon={PanelTop} />
                <CardBody className="space-y-4">
                  <div id="tpl-header" className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Header type" tabIndex={-1}>
                    {HEADER_TYPES.map((h) => (
                      <button
                        key={h.key}
                        type="button"
                        role="radio"
                        aria-checked={s.header_type === h.key}
                        onClick={() => set('header_type', h.key)}
                        className={clsx(
                          'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                          s.header_type === h.key
                            ? 'border-brand-600 bg-brand-900 text-white dark:border-brand-400 dark:bg-brand-500'
                            : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800',
                        )}
                      >
                        <h.icon size={14} aria-hidden />
                        {h.label}
                      </button>
                    ))}
                  </div>
                  {s.header_type === 'text' && (
                    <Field
                      label="Header text"
                      required
                      htmlFor="tpl-header-text"
                      error={fieldError('header_text')}
                      hint="Max 60 characters and one variable. No emojis or formatting."
                      aside={
                        <VariablePicker
                          size="xs"
                          align="right"
                          label="Add variable"
                          disabled={extractKeys(s.header_text).length >= 1}
                          onPick={(k, sample) => {
                            set('header_text', `${s.header_text}${s.header_text && !/\s$/.test(s.header_text) ? ' ' : ''}{{${k}}}`);
                            onVarInserted(k, sample);
                          }}
                        />
                      }
                    >
                      <Input
                        id="tpl-header-text"
                        value={s.header_text}
                        invalid={!!fieldError('header_text')}
                        onChange={(e) => set('header_text', e.target.value.replace(/[\r\n]/g, ' '))}
                        placeholder="Fee due reminder"
                        suffix={<span className={clsx('text-[11px] tabular-nums', metaLength(s.header_text) > L.header_text ? 'font-semibold text-red-600' : 'text-slate-400')}>{metaLength(s.header_text)}/{L.header_text}</span>}
                      />
                    </Field>
                  )}
                  {(s.header_type === 'image' || s.header_type === 'video' || s.header_type === 'document') && (
                    <MediaField
                      kind={s.header_type}
                      spec={L.media[s.header_type]}
                      url={s.header_media_url}
                      name={s.header_media_name}
                      uploading={uploading}
                      error={fieldError('header_media_url')}
                      onPick={() => fileRef.current?.click()}
                      onDropFile={(f) => void uploadFile(f)}
                      onRemove={() => setS((p) => ({ ...p, header_media_url: '', header_media_name: '' }))}
                    />
                  )}
                  {(s.header_type === 'video' || s.header_type === 'document') && (
                    <p className="flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
                      <Info size={14} className="mt-0.5 shrink-0" aria-hidden />
                      <span>
                        Interakt&apos;s template API documents Text and Image headers only. A {s.header_type} header is submitted in the same format (VIDEO / DOCUMENT with the uploaded sample) but is not
                        documented — if Interakt refuses it, create the template in the Interakt dashboard and use “Import from Interakt”.
                      </span>
                    </p>
                  )}
                  <input ref={fileRef} type="file" className="hidden" accept={s.header_type in L.media ? L.media[s.header_type as 'image'].accept : undefined} onChange={onFile} aria-hidden tabIndex={-1} />
                </CardBody>
              </Card>

              <Card>
                <CardHeader title="Message body" subtitle="Drag or insert GIMT variables — they are stored as Meta positional {{1}}, {{2}} … automatically." icon={Braces} />
                <CardBody className="space-y-2">
                  <BodyEditor
                    ref={bodyRef}
                    id="tpl-body"
                    value={s.body}
                    onChange={(v) => set('body', v)}
                    max={L.body}
                    invalid={!!fieldError('body')}
                    onVariableInserted={onVarInserted}
                    placeholder={'Dear {{student_name}}, your fee of {{fee_amount}} is due on {{due_date}}.'}
                  />
                  {fieldError('body') ? (
                    <p className="text-xs font-medium text-red-600 dark:text-red-400">{fieldError('body')}</p>
                  ) : (
                    <p className="text-[11px] text-slate-500 dark:text-slate-400">
                      *bold* · _italic_ · ~strikethrough~ · ```monospace``` — a variable cannot start or end the message, and two variables need words between them.
                    </p>
                  )}
                  <VariablePalette
                    used={keys}
                    onPick={(k, sample) => {
                      bodyRef.current?.insert(`{{${k}}}`);
                      onVarInserted(k, sample);
                    }}
                  />
                </CardBody>
              </Card>

              <Card>
                <CardHeader title="Footer" subtitle="Optional small grey line, e.g. the sending office. No variables." icon={Type} />
                <CardBody>
                  <Field label="Footer text" htmlFor="tpl-footer" error={fieldError('footer')}>
                    <Input
                      id="tpl-footer"
                      value={s.footer}
                      onChange={(e) => set('footer', e.target.value)}
                      placeholder={s.category === 'MARKETING' ? 'Reply STOP to unsubscribe' : 'GIMT Accounts Office'}
                      invalid={!!fieldError('footer')}
                      suffix={<span className={clsx('text-[11px] tabular-nums', Array.from(s.footer).length > L.footer ? 'font-semibold text-red-600' : 'text-slate-400')}>{Array.from(s.footer).length}/{L.footer}</span>}
                    />
                  </Field>
                </CardBody>
              </Card>

              <Card>
                <CardHeader title="Buttons" subtitle="Call-to-action buttons or quick replies (one group per template)." icon={ShieldCheck} />
                <CardBody id="tpl-buttons" tabIndex={-1}>
                  <ButtonsEditor
                    group={s.button_type}
                    buttons={s.buttons}
                    onChange={(g, b) => {
                      setS((p) => ({ ...p, button_type: g, buttons: b }));
                      touch('buttons');
                    }}
                    limits={L}
                    errors={errorMap}
                    allowCopyCode={s.category === 'MARKETING'}
                    onVariableInserted={onVarInserted}
                  />
                </CardBody>
              </Card>
            </>
          )}

          {!isAuth && (
            <Card>
              <CardHeader title="Sample values" subtitle="Meta reviews the template with these values. They also fill the preview and test messages." icon={Braces} />
              <CardBody>
                {keys.length === 0 ? (
                  <p className="text-sm text-slate-500 dark:text-slate-400">No variables yet. Use “Insert variable” in the body to personalise the message.</p>
                ) : (
                  <div className="grid gap-3 sm:grid-cols-2">
                    {keys.map((k) => {
                      const v = catalog.get(k);
                      return (
                        <Field
                          key={k}
                          htmlFor={`tpl-sample-${k}`}
                          required
                          error={fieldError(`samples.${k}`)}
                          label={
                            <span className="inline-flex items-center gap-1.5">
                              <code className="rounded bg-accent-50 px-1 text-[11px] text-accent-800 dark:bg-accent-500/15 dark:text-accent-200">{`{{${k}}}`}</code>
                              <span className="truncate text-xs font-normal text-slate-500 dark:text-slate-400">{v ? v.label : 'Custom'}</span>
                            </span>
                          }
                        >
                          <Input id={`tpl-sample-${k}`} inputSize="sm" value={s.samples[k] ?? ''} onChange={(e) => setSample(k, e.target.value)} placeholder={v?.sample || 'Sample value'} invalid={!!fieldError(`samples.${k}`)} />
                        </Field>
                      );
                    })}
                  </div>
                )}
              </CardBody>
            </Card>
          )}
        </div>

        {/* ------------------------------------------------ preview + compliance */}
        <aside className="min-w-0 space-y-5 lg:sticky lg:top-20 lg:self-start">
          <Card>
            <CardHeader title="Live preview" subtitle="How students see it on WhatsApp" icon={BadgeCheck} actions={<Badge color={CATEGORY_TONE[s.category].badge}>{CATEGORY_TONE[s.category].label}</Badge>} />
            <CardBody>
              <TemplatePreview data={preview} showVars={showVars} onToggleVars={setShowVars} businessName={meta.business_name} height={520} />
            </CardBody>
          </Card>
          <ComplianceCard issues={issues} counts={counts} checking={checking} attempted={attempted} error={validation.error as ApiError | null} />
          {validation.data && <MetaFormatCard v={validation.data} />}
        </aside>
      </div>
      {actions.dialogs}
    </>
  );
}

function NameStatus({ checking, ok }: { checking: boolean; ok: boolean }) {
  if (checking) {
    return (
      <span className="inline-flex items-center gap-1 text-slate-500">
        <Loader2 size={12} className="animate-spin" aria-hidden /> Checking…
      </span>
    );
  }
  return ok ? (
    <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400">
      <CheckCircle2 size={12} aria-hidden /> Available
    </span>
  ) : null;
}

function MediaField({
  kind,
  spec,
  url,
  name,
  uploading,
  error,
  onPick,
  onRemove,
  onDropFile,
  disabled,
  label,
}: {
  kind: 'image' | 'video' | 'document';
  spec: TplMeta['limits']['media']['image'];
  url: string;
  name: string;
  uploading: boolean;
  error?: string;
  onPick: () => void;
  onRemove: () => void;
  onDropFile?: (file: File) => void;
  disabled?: boolean;
  label?: string;
}) {
  const exts = spec.ext.filter((e) => e !== 'jpeg').map((e) => e.toUpperCase()).join(' / ');
  const [over, setOver] = useState(false);
  const canDrop = !!onDropFile && !disabled && !uploading;
  return (
    <Field
      label={label ?? `Sample ${spec.label.toLowerCase()}`}
      required={!label}
      error={error}
      hint={label ? `${exts}, up to ${spec.max_mb} MB. Used when an event or campaign does not send its own file.` : `${exts}, up to ${spec.max_mb} MB. Meta reviews this sample; each message can send its own file.`}
    >
      <div
        id="tpl-header-media"
        tabIndex={-1}
        onDragOver={(e) => {
          if (!canDrop || !e.dataTransfer.types.includes('Files')) return;
          e.preventDefault();
          e.dataTransfer.dropEffect = 'copy';
          setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => {
          if (!canDrop) return;
          e.preventDefault();
          setOver(false);
          const f = e.dataTransfer.files?.[0];
          if (f) onDropFile?.(f);
        }}
        className={clsx(
          'flex flex-col gap-3 rounded-xl border-2 border-dashed p-4 transition sm:flex-row sm:items-center',
          over
            ? 'border-accent-400 bg-accent-50/60 dark:border-accent-500/60 dark:bg-accent-500/10'
            : error
              ? 'border-red-300 bg-red-50/40 dark:border-red-500/40 dark:bg-red-500/5'
              : 'border-slate-200 bg-slate-50/50 dark:border-slate-700 dark:bg-slate-800/30',
        )}
      >
        {url ? (
          <>
            {kind === 'image' ? (
              <img src={url} alt="Header sample" className="h-20 w-28 shrink-0 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
            ) : (
              <span className="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-white text-brand-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-brand-200 dark:ring-slate-700">
                {kind === 'video' ? <Film size={26} aria-hidden /> : <FileText size={26} aria-hidden />}
              </span>
            )}
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{name || url.split('/').pop()}</p>
              <a href={url} target="_blank" rel="noopener noreferrer" className="text-xs text-brand-700 hover:underline dark:text-brand-300">
                Open file
              </a>
            </div>
            {!disabled && (
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" icon={Upload} onClick={onPick} loading={uploading}>
                  Replace
                </Button>
                <Button size="sm" variant="ghost" icon={X} onClick={onRemove}>
                  Remove
                </Button>
              </div>
            )}
          </>
        ) : (
          <>
            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white text-slate-400 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
              {uploading ? <Loader2 size={20} className="animate-spin" aria-hidden /> : <Upload size={20} aria-hidden />}
            </span>
            <p className="flex-1 text-sm text-slate-600 dark:text-slate-300">
              {disabled ? `No default ${spec.label.toLowerCase()} set.` : `Drop a sample ${spec.label.toLowerCase()} here or upload one (${exts}, max ${spec.max_mb} MB).`}
            </p>
            {!disabled && (
              <Button size="sm" icon={Upload} onClick={onPick} loading={uploading}>
                Upload {spec.label.toLowerCase()}
              </Button>
            )}
          </>
        )}
      </div>
    </Field>
  );
}

function OtpCard({
  s,
  set,
  setSample,
  fieldError,
  meta,
}: {
  s: DesignerState;
  set: <K extends keyof DesignerState>(k: K, v: DesignerState[K]) => void;
  setSample: (k: string, v: string) => void;
  fieldError: (f: string) => string | undefined;
  meta: TplMeta;
}) {
  const o = s.otp;
  const patch = (p: Partial<DesignerState['otp']>) => set('otp', { ...o, ...p });
  return (
    <Card>
      <CardHeader title="One-time password" subtitle="Meta fixes the wording of authentication templates; choose the button and code expiry." icon={KeyRound} />
      <CardBody className="space-y-4">
        <Alert variant="info" title="Fixed body">
          “<strong>{'{{1}}'}</strong> is your verification code.”{o.add_security_recommendation ? ' + “For your security, do not share this code.”' : ''} — footer “This code expires in {o.code_expiration_minutes} minutes.”
        </Alert>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Code expires after (minutes)" required htmlFor="tpl-otp-code_expiration_minutes" error={fieldError('otp.code_expiration_minutes')} hint={`${meta.limits.otp_expiry_min}–${meta.limits.otp_expiry_max} minutes`}>
            <Input
              id="tpl-otp-code_expiration_minutes"
              type="number"
              min={meta.limits.otp_expiry_min}
              max={meta.limits.otp_expiry_max}
              value={o.code_expiration_minutes}
              onChange={(e) => patch({ code_expiration_minutes: Number(e.target.value) })}
            />
          </Field>
          <Field label="Sample code" required htmlFor="tpl-sample-otp_code" error={fieldError('samples.otp_code')} hint="4–15 letters or digits, used for Meta review.">
            <Input id="tpl-sample-otp_code" value={s.samples.otp_code ?? ''} onChange={(e) => setSample('otp_code', e.target.value.replace(/\s+/g, ''))} className="font-mono" placeholder="482913" />
          </Field>
        </div>
        <Toggle checked={o.add_security_recommendation} onChange={(v) => patch({ add_security_recommendation: v })} label="Add security recommendation" description="Appends “For your security, do not share this code.”" />
        <Field label="Button">
          <RadioGroup
            name="otp-type"
            value={o.otp_type}
            onChange={(v) => patch({ otp_type: v as 'COPY_CODE' | 'ONE_TAP' })}
            options={[
              { value: 'COPY_CODE', label: 'Copy code' },
              { value: 'ONE_TAP', label: 'One-tap autofill (Android app)' },
            ]}
          />
        </Field>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Button text" htmlFor="tpl-otp-button_text" error={fieldError('otp.button_text')}>
            <Input id="tpl-otp-button_text" value={o.button_text} maxLength={meta.limits.button_text} onChange={(e) => patch({ button_text: e.target.value })} />
          </Field>
          {o.otp_type === 'ONE_TAP' && (
            <Field label="Autofill text" htmlFor="tpl-otp-autofill_text">
              <Input id="tpl-otp-autofill_text" value={o.autofill_text ?? 'Autofill'} maxLength={25} onChange={(e) => patch({ autofill_text: e.target.value })} />
            </Field>
          )}
        </div>
        {o.otp_type === 'ONE_TAP' && (
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Android package name" required htmlFor="tpl-otp-package_name" error={fieldError('otp.package_name')}>
              <Input id="tpl-otp-package_name" value={o.package_name ?? ''} onChange={(e) => patch({ package_name: e.target.value.trim() })} className="font-mono" placeholder="in.ac.gimt.portal" />
            </Field>
            <Field label="App signature hash" required htmlFor="tpl-otp-signature_hash" error={fieldError('otp.signature_hash')}>
              <Input id="tpl-otp-signature_hash" value={o.signature_hash ?? ''} maxLength={11} onChange={(e) => patch({ signature_hash: e.target.value.trim() })} className="font-mono" placeholder="K8a/AINcGX7" />
            </Field>
          </div>
        )}
        <p className="text-xs text-slate-500 dark:text-slate-400">
          Interakt documents template creation for Utility and Marketing only. If an authentication submission is refused, create the OTP template in the Interakt dashboard and use “Import from Interakt” in the library.
        </p>
      </CardBody>
    </Card>
  );
}

const LEVEL_UI = {
  error: { icon: XCircle, cls: 'text-red-600 dark:text-red-400', title: 'Must fix to save' },
  submit: { icon: AlertTriangle, cls: 'text-amber-600 dark:text-amber-400', title: 'Required for Meta approval' },
  warning: { icon: Info, cls: 'text-slate-500 dark:text-slate-400', title: 'Suggestions' },
} as const;

function ComplianceCard({ issues, counts, checking, attempted, error }: { issues: TplIssue[]; counts: Record<'error' | 'submit' | 'warning', number>; checking: boolean; attempted: boolean; error: ApiError | null }) {
  const ready = !counts.error && !counts.submit;
  return (
    <Card>
      <CardHeader
        title="Meta compliance"
        subtitle="Checked by GIMT against WhatsApp template rules"
        icon={ShieldCheck}
        actions={checking ? <Loader2 size={16} className="animate-spin text-slate-400" aria-label="Checking" /> : undefined}
      />
      <CardBody className="space-y-3">
        {error ? (
          <p className="text-sm text-red-600 dark:text-red-400">Could not check the template: {error.message}</p>
        ) : ready ? (
          <div className="flex items-start gap-2.5 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
            <CheckCircle2 size={18} className="mt-0.5 shrink-0" aria-hidden />
            <p>
              <span className="font-semibold">Ready to submit.</span> {counts.warning ? `${counts.warning} suggestion(s) below.` : 'No issues found.'}
            </p>
          </div>
        ) : (
          <div className={clsx('flex items-start gap-2.5 rounded-xl p-3 text-sm', counts.error ? 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100')}>
            <CircleAlert size={18} className="mt-0.5 shrink-0" aria-hidden />
            <p>{counts.error ? `${counts.error} item(s) must be fixed before saving.` : `${counts.submit} item(s) to complete before submitting${attempted ? '' : ' — you can still save the draft'}.`}</p>
          </div>
        )}
        {(['error', 'submit', 'warning'] as const).map((lvl) => {
          const list = issues.filter((i) => i.level === lvl);
          if (!list.length) return null;
          const ui = LEVEL_UI[lvl];
          return (
            <div key={lvl}>
              <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{ui.title}</p>
              <ul className="space-y-1">
                {list.map((i, n) => (
                  <li key={n}>
                    <button
                      type="button"
                      onClick={() => focusField(i.field)}
                      className="flex w-full items-start gap-2 rounded-lg px-1.5 py-1 text-left text-[13px] text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                      <ui.icon size={14} className={clsx('mt-0.5 shrink-0', ui.cls)} aria-hidden />
                      <span>{i.message}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          );
        })}
      </CardBody>
    </Card>
  );
}

function MetaFormatCard({ v }: { v: TplValidation }) {
  const [open, setOpen] = useState(false);
  const vars = v.compiled.variables;
  const rows = [
    ...(vars?.header ?? []).map((x) => ({ part: 'Header', pos: `{{${x.pos}}}`, key: x.key, sample: x.sample })),
    ...(vars?.body ?? []).map((x) => ({ part: 'Body', pos: `{{${x.pos}}}`, key: x.key, sample: x.sample })),
    ...Object.entries(vars?.buttons ?? {}).map(([i, x]) => ({ part: `Button ${Number(i) + 1}`, pos: '{{1}}', key: x.key, sample: x.sample })),
  ];
  return (
    <Card>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-5 py-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500"
      >
        <Braces size={16} className="text-brand-700 dark:text-brand-300" aria-hidden />
        <span className="flex-1">
          <span className="block text-sm font-semibold text-slate-900 dark:text-white">Meta format & variable mapping</span>
          <span className="block text-xs text-slate-500 dark:text-slate-400">What Interakt receives ({rows.length} variable{rows.length === 1 ? '' : 's'})</span>
        </span>
        <ChevronDown size={16} className={clsx('text-slate-400 transition', open && 'rotate-180')} aria-hidden />
      </button>
      {open && (
        <div className="space-y-3 border-t border-slate-100 px-5 py-4 dark:border-slate-800">
          {v.compiled.header_text && (
            <pre className="whitespace-pre-wrap rounded-lg bg-slate-50 p-2.5 font-mono text-[12px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{v.compiled.header_text}</pre>
          )}
          <pre className="max-h-56 overflow-y-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-2.5 font-mono text-[12px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{v.compiled.body || '—'}</pre>
          {rows.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">
                  <tr>
                    <th className="py-1 pr-2 font-semibold">Where</th>
                    <th className="py-1 pr-2 font-semibold">Meta</th>
                    <th className="py-1 pr-2 font-semibold">GIMT variable</th>
                    <th className="py-1 font-semibold">Sample</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {rows.map((r, i) => (
                    <tr key={i} className="text-slate-700 dark:text-slate-200">
                      <td className="py-1 pr-2">{r.part}</td>
                      <td className="py-1 pr-2 font-mono">{r.pos}</td>
                      <td className="py-1 pr-2 font-mono text-accent-700 dark:text-accent-300">{r.key}</td>
                      <td className="max-w-[8rem] truncate py-1" title={r.sample}>
                        {r.sample || <span className="text-amber-600">missing</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

/* ================================================================== submitted / approved: mapping only */

function LockedTemplate({ meta, detail }: { meta: TplMeta; detail: TplDetail }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const navigate = useNavigate();
  const actions = useTplActions({ onDeleted: () => navigate('/whatsapp/templates') });
  const t = detail.template;
  const [displayName, setDisplayName] = useState(t.display_name ?? '');
  const [description, setDescription] = useState(t.description ?? '');
  const [mediaUrl, setMediaUrl] = useState(t.header_media_url ?? '');
  const [mediaName, setMediaName] = useState(t.header_media_name ?? '');
  const [vars, setVars] = useState<TplVariables>(() => JSON.parse(JSON.stringify(t.variables)) as TplVariables);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [showVars, setShowVars] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const canEdit = can('whatsapp_templates', 'edit');
  const catalogKeys = meta.catalog.map((c) => c.key);
  const isMedia = t.header_type === 'image' || t.header_type === 'video' || t.header_type === 'document';

  // preview from the edited mapping
  const named = useMemo(() => {
    const samples: Record<string, string> = {};
    const toNamed = (text: string | null, list: TplVariables['header']) =>
      String(text ?? '').replace(/\{\{\s*(\d+)\s*\}\}/g, (_all, p: string) => {
        const v = list.find((x) => Number(x.pos) === Number(p));
        const key = v?.key || `var_${p}`;
        if (v?.sample) samples[key] = v.sample;
        return `{{${key}}}`;
      });
    const header = toNamed(t.header_text, vars.header);
    const body = toNamed(t.body, vars.body);
    const buttons = t.buttons.map((b, i) => {
      const bv = vars.buttons[String(i)];
      if (b.type === 'URL' && bv && b.url?.includes('{{')) {
        if (bv.sample) samples[bv.key] = bv.sample;
        return { ...b, url: b.url.replace(/\{\{\s*\d+\s*\}\}/, `{{${bv.key}}}`) };
      }
      return b;
    });
    return { header, body, buttons, samples };
  }, [t, vars]);

  const preview = previewData(
    {
      category: t.category,
      header_type: t.header_type,
      header_text: named.header,
      header_media_url: mediaUrl,
      header_media_name: mediaName,
      body: named.body,
      footer: t.footer ?? '',
      buttons: named.buttons,
      otp: { code_expiration_minutes: 10, add_security_recommendation: true, otp_type: 'COPY_CODE', button_text: 'Copy code', ...(t.otp_settings ?? {}) },
    },
    named.samples,
    showVars,
  );

  const setVar = (part: 'header' | 'body', pos: number, patch: { key?: string; sample?: string }) =>
    setVars((v) => ({ ...v, [part]: v[part].map((x) => (Number(x.pos) === pos ? { ...x, ...patch } : x)) }));
  const setBtnVar = (idx: string, patch: { key?: string; sample?: string }) => setVars((v) => ({ ...v, buttons: { ...v.buttons, [idx]: { ...v.buttons[idx], ...patch } } }));

  const save = async () => {
    setSaving(true);
    try {
      const res = await api.put<TplSaveResult>(`whatsapp/templates/${t.id}`, { display_name: displayName, description, header_media_url: mediaUrl, header_media_name: mediaName, variables: vars });
      toast.success(res.message);
      setErrors({});
      await refresh('templates');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const onFile = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (file) void uploadFile(file);
  };
  const uploadFile = async (file: File) => {
    if (!isMedia || !canEdit) return;
    const spec = meta.limits.media[t.header_type as 'image'];
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!spec.ext.includes(ext) && !spec.mime.includes(file.type)) {
      setErrors((x) => ({ ...x, header_media_url: `${spec.label} headers accept ${spec.ext.filter((v) => v !== 'jpeg').map((v) => v.toUpperCase()).join(' / ')} files only.` }));
      return;
    }
    if (file.size > spec.max_mb * 1048576) {
      setErrors((x) => ({ ...x, header_media_url: `${spec.label} files can be at most ${spec.max_mb} MB.` }));
      return;
    }
    setUploading(true);
    try {
      const up = await waUploadMedia(file, t.header_type as 'image' | 'video' | 'document');
      setMediaUrl(up.url);
      setMediaName(up.name || file.name);
      setErrors(({ header_media_url: _omit, ...rest }) => rest);
    } catch (err) {
      setErrors((x) => ({ ...x, header_media_url: (err as ApiError).message }));
    } finally {
      setUploading(false);
    }
  };

  const varRows: { part: 'header' | 'body' | 'button'; idx: string; pos: number; key: string; sample: string }[] = [
    ...vars.header.map((x) => ({ part: 'header' as const, idx: '', pos: Number(x.pos), key: x.key, sample: x.sample ?? '' })),
    ...vars.body.map((x) => ({ part: 'body' as const, idx: '', pos: Number(x.pos), key: x.key, sample: x.sample ?? '' })),
    ...Object.entries(vars.buttons).map(([i, x]) => ({ part: 'button' as const, idx: i, pos: 1, key: x.key, sample: x.sample ?? '' })),
  ];
  const statusTone = t.status === 'rejected' || t.status === 'disabled' ? 'error' : t.status === 'approved' ? 'success' : t.status === 'archived' ? 'info' : 'warning';
  // send test / simulate review / duplicate / archive / delete (same rules as the library menu); the header buttons
  // above already cover new version, restore and save
  const moreItems = tplMenuItems(t, actions, can, { canSimulate: meta.can_simulate }).filter(
    (i) => !['Map variables', 'Create new version', 'Restore'].includes(String(i.label)),
  );
  moreItems.unshift({ label: 'Open in library', icon: Library, to: `/whatsapp/templates?view=${t.id}` });

  return (
    <>
      <PageHeader
        title={t.display_name || t.name}
        description="Submitted templates are locked by Meta. Update the variable mapping and default media used by GIMT, or create a new version to change the wording."
        breadcrumbs={[...CRUMBS, { label: t.name }]}
        actions={
          <div className="flex flex-wrap gap-2">
            {t.status === 'archived' && canEdit && (
              <Button variant="secondary" icon={ArchiveRestore} onClick={() => void actions.restore(t)} loading={actions.busy === `restore-${t.id}`}>
                Restore
              </Button>
            )}
            {t.status !== 'archived' && can('whatsapp_templates', 'create') && (
              <Button variant="secondary" icon={GitBranchPlus} onClick={() => void actions.duplicate(t, true)} loading={actions.busy === `dup-${t.id}`}>
                New version
              </Button>
            )}
            {canEdit && (
              <Button icon={Save} onClick={() => void save()} loading={saving} disabled={uploading}>
                Save mapping
              </Button>
            )}
            {moreItems.length > 0 && <Dropdown label="More actions" align="right" triggerClassName="btn btn-secondary px-3" trigger={<MoreHorizontal size={16} aria-hidden />} items={moreItems} />}
          </div>
        }
      />
      <ModeBanner />
      <Alert
        variant={statusTone}
        className="mb-5"
        title={
          <span className="flex flex-wrap items-center gap-2">
            {STATUS_LABELS[t.status]} <Badge color={STATUS_COLORS[t.status]}>{t.name}</Badge>
            {t.synced_at && <span className="text-xs font-normal opacity-80">synced {timeAgo(t.synced_at)}</span>}
          </span>
        }
      >
        {t.status === 'rejected' && t.rejection_reason ? (
          <>
            <strong>Meta’s reason:</strong> {t.rejection_reason} — create a new version, fix the wording and submit it again.
          </>
        ) : t.status === 'pending' ? (
          'Waiting for Meta review. The wording cannot change while it is under review.'
        ) : t.status === 'archived' ? (
          `Archived in GIMT${t.archived_at ? ` ${timeAgo(t.archived_at)}` : ''}: hidden from template pickers and never sent. Restore it to use it again${t.interakt_id ? ' — it still exists in Interakt (delete it in the Interakt dashboard if it is no longer needed)' : ''}.`
        ) : t.status === 'paused' ? (
          'Meta paused this template after low-quality feedback. Sends fail until Meta re-activates it; improve the wording in a new version.'
        ) : t.status === 'disabled' ? (
          'Meta disabled this template. Sends using it fail; create a new version with improved wording.'
        ) : (
          'Interakt has no edit API, so the approved wording is read-only here. Edits and deletion happen in the Interakt dashboard; in GIMT you can archive it or create a new version.'
        )}
      </Alert>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_400px]">
        <div className="min-w-0 space-y-5">
          <Card>
            <CardHeader title="Details" icon={FileText} actions={<Badge color={CATEGORY_TONE[t.category].badge}>{CATEGORY_TONE[t.category].label}</Badge>} />
            <CardBody className="space-y-4">
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Display name" htmlFor="lk-display">
                  <Input id="lk-display" value={displayName} onChange={(e) => setDisplayName(e.target.value)} disabled={!canEdit} maxLength={190} />
                </Field>
                <Field label="Template name (code)" htmlFor="lk-name" hint={`${meta.languages[t.language]?.label ?? t.language} · Interakt id ${t.interakt_id ?? '—'}`}>
                  <Input id="lk-name" value={t.name} readOnly className="font-mono" />
                </Field>
              </div>
              <Field label="Description" htmlFor="lk-description">
                <Input id="lk-description" value={description} onChange={(e) => setDescription(e.target.value)} disabled={!canEdit} maxLength={255} />
              </Field>
              <div>
                <p className="form-label">Approved content</p>
                <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3.5 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-100">
                  {t.header_type === 'text' && t.header_text && <p className="font-semibold"><WaText text={named.header} highlightVars /></p>}
                  {isMedia && <p className="text-xs text-slate-500 dark:text-slate-400">Header: {t.header_type}</p>}
                  <WaText text={t.category === 'AUTHENTICATION' ? t.body : named.body} highlightVars />
                  {t.footer && <p className="text-xs text-slate-500 dark:text-slate-400">{t.footer}</p>}
                  {t.buttons.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 pt-1">
                      {t.buttons.map((b, i) => (
                        <Badge key={i} color="slate">
                          {b.text} {b.url ? `→ ${b.url}` : b.phone_number ? `→ ${b.phone_number}` : ''}
                        </Badge>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </CardBody>
          </Card>

          {isMedia && (
            <Card>
              <CardHeader title="Default header media" subtitle="Sent when an event or campaign does not provide its own file." icon={ImageIcon} />
              <CardBody>
                <MediaField
                  kind={t.header_type as 'image'}
                  spec={meta.limits.media[t.header_type as 'image']}
                  label={`Default ${meta.limits.media[t.header_type as 'image'].label.toLowerCase()}`}
                  url={mediaUrl}
                  name={mediaName}
                  uploading={uploading}
                  disabled={!canEdit}
                  error={errors.header_media_url}
                  onPick={() => canEdit && fileRef.current?.click()}
                  onDropFile={(f) => void uploadFile(f)}
                  onRemove={() => {
                    setMediaUrl('');
                    setMediaName('');
                  }}
                />
                <input ref={fileRef} type="file" className="hidden" accept={meta.limits.media[t.header_type as 'image'].accept} onChange={onFile} aria-hidden tabIndex={-1} />
              </CardBody>
            </Card>
          )}

          <Card>
            <CardHeader title="Variable mapping" subtitle="Which GIMT field fills each Meta position when GIMT sends this template." icon={Braces} />
            <CardBody>
              {varRows.length === 0 ? (
                <p className="text-sm text-slate-500 dark:text-slate-400">This template has no variables.</p>
              ) : (
                <div className="space-y-3">
                  <datalist id="tpl-catalog-keys">
                    {catalogKeys.map((k) => (
                      <option key={k} value={k} />
                    ))}
                  </datalist>
                  {varRows.map((r) => {
                    const errKey = r.part === 'button' ? `variables.buttons.${r.idx}` : `variables.${r.part}.${r.pos}`;
                    const fid = `map-${r.part}-${r.idx || r.pos}`;
                    return (
                      <div key={fid} className="grid items-start gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-[7rem_minmax(0,1fr)_minmax(0,1fr)] dark:border-slate-700">
                        <div className="pt-1.5">
                          <span className="font-mono text-sm font-semibold text-slate-900 dark:text-white">{`{{${r.pos}}}`}</span>
                          <span className="block text-[11px] capitalize text-slate-500 dark:text-slate-400">{r.part === 'button' ? `Button ${Number(r.idx) + 1} link` : r.part}</span>
                        </div>
                        <Field
                          label="GIMT variable"
                          htmlFor={`${fid}-key`}
                          error={errors[errKey]}
                          hint={
                            r.part !== 'button' ? undefined : isLinkValuedKey(r.key) ? (
                              <span className="flex items-start gap-1.5 text-amber-700 dark:text-amber-300" role="status">
                                <AlertTriangle size={13} className="mt-px shrink-0" aria-hidden />
                                <span>This variable holds a whole link, but WhatsApp adds the value to the end of the button address — pick one that completes it (e.g. receipt_no).</span>
                              </span>
                            ) : (
                              'Its value is added to the end of the button link.'
                            )
                          }
                        >
                          <Input
                            id={`${fid}-key`}
                            inputSize="sm"
                            list="tpl-catalog-keys"
                            className="font-mono"
                            value={r.key}
                            disabled={!canEdit}
                            invalid={!!errors[errKey]}
                            onChange={(e) => {
                              const key = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
                              if (r.part === 'button') setBtnVar(r.idx, { key });
                              else setVar(r.part, r.pos, { key });
                            }}
                          />
                        </Field>
                        <Field label="Sample" htmlFor={`${fid}-sample`}>
                          <Input
                            id={`${fid}-sample`}
                            inputSize="sm"
                            value={r.sample}
                            disabled={!canEdit}
                            onChange={(e) => (r.part === 'button' ? setBtnVar(r.idx, { sample: e.target.value }) : setVar(r.part, r.pos, { sample: e.target.value }))}
                          />
                        </Field>
                      </div>
                    );
                  })}
                  <p className="text-xs text-slate-500 dark:text-slate-400">Pick a key from the GIMT catalogue (type to search) or name a value the sending event / campaign provides.</p>
                </div>
              )}
            </CardBody>
          </Card>
        </div>

        <aside className="min-w-0 space-y-5 lg:sticky lg:top-20 lg:self-start">
          <Card>
            <CardHeader title="Preview" subtitle="With the mapping and samples above" icon={BadgeCheck} />
            <CardBody>
              <TemplatePreview data={preview} showVars={showVars} onToggleVars={setShowVars} businessName={meta.business_name} height={520} />
            </CardBody>
          </Card>
        </aside>
      </div>
      {actions.dialogs}
    </>
  );
}

