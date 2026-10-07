import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, ArrowRight, CheckCircle2, Copy, Users } from 'lucide-react';
import { Alert, Button, EmptyState, Field, Modal, PageLoader, RadioGroup, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useCrudList } from '@/lib/queries';
import type { TtSectionContext } from '../types';

interface CopyReport {
  copied: number;
  skipped: number;
  without_faculty: number;
  without_room: number;
  notes: string[];
}

/** Copy a section's weekly timetable to another section of the same program & semester. */
export function CopyModal({ open, onClose, section, sessionId, onDone }: { open: boolean; onClose: () => void; section: TtSectionContext; sessionId: number; onDone: () => void }) {
  const toast = useToast();
  const navigate = useNavigate();
  const [target, setTarget] = useState('');
  const [mode, setMode] = useState('replace');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [report, setReport] = useState<CopyReport | null>(null);
  const { data, isLoading } = useCrudList(
    'sections',
    { per_page: 50, f: { program_id: section.program_id, semester_no: section.semester_no, academic_session_id: sessionId } },
    open,
  );
  const options = (data?.rows ?? []).filter((r) => Number(r.id) !== section.id);

  useEffect(() => {
    if (open) {
      setTarget('');
      setMode('replace');
      setError(null);
      setReport(null);
    }
  }, [open]);
  useEffect(() => {
    if (open && !target && options.length) setTarget(String(options[0].id));
  }, [open, options, target]);

  const submit = async () => {
    if (!target) {
      setError('Choose the section to copy to.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await api.post<CopyReport>('timetable/copy', { from_section_id: section.id, to_section_id: Number(target), mode, session_id: sessionId });
      setReport(res.data);
      toast.success(res.message);
      onDone();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.to_section_id ?? err.message);
    } finally {
      setSaving(false);
    }
  };

  const targetRow = options.find((o) => String(o.id) === target);
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="md"
      title="Copy timetable"
      description={`From ${section.label} to another section of ${section.program_short} Semester ${section.semester_no}.`}
      footer={
        report ? (
          <>
            <Button variant="secondary" onClick={onClose}>Close</Button>
            <Button iconRight={ArrowRight} onClick={() => { onClose(); navigate(`/timetable?section=${target}&session=${sessionId}`); }}>
              Open {targetRow ? `Sec ${targetRow.name}` : 'target'}
            </Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
            <Button icon={Copy} onClick={submit} loading={saving} disabled={!options.length}>Copy timetable</Button>
          </>
        )
      }
    >
      {isLoading ? (
        <PageLoader label="Loading sections…" />
      ) : report ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              ['Copied', report.copied, 'text-emerald-700 dark:text-emerald-300'],
              ['Skipped', report.skipped, 'text-slate-700 dark:text-slate-200'],
              ['No faculty', report.without_faculty, report.without_faculty ? 'text-amber-700 dark:text-amber-300' : 'text-slate-700 dark:text-slate-200'],
              ['No room', report.without_room, report.without_room ? 'text-amber-700 dark:text-amber-300' : 'text-slate-700 dark:text-slate-200'],
            ].map(([label, n, cls]) => (
              <div key={String(label)} className="rounded-xl border border-slate-200 p-3 text-center dark:border-slate-700">
                <p className={clsx('font-display text-2xl font-bold', String(cls))}>{Number(n)}</p>
                <p className="text-xs text-slate-500">{String(label)}</p>
              </div>
            ))}
          </div>
          {report.notes.length > 0 ? (
            <Alert variant="warning" title="Some periods need attention">
              <ul className="mt-1 max-h-40 list-disc space-y-0.5 overflow-y-auto pl-4 text-xs">
                {report.notes.map((n, i) => (
                  <li key={i}>{n}</li>
                ))}
              </ul>
            </Alert>
          ) : (
            <p className="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">
              <CheckCircle2 className="h-4 w-4" /> Every period was copied with its faculty and room.
            </p>
          )}
        </div>
      ) : options.length === 0 ? (
        <EmptyState
          icon={Users}
          title="No other section to copy to"
          description={`${section.program_short} Semester ${section.semester_no} has only one section in this session. Add another section first.`}
          action={<Button variant="secondary" to={`/academics?tab=sections&f.program_id=${section.program_id}`}>Manage sections</Button>}
        />
      ) : (
        <div className="space-y-5">
          <Field label="Copy to section" required htmlFor="copy-target" error={error}>
            <select id="copy-target" className={clsx('form-input', error && 'form-input-error')} value={target} onChange={(e) => setTarget(e.target.value)}>
              {options.map((o) => (
                <option key={String(o.id)} value={String(o.id)}>
                  {String(o.label)} · {String(o.strength_label)} students · timetable {String(o.timetable_status).replace('_', ' ')}
                </option>
              ))}
            </select>
          </Field>
          <Field label="How should existing periods be handled?">
            <RadioGroup
              name="copy-mode"
              inline={false}
              value={mode}
              onChange={setMode}
              options={[
                { value: 'replace', label: 'Replace - clear the target timetable first' },
                { value: 'merge', label: 'Merge - only fill the target’s empty periods' },
              ]}
            />
          </Field>
          <p className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
            The target section’s own faculty assignments are used where they exist. Faculty or rooms that would clash are left blank and listed so you can fix them.
          </p>
        </div>
      )}
    </Modal>
  );
}
