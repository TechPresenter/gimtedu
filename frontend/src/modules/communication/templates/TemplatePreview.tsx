import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FlaskConical, Pencil } from 'lucide-react';
import { Alert, Badge, Button, Field, Input, Modal, PageLoader, useToast } from '@/components/ui';
import type { CrudRecordPayload } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { ChannelBadge, EmailFrame } from '../shared';
import type { TemplatePreviewResult } from './TemplateEditor';

/** Rendered template with sample data. */
export function TemplatePreviewModal({ id, onClose, onEdit, onTest }: { id: number | null; onClose: () => void; onEdit?: (id: number) => void; onTest?: (t: { id: number; name: string; channel: string }) => void }) {
  const { can } = useAuth();
  const { data, isLoading, error } = useQuery({
    queryKey: ['comm', 'tpl-render', id],
    enabled: !!id,
    queryFn: async () => {
      const rec = await api.get<CrudRecordPayload>(`crud/email_templates/${id}`);
      const v = rec.values;
      const pv = await api.post<TemplatePreviewResult>('communication/templates/preview', { channel: v.channel, subject: v.subject, body_html: v.body_html });
      return { row: rec.row, preview: pv.data };
    },
  });
  const t = data?.row;
  return (
    <Modal
      open={!!id}
      onClose={onClose}
      size="lg"
      title={t ? String(t.name) : 'Template preview'}
      description={t ? <span className="flex flex-wrap items-center gap-1.5"><code className="text-xs">{String(t.code)}</code><ChannelBadge channel={String(t.channel)} />{t.is_system ? <Badge color="navy">System</Badge> : null}</span> : undefined}
      footer={
        t && (
          <>
            <Button variant="secondary" onClick={onClose}>
              Close
            </Button>
            {onTest && can('communication', 'edit') && (
              <Button variant="secondary" icon={FlaskConical} onClick={() => onTest({ id: Number(t.id), name: String(t.name), channel: String(t.channel) })}>
                Send test
              </Button>
            )}
            {onEdit && can('communication', 'edit') && (
              <Button icon={Pencil} onClick={() => onEdit(Number(t.id))}>
                Edit
              </Button>
            )}
          </>
        )
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !data || !t ? (
        <PageLoader />
      ) : t.channel === 'email' ? (
        <div className="space-y-2">
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800/60">
            <span className="text-slate-500">Subject: </span>
            <span className="font-medium text-slate-900 dark:text-white">{data.preview.subject}</span>
          </div>
          <EmailFrame html={data.preview.html} className="h-[460px]" />
          <p className="text-xs text-slate-500 dark:text-slate-400">Shown with sample data. Placeholders: {String(t.variables ?? '—')}</p>
        </div>
      ) : (
        <div className="rounded-2xl bg-slate-100 p-4 dark:bg-slate-800">
          <div className="max-w-[90%] whitespace-pre-wrap rounded-2xl rounded-tl-sm bg-white px-4 py-3 text-sm text-slate-800 shadow-sm dark:bg-slate-900 dark:text-slate-100">{data.preview.text}</div>
          <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{data.preview.length} characters · {data.preview.segments} SMS segment{data.preview.segments === 1 ? '' : 's'}</p>
        </div>
      )}
    </Modal>
  );
}

/** Send one test message of a template (sample data). */
export function TemplateTestDialog({ template, onClose }: { template: { id: number; name: string; channel: string } | null; onClose: () => void }) {
  const toast = useToast();
  const { session } = useAuth();
  const [to, setTo] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const isEmail = template?.channel === 'email';

  useEffect(() => {
    if (!template) return;
    setErrors({});
    setTo(isEmail ? session?.user?.email ?? '' : session?.user?.phone ?? '');
  }, [template, isEmail, session]);

  const send = async () => {
    if (!template) return;
    setBusy(true);
    setErrors({});
    try {
      const res = await api.post(`communication/templates/${template.id}/test`, isEmail ? { email: to } : { phone: to });
      toast.success(res.message);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={!!template}
      onClose={onClose}
      static={busy}
      size="sm"
      title="Send a test"
      description={template ? `“${template.name}” with sample data.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={FlaskConical} loading={busy} onClick={() => void send()}>
            Send test
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void send();
        }}
      >
        <Field label={isEmail ? 'E-mail address' : 'Mobile number'} required htmlFor="tpl-test-to" error={isEmail ? errors.email : errors.phone}>
          <Input id="tpl-test-to" type={isEmail ? 'email' : 'tel'} value={to} onChange={(e) => setTo(e.target.value)} invalid={!!(isEmail ? errors.email : errors.phone)} autoFocus />
        </Field>
      </form>
    </Modal>
  );
}
