import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import clsx from 'clsx';
import {
  Calendar, Footprints, Mail, MessageCircle, MessageSquareText, Phone, StickyNote, Users, type LucideIcon,
} from 'lucide-react';
import { Alert, Button, Field, Modal, Select, StatusBadge, Textarea, useToast, type Tone } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { ADMISSION_STAGES, type BadgeColor } from '@/lib/status';
import { isoDate, labelize } from '@/lib/format';
import { useInvalidate } from '@/lib/queries';

/*
 * Tailwind only emits component classes it can see literally in the sources; <Button variant="success"> builds
 * "btn-success" dynamically, so it is listed here: btn-success btn-soft btn-ghost btn-danger
 */

/* ------------------------------------------------------------------ Reference data */

export const PIPELINE = ADMISSION_STAGES as readonly { key: string; label: string }[];
export const CLOSED_STAGES = [
  { key: 'waitlisted', label: 'Waitlisted' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'withdrawn', label: 'Withdrawn' },
] as const;
export const ALL_STAGES = [...PIPELINE, ...CLOSED_STAGES];

export const STAGE_TONE: Record<string, Tone> = {
  enquiry: 'slate', application: 'blue', document_verification: 'cyan', entrance_interview: 'purple', approval: 'amber',
  fee_payment: 'navy', confirmed: 'green', waitlisted: 'orange', rejected: 'red', withdrawn: 'slate',
};
export const STAGE_BADGE: Record<string, BadgeColor> = {
  enquiry: 'slate', application: 'blue', document_verification: 'cyan', entrance_interview: 'purple', approval: 'amber',
  fee_payment: 'navy', confirmed: 'green', waitlisted: 'amber', rejected: 'red', withdrawn: 'slate',
};

export function stageLabel(stage: string | null | undefined): string {
  return ALL_STAGES.find((s) => s.key === stage)?.label ?? labelize(stage);
}

export function StageBadge({ stage, dot = true }: { stage: string | null | undefined; dot?: boolean }) {
  return <StatusBadge status={stage} label={stageLabel(stage)} colors={STAGE_BADGE} dot={dot} />;
}

export const SOURCES: Record<string, string> = {
  website: 'Website', walk_in: 'Walk-in', phone: 'Phone Call', social_media: 'Social Media', referral: 'Referral',
  campaign: 'Ad Campaign', education_fair: 'Education Fair', whatsapp: 'WhatsApp', other: 'Other',
};
export const sourceLabel = (s: string | null | undefined) => (s ? SOURCES[s] ?? labelize(s) : '—');

export const FOLLOWUP_TYPES: { value: string; label: string; icon: LucideIcon }[] = [
  { value: 'call', label: 'Call', icon: Phone },
  { value: 'whatsapp', label: 'WhatsApp', icon: MessageCircle },
  { value: 'email', label: 'Email', icon: Mail },
  { value: 'meeting', label: 'Meeting', icon: Users },
  { value: 'campus_visit', label: 'Campus visit', icon: Footprints },
  { value: 'sms', label: 'SMS', icon: MessageSquareText },
  { value: 'note', label: 'Note', icon: StickyNote },
];
export const followupIcon = (t: string) => FOLLOWUP_TYPES.find((f) => f.value === t)?.icon ?? Calendar;
export const followupLabel = (t: string) => FOLLOWUP_TYPES.find((f) => f.value === t)?.label ?? labelize(t);

export const OUTCOMES: Record<string, string> = {
  interested: 'Interested', callback: 'Call back later', not_reachable: 'Not reachable', visited: 'Visited campus', applied: 'Applied', not_interested: 'Not interested',
};

export const PAYMENT_MODES: Record<string, string> = {
  cash: 'Cash', upi: 'UPI', card: 'Debit / Credit Card', bank_transfer: 'Bank Transfer (NEFT/IMPS)', cheque: 'Cheque', dd: 'Demand Draft', online: 'Online Gateway',
};

export const ENQUIRY_STATUSES: Record<string, string> = {
  new: 'New', contacted: 'Contacted', interested: 'Interested', not_interested: 'Not Interested', converted: 'Converted', closed: 'Closed',
};

export interface BoardCard {
  id: number;
  application_no: string;
  name: string;
  photo: string | null;
  phone: string;
  stage: string;
  source: string;
  program: string;
  counsellor: string | null;
  counsellor_avatar: string | null;
  score: number | null;
  percentage: number | null;
  docs_count: number;
  docs_verified: number;
  admission_fee: number | null;
  fee_paid: number;
  days_in_stage: number;
  next_followup: string | null;
  converted: boolean;
  rejection_reason: string | null;
  created_at: string;
}

export interface DueFollowup {
  id: number;
  type: string;
  notes: string;
  outcome: string | null;
  next_followup_date: string;
  admission_id: number | null;
  enquiry_id: number | null;
  name: string;
  phone: string | null;
  application_no: string | null;
  stage: string | null;
  enquiry_status: string | null;
  program: string | null;
  counsellor: string | null;
  days_overdue: number;
}

/** "Today", "Tomorrow", "3 days overdue", "in 4 days" */
export function dueLabel(date: string | null | undefined): { text: string; tone: 'red' | 'amber' | 'slate' | 'green' } | null {
  if (!date) return null;
  const today = new Date(`${isoDate()}T00:00:00`).getTime();
  const d = new Date(`${date.slice(0, 10)}T00:00:00`).getTime();
  const diff = Math.round((d - today) / 86400000);
  if (diff < 0) return { text: `${-diff}d overdue`, tone: 'red' };
  if (diff === 0) return { text: 'Due today', tone: 'amber' };
  if (diff === 1) return { text: 'Tomorrow', tone: 'slate' };
  return { text: `In ${diff} days`, tone: 'slate' };
}

export function DueChip({ date, className }: { date: string | null | undefined; className?: string }) {
  const d = dueLabel(date);
  if (!d) return null;
  const cls = { red: 'bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-500/10 dark:text-red-300', amber: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300', slate: 'bg-slate-100 text-slate-600 ring-slate-500/15 dark:bg-slate-800 dark:text-slate-300', green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' }[d.tone];
  return <span className={clsx('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset', cls, className)}>{d.text}</span>;
}

/** Days added to today as Y-m-d */
export function plusDays(n: number): string {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return isoDate(d);
}

/* ------------------------------------------------------------------ Follow-up logging */

export interface FollowupTarget {
  kind: 'admission' | 'enquiry';
  id: number;
  name: string;
  sub?: string;
}

interface FollowupFormProps {
  target: FollowupTarget;
  onSaved?: () => void;
  /** Enquiries can also change status while logging */
  showStatus?: boolean;
  compact?: boolean;
  submitLabel?: string;
  formId?: string;
  onSavingChange?: (saving: boolean) => void;
  hideSubmit?: boolean;
}

/** Inline form to log a call/meeting/visit and schedule the next follow-up. */
export function FollowupForm({ target, onSaved, showStatus, compact, submitLabel = 'Log follow-up', formId, onSavingChange, hideSubmit }: FollowupFormProps) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const [type, setType] = useState('call');
  const [outcome, setOutcome] = useState('');
  const [notes, setNotes] = useState('');
  const [next, setNext] = useState('');
  const [status, setStatus] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => onSavingChange?.(saving), [saving, onSavingChange]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (!notes.trim()) errs.notes = 'Write a short note about this interaction.';
    if (next && next < isoDate()) errs.next_followup_date = 'Next follow-up date cannot be in the past.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    try {
      const path = target.kind === 'admission' ? `admissions/${target.id}/followups` : `enquiries/${target.id}/followups`;
      const res = await api.post(path, { type, outcome, notes, next_followup_date: next, status: showStatus ? status : undefined });
      toast.success(res.message);
      setNotes('');
      setOutcome('');
      setNext('');
      setStatus('');
      await invalidate('adm-profile', 'adm-dashboard', 'adm-board', 'enq-detail', 'enq-summary', 'crud');
      onSaved?.();
    } catch (err) {
      const e2 = err as ApiError;
      setErrors(e2.errors ?? {});
      if (!Object.keys(e2.errors ?? {}).length) toast.error(e2.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <form id={formId} onSubmit={submit} noValidate className="space-y-4">
      <div role="radiogroup" aria-label="Interaction type" className="flex flex-wrap gap-1.5">
        {FOLLOWUP_TYPES.map((t) => (
          <button
            key={t.value}
            type="button"
            role="radio"
            aria-checked={type === t.value}
            onClick={() => setType(t.value)}
            className={clsx(
              'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition',
              type === t.value
                ? 'border-brand-600 bg-brand-50 text-brand-800 dark:border-brand-400 dark:bg-brand-500/15 dark:text-brand-100'
                : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800',
            )}
          >
            <t.icon className="h-3.5 w-3.5" aria-hidden />
            {t.label}
          </button>
        ))}
      </div>
      <Field label="Notes" required error={errors.notes} htmlFor={`fu-notes-${target.kind}-${target.id}`}>
        <Textarea id={`fu-notes-${target.kind}-${target.id}`} rows={compact ? 2 : 3} value={notes} onChange={(e) => setNotes(e.target.value)} invalid={!!errors.notes} maxLength={2000} placeholder="What was discussed? Any concerns or commitments?" />
      </Field>
      <div className={clsx('grid gap-3', showStatus ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
        <Field label="Outcome" error={errors.outcome} htmlFor={`fu-outcome-${target.id}`}>
          <Select id={`fu-outcome-${target.id}`} value={outcome} onChange={(e) => setOutcome(e.target.value)} options={OUTCOMES} placeholder="— Select —" />
        </Field>
        {showStatus && (
          <Field label="Update status" htmlFor={`fu-status-${target.id}`}>
            <Select id={`fu-status-${target.id}`} value={status} onChange={(e) => setStatus(e.target.value)} options={{ contacted: 'Contacted', interested: 'Interested', not_interested: 'Not Interested', closed: 'Closed' }} placeholder="Keep current" />
          </Field>
        )}
        <Field label="Next follow-up" error={errors.next_followup_date} htmlFor={`fu-next-${target.id}`}>
          <input id={`fu-next-${target.id}`} type="date" className={clsx('form-input', errors.next_followup_date && 'form-input-error')} min={isoDate()} value={next} onChange={(e) => setNext(e.target.value)} />
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        <span className="text-xs text-slate-500">Schedule:</span>
        {[
          ['Tomorrow', 1],
          ['In 3 days', 3],
          ['Next week', 7],
        ].map(([l, n]) => (
          <button key={String(l)} type="button" onClick={() => setNext(plusDays(Number(n)))} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-brand-500/15">
            {l}
          </button>
        ))}
        {next && (
          <button type="button" onClick={() => setNext('')} className="text-xs text-slate-400 underline hover:text-slate-600">
            clear
          </button>
        )}
      </div>
      {!hideSubmit && (
        <div className="flex justify-end">
          <Button type="submit" loading={saving} size="sm">
            {submitLabel}
          </Button>
        </div>
      )}
    </form>
  );
}

export function FollowupModal({ open, onClose, target, showStatus, onSaved }: { open: boolean; onClose: () => void; target: FollowupTarget | null; showStatus?: boolean; onSaved?: () => void }) {
  const [saving, setSaving] = useState(false);
  const formId = 'followup-modal-form';
  return (
    <Modal
      open={open && !!target}
      onClose={onClose}
      static={saving}
      title="Log follow-up"
      description={target ? `${target.name}${target.sub ? ` · ${target.sub}` : ''}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form={formId} loading={saving}>
            Save follow-up
          </Button>
        </>
      }
    >
      {target && (
        <FollowupForm
          key={`${target.kind}-${target.id}`}
          target={target}
          showStatus={showStatus}
          formId={formId}
          hideSubmit
          onSavingChange={setSaving}
          onSaved={() => {
            onSaved?.();
            onClose();
          }}
        />
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------------ Stage move with remarks */

export interface MoveRequest {
  ids: number[];
  to: string;
  title?: string;
  /** shown above the remarks */
  intro?: ReactNode;
  names?: string;
}

/** Confirmation modal for stage moves that need remarks (reject, waitlist, withdraw, bulk moves). */
export function StageMoveModal({ request, onClose, onDone }: { request: MoveRequest | null; onClose: () => void; onDone?: (result: { moved: number; failed: { application_no: string; message: string }[] }) => void }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const [remarks, setRemarks] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [failures, setFailures] = useState<{ application_no: string; message: string }[]>([]);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    setRemarks('');
    setError(null);
    setFailures([]);
  }, [request]);
  if (!request) return null;
  const reject = request.to === 'rejected';
  const bulk = request.ids.length > 1;
  const submit = async () => {
    if (reject && !remarks.trim()) {
      setError('Enter the reason for rejecting the application.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      if (bulk) {
        const res = await api.post<{ moved: number; failed: { application_no: string; message: string }[] }>('admissions/bulk-stage', { ids: request.ids, to_stage: request.to, remarks });
        if (res.data.failed.length) {
          toast.warning(res.message);
          setFailures(res.data.failed);
        } else {
          toast.success(res.message);
        }
        onDone?.(res.data);
        if (!res.data.failed.length) onClose();
      } else {
        const res = await api.post(`admissions/${request.ids[0]}/move`, { to_stage: request.to, remarks });
        toast.success(res.message);
        onDone?.({ moved: 1, failed: [] });
        onClose();
      }
      await invalidate('adm-profile', 'adm-dashboard', 'adm-board', 'crud');
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.remarks ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  const verb = { rejected: 'Reject', waitlisted: 'Waitlist', withdrawn: 'Mark as withdrawn' }[request.to] ?? `Move to ${stageLabel(request.to)}`;
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="md"
      title={request.title ?? `${verb}${bulk ? ` ${request.ids.length} applications` : ''}`}
      description={request.names}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            {failures.length ? 'Close' : 'Cancel'}
          </Button>
          {!failures.length && (
            <Button variant={reject ? 'danger' : 'primary'} onClick={submit} loading={saving}>
              {verb}
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        {request.intro}
        {failures.length > 0 ? (
          <Alert variant="warning" title={`${failures.length} application${failures.length === 1 ? '' : 's'} could not be moved`}>
            <ul className="mt-2 max-h-60 space-y-1.5 overflow-y-auto">
              {failures.map((f) => (
                <li key={f.application_no} className="text-xs">
                  <span className="font-semibold">{f.application_no}:</span> {f.message}
                </li>
              ))}
            </ul>
          </Alert>
        ) : (
          <Field label={reject ? 'Reason for rejection' : 'Remarks'} required={reject} error={error} htmlFor="stage-move-remarks" hint={reject ? 'The reason is stored on the application and shown on its timeline.' : 'Optional note for the timeline.'}>
            <Textarea id="stage-move-remarks" rows={3} value={remarks} onChange={(e) => setRemarks(e.target.value)} invalid={!!error} maxLength={500} autoFocus
              placeholder={reject ? 'e.g. Did not meet the minimum eligibility (50% in qualifying exam).' : 'Add a note (optional)'} />
          </Field>
        )}
      </div>
    </Modal>
  );
}
