import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { Ban, FileBadge, Printer, ShieldCheck, UserRound } from 'lucide-react';
import { Alert, Avatar, Button, Drawer, Field, Modal, Skeleton, Textarea, useToast } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { api, type ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { useApi, useInvalidate } from '@/lib/queries';
import { formatDateTime } from '@/lib/format';
import { ResultBadge, fmtGpa, fmtMarks } from './shared';
import type { ResultDetail } from './types';

export function ResultDrawer({ resultId, onClose }: { resultId: number | null; onClose: () => void }) {
  const q = useApi<ResultDetail>(['results', 'detail', resultId], `results/${resultId}`, undefined, { enabled: !!resultId });
  const d = q.data;
  const r = d?.result;
  const [withhold, setWithhold] = useState(false);
  const toast = useToast();
  const invalidate = useInvalidate();
  const [busy, setBusy] = useState(false);
  const finals = (d?.history ?? []).filter((h) => h.is_final && h.sgpa !== null);

  const release = async () => {
    if (!r) return;
    setBusy(true);
    try {
      const res = await api.post(`results/${r.id}/release`);
      toast.success(res.message);
      await invalidate('results', 'crud', 'exams');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Drawer
      open={!!resultId}
      onClose={onClose}
      width="max-w-2xl"
      title={r ? r.student_name : 'Result'}
      description={r ? `${r.roll_no ?? r.student_uid} · ${r.exam_name}` : undefined}
      footer={
        r && (
          <>
            <Button variant="ghost" icon={UserRound} to={`/students/${r.student_id}?tab=results`}>
              Student profile
            </Button>
            {r.marksheet_id && r.marksheet_status === 'issued' ? (
              <Button variant="secondary" icon={FileBadge} href={printUrl('marksheet.php', { id: r.marksheet_id })} target="_blank">
                Print marksheet
              </Button>
            ) : r.is_published && r.result_status !== 'WITHHELD' ? (
              <Button variant="secondary" icon={Printer} href={printUrl('marksheet.php', { result_id: r.id })} target="_blank">
                Provisional statement
              </Button>
            ) : null}
            {d?.can_withhold && ['verified', 'published'].includes(r.exam_status) &&
              (r.result_status === 'WITHHELD' ? (
                <Button icon={ShieldCheck} loading={busy} onClick={release}>
                  Release result
                </Button>
              ) : (
                <Button variant="danger" icon={Ban} onClick={() => setWithhold(true)} disabled={!!r.marksheet_id && r.marksheet_status === 'issued'} title={r.marksheet_id && r.marksheet_status === 'issued' ? 'Revoke the marksheet first' : undefined}>
                  Withhold
                </Button>
              ))}
          </>
        )
      }
    >
      {q.error ? (
        <Alert variant="error">{(q.error as ApiError).message}</Alert>
      ) : !d || !r ? (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full rounded-xl" />
          {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
        </div>
      ) : (
        <div className="space-y-5">
          <div className="flex items-center gap-4 rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
            <Avatar name={r.student_name} src={r.photo} size="lg" />
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-900 dark:text-white">{r.student_name}</p>
              <p className="text-xs text-slate-500">{r.program_short} · Sem {r.semester_no}{r.section_name ? ` · Sec ${r.section_name}` : ''} · {r.session_name}</p>
              <div className="mt-1.5 flex flex-wrap items-center gap-2">
                <ResultBadge status={r.result_status} />
                {r.class_rank && <span className="text-xs font-medium text-slate-600 dark:text-slate-300">Rank {r.class_rank}</span>}
                {r.division && <span className="text-xs text-slate-500">{r.division}</span>}
                {!r.is_published && <span className="text-xs font-medium text-amber-600">Not published</span>}
              </div>
            </div>
          </div>
          {r.result_status === 'WITHHELD' && (
            <Alert variant="warning" title="Result withheld">
              {r.withheld_reason}
              {r.withheld_at && <span className="block text-xs opacity-80">{r.withheld_by_name ? `${r.withheld_by_name} · ` : ''}{formatDateTime(r.withheld_at)}</span>}
            </Alert>
          )}
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {[
              ['Total', `${fmtMarks(r.total_marks)} / ${fmtMarks(r.max_marks, 0)}`],
              ['Percentage', r.percentage === null ? '—' : `${fmtMarks(r.percentage)}%`],
              ['SGPA', r.is_final ? fmtGpa(r.sgpa) : '—'],
              ['CGPA', r.is_final ? fmtGpa(r.cgpa) : '—'],
            ].map(([l, v]) => (
              <div key={l} className="rounded-xl border border-slate-200 p-3 text-center dark:border-slate-800">
                <p className="text-xs text-slate-500">{l}</p>
                <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{v}</p>
              </div>
            ))}
          </div>
          <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
            <table className="w-full min-w-[520px] text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60">
                <tr>
                  <th className="px-3 py-2 text-left font-semibold">Subject</th>
                  <th className="px-2 py-2 text-center font-semibold">Int</th>
                  <th className="px-2 py-2 text-center font-semibold">Ext</th>
                  <th className="px-2 py-2 text-center font-semibold">Prac</th>
                  <th className="px-2 py-2 text-center font-semibold">Total</th>
                  <th className="px-2 py-2 text-center font-semibold">Grade</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {d.subjects.map((s) => {
                  const cell = (v: number | null, max: number) => (max > 0 ? <>{v === null ? (s.is_absent ? 'AB' : '—') : fmtMarks(v)}<span className="text-[10px] text-slate-400">/{max}</span></> : <span className="text-slate-300">—</span>);
                  return (
                    <tr key={s.code}>
                      <td className="px-3 py-2">
                        <p className="font-medium text-slate-900 dark:text-white">{s.name}</p>
                        <p className="text-xs text-slate-500"><span className="font-mono">{s.code}</span> · {s.credits} cr</p>
                      </td>
                      <td className="px-2 py-2 text-center tabular-nums">{cell(s.internal_marks, s.max_internal)}</td>
                      <td className="px-2 py-2 text-center tabular-nums">{cell(s.external_marks, s.max_external)}</td>
                      <td className="px-2 py-2 text-center tabular-nums">{cell(s.practical_marks, s.max_practical)}</td>
                      <td className="px-2 py-2 text-center font-semibold tabular-nums">{s.total_marks === null ? '—' : fmtMarks(s.total_marks)}</td>
                      <td className="px-2 py-2 text-center">
                        <span className={clsx('inline-flex min-w-[2rem] justify-center rounded-md px-1.5 py-0.5 text-xs font-bold', s.is_absent ? 'bg-slate-100 text-slate-600 dark:bg-slate-800' : s.is_pass ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300')}>
                          {s.grade ?? '—'}
                        </span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {finals.length > 1 && (
            <div>
              <p className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">SGPA / CGPA trend</p>
              <LineChart labels={finals.map((h) => `Sem ${h.semester_no}`)} series={[{ label: 'SGPA', data: finals.map((h) => Number(h.sgpa)) }, { label: 'CGPA', data: finals.map((h) => Number(h.cgpa ?? 0)), dashed: true }]} height={200} />
            </div>
          )}
          {d.history.length > 0 && (
            <div>
              <p className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Published results</p>
              <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                {d.history.map((h) => (
                  <li key={h.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <div className="min-w-0">
                      <p className="truncate font-medium text-slate-800 dark:text-slate-100">{h.exam_name}</p>
                      <p className="text-xs text-slate-500">{h.session_name}{h.marksheet_no ? ` · ${h.marksheet_no}` : ''}</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2 text-xs">
                      <span className="tabular-nums text-slate-600 dark:text-slate-300">{h.sgpa !== null ? `SGPA ${fmtGpa(h.sgpa)}` : h.percentage !== null ? `${fmtMarks(h.percentage)}%` : ''}</span>
                      <ResultBadge status={h.result_status} />
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <p className="text-center text-xs text-slate-500">
            <Link to={`/examination/${r.exam_id}`} className="hover:underline">Open the exam workflow →</Link>
          </p>
        </div>
      )}
      {r && <WithholdModal open={withhold} onClose={() => setWithhold(false)} resultId={r.id} name={r.student_name} />}
    </Drawer>
  );
}

export function WithholdModal({ open, onClose, resultId, name }: { open: boolean; onClose: () => void; resultId: number; name: string }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const res = await api.post(`results/${resultId}/withhold`, { reason });
      toast.success(res.message);
      await invalidate('results', 'crud', 'exams');
      setReason('');
      onClose();
    } catch (e) {
      const ex = e as ApiError;
      if (ex.errors?.reason) setError(ex.errors.reason);
      else toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="md"
      title="Withhold result"
      description={name}
      icon={<span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15"><Ban className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="danger" icon={Ban} loading={saving} onClick={save}>Withhold result</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-300">The result is hidden from the student, excluded from ranks and no marksheet can be generated until it is released.</p>
      <Field label="Reason" required error={error} htmlFor="wh-reason" hint="e.g. Unfair means case under enquiry, pending fee clearance, document verification pending.">
        <Textarea id="wh-reason" rows={3} value={reason} onChange={(ev) => setReason(ev.target.value)} invalid={!!error} maxLength={255} />
      </Field>
    </Modal>
  );
}
