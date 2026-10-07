import { useState } from 'react';
import { BellRing, Mail, MessageCircle, MessageSquare, Send } from 'lucide-react';
import { Alert, Button, Checkbox, Modal, useToast } from '@/components/ui';
import { api, type ApiError, type Query } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';

interface Props {
  open: boolean;
  onClose: () => void;
  /** Explicit students, or null to alert every defaulter matching the filters */
  students: { id: number; name: string; percent: number | null }[] | null;
  total: number;
  threshold: number;
  query: Query;
  onSent?: () => void;
}

const CHANNELS = [
  { key: 'inapp', label: 'In-app notification', desc: 'Student portal account and class teacher', icon: BellRing },
  { key: 'email', label: 'Email', desc: 'Student and parent email addresses', icon: Mail },
  { key: 'sms', label: 'SMS', desc: 'Student, parent and guardian mobile numbers', icon: MessageSquare },
  { key: 'whatsapp', label: 'WhatsApp', desc: 'Same numbers as SMS (when the gateway is enabled)', icon: MessageCircle },
] as const;

export function AlertDialog({ open, onClose, students, total, threshold, query, onSent }: Props) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const [channels, setChannels] = useState<string[]>(['inapp', 'email', 'sms']);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const count = students ? students.length : total;
  const toggle = (k: string) => setChannels((c) => (c.includes(k) ? c.filter((x) => x !== k) : [...c, k]));

  const send = async () => {
    setSending(true);
    setError(null);
    try {
      const body = students ? { ...query, student_ids: students.map((s) => s.id), channels } : { ...query, all: true, channels };
      const res = await api.post('attendance/alerts', body);
      toast.success(res.message, 'Alerts sent');
      await invalidate('attendance');
      onSent?.();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setError(Object.values(err.errors ?? {})[0] ?? err.message);
    } finally {
      setSending(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={sending}
      size="lg"
      title="Send low attendance alert"
      description={`${count} student${count === 1 ? '' : 's'} below ${threshold}% attendance`}
      icon={<span className="kpi-icon bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300"><BellRing className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>Cancel</Button>
          <Button icon={Send} loading={sending} disabled={!channels.length || count === 0} onClick={send}>
            Send to {count} student{count === 1 ? '' : 's'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        <fieldset>
          <legend className="form-label">Channels</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {CHANNELS.map((c) => (
              <label key={c.key} className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-brand-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:border-slate-700 dark:has-[:checked]:bg-brand-500/10">
                <Checkbox checked={channels.includes(c.key)} onChange={() => toggle(c.key)} aria-label={c.label} />
                <c.icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" aria-hidden />
                <span className="text-sm">
                  <span className="block font-medium text-slate-800 dark:text-slate-100">{c.label}</span>
                  <span className="block text-xs text-slate-500">{c.desc}</span>
                </span>
              </label>
            ))}
          </div>
          {!channels.length && <p className="form-error">Choose at least one channel.</p>}
        </fieldset>
        <div className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Message preview</p>
          <p>
            Dear Parent/Student, the attendance of <strong>{students?.[0]?.name ?? '[Student name]'}</strong> is <strong>{students?.[0]?.percent !== undefined && students?.[0]?.percent !== null ? `${students[0].percent.toFixed(1)}%` : '[their %]'}</strong>, below the required {threshold}%. Please ensure regular attendance.
          </p>
        </div>
        {students && students.length > 0 && (
          <div>
            <p className="form-label">Recipients</p>
            <div className="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
              {students.slice(0, 40).map((s) => (
                <span key={s.id} className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                  {s.name} · {s.percent?.toFixed(1)}%
                </span>
              ))}
              {students.length > 40 && <span className="px-1 text-xs text-slate-500">+{students.length - 40} more</span>}
            </div>
          </div>
        )}
        <p className="text-xs text-slate-500">Every alert is recorded in the activity log and message log. Students already at or above {threshold}% are skipped automatically.</p>
      </div>
    </Modal>
  );
}
