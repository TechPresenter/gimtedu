import { useEffect, useMemo, useState } from 'react';
import { FlaskConical, Play, RotateCcw } from 'lucide-react';
import { Alert, Badge, Button, Combobox, Field, Input, Modal, RadioGroup, StatusBadge, Textarea, Toggle, useToast } from '@/components/ui';
import { ApiError, api } from '@/lib/api';
import { useWorkflowRefresh } from './api';
import { RUN_STATUS_COLORS } from './RunDrawer';
import { RunTimeline } from './RunTimeline';
import type { Definition, Meta, RunDetail } from './types';

interface Props {
  open: boolean;
  onClose: () => void;
  workflowId: number;
  def: Definition;
  meta?: Meta;
  /** step statuses of the finished test, for highlighting the canvas */
  onTrace: (trace: Record<string, string>) => void;
}

/** Run the workflow now for a test contact: waits are fast-forwarded and the trace is shown step by step. */
export function TestRunModal({ open, onClose, workflowId, def, meta, onTrace }: Props) {
  const toast = useToast();
  const refresh = useWorkflowRefresh();
  const trigger = def.nodes.find((n) => n.type === 'trigger');
  const event = meta?.events.find((e) => e.code === trigger?.data.event);
  const hasWait = def.nodes.some((n) => n.type === 'wait_reply');
  const createsEnquiry = def.nodes.some((n) => n.type === 'contact' && !!n.data.create_enquiry);
  const hasCrm = createsEnquiry || def.nodes.some((n) => ['tag', 'stage', 'assign'].includes(n.type));
  const [source, setSource] = useState<'contact' | 'phone'>('contact');
  const [contactId, setContactId] = useState<string | number | null>(null);
  const [phone, setPhone] = useState('');
  const [payload, setPayload] = useState('{}');
  const [replyMode, setReplyMode] = useState('replied');
  const [replyText, setReplyText] = useState('Yes, I am interested. Please share the fee details.');
  const [applyCrm, setApplyCrm] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<RunDetail | null>(null);

  const sample = useMemo(() => {
    const s = { ...(event?.sample ?? {}) } as Record<string, unknown>;
    // identity comes from the test contact
    ['phone', 'mobile', 'whatsapp', 'contact_id', 'name', 'student_name', 'first_name', 'email'].forEach((k) => delete s[k]);
    return JSON.stringify(s, null, 2);
  }, [event]);

  useEffect(() => {
    if (open) {
      setPayload(sample);
      setResult(null);
      setErrors({});
    }
  }, [open, sample]);

  const run = async () => {
    const errs: Record<string, string> = {};
    let parsed: Record<string, unknown> = {};
    try {
      parsed = payload.trim() ? JSON.parse(payload) : {};
      if (typeof parsed !== 'object' || Array.isArray(parsed) || parsed === null) throw new Error('not an object');
    } catch {
      errs.payload = 'Enter a JSON object, e.g. {"course_name": "BBA"}.';
    }
    if (source === 'contact' && !contactId) errs.contact_id = 'Pick a contact.';
    if (source === 'phone' && phone.replace(/\D/g, '').length < 10) errs.phone = 'Enter a 10-digit WhatsApp number.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy(true);
    try {
      const res = await api.post<RunDetail>(`whatsapp/workflows/${workflowId}/test`, {
        ...(source === 'contact' ? { contact_id: contactId } : { phone }),
        payload: parsed,
        reply_mode: replyMode,
        reply_text: replyText,
        apply_crm: applyCrm,
        definition: def,
      });
      setResult(res.data);
      const trace: Record<string, string> = {};
      res.data.steps.forEach((s) => {
        if (s.node_type !== 'retry' && s.node_type !== 'cancel') trace[s.node_id] = s.status;
      });
      onTrace(trace);
      toast[res.data.run.status === 'failed' ? 'warning' : 'success'](res.message);
      refresh();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      static={busy}
      title="Test workflow"
      description="Runs the steps on the canvas right now (unsaved changes included). Delays are skipped and the reply is simulated."
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200">
          <FlaskConical className="h-5 w-5" />
        </span>
      }
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Close
          </Button>
          <Button icon={result ? RotateCcw : Play} loading={busy} onClick={run}>
            {result ? 'Run again' : 'Run test'}
          </Button>
        </>
      }
    >
      <div className="grid gap-6 lg:grid-cols-[minmax(0,20rem)_1fr]">
        <div className="space-y-4">
          <Field label="Test contact" required>
            <RadioGroup name="wf-test-src" value={source} onChange={(v) => setSource(v as 'contact' | 'phone')} options={[{ value: 'contact', label: 'Pick a contact' }, { value: 'phone', label: 'Type a number' }]} />
          </Field>
          {source === 'contact' ? (
            <Field error={errors.contact_id} htmlFor="wf-test-contact">
              <Combobox id="wf-test-contact" optionsUrl="whatsapp/workflows/options/contacts" value={contactId} onChange={(v) => setContactId(v)} placeholder="Search name or phone…" invalid={!!errors.contact_id} />
            </Field>
          ) : (
            <Field error={errors.phone} htmlFor="wf-test-phone" hint="A contact is created for a new number.">
              <Input id="wf-test-phone" type="tel" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="98765 43210" prefix="+91" invalid={!!errors.phone} />
            </Field>
          )}
          <Alert variant={meta?.mode === 'live' ? 'warning' : 'info'}>
            {meta?.mode === 'live' ? 'WhatsApp is LIVE: template and text steps really message this number.' : 'Test mode: messages are simulated (nothing reaches WhatsApp) and appear in the inbox.'}
          </Alert>
          {hasWait && (
            <Field label="Simulate the contact's answer">
              <RadioGroup name="wf-test-reply" value={replyMode} onChange={setReplyMode} options={[{ value: 'replied', label: 'Replies' }, { value: 'timeout', label: 'No reply' }]} />
            </Field>
          )}
          {hasWait && replyMode === 'replied' && (
            <Field label="Reply text" htmlFor="wf-test-reply-text">
              <Input id="wf-test-reply-text" value={replyText} onChange={(e) => setReplyText(e.target.value)} maxLength={500} />
            </Field>
          )}
          {hasCrm && <Toggle checked={applyCrm} onChange={setApplyCrm} label="Apply CRM steps to the test contact" description={`Tags, stage and counsellor changes${createsEnquiry ? ', and the ERP admission enquiry (a real enquiry in Admissions › Enquiries)' : ''}. Off = shown as a dry run.`} />}
          <Field label={`Event data${event ? ` (${event.code})` : ''}`} htmlFor="wf-test-payload" error={errors.payload} hint="Phone / name / e-mail come from the test contact.">
            <Textarea id="wf-test-payload" rows={7} value={payload} onChange={(e) => setPayload(e.target.value)} className="font-mono text-xs" invalid={!!errors.payload} />
          </Field>
          {errors.definition && <Alert variant="error">{errors.definition}</Alert>}
        </div>
        <div className="min-w-0 rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
          {result ? (
            <div className="space-y-4">
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={result.run.status} colors={RUN_STATUS_COLORS} />
                <Badge color="purple">Run #{result.run.id}</Badge>
                <span className="text-sm text-slate-600 dark:text-slate-300">
                  {result.run.contact_name || `+${result.run.contact_phone}`} · {result.steps.length} steps · {result.messages.length} message(s)
                </span>
              </div>
              {result.run.error && <Alert variant={result.run.status === 'cancelled' ? 'warning' : 'error'}>{result.run.error}</Alert>}
              <RunTimeline steps={result.steps} phone={result.run.contact_phone} />
            </div>
          ) : (
            <div className="flex h-full min-h-[16rem] flex-col items-center justify-center text-center">
              <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200">
                <FlaskConical className="h-6 w-6" />
              </span>
              <p className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-100">The step-by-step trace appears here</p>
              <p className="mt-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">Every step shows what happened — messages queued, branches taken, waits skipped, requests made.</p>
            </div>
          )}
        </div>
      </div>
    </Modal>
  );
}
