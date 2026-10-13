import { useEffect, useState, type FormEvent } from 'react';
import { Send } from 'lucide-react';
import { Button, Combobox, Field, Input, Modal, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import type { Option } from '@/lib/types';

const MAX_BODY = 10000;

interface ComposeModalProps {
  open: boolean;
  onClose: () => void;
  onSent: (threadId: number) => void;
  /** Pre-selected recipients (deep link /messages?compose=1&to=5 or "message this person"). */
  initialTo?: Option[];
}

/** New message to one or more admin users. */
export function ComposeModal({ open, onClose, onSent, initialTo = [] }: ComposeModalProps) {
  const toast = useToast();
  const [to, setTo] = useState<(string | number)[]>([]);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sending, setSending] = useState(false);

  useEffect(() => {
    if (open) {
      setTo(initialTo.map((o) => o.value));
      setSubject('');
      setBody('');
      setErrors({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const dirty = to.length > 0 || subject.trim() !== '' || body.trim() !== '';

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!to.length) local.recipient_ids = 'Choose at least one recipient.';
    if (!subject.trim()) local.subject = 'Subject is required.';
    if (!body.trim()) local.body = 'Write a message before sending.';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    setSending(true);
    try {
      const res = await api.post<{ thread_id: number }>('messages', { recipient_ids: to, subject: subject.trim(), body });
      toast.success(res.message || 'Message sent.');
      onSent(res.data.thread_id);
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      toast.error(ae.message);
    } finally {
      setSending(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !sending && onClose()}
      title="New message"
      description="Send an internal message to colleagues. They will see it in the top-bar envelope."
      size="lg"
      static={sending}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>
            {dirty ? 'Discard' : 'Cancel'}
          </Button>
          <Button type="submit" form="compose-form" icon={Send} loading={sending}>
            Send message
          </Button>
        </>
      }
    >
      <form id="compose-form" onSubmit={submit} noValidate className="space-y-4">
        <Field label="To" required htmlFor="compose-to" error={errors.recipient_ids} hint="Search by name, email or designation. Add up to 50 people.">
          <Combobox
            multiple
            id="compose-to"
            optionsUrl="messages/recipients"
            value={to}
            initialOptions={initialTo}
            onChange={(v) => {
              setTo(v);
              setErrors((er) => ({ ...er, recipient_ids: '' }));
            }}
            placeholder="Type a colleague's name…"
            invalid={!!errors.recipient_ids}
          />
        </Field>
        <Field label="Subject" required htmlFor="compose-subject" error={errors.subject}>
          <Input id="compose-subject" value={subject} maxLength={190} onChange={(e) => { setSubject(e.target.value); setErrors((er) => ({ ...er, subject: '' })); }} invalid={!!errors.subject} placeholder="e.g. Counselling schedule for Saturday" />
        </Field>
        <Field label="Message" required htmlFor="compose-body" error={errors.body} aside={`${body.length.toLocaleString('en-IN')} / ${MAX_BODY.toLocaleString('en-IN')}`}>
          <Textarea id="compose-body" rows={8} value={body} maxLength={MAX_BODY} onChange={(e) => { setBody(e.target.value); setErrors((er) => ({ ...er, body: '' })); }} invalid={!!errors.body} placeholder="Write your message…" />
        </Field>
      </form>
    </Modal>
  );
}
