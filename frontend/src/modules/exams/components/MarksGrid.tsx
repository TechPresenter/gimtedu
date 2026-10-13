import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { useBlocker } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertCircle, CheckCircle2, CircleDashed, CloudUpload, Download, FileSpreadsheet, FileText, Keyboard, Lock, Save, Send, Upload, UserX,
} from 'lucide-react';
import { Alert, Avatar, Button, Card, Dropdown, EmptyState, ProgressBar, SearchInput, Select, Spinner, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, downloadFile, toFormData, type ApiError } from '@/lib/api';
import { useLocalStorage } from '@/lib/hooks';
import { useInvalidate } from '@/lib/queries';
import { formatDate, timeAgo } from '@/lib/format';
import { Chip, fmtMarks, timeRange } from './shared';
import type { GradeBand, MarkRow, MarksSheet } from './types';

type Field = 'internal_marks' | 'external_marks' | 'practical_marks';
interface Entry {
  internal_marks: string;
  external_marks: string;
  practical_marks: string;
  is_absent: boolean;
  remarks: string;
}
const FIELDS: { key: Field; label: string; max: 'max_internal' | 'max_external' | 'max_practical' }[] = [
  { key: 'internal_marks', label: 'Internal', max: 'max_internal' },
  { key: 'external_marks', label: 'External', max: 'max_external' },
  { key: 'practical_marks', label: 'Practical', max: 'max_practical' },
];

const toEntry = (r: MarkRow): Entry => ({
  internal_marks: r.internal_marks === null ? '' : fmtMarks(r.internal_marks),
  external_marks: r.external_marks === null ? '' : fmtMarks(r.external_marks),
  practical_marks: r.practical_marks === null ? '' : fmtMarks(r.practical_marks),
  is_absent: r.is_absent,
  remarks: r.remarks ?? '',
});
const same = (a: Entry, b: Entry) => a.internal_marks === b.internal_marks && a.external_marks === b.external_marks && a.practical_marks === b.practical_marks && a.is_absent === b.is_absent && a.remarks.trim() === b.remarks.trim();

/** Mirrors exam_parse_mark() on the server. */
function validateMark(raw: string, max: number, label: string): string | null {
  const v = raw.trim().replace(',', '.');
  if (v === '') return null;
  if (!/^\d+(\.\d+)?$/.test(v)) return `${label} must be a number.`;
  const n = Number(v);
  if (max <= 0 && n !== 0) return `${label} is not assessed in this paper.`;
  if (n > max) return `${label} cannot exceed ${max}.`;
  if (Math.abs(n * 2 - Math.round(n * 2)) > 0.0001) return `${label} can only use half marks (e.g. 23.5).`;
  return null;
}

/** Short inline version of a cell error (the full message is in the tooltip and the footer). */
function shortError(err: string, max: number): string {
  if (/exceed/i.test(err)) return `Max ${max}`;
  if (/half marks/i.test(err)) return 'Half marks only';
  if (/must be a number/i.test(err)) return 'Numbers only';
  if (/not assessed/i.test(err)) return 'Not assessed';
  if (/negative/i.test(err)) return 'Min 0';
  if (/missing/i.test(err)) return 'Required';
  return 'Invalid';
}

/** Mirrors exam_mark_compute() so faculty see the total / grade before saving. */
function compute(e: Entry, s: MarksSheet['subject'], grades: GradeBand[]) {
  const vals = FIELDS.map((f) => (s[f.max] > 0 && e[f.key].trim() !== '' ? Number(e[f.key].replace(',', '.')) : null));
  const any = vals.some((v) => v !== null);
  if (!any && !e.is_absent) return null;
  if (e.is_absent) return { total: vals[0] ?? 0, grade: 'AB', gp: 0, pass: false, complete: true };
  const complete = FIELDS.every((f, i) => s[f.max] <= 0 || vals[i] !== null);
  const total = vals.reduce<number>((a, v) => a + (v ?? 0), 0);
  const pct = s.max_total > 0 ? (total * 100) / s.max_total : 0;
  const pass = total >= s.pass_marks;
  let band = grades.find((g) => pct >= g.min - 0.0001 && pct <= g.max + 0.0099) ?? grades.find((g) => pct >= g.min) ?? grades[grades.length - 1];
  if (band && pass && !band.is_pass) band = [...grades].reverse().find((g) => g.is_pass) ?? band;
  if (band && !pass && band.is_pass) band = [...grades].reverse().find((g) => !g.is_pass) ?? band;
  return { total, grade: band?.grade ?? '—', gp: band?.grade_point ?? 0, pass, complete };
}

export function MarksGrid({ sheet, examId, sectionId, onSaved }: { sheet: MarksSheet; examId: number; sectionId: number | null; onSaved: (s: MarksSheet) => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const s = sheet.subject;
  const cols = FIELDS.filter((f) => s[f.max] > 0);
  const editable = sheet.can_edit;
  const [orig, setOrig] = useState<Record<number, Entry>>({});
  const [entries, setEntries] = useState<Record<number, Entry>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<false | 'save' | 'auto' | 'submit'>(false);
  const [lastSaved, setLastSaved] = useState<string | null>(null);
  const [autosave, setAutosave] = useLocalStorage<boolean>('gimt.marks.autosave', true);
  const [filter, setFilter] = useState('');
  const [show, setShow] = useState('all');
  const [importInfo, setImportInfo] = useState<{ message: string; problems: { row: number; message: string }[] } | null>(null);
  const [importing, setImporting] = useState(false);
  const inputs = useRef<Map<string, HTMLInputElement>>(new Map());
  const fileRef = useRef<HTMLInputElement>(null);

  const origRef = useRef<Record<number, Entry>>({});
  const sentRef = useRef<Record<number, Entry>>({});
  useEffect(() => {
    // Server state arrived (first load, after a save or a refetch): keep edits typed while a save was in flight.
    const server: Record<number, Entry> = {};
    sheet.rows.forEach((r) => (server[r.student_id] = toEntry(r)));
    const oldOrig = origRef.current;
    const sent = sentRef.current;
    setEntries((prev) => {
      const next = { ...server };
      Object.entries(prev).forEach(([k, local]) => {
        const sid = Number(k);
        const o = oldOrig[sid];
        if (server[sid] && o && !same(local, o) && !(sent[sid] && same(local, sent[sid]))) next[sid] = local;
      });
      return next;
    });
    origRef.current = server;
    sentRef.current = {};
    setOrig(server);
    setServerErrors({});
  }, [sheet]);

  const errors = useMemo(() => {
    const out: Record<string, string> = {};
    Object.entries(entries).forEach(([sid, e]) => {
      cols.forEach((f) => {
        if (e.is_absent && f.key !== 'internal_marks') return;
        const err = validateMark(e[f.key], s[f.max], f.label);
        if (err) out[`rows.${sid}.${f.key}`] = err;
      });
    });
    return { ...serverErrors, ...out };
  }, [entries, serverErrors, cols, s]);

  const dirtyIds = useMemo(() => Object.keys(entries).map(Number).filter((id) => orig[id] && !same(entries[id], orig[id])), [entries, orig]);
  const dirty = dirtyIds.length > 0;
  const hasErrors = Object.keys(errors).length > 0;

  const rowsShown = useMemo(() => {
    const q = filter.trim().toLowerCase();
    return sheet.rows.filter((r) => {
      if (q && !`${r.name} ${r.roll_no ?? ''} ${r.student_uid}`.toLowerCase().includes(q)) return false;
      const e = entries[r.student_id];
      const c = e ? compute(e, s, sheet.grades) : null;
      if (show === 'pending') return !c || !c.complete;
      if (show === 'entered') return !!c && c.complete;
      if (show === 'failing') return !!c && !c.pass;
      if (show === 'absent') return !!e?.is_absent;
      return true;
    });
  }, [sheet.rows, filter, show, entries, s, sheet.grades]);

  const stats = useMemo(() => {
    let entered = 0, absent = 0, passed = 0, sum = 0, present = 0;
    Object.values(entries).forEach((e) => {
      const c = compute(e, s, sheet.grades);
      if (!c || !c.complete) return;
      entered++;
      if (e.is_absent) absent++;
      else {
        present++;
        sum += c.total;
        if (c.pass) passed++;
      }
    });
    return { entered, absent, passed, average: present ? sum / present : null, passPct: present ? (100 * passed) / present : null };
  }, [entries, s, sheet.grades]);

  const save = useCallback(
    async (mode: 'save' | 'auto' | 'submit') => {
      if (mode !== 'submit' && !dirtyIds.length) return;
      if (hasErrors) {
        if (mode !== 'auto') toast.error('Fix the highlighted marks before saving.');
        return;
      }
      setSaving(mode);
      setServerErrors({});
      try {
        const rows = dirtyIds.map((id) => ({ student_id: id, ...entries[id] }));
        if (!rows.length) {
          // Nothing to save - just submit what is already stored
          const r = await api.post(`exams/${examId}/marks/${s.id}/submit`);
          const fresh = await api.get<MarksSheet>(`exams/${examId}/marks/${s.id}`, { section_id: sectionId ?? undefined });
          toast.success(r.message);
          onSaved(fresh);
          invalidate('exams');
          return;
        }
        sentRef.current = Object.fromEntries(rows.map((r) => [r.student_id, entries[r.student_id]]));
        const res = await api.post<{ saved: number; deleted: number; submitted: boolean; submit_error: string | null; sheet: MarksSheet }>(`exams/${examId}/marks/${s.id}`, {
          rows,
          submit: mode === 'submit',
          autosave: mode === 'auto',
          section_id: sectionId,
        });
        setLastSaved(new Date().toISOString());
        if (res.data.submit_error) toast.warning(res.message);
        else if (mode !== 'auto') toast.success(res.message);
        onSaved(res.data.sheet);
        invalidate('exams');
      } catch (err) {
        const ex = err as ApiError;
        setServerErrors(ex.errors ?? {});
        toast.error(ex.message);
      } finally {
        setSaving(false);
      }
    },
    [dirtyIds, hasErrors, entries, examId, s.id, sectionId, sheet.rows, toast, onSaved, invalidate],
  );

  // Autosave 2s after the last change
  useEffect(() => {
    if (!autosave || !editable || !dirty || hasErrors || saving) return;
    const t = window.setTimeout(() => save('auto'), 2000);
    return () => window.clearTimeout(t);
  }, [autosave, editable, dirty, hasErrors, saving, entries, save]);

  // Ctrl+S
  useEffect(() => {
    const h = (ev: globalThis.KeyboardEvent) => {
      if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
        ev.preventDefault();
        if (editable) save('save');
      }
    };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [save, editable]);

  // Unsaved changes guard
  useEffect(() => {
    if (!dirty) return;
    const h = (ev: BeforeUnloadEvent) => {
      ev.preventDefault();
      ev.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname + currentLocation.search !== nextLocation.pathname + nextLocation.search);
  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    confirm({ title: 'Discard unsaved marks?', message: `${dirtyIds.length} student${dirtyIds.length === 1 ? ' has' : 's have'} unsaved marks. Leave without saving?`, confirmText: 'Discard changes', danger: true }).then((ok) =>
      ok ? blocker.proceed() : blocker.reset(),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [blocker.state]);

  const setField = (sid: number, patch: Partial<Entry>) => {
    setEntries((prev) => ({ ...prev, [sid]: { ...prev[sid], ...patch } }));
    if (Object.keys(serverErrors).length) {
      setServerErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => !k.startsWith(`rows.${sid}.`))));
    }
  };

  const focusCell = (row: number, col: number) => {
    const el = inputs.current.get(`${row}:${col}`);
    if (el) {
      el.focus();
      el.select();
    }
  };
  const onKey = (ev: KeyboardEvent<HTMLInputElement>, row: number, col: number) => {
    const el = ev.currentTarget;
    if (ev.key === 'Enter' || ev.key === 'ArrowDown') {
      ev.preventDefault();
      focusCell(ev.shiftKey && ev.key === 'Enter' ? row - 1 : row + 1, col);
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      focusCell(row - 1, col);
    } else if (ev.key === 'ArrowRight' && el.selectionStart === el.value.length) {
      ev.preventDefault();
      focusCell(row, col + 1);
    } else if (ev.key === 'ArrowLeft' && el.selectionStart === 0 && el.selectionEnd === 0) {
      ev.preventDefault();
      focusCell(row, col - 1);
    } else if (ev.key.toLowerCase() === 'a' && (ev.altKey || el.value === '')) {
      // "A" in an empty cell (or Alt+A) toggles absent
      ev.preventDefault();
      const sid = Number(el.dataset.sid);
      setField(sid, { is_absent: !entries[sid]?.is_absent });
    } else if (ev.key === 'Escape') {
      const sid = Number(el.dataset.sid);
      const f = el.dataset.field as Field;
      if (orig[sid]) setField(sid, { [f]: orig[sid][f] } as Partial<Entry>);
    }
  };

  const doImport = async (file: File) => {
    setImporting(true);
    try {
      const res = await api.post<{ rows: { student_id: number; internal_marks?: number | null; external_marks?: number | null; practical_marks?: number | null; is_absent: boolean; remarks?: string | null }[]; problems: { row: number; message: string }[] }>(
        `exams/${examId}/marks/${s.id}/import`,
        toFormData({ file }),
      );
      setEntries((prev) => {
        const next = { ...prev };
        res.data.rows.forEach((r) => {
          if (!next[r.student_id]) return;
          next[r.student_id] = {
            internal_marks: r.internal_marks === null || r.internal_marks === undefined ? '' : fmtMarks(r.internal_marks),
            external_marks: r.external_marks === null || r.external_marks === undefined ? '' : fmtMarks(r.external_marks),
            practical_marks: r.practical_marks === null || r.practical_marks === undefined ? '' : fmtMarks(r.practical_marks),
            is_absent: !!r.is_absent,
            remarks: r.remarks ?? next[r.student_id].remarks,
          };
        });
        return next;
      });
      setImportInfo({ message: res.message, problems: res.data.problems });
      toast.info(res.message);
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setImporting(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const submit = async () => {
    const missing = sheet.rows.length - stats.entered;
    if (missing > 0) {
      toast.error(`Marks are missing for ${missing} student${missing === 1 ? '' : 's'}. Enter them or mark the students absent before submitting.`);
      setShow('pending');
      return;
    }
    const ok = await confirm({ title: 'Submit marks for verification?', message: `${s.code} ${s.name}: ${stats.entered} students, ${stats.passPct === null ? 0 : stats.passPct.toFixed(1)}% pass. The exam controller is notified to verify the marks.`, confirmText: 'Submit' });
    if (ok) save('submit');
  };

  const pct = sheet.rows.length ? (100 * stats.entered) / sheet.rows.length : 0;
  const statusLine = saving === 'auto' ? (
    <span className="inline-flex items-center gap-1.5 text-slate-500"><Spinner className="h-3.5 w-3.5" /> Autosaving…</span>
  ) : dirty ? (
    <span className="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-400"><CircleDashed className="h-3.5 w-3.5" /> {dirtyIds.length} unsaved change{dirtyIds.length === 1 ? '' : 's'}</span>
  ) : lastSaved ? (
    <span className="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400"><CheckCircle2 className="h-3.5 w-3.5" /> All changes saved {timeAgo(lastSaved)}</span>
  ) : (
    <span className="inline-flex items-center gap-1.5 text-slate-500"><CheckCircle2 className="h-3.5 w-3.5" /> Up to date</span>
  );
  const submittedAll = sheet.stats.submitted >= sheet.rows.length && sheet.rows.length > 0;

  return (
    <div className="space-y-4">
      {sheet.lock_reason && (
        <Alert variant={sheet.exam.status === 'published' || sheet.exam.status === 'verified' ? 'info' : 'warning'} title={editable ? undefined : 'Read-only'}>
          <span className="inline-flex items-center gap-1.5"><Lock className="h-3.5 w-3.5" /> {sheet.lock_reason}</span>
        </Alert>
      )}
      {importInfo && (
        <Alert variant={importInfo.problems.length ? 'warning' : 'success'} title="File imported — review and save" action={<Button size="xs" variant="ghost" onClick={() => setImportInfo(null)}>Dismiss</Button>}>
          {importInfo.message}
          {importInfo.problems.length > 0 && (
            <ul className="mt-1 list-disc pl-4 text-xs">
              {importInfo.problems.slice(0, 6).map((p, i) => <li key={i}>Row {p.row}: {p.message}</li>)}
              {importInfo.problems.length > 6 && <li>…and {importInfo.problems.length - 6} more</li>}
            </ul>
          )}
        </Alert>
      )}

      <Card className="overflow-hidden">
        <div className="space-y-4 border-b border-slate-100 p-4 sm:p-5 dark:border-slate-800">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div className="min-w-0">
              <h2 className="font-display text-lg font-semibold text-slate-900 dark:text-white">
                <span className="font-mono text-sm text-slate-500">{s.code}</span> {s.name}
              </h2>
              <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                {cols.map((f) => `${f.label} ${s[f.max]}`).join(' + ')} = <strong className="text-slate-700 dark:text-slate-200">{s.max_total}</strong> · pass {s.pass_marks} · {s.credits} credits
                {s.schedule ? ` · paper ${formatDate(s.schedule.exam_date)}, ${timeRange(s.schedule.start_time, s.schedule.end_time)}` : ''}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Dropdown
                label="Export marks"
                trigger={<><Download className="h-4 w-4" /> Export</>}
                items={[
                  { label: 'CSV (also the import template)', icon: FileText, onClick: () => downloadFile(`exams/${examId}/marks/${s.id}/export`, { format: 'csv', section_id: sectionId ?? undefined }) },
                  { label: 'Excel', icon: FileSpreadsheet, onClick: () => downloadFile(`exams/${examId}/marks/${s.id}/export`, { format: 'xlsx', section_id: sectionId ?? undefined }) },
                ]}
              />
              {editable && (
                <>
                  <input ref={fileRef} type="file" accept=".csv,.xlsx" className="sr-only" aria-label="Import marks file" onChange={(ev) => ev.target.files?.[0] && doImport(ev.target.files[0])} />
                  <Button size="sm" variant="secondary" icon={Upload} loading={importing} onClick={() => fileRef.current?.click()}>
                    Import
                  </Button>
                  <Button size="sm" variant="secondary" icon={Save} loading={saving === 'save'} disabled={!dirty || !!saving} onClick={() => save('save')}>
                    Save
                  </Button>
                  <Button size="sm" icon={Send} loading={saving === 'submit'} disabled={!!saving || submittedAll && !dirty} onClick={submit}>
                    {submittedAll && !dirty ? 'Submitted' : 'Submit'}
                  </Button>
                </>
              )}
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-center">
            <div>
              <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span className="font-medium text-slate-600 dark:text-slate-300">
                  {stats.entered} of {sheet.rows.length} entered · {Math.round(pct)}% complete
                </span>
                {editable && statusLine}
              </div>
              <ProgressBar value={pct} tone={pct >= 100 ? 'green' : 'blue'} label="Marks entry progress" />
            </div>
            <div className="flex flex-wrap gap-2">
              <Chip label="Absent" value={stats.absent} tone="slate" />
              <Chip label="Pass" value={stats.passPct === null ? '—' : `${stats.passPct.toFixed(1)}%`} tone="green" />
              <Chip label="Average" value={stats.average === null ? '—' : fmtMarks(stats.average, 1)} tone="blue" />
            </div>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchInput value={filter} onChange={setFilter} placeholder="Find student…" className="sm:w-64" size="sm" />
            <Select inputSize="sm" value={show} onChange={(ev) => setShow(ev.target.value)} aria-label="Show" className="sm:!w-44"
              options={[{ value: 'all', label: 'All students' }, { value: 'pending', label: 'Pending entry' }, { value: 'entered', label: 'Entered' }, { value: 'failing', label: 'Failing' }, { value: 'absent', label: 'Absent' }]} />
            {editable && (
              <div className="flex items-center gap-4 sm:ml-auto">
                <span className="hidden items-center gap-1.5 text-xs text-slate-500 xl:inline-flex" title="Enter / ↓ next student · ↑ previous · ← → move between columns · A in an empty cell = absent · Esc undo cell · Ctrl+S save">
                  <Keyboard className="h-3.5 w-3.5" /> Enter ↓ · ↑ · ← → · A = absent · Ctrl+S
                </span>
                <Toggle checked={autosave} onChange={setAutosave} label="Autosave" />
              </div>
            )}
          </div>
        </div>

        {!sheet.rows.length ? (
          <EmptyState icon={UserX} title="No eligible students" description="Allocate eligible students to the exam before entering marks." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead className="sticky top-0 z-[1] bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/80 dark:text-slate-400">
                <tr>
                  <th className="w-10 px-3 py-2.5 text-left font-semibold">#</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Student</th>
                  {cols.map((f) => (
                    <th key={f.key} className="w-28 px-2 py-2.5 text-center font-semibold">
                      {f.label}
                      <span className="block text-[10px] font-medium normal-case text-slate-400">max {s[f.max]}</span>
                    </th>
                  ))}
                  <th className="w-16 px-2 py-2.5 text-center font-semibold">Absent</th>
                  <th className="w-20 px-2 py-2.5 text-center font-semibold">Total</th>
                  <th className="w-20 px-2 py-2.5 text-center font-semibold">Grade</th>
                  <th className="hidden w-48 px-2 py-2.5 text-left font-semibold lg:table-cell">Remarks</th>
                  <th className="w-10 px-2 py-2.5" aria-label="Row status" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rowsShown.map((r, ri) => {
                  const e = entries[r.student_id];
                  if (!e) return null;
                  const c = compute(e, s, sheet.grades);
                  const isDirty = orig[r.student_id] && !same(e, orig[r.student_id]);
                  const rowErr = Object.keys(errors).find((k) => k.startsWith(`rows.${r.student_id}.`));
                  return (
                    <tr key={r.student_id} className={clsx('transition-colors', isDirty ? 'bg-amber-50/60 dark:bg-amber-500/[.06]' : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40', e.is_absent && 'text-slate-500')}>
                      <td className="px-3 py-2 text-xs tabular-nums text-slate-400">{ri + 1}</td>
                      <td className="px-3 py-2">
                        <div className="flex min-w-0 items-center gap-2.5">
                          <Avatar name={r.name} src={r.photo} size="sm" />
                          <div className="min-w-0 leading-tight">
                            <p className="truncate font-medium text-slate-900 dark:text-white">{r.name}</p>
                            <p className="truncate text-xs text-slate-500">
                              {r.roll_no ?? r.student_uid}
                              {r.section_name ? ` · Sec ${r.section_name}` : ''}
                              {r.attendance_status === 'absent' && <span className="ml-1 font-medium text-red-600">· absent at paper</span>}
                              {r.attendance_status === 'malpractice' && <span className="ml-1 font-medium text-violet-600">· UFM case</span>}
                            </p>
                          </div>
                        </div>
                      </td>
                      {cols.map((f, ci) => {
                        const key = `rows.${r.student_id}.${f.key}`;
                        const err = errors[key];
                        const disabled = !editable || (e.is_absent && f.key !== 'internal_marks');
                        return (
                          <td key={f.key} className="px-2 py-1.5 text-center">
                            <input
                              ref={(el) => {
                                if (el) inputs.current.set(`${ri}:${ci}`, el);
                                else inputs.current.delete(`${ri}:${ci}`);
                              }}
                              data-sid={r.student_id}
                              data-field={f.key}
                              type="text"
                              inputMode="decimal"
                              autoComplete="off"
                              value={disabled && e.is_absent && f.key !== 'internal_marks' ? 'AB' : e[f.key]}
                              disabled={disabled}
                              onChange={(ev) => setField(r.student_id, { [f.key]: ev.target.value } as Partial<Entry>)}
                              onKeyDown={(ev) => onKey(ev, ri, ci)}
                              onFocus={(ev) => ev.currentTarget.select()}
                              aria-label={`${f.label} marks of ${r.name}`}
                              aria-invalid={!!err || undefined}
                              title={err ?? undefined}
                              aria-describedby={err ? `err-${r.student_id}-${f.key}` : undefined}
                              className={clsx(
                                'h-9 w-20 rounded-lg border bg-white px-2 text-center text-sm tabular-nums outline-none transition focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400 dark:bg-slate-900 dark:disabled:bg-slate-800/60',
                                err ? 'border-red-400 text-red-700 focus:ring-red-200 dark:border-red-500/70 dark:text-red-300 dark:focus:ring-red-500/20' : 'border-slate-200 focus:border-brand-500 focus:ring-brand-100 dark:border-slate-700 dark:focus:ring-brand-500/20',
                              )}
                            />
                            {err && (
                              <p id={`err-${r.student_id}-${f.key}`} className="mt-0.5 text-[10px] font-medium leading-tight text-red-600 dark:text-red-400" role="alert">
                                {shortError(err, s[f.max])}
                              </p>
                            )}
                          </td>
                        );
                      })}
                      <td className="px-2 py-1.5 text-center">
                        <input
                          type="checkbox"
                          className="form-checkbox"
                          checked={e.is_absent}
                          disabled={!editable}
                          onChange={(ev) => setField(r.student_id, { is_absent: ev.target.checked, ...(ev.target.checked ? { external_marks: '', practical_marks: '' } : {}) })}
                          aria-label={`${r.name} absent`}
                        />
                      </td>
                      <td className="px-2 py-1.5 text-center font-semibold tabular-nums text-slate-900 dark:text-white">{c && !rowErr ? fmtMarks(c.total) : <span className="text-slate-300 dark:text-slate-600">—</span>}</td>
                      <td className="px-2 py-1.5 text-center">
                        {rowErr ? (
                          <span className="whitespace-nowrap text-xs font-medium text-red-600 dark:text-red-400">Invalid</span>
                        ) : c && c.complete ? (
                          <span className={clsx('inline-flex min-w-[2.25rem] justify-center rounded-md px-1.5 py-0.5 text-xs font-bold', c.grade === 'AB' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : c.pass ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300')}>
                            {c.grade}
                          </span>
                        ) : c ? (
                          <span className="text-xs text-amber-600 dark:text-amber-400">Incomplete</span>
                        ) : (
                          <span className="text-slate-300 dark:text-slate-600">—</span>
                        )}
                      </td>
                      <td className="hidden px-2 py-1.5 lg:table-cell">
                        <input
                          type="text"
                          value={e.remarks}
                          maxLength={255}
                          disabled={!editable}
                          onChange={(ev) => setField(r.student_id, { remarks: ev.target.value })}
                          placeholder=""
                          aria-label={`Remarks for ${r.name}`}
                          className="h-9 w-full rounded-lg border border-transparent bg-transparent px-2 text-xs outline-none transition hover:border-slate-200 focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-100 disabled:hover:border-transparent dark:hover:border-slate-700 dark:focus:bg-slate-900"
                        />
                      </td>
                      <td className="px-2 py-1.5 text-center">
                        {rowErr ? (
                          <span title={errors[rowErr]} className="inline-flex">
                            <AlertCircle className="mx-auto h-4 w-4 text-red-500" aria-label={errors[rowErr]} />
                          </span>
                        ) : isDirty ? (
                          <CloudUpload className="mx-auto h-4 w-4 text-amber-500" aria-label="Unsaved" />
                        ) : r.entered ? (
                          <CheckCircle2 className={clsx('mx-auto h-4 w-4', r.status === 'draft' ? 'text-slate-300 dark:text-slate-600' : 'text-emerald-500')} aria-label={r.status ?? 'Saved'} />
                        ) : null}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            {!rowsShown.length && <p className="py-10 text-center text-sm text-slate-500">No students match this filter.</p>}
          </div>
        )}
        {hasErrors && (
          <div className="flex items-center gap-2 border-t border-red-100 bg-red-50 px-5 py-2.5 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
            <AlertCircle className="h-4 w-4 shrink-0" /> {Object.keys(errors).length} cell{Object.keys(errors).length === 1 ? '' : 's'} need attention: {Object.values(errors)[0]}
          </div>
        )}
      </Card>
    </div>
  );
}
