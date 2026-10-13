import clsx from 'clsx';

export interface ScaleRow {
  id: number;
  grade: string;
  min_percent: number | string;
  max_percent: number | string;
  grade_point: number | string;
  description?: string | null;
  is_pass: number | string | boolean;
}

export interface ScaleIssue {
  type: 'gap' | 'overlap';
  from: number;
  to: number;
  message: string;
}

/** Same rules as grade_scale_issues() on the server. */
export function scaleIssues(rows: ScaleRow[]): ScaleIssue[] {
  const sorted = [...rows].sort((a, b) => Number(a.min_percent) - Number(b.min_percent));
  const out: ScaleIssue[] = [];
  if (!sorted.length) return out;
  if (Number(sorted[0].min_percent) > 0) out.push({ type: 'gap', from: 0, to: Number(sorted[0].min_percent), message: `No grade covers 0% to ${Number(sorted[0].min_percent)}%.` });
  for (let i = 1; i < sorted.length; i++) {
    const p = sorted[i - 1];
    const c = sorted[i];
    const pMax = Number(p.max_percent);
    const cMin = Number(c.min_percent);
    if (cMin <= pMax) out.push({ type: 'overlap', from: cMin, to: pMax, message: `Grades ${p.grade} and ${c.grade} overlap between ${cMin}% and ${pMax}%.` });
    else if (cMin - pMax > 0.011) out.push({ type: 'gap', from: pMax, to: cMin, message: `No grade covers ${pMax}% to ${cMin}% (between ${p.grade} and ${c.grade}).` });
  }
  const last = sorted[sorted.length - 1];
  if (Number(last.max_percent) < 99.99) out.push({ type: 'gap', from: Number(last.max_percent), to: 100, message: `No grade covers ${Number(last.max_percent)}% to 100%.` });
  return out;
}

const PASS_COLORS = ['#15803D', '#16A34A', '#22C55E', '#65A30D', '#0EA5E9', '#2563EB', '#6366F1', '#8B5CF6', '#A855F7'];

/** Visual 0-100% bar with one segment per grade band (fail bands red, gaps hatched). */
export function GradeScaleBar({ rows }: { rows: ScaleRow[] }) {
  const sorted = [...rows].sort((a, b) => Number(b.min_percent) - Number(a.min_percent));
  const passBands = sorted.filter((r) => Number(r.is_pass));
  const color = (r: ScaleRow) => (Number(r.is_pass) ? PASS_COLORS[Math.min(passBands.indexOf(r), PASS_COLORS.length - 1)] : '#EF4444');
  const issues = scaleIssues(rows);
  const asc = [...sorted].reverse();
  const passMin = passBands.length ? Math.min(...passBands.map((r) => Number(r.min_percent))) : null;
  return (
    <div>
      <div className="relative">
        <div className="relative flex h-14 w-full overflow-hidden rounded-xl bg-[repeating-linear-gradient(45deg,#fee2e2,#fee2e2_6px,#fff_6px,#fff_12px)] ring-1 ring-slate-200 dark:bg-[repeating-linear-gradient(45deg,rgba(239,68,68,.2),rgba(239,68,68,.2)_6px,transparent_6px,transparent_12px)] dark:ring-slate-700">
          {asc.map((r) => {
            const left = Math.max(0, Number(r.min_percent));
            const width = Math.max(0.6, Math.min(100, Number(r.max_percent) + 0.01) - left);
            return (
              <div
                key={r.id}
                className="group absolute inset-y-0 flex flex-col items-center justify-center border-r border-white/70 text-white transition-[filter] hover:brightness-110 dark:border-slate-900/60"
                style={{ left: `${left}%`, width: `${width}%`, background: color(r) }}
                title={`${r.grade}: ${Number(r.min_percent)}% – ${Number(r.max_percent)}% · ${Number(r.grade_point)} points${r.description ? ` · ${r.description}` : ''}`}
              >
                <span className="font-display text-sm font-bold leading-none drop-shadow-sm">{r.grade}</span>
                {width > 6 && <span className="mt-0.5 text-[10px] font-medium leading-none opacity-90">{Number(r.grade_point)} GP</span>}
              </div>
            );
          })}
        </div>
        {passMin !== null && (
          <div className="pointer-events-none absolute -bottom-1.5 top-[-6px] w-0.5 rounded bg-slate-900 dark:bg-white" style={{ left: `${passMin}%` }} aria-hidden>
            <span className="absolute -top-5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-1.5 py-0.5 text-[10px] font-semibold text-white dark:bg-white dark:text-slate-900">Pass {passMin}%</span>
          </div>
        )}
      </div>
      <div className="mt-2 flex justify-between text-[11px] tabular-nums text-slate-400">
        {[0, 20, 40, 60, 80, 100].map((t) => (
          <span key={t}>{t}%</span>
        ))}
      </div>
      <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        {sorted.map((r) => (
          <div key={r.id} className="flex items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-1.5 dark:border-slate-800">
            <span className="h-3 w-3 shrink-0 rounded-sm" style={{ background: color(r) }} aria-hidden />
            <div className="min-w-0 leading-tight">
              <p className="text-xs font-bold text-slate-900 dark:text-white">{r.grade} <span className="font-medium text-slate-500">· {Number(r.grade_point)}</span></p>
              <p className="truncate text-[10px] text-slate-500">{Number(r.min_percent)}–{Number(r.max_percent)}%</p>
            </div>
          </div>
        ))}
      </div>
      {issues.length > 0 && (
        <ul className="mt-4 space-y-1">
          {issues.map((i, k) => (
            <li key={k} className={clsx('text-xs font-medium', i.type === 'overlap' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400')}>
              {i.type === 'overlap' ? 'Overlap: ' : 'Gap: '}
              {i.message}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
