import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { FileText, LayoutTemplate, Send, Upload } from 'lucide-react';
import { Alert, Badge, Button, EmptyState, Field, Input, Modal, SearchInput, Skeleton } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { WaBubble, type WaTemplatePreviewData } from '../shared';
import type { PickerTemplate, SendPayload, TemplateField } from './types';
import { ATTACH_RULES, type AttachKind, uploadWithProgress, validateAttachment } from './utils';

interface Props {
  open: boolean;
  onClose: () => void;
  convId: number;
  contactName: string;
  windowOpen: boolean;
  onSend: (payload: SendPayload, preview: { body: string; template_name: string; interactive: { template: WaTemplatePreviewData } }) => Promise<unknown>;
}

const fill = (text: string | null | undefined, part: 'header' | 'body', fields: TemplateField[], values: Record<string, string>) =>
  (text ?? '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => {
    const f = fields.find((x) => x.part === part && x.pos === Number(n));
    const v = f ? values[f.key] : '';
    return v ? v : m;
  });

/** Approved-template picker: search, variables auto-filled from the contact's CRM / ERP data, live preview, send. */
export function TemplatePicker({ open, onClose, convId, contactName, windowOpen, onSend }: Props) {
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState<PickerTemplate | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sending, setSending] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [media, setMedia] = useState<{ url: string; name: string; progress: number | null }>({ url: '', name: '', progress: null });
  const fileRef = useRef<HTMLInputElement>(null);
  const mediaKind: AttachKind | null = selected && ['image', 'video', 'document'].includes(selected.header_type) ? (selected.header_type as AttachKind) : null;

  const list = useQuery({
    queryKey: ['wa', 'inbox', 'templates', convId],
    queryFn: ({ signal }) => api.get<PickerTemplate[]>(`whatsapp/inbox/conversations/${convId}/templates`, undefined, { signal }),
    enabled: open,
    staleTime: 60_000,
  });

  useEffect(() => {
    if (!open) {
      setSelected(null);
      setQ('');
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  const choose = (t: PickerTemplate) => {
    setSelected(t);
    setValues(Object.fromEntries(t.fields.map((f) => [f.key, f.value ?? ''])));
    setMedia({ url: t.header_media_url ?? '', name: '', progress: null });
    setErrors({});
    setFormError(null);
  };

  // one input per GIMT variable (a variable can fill the header and a body slot); vars are still built per slot
  const inputs = useMemo(() => (selected ? selected.fields.filter((f, i, a) => a.findIndex((x) => x.key === f.key) === i) : []), [selected]);
  const slotHint = (f: TemplateField) =>
    (selected?.fields ?? [])
      .filter((x) => x.key === f.key)
      .map((x) => (x.part === 'button' ? `Button ${x.pos + 1} URL` : `${x.part === 'header' ? 'Header' : 'Body'} {{${x.pos}}}`))
      .join(' · ');

  const rows = useMemo(() => {
    const s = q.trim().toLowerCase();
    const all = list.data ?? [];
    return s ? all.filter((t) => `${t.display_name} ${t.name} ${t.body} ${t.category}`.toLowerCase().includes(s)) : all;
  }, [list.data, q]);

  // server render (exact text the contact will receive), debounced
  const debouncedValues = useDebounce(values, 400);
  const vars = useMemo(() => {
    if (!selected) return null;
    const header: string[] = [];
    const body: string[] = [];
    const buttons: Record<string, string[]> = {};
    selected.fields.forEach((f) => {
      const v = (debouncedValues[f.key] ?? '').trim();
      if (f.part === 'header') header[f.pos - 1] = v;
      else if (f.part === 'body') body[f.pos - 1] = v;
      else buttons[String(f.pos)] = [v];
    });
    return { header: mediaKind ? [media.url] : Array.from(header, (x) => x ?? ''), body: Array.from(body, (x) => x ?? ''), buttons };
  }, [selected, debouncedValues, mediaKind, media.url]);

  const preview = useQuery({
    queryKey: ['wa', 'inbox', 'tpl-preview', convId, selected?.id, vars],
    queryFn: ({ signal }) => api.post<{ text: string; missing: string[] }>(`whatsapp/inbox/conversations/${convId}/template-preview`, { template_id: selected?.id, vars }, { signal }).then((r) => r.data),
    enabled: open && !!selected && !!vars,
    staleTime: 60_000,
  });

  const localPreview: WaTemplatePreviewData | null = selected
    ? {
        header_type: selected.header_type,
        header_text: fill(selected.header_text, 'header', selected.fields, values),
        header_media_url: media.url || selected.header_media_url,
        file_name: media.name || null,
        body: fill(selected.body, 'body', selected.fields, values),
        footer: selected.footer,
        buttons: selected.buttons,
      }
    : null;

  const submit = async () => {
    if (!selected || !localPreview) return;
    const errs: Record<string, string> = {};
    selected.fields.forEach((f) => {
      if (!(values[f.key] ?? '').trim()) errs[f.key] = 'Required';
    });
    if (mediaKind && !/^https?:\/\//i.test(media.url.trim())) errs.header_media = 'Upload a file or paste a public https link.';
    setErrors(errs);
    if (Object.keys(errs).length) {
      setFormError('Fill every variable before sending.');
      return;
    }
    const header: string[] = [];
    const body: string[] = [];
    const buttons: Record<string, string[]> = {};
    selected.fields.forEach((f) => {
      const v = values[f.key].trim();
      if (f.part === 'header') header[f.pos - 1] = v;
      else if (f.part === 'body') body[f.pos - 1] = v;
      else buttons[String(f.pos)] = [v];
    });
    setSending(true);
    setFormError(null);
    try {
      await onSend(
        {
          type: 'template',
          template_id: selected.id,
          vars: { header: mediaKind ? [media.url.trim()] : Array.from(header, (x) => x ?? ''), body: Array.from(body, (x) => x ?? ''), buttons, file_name: mediaKind === 'document' && media.name ? media.name : undefined },
        },
        { body: localPreview.body, template_name: selected.name, interactive: { template: localPreview } },
      );
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setFormError(err.message);
      if (err.errors) {
        const mapped: Record<string, string> = {};
        Object.entries(err.errors).forEach(([k, v]) => {
          if (k.startsWith('vars.')) mapped[k.slice(5)] = v;
          else if (k === 'header_media') mapped.header_media = v;
        });
        setErrors((p) => ({ ...p, ...mapped }));
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title="Send a template"
      description={windowOpen ? `Approved WhatsApp templates for ${contactName}. Variables are filled from the contact's record — check them before sending.` : `The 24-hour window is closed, so only an approved template can reach ${contactName}.`}
      icon={<span className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300"><LayoutTemplate className="h-5 w-5" aria-hidden /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>Cancel</Button>
          <Button icon={Send} onClick={submit} loading={sending} disabled={!selected}>Send template</Button>
        </>
      }
    >
      {list.error ? (
        <Alert variant="error" title="Unable to load templates" action={<Button size="xs" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>{(list.error as ApiError).message}</Alert>
      ) : (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,280px)_minmax(0,1fr)]">
          <div className="min-w-0">
            <SearchInput value={q} onChange={setQ} placeholder="Search templates…" size="sm" />
            <div className="mt-2 max-h-[26rem] space-y-1 overflow-y-auto pr-1" role="listbox" aria-label="Approved templates">
              {list.isLoading ? (
                Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-14 w-full rounded-lg" />)
              ) : rows.length === 0 ? (
                <EmptyState
                  icon={FileText}
                  title={q ? 'No matching templates' : 'No approved templates'}
                  description={q ? 'Try another word.' : 'Templates must be approved by WhatsApp before they can be sent.'}
                  action={!q ? <Button size="sm" variant="secondary" to="/whatsapp/templates">Template library</Button> : undefined}
                  className="!py-6"
                />
              ) : (
                rows.map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    role="option"
                    aria-selected={selected?.id === t.id}
                    onClick={() => choose(t)}
                    className={clsx(
                      'w-full rounded-lg border px-3 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40',
                      selected?.id === t.id
                        ? 'border-accent-400 bg-accent-50/70 dark:border-accent-500/60 dark:bg-accent-500/10'
                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
                    )}
                  >
                    <span className="flex items-center justify-between gap-2">
                      <span className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{t.display_name}</span>
                      <Badge color={t.category === 'MARKETING' ? 'purple' : t.category === 'AUTHENTICATION' ? 'cyan' : 'blue'}>{t.category.toLowerCase()}</Badge>
                    </span>
                    <span className="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{t.body}</span>
                  </button>
                ))
              )}
            </div>
          </div>

          <div className="min-w-0">
            {!selected ? (
              <div className="flex h-full min-h-[16rem] items-center justify-center rounded-xl border border-dashed border-slate-200 p-6 dark:border-slate-700">
                <EmptyState icon={LayoutTemplate} title="Choose a template" description="Pick a template on the left to fill its variables and preview the message." className="!py-4" />
              </div>
            ) : (
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-3">
                  <div>
                    <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{selected.display_name}</p>
                    <p className="font-mono text-[11px] text-slate-400">{selected.name} · {selected.language}</p>
                  </div>
                  {formError && <Alert variant="error">{formError}</Alert>}
                  {selected.category === 'MARKETING' && (
                    <Alert variant="warning">Marketing template — it is only delivered when the contact has opted in to WhatsApp messages.</Alert>
                  )}
                  {mediaKind && (
                    <Field label={`Header ${ATTACH_RULES[mediaKind].label.toLowerCase()}`} htmlFor="tpl-media" required error={errors.header_media} hint={media.progress !== null ? `Uploading… ${media.progress}%` : `Public https link, or upload a ${ATTACH_RULES[mediaKind].exts.join(' / ')} file.`}>
                      <div className="flex gap-2">
                        <Input id="tpl-media" inputSize="sm" value={media.url} invalid={!!errors.header_media} placeholder="https://…" onChange={(e) => { setMedia({ url: e.target.value, name: '', progress: null }); setErrors((p) => ({ ...p, header_media: '' })); }} />
                        <Button size="sm" variant="secondary" icon={Upload} loading={media.progress !== null} onClick={() => fileRef.current?.click()}>Upload</Button>
                        <input
                          ref={fileRef}
                          type="file"
                          className="hidden"
                          accept={ATTACH_RULES[mediaKind].accept}
                          tabIndex={-1}
                          aria-hidden
                          onChange={(e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            if (!file) return;
                            const err = validateAttachment(mediaKind, file);
                            if (err) {
                              setErrors((p) => ({ ...p, header_media: err }));
                              return;
                            }
                            setMedia((m) => ({ ...m, progress: 0 }));
                            uploadWithProgress(file, mediaKind, (pct) => setMedia((m) => ({ ...m, progress: pct })))
                              .then((up) => {
                                setMedia({ url: up.url, name: up.name, progress: null });
                                setErrors((p) => ({ ...p, header_media: '' }));
                              })
                              .catch((er: Error) => {
                                setMedia((m) => ({ ...m, progress: null }));
                                setErrors((p) => ({ ...p, header_media: er.message }));
                              });
                          }}
                        />
                      </div>
                    </Field>
                  )}
                  {inputs.length === 0 ? (
                    !mediaKind && <p className="text-sm text-slate-500">This template has no variables.</p>
                  ) : (
                    inputs.map((f) => (
                      <Field key={f.key} label={`${f.label}`} htmlFor={`tplv-${f.key}`} required error={errors[f.key]} hint={`${slotHint(f)}${f.sample ? ` · e.g. ${f.sample}` : ''}`}>
                        <Input
                          id={`tplv-${f.key}`}
                          inputSize="sm"
                          value={values[f.key] ?? ''}
                          invalid={!!errors[f.key]}
                          maxLength={f.part === 'header' ? 60 : 1024}
                          onChange={(e) => {
                            const v = e.target.value;
                            setValues((p) => ({ ...p, [f.key]: v }));
                            if (errors[f.key]) setErrors((p) => ({ ...p, [f.key]: '' }));
                          }}
                        />
                      </Field>
                    ))
                  )}
                </div>
                <div className="rounded-xl bg-[#EFEAE2] p-3 dark:bg-[#0B141A]">
                  <p className="mb-2 text-center text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Preview</p>
                  {localPreview && <WaBubble direction="out" type="template" template={localPreview} status="queued" showStatus={false} highlightVars />}
                  {preview.data && preview.data.missing.length > 0 && <p className="mt-2 text-center text-xs text-amber-700 dark:text-amber-300">{preview.data.missing.length} variable(s) still empty</p>}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}
