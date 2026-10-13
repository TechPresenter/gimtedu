import { useEffect, useState, type FormEvent } from 'react';
import { ClipboardCheck } from 'lucide-react';
import { Alert, Button, Combobox, Field, Input, Modal, RadioGroup, Select, Textarea, useToast } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { api, ApiError } from '@/lib/api';
import type { ApplicationDetail } from './types';
import { INTERVIEW_MODES, ROUND_RESULT, usePlacementRefresh } from './shared';

function localNow(): string {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}

/** Record the outcome (or schedule) of an interview round for one application. */
export function RecordRoundModal({ detail, open, onClose }: { detail: ApplicationDetail | null; open: boolean; onClose: () => void }) {
  const toast = useToast();
  const refresh = usePlacementRefresh();
  const [round, setRound] = useState('');
  const [custom, setCustom] = useState('');
  const [when, setWhen] = useState(localNow());
  const [mode, setMode] = useState('in_person');
  const [result, setResult] = useState('cleared');
  const [score, setScore] = useState('');
  const [panel, setPanel] = useState('');
  const [feedback, setFeedback] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || !detail) return;
    const pendingList = detail.interviews.filter((i) => i.result === 'pending');
    const pending = pendingList[pendingList.length - 1];
    const next = pending?.round_name ?? detail.next_round ?? detail.rounds[0] ?? '';
    const known = detail.rounds.some((r) => r.toLowerCase() === next.toLowerCase());
    setRound(known ? detail.rounds.find((r) => r.toLowerCase() === next.toLowerCase()) ?? '' : next ? '__other' : detail.rounds[0] ?? '__other');
    setCustom(known ? '' : next);
    setWhen(pending?.scheduled_at ? pending.scheduled_at.slice(0, 16).replace(' ', 'T') : localNow());
    setMode(pending?.mode ?? 'in_person');
    setResult(pending?.scheduled_at && new Date(pending.scheduled_at.replace(' ', 'T')).getTime() > Date.now() ? 'pending' : 'cleared');
    setScore('');
    setPanel(pending?.panel ?? '');
    setFeedback('');
    setErrors({});
    setFormError(null);
  }, [open, detail]);

  if (!detail) return null;
  const a = detail.application;
  const roundName = round === '__other' ? custom.trim() : round;
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!roundName) {
      setErrors({ round_name: 'Choose or enter the round.' });
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.post(`placement/applications/${a.id}/rounds`, { round_name: roundName, scheduled_at: when, mode, result, score: score === '' || result === 'pending' || result === 'absent' ? null : score, panel, feedback });
      toast.success(res.message);
      await refresh('placement_applications', 'placement_interviews', 'placement_drives');
      onClose();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      title="Record interview round"
      description={`${a.student_name} · ${a.company_name} — ${a.job_role}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="placement-round-form" loading={saving} icon={ClipboardCheck}>Save round</Button>
        </>
      }
    >
      <form id="placement-round-form" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Round" required htmlFor="rr-round" error={errors.round_name}>
            <Select id="rr-round" value={round} onChange={(e) => setRound(e.target.value)} invalid={!!errors.round_name}
              options={[...detail.rounds.map((r) => ({ value: r, label: r })), { value: '__other', label: 'Other round…' }]} />
          </Field>
          {round === '__other' && (
            <Field label="Round name" required htmlFor="rr-custom" error={errors.round_name}>
              <Input id="rr-custom" value={custom} maxLength={60} onChange={(e) => setCustom(e.target.value)} placeholder="e.g. Managerial Interview" invalid={!!errors.round_name} />
            </Field>
          )}
          <Field label="Date & time" required htmlFor="rr-when" error={errors.scheduled_at}>
            <Input id="rr-when" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} invalid={!!errors.scheduled_at} />
          </Field>
          <Field label="Mode" htmlFor="rr-mode" error={errors.mode}>
            <Select id="rr-mode" value={mode} onChange={(e) => setMode(e.target.value)} options={INTERVIEW_MODES} />
          </Field>
        </div>
        <Field label="Result" required error={errors.result} hint={result === 'pending' ? 'Schedules the round — record the outcome later.' : undefined}>
          <RadioGroup name="rr-result" value={result} onChange={setResult} options={Object.entries(ROUND_RESULT).map(([value, label]) => ({ value, label: value === 'pending' ? 'Scheduled / pending' : label }))} />
        </Field>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Score (0–100)" htmlFor="rr-score" error={errors.score}>
            <Input id="rr-score" type="number" min={0} max={100} step={0.5} value={score} onChange={(e) => setScore(e.target.value)} invalid={!!errors.score} disabled={result === 'pending' || result === 'absent'} />
          </Field>
          <Field label="Panel / interviewer" htmlFor="rr-panel" error={errors.panel} className="sm:col-span-2">
            <Input id="rr-panel" value={panel} maxLength={255} onChange={(e) => setPanel(e.target.value)} placeholder="e.g. Mr. Arvind Rao (Tech Lead)" />
          </Field>
        </div>
        <Field label="Feedback" htmlFor="rr-feedback" error={errors.feedback}>
          <Textarea id="rr-feedback" rows={3} maxLength={500} value={feedback} onChange={(e) => setFeedback(e.target.value)} placeholder="Strengths, gaps and the panel's recommendation" />
        </Field>
      </form>
    </Modal>
  );
}

/** "Record round" from the interview list: pick an application first, then record its round. */
export function PickApplicationRoundModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [appId, setAppId] = useState<number | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const { data, isFetching } = useApi<ApplicationDetail>(['placement', 'application', appId], `placement/applications/${appId}`, undefined, { enabled: !!appId && confirmed });
  useEffect(() => {
    if (open) {
      setAppId(null);
      setConfirmed(false);
    }
  }, [open]);
  if (confirmed && data) return <RecordRoundModal detail={data} open={open} onClose={onClose} />;
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="md"
      title="Record interview round"
      description="Choose the student's application (student → company)."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button disabled={!appId} loading={confirmed && isFetching} onClick={() => setConfirmed(true)}>Continue</Button>
        </>
      }
    >
      <Field label="Application" required htmlFor="pick-app" hint="Search by student name, ID or company.">
        <Combobox id="pick-app" value={appId} onChange={(v) => setAppId(v ? Number(v) : null)} optionsUrl="placement/applications/options" placeholder="Search application…" />
      </Field>
    </Modal>
  );
}
