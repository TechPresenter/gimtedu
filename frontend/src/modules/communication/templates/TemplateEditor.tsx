import { useEffect, useMemo, useRef, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { Braces, Copy, Lock, Mail, MessageCircle, MessageSquareText, Save } from 'lucide-react';
import { Alert, Badge, Button, Field, Input, Modal, PageLoader, RichText, Select, Tabs, Textarea, useToast } from '@/components/ui';
import type { CrudRecordPayload } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { EmailFrame, copyText, type ChannelsState } from '../shared';

export interface Placeholder {
  key: string;
  label: string;
  group: string;
  sample: string;
}

export interface PlaceholderCatalog {
  placeholders: Placeholder[];
  system_codes: string[];
  channels: ChannelsState;
}

export interface TemplatePreviewResult {
  subject: string | null;
  html: string | null;
  inner: string;
  text: string;
  length?: number;
  segments?: number;
  used: string[];
  unknown: string[];
}

interface Form {
  name: string;
  code: string;
  channel: string;
  status: string;
  subject: string;
  body_html: string;
}

const EMPTY: Form = { name: '', code: '', channel: 'email', status: 'active', subject: '', body_html: '' };

interface Props {
  open: boolean;
  id: number | null;
  onClose: () => void;
  onSaved: (id: number) => void;
}

/** Create / edit a message template with a placeholder reference and a live preview using sample data. */
export function TemplateEditor({ open, id, onClose, onSaved }: Props) {
  const toast = useToast();
  const { data: catalog } = useApi<PlaceholderCatalog>(['comm', 'placeholders'], 'communication/templates/placeholders', undefined, { staleTime: 10 * 60 * 1000, enabled: open });
  const [f, setF] = useState<Form>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editorKey, setEditorKey] = useState(0);
  const [side, setSide] = useState<'preview' | 'placeholders'>('preview');
  const textRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    setSide('preview');
    if (!id) {
      setF(EMPTY);
      setEditorKey((k) => k + 1);
      return;
    }
    setLoading(true);
    api
      .get<CrudRecordPayload>(`crud/email_templates/${id}`)
      .then((p) => {
        const v = p.values;
        setF({ name: String(v.name ?? ''), code: String(v.code ?? ''), channel: String(v.channel ?? 'email'), status: String(v.status ?? 'active'), subject: String(v.subject ?? ''), body_html: String(v.body_html ?? '') });
        setEditorKey((k) => k + 1);
      })
      .catch((e: ApiError) => setFormError(e.message))
      .finally(() => setLoading(false));
  }, [open, id]);

  const isSystem = !!id && !!catalog?.system_codes.includes(f.code);
  const set = <K extends keyof Form>(k: K, v: Form[K]) => {
    setF((s) => ({ ...s, [k]: v }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };

  const previewKey = useDebounce(JSON.stringify({ channel: f.channel, subject: f.subject, body_html: f.body_html }), 500);
  const { data: preview } = useQuery({
    queryKey: ['comm', 'tpl-preview', previewKey],
    queryFn: () => api.post<TemplatePreviewResult>('communication/templates/preview', JSON.parse(previewKey)).then((r) => r.data),
    enabled: open && f.body_html.trim() !== '',
    placeholderData: keepPreviousData,
  });

  const groups = useMemo(() => {
    const g: Record<string, Placeholder[]> = {};
    (catalog?.placeholders ?? []).forEach((p) => (g[p.group] = [...(g[p.group] ?? []), p]));
    return Object.entries(g);
  }, [catalog]);

  const insert = async (key: string) => {
    const token = `{{${key}}}`;
    if (f.channel !== 'email' && textRef.current) {
      const el = textRef.current;
      const start = el.selectionStart ?? f.body_html.length;
      const end = el.selectionEnd ?? start;
      const next = f.body_html.slice(0, start) + token + f.body_html.slice(end);
      set('body_html', next);
      requestAnimationFrame(() => {
        el.focus();
        el.setSelectionRange(start + token.length, start + token.length);
      });
      return;
    }
    if (await copyText(token)) toast.info(`Copied ${token} — paste it into the subject or body.`);
  };

  const save = async () => {
    const clientErr: Record<string, string> = {};
    if (!f.name.trim()) clientErr.name = 'Template name is required.';
    if (!id && !/^[a-z0-9_-]+$/i.test(f.code)) clientErr.code = 'Use letters, numbers, dashes and underscores only.';
    if (f.channel === 'email' && !f.subject.trim()) clientErr.subject = 'Subject is required for e-mail templates.';
    if (!f.body_html.replace(/<[^>]+>/g, '').trim()) clientErr.body_html = 'Write the template body.';
    if (Object.keys(clientErr).length) {
      setErrors(clientErr);
      setFormError('Please fix the highlighted fields.');
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const payload: Record<string, unknown> = { name: f.name, channel: f.channel, status: f.status, subject: f.subject, body_html: f.body_html, variables: (preview?.used ?? []).join(',') };
      if (!id) payload.code = f.code.toLowerCase();
      const res = await api.post<{ id: number }>(id ? `crud/email_templates/${id}` : 'crud/email_templates', payload);
      toast.success(res.message || 'Template saved.');
      onSaved(res.data.id);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };

  const channelOptions = [
    { value: 'email', label: 'E-mail' },
    { value: 'sms', label: 'SMS' },
    { value: 'whatsapp', label: 'WhatsApp' },
  ];
  const ChannelIcon = f.channel === 'sms' ? MessageSquareText : f.channel === 'whatsapp' ? MessageCircle : Mail;

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="2xl"
      title={id ? 'Edit template' : 'New template'}
      description={isSystem ? 'This is a system template used automatically by the ERP — keep its placeholders intact.' : 'Use {{placeholders}} for values that change per recipient.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button icon={Save} loading={saving} disabled={loading} onClick={() => void save()}>
            {id ? 'Save template' : 'Create template'}
          </Button>
        </>
      }
    >
      {loading ? (
        <PageLoader label="Loading template…" />
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
          <form
            className="space-y-4 lg:col-span-3"
            noValidate
            onSubmit={(e) => {
              e.preventDefault();
              void save();
            }}
          >
            {formError && <Alert variant="error">{formError}</Alert>}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
              <Field className="sm:col-span-6" label="Template name" required htmlFor="t-name" error={errors.name}>
                <Input id="t-name" value={f.name} maxLength={150} onChange={(e) => set('name', e.target.value)} invalid={!!errors.name} placeholder="Fee reminder" />
              </Field>
              <Field className="sm:col-span-6" label="Code" required={!id} htmlFor="t-code" error={errors.code} hint={id ? 'The code cannot be changed — the system looks templates up by code.' : 'Lower-case, e.g. fee_reminder_hostel'}>
                <Input id="t-code" value={f.code} maxLength={60} disabled={!!id} onChange={(e) => set('code', e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, '_'))} invalid={!!errors.code} className="font-mono" suffix={isSystem ? <Lock className="h-3.5 w-3.5" /> : undefined} />
              </Field>
              <Field className="sm:col-span-6" label="Channel" htmlFor="t-channel" error={errors.channel}>
                <Select
                  id="t-channel"
                  value={f.channel}
                  onChange={(e) => {
                    set('channel', e.target.value);
                    if (e.target.value !== 'email') set('body_html', f.body_html.replace(/<\/(p|li|h\d)>/gi, '\n').replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '').replace(/\n{3,}/g, '\n\n').trim());
                    setEditorKey((k) => k + 1);
                  }}
                  options={channelOptions}
                />
              </Field>
              <Field className="sm:col-span-6" label="Status" htmlFor="t-status">
                <Select id="t-status" value={f.status} onChange={(e) => set('status', e.target.value)} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
              </Field>
              {f.channel === 'email' && (
                <Field className="sm:col-span-12" label="Subject" required htmlFor="t-subject" error={errors.subject}>
                  <Input id="t-subject" value={f.subject} maxLength={255} onChange={(e) => set('subject', e.target.value)} invalid={!!errors.subject} placeholder="Fee payment reminder — {{title}}" />
                </Field>
              )}
              <Field
                className="sm:col-span-12"
                label={f.channel === 'email' ? 'Body' : 'Message'}
                required
                htmlFor="t-body"
                error={errors.body_html}
                aside={f.channel !== 'email' ? `${f.body_html.length}/1000` : undefined}
              >
                {f.channel === 'email' ? (
                  <RichText key={editorKey} id="t-body" value={f.body_html} onChange={(v) => set('body_html', v)} invalid={!!errors.body_html} minHeight={260} />
                ) : (
                  <Textarea ref={textRef} id="t-body" rows={7} maxLength={1000} value={f.body_html} onChange={(e) => set('body_html', e.target.value)} invalid={!!errors.body_html} placeholder="Dear {{name}}, …" />
                )}
              </Field>
            </div>
            {preview && preview.used.length > 0 && (
              <div className="flex flex-wrap items-center gap-1.5 text-xs">
                <span className="font-medium text-slate-500 dark:text-slate-400">Placeholders used:</span>
                {preview.used.map((u) => (
                  <Badge key={u} color={preview.unknown.includes(u) ? 'amber' : 'slate'} className="font-mono">{`{{${u}}}`}</Badge>
                ))}
              </div>
            )}
            {preview && preview.unknown.length > 0 && (
              <Alert variant="warning">
                {preview.unknown.map((u) => `{{${u}}}`).join(', ')} {preview.unknown.length === 1 ? 'is' : 'are'} not a known placeholder. Make sure the code that sends this template provides {preview.unknown.length === 1 ? 'it' : 'them'}.
              </Alert>
            )}
          </form>

          <div className="lg:col-span-2">
            <Tabs
              tabs={[
                { key: 'preview', label: 'Preview', icon: ChannelIcon },
                { key: 'placeholders', label: 'Placeholders', icon: Braces },
              ]}
              value={side}
              onChange={(k) => setSide(k as 'preview' | 'placeholders')}
              className="mb-3"
            />
            {side === 'preview' ? (
              f.channel === 'email' ? (
                <>
                  <div className="mb-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800/60">
                    <span className="text-slate-500">Subject: </span>
                    <span className="font-medium text-slate-900 dark:text-white">{preview?.subject || '—'}</span>
                  </div>
                  <EmailFrame html={f.body_html.trim() ? preview?.html : null} className="h-[420px]" />
                  <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Rendered with sample data inside the institute e-mail layout.</p>
                </>
              ) : (
                <div className="rounded-2xl bg-slate-100 p-4 dark:bg-slate-800">
                  <div className="max-w-[90%] whitespace-pre-wrap rounded-2xl rounded-tl-sm bg-white px-4 py-3 text-sm text-slate-800 shadow-sm dark:bg-slate-900 dark:text-slate-100">
                    {f.body_html.trim() ? preview?.text : <span className="text-slate-400">Your message preview appears here.</span>}
                  </div>
                  {preview?.length !== undefined && f.body_html.trim() && (
                    <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{preview.length} characters with sample data · {preview.segments} SMS segment{preview.segments === 1 ? '' : 's'}</p>
                  )}
                </div>
              )
            ) : (
              <div className="max-h-[480px] space-y-4 overflow-y-auto pr-1">
                <p className="text-xs text-slate-500 dark:text-slate-400">{f.channel === 'email' ? 'Click to copy, then paste into the subject or body.' : 'Click to insert at the cursor.'} Sample values are used in previews and tests.</p>
                {groups.map(([group, items]) => (
                  <div key={group}>
                    <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{group}</p>
                    <ul className="space-y-1">
                      {items.map((p) => (
                        <li key={p.key}>
                          <button
                            type="button"
                            onClick={() => void insert(p.key)}
                            className={clsx('group flex w-full items-start gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-brand-50 dark:hover:bg-brand-500/10')}
                          >
                            <code className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-700 group-hover:bg-white dark:bg-slate-800 dark:text-slate-200">{`{{${p.key}}}`}</code>
                            <span className="min-w-0 flex-1 text-xs">
                              <span className="block text-slate-700 dark:text-slate-200">{p.label}</span>
                              <span className="block truncate text-slate-400" title={p.sample}>e.g. {p.sample.replace(/<[^>]+>/g, '')}</span>
                            </span>
                            <Copy className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300 group-hover:text-brand-600" />
                          </button>
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}
