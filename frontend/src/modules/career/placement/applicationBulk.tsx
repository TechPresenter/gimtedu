import { useEffect, useState, type FormEvent } from 'react';
import { Ban, CheckCheck, ChevronsRight, CirclePause, ListChecks, RotateCcw, Undo2, type LucideIcon } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Textarea, useToast } from '@/components/ui';
import type { CrudBulkAction } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { usePlacementRefresh } from './shared';

export type AppAction = 'shortlist' | 'advance' | 'select' | 'reject' | 'hold' | 'withdraw' | 'reopen';

export const APP_ACTIONS: Record<AppAction, { label: string; verb: string; icon: LucideIcon; danger?: boolean; help: string }> = {
  shortlist: { label: 'Shortlist', verb: 'Shortlist', icon: ListChecks, help: 'Moves applied students to the shortlist (first selection round).' },
  advance: { label: 'Advance round', verb: 'Clear current round', icon: ChevronsRight, help: 'Records the current round as cleared and moves each student to the next round. Students clearing the final round are selected.' },
  select: { label: 'Select', verb: 'Mark selected', icon: CheckCheck, help: 'Marks the students as selected by the company. Record their offers next to mark them placed.' },
  reject: { label: 'Reject', verb: 'Reject', icon: Ban, danger: true, help: 'Marks the students as not selected. Students who already hold an offer from this drive are skipped.' },
  hold: { label: 'On hold', verb: 'Put on hold', icon: CirclePause, help: 'Keeps the students waitlisted for the drive.' },
  withdraw: { label: 'Withdraw', verb: 'Withdraw', icon: Undo2, danger: true, help: 'Withdraws the applications (e.g. the student opted out).' },
  reopen: { label: 'Reopen', verb: 'Reopen', icon: RotateCcw, help: 'Brings rejected, withdrawn or on-hold applications back into the process.' },
};

/** Bulk actions for an applications CrudTable (opens a confirmation modal with optional remarks). */
export function useApplicationBulkActions(open: (action: AppAction, ids: number[], clear: () => void) => void): CrudBulkAction[] {
  const { can } = useAuth();
  if (!can('placement', 'edit')) return [];
  return (['shortlist', 'advance', 'select', 'hold', 'reject'] as AppAction[]).map((a) => ({
    label: APP_ACTIONS[a].label,
    icon: APP_ACTIONS[a].icon,
    danger: APP_ACTIONS[a].danger,
    onClick: (ids, clear) => open(a, ids, clear),
  }));
}

export interface PendingBulk {
  action: AppAction;
  ids: number[];
  clear?: () => void;
}

/** Confirmation + remarks for a workflow action on one or more applications. */
export function ApplicationActionModal({ pending, onClose, onDone }: { pending: PendingBulk | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = usePlacementRefresh();
  const [remarks, setRemarks] = useState('');
  const [date, setDate] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (pending) {
      setRemarks('');
      setError(null);
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      setDate(now.toISOString().slice(0, 16));
    }
  }, [pending]);
  if (!pending) return null;
  const def = APP_ACTIONS[pending.action];
  const n = pending.ids.length;
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await api.post<{ updated: number; skipped: number }>('placement/applications/bulk', { action: pending.action, ids: pending.ids, remarks, date: pending.action === 'advance' ? date : undefined });
      if (res.data.updated) toast.success(res.message);
      else toast.warning(res.message);
      pending.clear?.();
      await refresh('placement_applications', 'placement_interviews', 'placement_drives');
      onDone?.();
      onClose();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="md"
      title={`${def.verb} ${n} application${n === 1 ? '' : 's'}?`}
      description={def.help}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="placement-app-action" loading={saving} variant={def.danger ? 'danger' : pending.action === 'select' ? 'success' : 'primary'} icon={def.icon}>
            {def.verb}
          </Button>
        </>
      }
    >
      <form id="placement-app-action" onSubmit={submit} noValidate className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        {pending.action === 'advance' && (
          <Field label="Round held on" htmlFor="pa-date" hint="Used for rounds that were not scheduled in advance.">
            <Input id="pa-date" type="datetime-local" value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
        )}
        <Field label={pending.action === 'advance' ? 'Feedback (optional)' : 'Remarks (optional)'} htmlFor="pa-remarks">
          <Textarea id="pa-remarks" rows={3} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)}
            placeholder={pending.action === 'reject' ? 'e.g. Did not meet the cut-off in the technical round' : pending.action === 'hold' ? 'e.g. Waitlisted by the company' : ''} />
        </Field>
      </form>
    </Modal>
  );
}
