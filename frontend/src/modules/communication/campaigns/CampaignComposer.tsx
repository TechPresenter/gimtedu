import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { CalendarClock, Copy, FlaskConical, Mail, MessageCircle, MessageSquareText, Save, Send, Users } from 'lucide-react';
import { Alert, Button, Combobox, Field, Input, Modal, PageLoader, RichText, Select, Textarea, useConfirm, useToast } from '@/components/ui';
import type { CrudRecordPayload } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatNumber } from '@/lib/format';
import { CAMPAIGN_AUDIENCES, EmailFrame, copyText, type AudienceSummary, type ChannelsState } from '../shared';

export type ComposerNext = 'close' | 'test' | 'schedule' | 'send';

interface Props {
  open: boolean;
  id: number | null;
  channels?: ChannelsState;
  onClose: () => void;
  onSaved: (c: { id: number; title: string; channel: string; recipients: number }, next: ComposerNext) => void;
}

interface Form {
  title: string;
  channel: string;
  template_id: string;
  subject: string;
  body_html: string;
  audience: string;
  program_ids: (string | number)[];
  semester_no: string;
  emails: string;
}

const EMPTY: Form = { title: '', channel: 'email', template_id: '', subject: '', body_html: '', audience: 'subscribers', program_ids: [], semester_no: '', emails: '' };
interface PreviewResult {
  subject: string | null;
  html: string | null;
  text: string;
  length?: number;
  segments?: number;
  unknown: string[];
}

const PLACEHOLDERS = ['name', 'email', 'institute_name', 'site_url', 'year', 'unsubscribe_link'];

/** Compose / edit a campaign: content (from a template or scratch), audience with live reach, live preview. */
export function CampaignComposer({ open, id, channels, onClose, onSaved }: Props) {
  const toast = useToast();
  const confirm = useConfirm();
  const { can } = useAuth();
  const { data: meta } = useCrudMeta('email_campaigns');
  const [f, setF] = useState<Form>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState<ComposerNext | null>(null);
  const [editorKey, setEditorKey] = useState(0);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    if (!id) {
      setF(EMPTY);
      setEditorKey((k) => k + 1);
      return;
    }
    setLoading(true);
    api
      .get<CrudRecordPayload>(`crud/email_campaigns/${id}`)
      .then((p) => {
        const v = p.values;
        let filter: { program_ids?: number[]; semester_no?: number | null; emails?: string } = {};
        try {
          filter = typeof v.audience_filter === 'string' && v.audience_filter ? JSON.parse(v.audience_filter) : ((v.audience_filter as typeof filter) ?? {});
        } catch {
          filter = {};
        }
        setF({
          title: String(v.title ?? ''), channel: String(v.channel ?? 'email'), template_id: v.template_id ? String(v.template_id) : '', subject: String(v.subject ?? ''),
          body_html: String(v.body_html ?? ''), audience: String(v.audience ?? 'subscribers'), program_ids: filter.program_ids ?? [], semester_no: filter.semester_no ? String(filter.semester_no) : '',
          emails: filter.emails ?? '',
        });
        setEditorKey((k) => k + 1);
      })
      .catch((e: ApiError) => setFormError(e.message))
      .finally(() => setLoading(false));
  }, [open, id]);

  const set = <K extends keyof Form>(k: K, v: Form[K]) => {
    setF((s) => ({ ...s, [k]: v }));
    if (errors[k] || (k !== 'audience' && errors.audience_filter)) setErrors((e) => ({ ...e, [k]: '', audience_filter: '' }));
  };

  const templateOptions = useMemo(() => meta?.fields.find((x) => x.name === 'template_id')?.options ?? [], [meta]);
  const applyTemplate = async (tid: string) => {
    set('template_id', tid);
    if (!tid) return;
    if (f.body_html.replace(/<[^>]+>/g, '').trim() !== '') {
      const ok = await confirm({ title: 'Replace the message?', message: 'The subject and body will be replaced with the template content.', confirmText: 'Use template' });
      if (!ok) return;
    }
    try {
      const t = await api.get<CrudRecordPayload>(`crud/email_templates/${tid}`);
      const ch = String(t.values.channel ?? 'email');
      setF((s) => ({ ...s, template_id: tid, subject: ch === 'email' ? String(t.values.subject ?? '') : s.subject, body_html: String(t.values.body_html ?? ''), channel: ch === 'email' || channels?.[ch as keyof ChannelsState] ? ch : s.channel }));
      setEditorKey((k) => k + 1);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  // live audience reach
  const reachKey = useDebounce(JSON.stringify({ audience: f.audience, program_ids: f.program_ids, semester_no: f.semester_no, emails: f.audience === 'custom' ? f.emails : '' }), 350);
  const reachParams = useMemo(() => JSON.parse(reachKey) as Record<string, string>, [reachKey]);
  const { data: reach, isFetching: reachLoading } = useApi<AudienceSummary>(['comm', 'campaign-reach', reachParams], 'newsletter/audience-preview', reachParams, {
    enabled: open && (f.audience !== 'program' || f.program_ids.length > 0),
  });
  const recipients = reach ? (f.channel === 'email' ? reach.email : f.channel === 'sms' ? reach.sms : reach.whatsapp) : 0;

  // live preview
  const previewKey = useDebounce(JSON.stringify({ channel: f.channel, subject: f.subject, body_html: f.body_html }), 600);
  const { data: preview } = useQuery({
    queryKey: ['comm', 'campaign-preview', previewKey],
    queryFn: () => api.post<PreviewResult>('communication/templates/preview', { ...JSON.parse(previewKey), vars: { name: 'Ananya Sharma', email: 'ananya.sharma@gmail.com' } }).then((r) => r.data),
    enabled: open && f.body_html.trim() !== '',
    placeholderData: keepPreviousData,
  });

  const save = async (next: ComposerNext) => {
    const clientErr: Record<string, string> = {};
    if (!f.title.trim()) clientErr.title = 'Campaign name is required.';
    if (f.channel === 'email' && !f.subject.trim()) clientErr.subject = 'Subject line is required for e-mail campaigns.';
    if (!f.body_html.replace(/<[^>]+>/g, '').trim()) clientErr.body_html = 'Write the message before saving.';
    if (f.audience === 'program' && !f.program_ids.length) clientErr.audience_filter = 'Select at least one program.';
    if (Object.keys(clientErr).length) {
      setErrors(clientErr);
      setFormError('Please fix the highlighted fields.');
      return;
    }
    setSaving(next);
    setFormError(null);
    try {
      const payload = {
        title: f.title, channel: f.channel, template_id: f.template_id || null, subject: f.subject, body_html: f.body_html, audience: f.audience,
        audience_filter: { program_ids: f.audience === 'program' ? f.program_ids.map(Number) : [], semester_no: f.audience === 'program' && f.semester_no ? Number(f.semester_no) : null, emails: f.audience === 'custom' ? f.emails : '' },
      };
      const res = await api.post<{ id: number }>(id ? `crud/email_campaigns/${id}` : 'crud/email_campaigns', payload);
      if (next === 'close') toast.success(res.message || 'Campaign saved.');
      onSaved({ id: res.data.id, title: f.title, channel: f.channel, recipients }, next);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setFormError(err.message);
    } finally {
      setSaving(null);
    }
  };

  const channelBtn = (key: string, label: string, Icon: typeof Mail, enabled: boolean) => (
    <button
      key={key}
      type="button"
      disabled={!enabled}
      onClick={() => {
        set('channel', key);
        if (key !== 'email' && f.audience === 'subscribers') set('audience', 'students');
        setEditorKey((k) => k + 1);
      }}
      title={enabled ? label : `${label} is not enabled in Settings → Email & SMS`}
      className={clsx(
        'inline-flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-2 py-2 text-sm font-medium transition',
        f.channel === key ? 'bg-white text-brand-800 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:text-white dark:ring-slate-700' : 'text-slate-500 hover:text-slate-800 dark:hover:text-white',
        !enabled && 'cursor-not-allowed opacity-50',
      )}
    >
      <Icon className="h-4 w-4" />
      {label}
    </button>
  );

  const audienceOptions = Object.entries(CAMPAIGN_AUDIENCES)
    .filter(([k]) => f.channel === 'email' || k !== 'subscribers')
    .map(([value, label]) => ({ value, label }));

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={!!saving}
      size="2xl"
      title={id ? 'Edit campaign' : 'New campaign'}
      description="Write the message, pick the audience and preview it exactly as recipients will see it."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={!!saving} className="mr-auto hidden sm:inline-flex">
            Cancel
          </Button>
          <Button variant="secondary" icon={Save} loading={saving === 'close'} disabled={!!saving || loading} onClick={() => void save('close')} aria-label="Save draft" title="Save draft">
            <span className="hidden sm:inline">Save draft</span>
          </Button>
          <Button variant="secondary" icon={FlaskConical} loading={saving === 'test'} disabled={!!saving || loading} onClick={() => void save('test')} aria-label="Save and send a test" title="Send test">
            <span className="hidden sm:inline">Send test</span>
          </Button>
          {can('communication', 'publish') && (
            <>
              <Button variant="secondary" icon={CalendarClock} loading={saving === 'schedule'} disabled={!!saving || loading} onClick={() => void save('schedule')} aria-label="Save and schedule" title="Schedule">
                <span className="hidden sm:inline">Schedule…</span>
              </Button>
              <Button icon={Send} loading={saving === 'send'} disabled={!!saving || loading || !recipients} onClick={() => void save('send')}>
                Send now{recipients ? ` (${formatNumber(recipients)})` : ''}
              </Button>
            </>
          )}
        </>
      }
    >
      {loading ? (
        <PageLoader label="Loading campaign…" />
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
          <div className="space-y-4 lg:col-span-3">
            {formError && <Alert variant="error">{formError}</Alert>}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
              <Field className="sm:col-span-6" label="Campaign name" required htmlFor="c-title" error={errors.title} hint="Internal name — recipients don't see it.">
                <Input id="c-title" value={f.title} maxLength={190} onChange={(e) => set('title', e.target.value)} invalid={!!errors.title} placeholder="e.g. November newsletter" />
              </Field>
              <Field className="sm:col-span-6" label="Channel" error={errors.channel}>
                <div className="flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-900/60" role="radiogroup" aria-label="Channel">
                  {channelBtn('email', 'E-mail', Mail, true)}
                  {channelBtn('sms', 'SMS', MessageSquareText, !!channels?.sms)}
                  {channelBtn('whatsapp', 'WhatsApp', MessageCircle, !!channels?.whatsapp)}
                </div>
              </Field>
              <Field className="sm:col-span-12" label="Start from a template" htmlFor="c-template">
                <Select id="c-template" value={f.template_id} onChange={(e) => void applyTemplate(e.target.value)} options={templateOptions.map((o) => ({ value: o.value, label: `${o.label}${o.sub && o.sub !== 'email' ? ` (${o.sub})` : ''}` }))} placeholder="— Blank message —" />
              </Field>
              {f.channel === 'email' && (
                <Field className="sm:col-span-12" label="Subject line" required htmlFor="c-subject" error={errors.subject} aside={`${f.subject.length}/255`}>
                  <Input id="c-subject" value={f.subject} maxLength={255} onChange={(e) => set('subject', e.target.value)} invalid={!!errors.subject} placeholder="What's new at GIMT this month" />
                </Field>
              )}
              <Field className="sm:col-span-12" label="Message" required htmlFor="c-body" error={errors.body_html} aside={f.channel !== 'email' && preview?.length ? `${preview.length} chars · ${preview.segments} SMS` : undefined}>
                {f.channel === 'email' ? (
                  <RichText key={editorKey} id="c-body" value={f.body_html} onChange={(v) => set('body_html', v)} invalid={!!errors.body_html} minHeight={240} placeholder="Write your newsletter…" />
                ) : (
                  <Textarea id="c-body" rows={6} value={f.body_html.replace(/<[^>]+>/g, '')} onChange={(e) => set('body_html', e.target.value)} invalid={!!errors.body_html} maxLength={1000} placeholder="Dear {{name}}, …" />
                )}
              </Field>
              <div className="sm:col-span-12">
                <p className="mb-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">Personalise with placeholders (click to copy):</p>
                <div className="flex flex-wrap gap-1.5">
                  {PLACEHOLDERS.filter((p) => f.channel === 'email' || p !== 'unsubscribe_link').map((p) => (
                    <button
                      key={p}
                      type="button"
                      onClick={async () => {
                        const ok = await copyText(`{{${p}}}`);
                        if (ok) toast.info(`Copied {{${p}}} — paste it into the message.`);
                        else if (f.channel !== 'email') set('body_html', `${f.body_html}{{${p}}}`);
                      }}
                      className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 font-mono text-[11px] text-slate-700 transition hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-brand-500/15"
                    >
                      <Copy className="h-3 w-3" />
                      {`{{${p}}}`}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            <div className="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
              <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><Users className="h-4 w-4 text-brand-700 dark:text-brand-300" />Audience</h3>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
                <Field className="sm:col-span-6" label="Send to" required htmlFor="c-aud" error={errors.audience}>
                  <Select id="c-aud" value={f.audience} onChange={(e) => set('audience', e.target.value)} options={audienceOptions} />
                </Field>
                {f.audience === 'program' && (
                  <>
                    <Field className="sm:col-span-6" label="Semester" htmlFor="c-sem">
                      <Select id="c-sem" value={f.semester_no} onChange={(e) => set('semester_no', e.target.value)} options={Array.from({ length: 10 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }))} placeholder="All semesters" />
                    </Field>
                    <Field className="sm:col-span-12" label="Programs" required htmlFor="c-programs" error={errors.audience_filter}>
                      <Combobox id="c-programs" multiple source="programs" value={f.program_ids} onChange={(v) => set('program_ids', v)} placeholder="Select programs…" invalid={!!errors.audience_filter} />
                    </Field>
                  </>
                )}
                {f.audience === 'custom' && (
                  <Field className="sm:col-span-12" label="E-mail addresses" required htmlFor="c-emails" error={errors.audience_filter} hint="Separate addresses with commas, spaces or new lines.">
                    <Textarea id="c-emails" rows={3} value={f.emails} onChange={(e) => set('emails', e.target.value)} invalid={!!errors.audience_filter} placeholder="principal@school.edu.in, counsellor@school.edu.in" />
                  </Field>
                )}
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-brand-50/70 px-3 py-2.5 text-sm dark:bg-brand-500/10" aria-live="polite">
                {f.audience === 'program' && !f.program_ids.length ? (
                  <span className="text-slate-500">Select programs to see the reach.</span>
                ) : !reach || reachLoading ? (
                  <span className="skeleton h-4 w-48" />
                ) : (
                  <>
                    <span className="font-semibold text-brand-900 dark:text-brand-100">{formatNumber(recipients)} recipients</span>
                    <span className="text-slate-600 dark:text-slate-300">
                      {formatNumber(reach.people)} people in this audience
                      {reach.people > recipients ? ` · ${formatNumber(reach.people - recipients)} without ${f.channel === 'email' ? 'an e-mail address' : 'a mobile number'} or duplicates` : ''}
                    </span>
                  </>
                )}
              </div>
            </div>
          </div>

          <div className="lg:col-span-2">
            <div className="lg:sticky lg:top-0">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Preview · sample recipient</p>
              {f.channel === 'email' ? (
                <>
                  <div className="mb-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800/60">
                    <span className="text-slate-500">Subject: </span>
                    <span className="font-medium text-slate-900 dark:text-white">{preview?.subject || f.subject || '—'}</span>
                  </div>
                  <EmailFrame html={f.body_html.trim() ? preview?.html : null} className="h-[440px]" />
                </>
              ) : (
                <div className="rounded-2xl bg-slate-100 p-4 dark:bg-slate-800">
                  <div className="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-tl-sm bg-white px-4 py-3 text-sm text-slate-800 shadow-sm dark:bg-slate-900 dark:text-slate-100">
                    {preview?.text || <span className="text-slate-400">Your message preview appears here.</span>}
                  </div>
                </div>
              )}
              {preview && preview.unknown.length > 0 && (
                <Alert variant="warning" className="mt-3">
                  Unknown placeholder{preview.unknown.length > 1 ? 's' : ''}: {preview.unknown.map((u) => `{{${u}}}`).join(', ')} — {preview.unknown.length > 1 ? 'they' : 'it'} will be left empty.
                </Alert>
              )}
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
