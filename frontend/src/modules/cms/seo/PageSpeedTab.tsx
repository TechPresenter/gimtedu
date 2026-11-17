import { useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { CircleAlert, CircleCheck, CircleHelp, Gauge, History, Monitor, Play, Plus, Smartphone, Square, Timer, X } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, EmptyState, Field, Input, ProgressBar, Reveal, Skeleton } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';

interface Run {
  id: number;
  url: string;
  strategy: 'mobile' | 'desktop';
  performance_score: number | null;
  seo_score: number | null;
  accessibility_score: number | null;
  best_practices_score: number | null;
  lcp_ms: number | null;
  cls: number | null;
  tbt_ms: number | null;
  fcp_ms: number | null;
  field_lcp_ms: number | null;
  field_inp_ms: number | null;
  field_cls: number | null;
  opportunities: { id: string; title: string; savings_ms: number; display: string }[];
  error: string | null;
  created_at: string;
  created_by_name?: string | null;
  cwv: { source: 'field' | 'lab'; checks: { lcp: boolean | null; inp: boolean | null; cls: boolean | null }; pass: boolean | null };
}
interface Payload {
  runs: Run[];
  latest: Run[];
  origin: string | null;
  site_is_local: boolean;
  default_paths: string[];
  thresholds: { lcp_ms: number; inp_ms: number; cls: number; tbt_ms: number };
  has_key: boolean;
  can: { run: boolean };
}
interface QueueItem { url: string; strategy: 'mobile' | 'desktop'; state: 'queued' | 'running' | 'done' | 'failed'; message?: string }

const sec = (ms: number | null) => (ms === null ? '—' : `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} s`);
const msFmt = (ms: number | null) => (ms === null ? '—' : `${ms.toLocaleString('en-IN')} ms`);
const clsFmt = (v: number | null) => (v === null ? '—' : v.toFixed(3).replace(/0+$/, '').replace(/\.$/, '.0'));
const shortUrl = (u: string) => u.replace(/^https?:\/\/[^/]+/i, '') || '/';

function ScoreRing({ score, size = 44 }: { score: number | null; size?: number }) {
  const tone = score === null ? 'text-slate-300 dark:text-slate-600' : score >= 90 ? 'text-emerald-500' : score >= 50 ? 'text-amber-500' : 'text-red-500';
  const r = 16;
  const c = 2 * Math.PI * r;
  return (
    <span className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }} role="img" aria-label={score === null ? 'No score' : `Score ${score} of 100`}>
      <svg viewBox="0 0 40 40" className="absolute inset-0 -rotate-90" aria-hidden>
        <circle cx="20" cy="20" r={r} fill="none" strokeWidth="4" className="stroke-slate-100 dark:stroke-slate-800" />
        <circle cx="20" cy="20" r={r} fill="none" strokeWidth="4" strokeLinecap="round" className={clsx('stroke-current', tone)} strokeDasharray={c} strokeDashoffset={c - (c * (score ?? 0)) / 100} />
      </svg>
      <span className={clsx('relative text-xs font-bold tabular-nums', tone)}>{score ?? '—'}</span>
    </span>
  );
}

function Verdict({ ok, label, value }: { ok: boolean | null; label: string; value: string }) {
  const Icon = ok === null ? CircleHelp : ok ? CircleCheck : CircleAlert;
  return (
    <span className={clsx('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold', ok === null ? 'bg-slate-100 text-slate-500 dark:bg-slate-800' : ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300')}>
      <Icon className="h-3 w-3" aria-hidden />{label} <span className="tabular-nums">{value}</span>
    </span>
  );
}

function RunMetrics({ r }: { r: Run }) {
  const field = r.cwv.source === 'field';
  return (
    <div className="flex flex-wrap gap-1">
      <Verdict ok={r.cwv.checks.lcp} label="LCP" value={sec(field ? r.field_lcp_ms : r.lcp_ms)} />
      {field ? <Verdict ok={r.cwv.checks.inp} label="INP" value={msFmt(r.field_inp_ms)} /> : <Verdict ok={r.cwv.checks.inp} label="TBT" value={msFmt(r.tbt_ms)} />}
      <Verdict ok={r.cwv.checks.cls} label="CLS" value={clsFmt(field ? r.field_cls : r.cls)} />
    </div>
  );
}

export default function PageSpeedTab() {
  const qc = useQueryClient();
  const { can } = useAuth();
  const q = useApi<Payload>(['seo', 'pagespeed'], 'seo-ai/pagespeed');
  const d = q.data;
  const canRun = (d?.can.run ?? false) && can('seo', 'edit');
  const [origin, setOrigin] = useState('');
  const [paths, setPaths] = useState<Record<string, boolean>>({});
  const [extra, setExtra] = useState('');
  const [extraPaths, setExtraPaths] = useState<string[]>([]);
  const [strategies, setStrategies] = useState({ mobile: true, desktop: false });
  const [queue, setQueue] = useState<QueueItem[]>([]);
  const [running, setRunning] = useState(false);
  const [originError, setOriginError] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const stopRef = useRef(false);

  useEffect(() => {
    if (!d) return;
    setOrigin((o) => o || d.origin || '');
    setPaths((p) => (Object.keys(p).length ? p : Object.fromEntries(d.default_paths.map((x, i) => [x, i < 3]))));
  }, [d]);

  const allPaths = useMemo(() => [...(d?.default_paths ?? []), ...extraPaths.filter((p) => !(d?.default_paths ?? []).includes(p))], [d, extraPaths]);
  const selected = allPaths.filter((p) => paths[p]);
  const strategyList = (['mobile', 'desktop'] as const).filter((s) => strategies[s]);
  const total = selected.length * strategyList.length;

  const addExtra = () => {
    let p = extra.trim();
    if (!p) return;
    p = p.replace(/^https?:\/\/[^/]+/i, '');
    p = p.startsWith('/') ? p : `/${p}`;
    if (!/^\/[^\s"'<>]*$/.test(p)) return;
    setExtraPaths((x) => (x.includes(p) ? x : [...x, p]));
    setPaths((x) => ({ ...x, [p]: true }));
    setExtra('');
  };

  const run = async () => {
    const base = origin.trim().replace(/\/+$/, '');
    if (!/^https?:\/\/[a-z0-9.-]+(:\d+)?$/i.test(base)) {
      setOriginError('Enter the public website address, e.g. https://www.example.com');
      return;
    }
    setOriginError(null);
    const items: QueueItem[] = selected.flatMap((p) => strategyList.map((s) => ({ url: base + (p === '/' ? '/' : p), strategy: s, state: 'queued' as const })));
    setQueue(items);
    setRunning(true);
    stopRef.current = false;
    for (let i = 0; i < items.length; i++) {
      if (stopRef.current) break;
      setQueue((qq) => qq.map((x, j) => (j === i ? { ...x, state: 'running' } : x)));
      try {
        const res = await api.post<Run>('seo-ai/pagespeed/run', { url: items[i].url, strategy: items[i].strategy });
        setQueue((qq) => qq.map((x, j) => (j === i ? { ...x, state: 'done', message: `Performance ${res.data.performance_score ?? '—'}/100` } : x)));
      } catch (e) {
        const err = e as ApiError;
        setQueue((qq) => qq.map((x, j) => (j === i ? { ...x, state: 'failed', message: err.errors?.url ?? err.message } : x)));
        if (err.status === 429 || err.status === 403) stopRef.current = true; // quota / permission: the rest would fail too
      }
      void qc.invalidateQueries({ queryKey: ['seo', 'pagespeed'] });
      if (i < items.length - 1 && !stopRef.current) await new Promise((r) => window.setTimeout(r, 1500));
    }
    setRunning(false);
  };

  const done = queue.filter((x) => x.state === 'done' || x.state === 'failed').length;
  const t = d?.thresholds;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader icon={Gauge} title="Run PageSpeed Insights" subtitle="Google’s lab test (Lighthouse) plus real-visitor Core Web Vitals when Google has enough data for the page" />
        <div className="card-body space-y-4">
          {q.error ? <Alert variant="error">{(q.error as ApiError).message}</Alert> : q.isLoading || !d ? <Skeleton className="h-40 w-full" /> : (
            <>
              {d.site_is_local && <Alert variant="info">This copy runs on a local address that Google cannot reach. Enter the public website address to test the live pages.</Alert>}
              {!d.has_key && <Alert variant="warning">No PageSpeed API key is set, so runs use Google’s small shared daily quota and may fail with “quota exceeded”. Add a free key under Settings › Integrations.</Alert>}
              <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div className="space-y-4">
                  <Field label="Website address" htmlFor="psi-origin" error={originError} hint={d.site_is_local ? 'The public https:// address of this website.' : 'Only pages of this website can be tested.'}>
                    <Input id="psi-origin" value={origin} disabled={!canRun || running || (!d.site_is_local && !!d.origin)} placeholder="https://www.example.com" invalid={!!originError}
                      onChange={(e) => { setOrigin(e.target.value.trim()); setOriginError(null); }} />
                  </Field>
                  <fieldset>
                    <legend className="form-label">Device</legend>
                    <div className="mt-1 flex gap-4">
                      <Checkbox label="Mobile" checked={strategies.mobile} disabled={running} onChange={(e) => setStrategies((s) => ({ ...s, mobile: e.target.checked }))} />
                      <Checkbox label="Desktop" checked={strategies.desktop} disabled={running} onChange={(e) => setStrategies((s) => ({ ...s, desktop: e.target.checked }))} />
                    </div>
                  </fieldset>
                  <p className="text-xs text-slate-500">
                    “Good” Core Web Vitals: LCP ≤ {t ? t.lcp_ms / 1000 : 2.5} s, INP ≤ {t?.inp_ms ?? 200} ms, CLS ≤ {t?.cls ?? 0.1}. Without real-visitor data the lab test decides, with Total Blocking Time ≤ {t?.tbt_ms ?? 200} ms standing in for INP.
                  </p>
                </div>
                <fieldset className="min-w-0">
                  <legend className="form-label">Pages</legend>
                  <div className="mt-1 grid grid-cols-1 gap-1.5">
                    {allPaths.map((p) => (
                      <Checkbox key={p} label={<span className="break-all font-mono text-xs">{p}</span>} checked={!!paths[p]} disabled={running} onChange={(e) => setPaths((x) => ({ ...x, [p]: e.target.checked }))} />
                    ))}
                  </div>
                  <div className="mt-3 flex gap-2">
                    <Input value={extra} onChange={(e) => setExtra(e.target.value)} placeholder="/another-page" aria-label="Add a page path" disabled={running} inputSize="sm"
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addExtra(); } }} />
                    <Button size="sm" variant="secondary" icon={Plus} onClick={addExtra} disabled={running || !extra.trim()}>Add</Button>
                  </div>
                </fieldset>
              </div>
              <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                {running && <Button variant="ghost" icon={Square} onClick={() => { stopRef.current = true; }}>Stop after this test</Button>}
                {canRun ? <Button icon={Play} loading={running} disabled={!total} onClick={() => void run()}>{running ? `Testing ${done + 1} of ${queue.length}…` : `Run ${total} test${total === 1 ? '' : 's'}`}</Button>
                  : <p className="text-xs text-slate-500">Your role can view results but not start tests.</p>}
              </div>
              {queue.length > 0 && (
                <div className="space-y-2" aria-live="polite">
                  <ProgressBar value={queue.length ? (done / queue.length) * 100 : 0} />
                  <ul className="grid grid-cols-1 gap-1.5 md:grid-cols-2">
                    {queue.map((x, i) => (
                      <li key={i} className="flex items-start gap-2 rounded-lg border border-slate-100 px-2.5 py-1.5 text-xs dark:border-slate-800">
                        {x.strategy === 'mobile' ? <Smartphone className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" aria-label="Mobile" /> : <Monitor className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" aria-label="Desktop" />}
                        <span className="min-w-0 flex-1">
                          <span className="block truncate font-mono">{shortUrl(x.url)}</span>
                          {x.message && <span className={clsx('block', x.state === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-slate-500')}>{x.message}</span>}
                        </span>
                        <Badge color={x.state === 'done' ? 'green' : x.state === 'failed' ? 'red' : x.state === 'running' ? 'blue' : 'slate'}>{x.state === 'running' ? 'Testing…' : x.state}</Badge>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}
        </div>
      </Card>

      <Reveal>
        <Card className="overflow-hidden">
          <CardHeader icon={Timer} title="Latest result per page" subtitle="Core Web Vitals verdict from real visitors (field) when available, else from the lab test" />
          {q.isLoading ? <div className="p-5"><Skeleton className="h-40 w-full" /></div> : !d || d.latest.length === 0 ? (
            <EmptyState icon={Gauge} title="No PageSpeed results yet" description="Choose pages above and run the first test." />
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {d.latest.map((r) => (
                <li key={r.id} className="px-4 py-3">
                  <div className="flex flex-wrap items-center gap-3">
                    <ScoreRing score={r.error ? null : r.performance_score} />
                    <div className="min-w-0 flex-1">
                      <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-white">
                        {r.strategy === 'mobile' ? <Smartphone className="h-3.5 w-3.5 text-slate-400" aria-label="Mobile" /> : <Monitor className="h-3.5 w-3.5 text-slate-400" aria-label="Desktop" />}
                        <a href={r.url} target="_blank" rel="noreferrer noopener" className="truncate font-mono hover:text-brand-700 dark:hover:text-brand-300">{shortUrl(r.url)}</a>
                      </p>
                      <p className="text-[11px] text-slate-500">{formatDateTime(r.created_at)} · {r.error ? 'failed' : `${r.cwv.source === 'field' ? 'Real-visitor data' : 'Lab data'}`}</p>
                    </div>
                    {r.error ? <p className="w-full text-xs text-red-600 sm:w-auto sm:max-w-md dark:text-red-400">{r.error}</p> : (
                      <>
                        <RunMetrics r={r} />
                        <Badge color={r.cwv.pass === null ? 'slate' : r.cwv.pass ? 'green' : 'red'}>{r.cwv.pass === null ? 'CWV unknown' : r.cwv.pass ? 'CWV passed' : 'CWV failed'}</Badge>
                        {r.opportunities.length > 0 && <Button size="xs" variant="ghost" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}>{open === r.id ? 'Hide' : 'Fixes'}</Button>}
                      </>
                    )}
                  </div>
                  {open === r.id && (
                    <ul className="mt-2 space-y-1 rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/50">
                      {r.opportunities.map((o) => (
                        <li key={o.id} className="flex justify-between gap-3"><span className="text-slate-700 dark:text-slate-200">{o.title}</span><span className="shrink-0 tabular-nums text-slate-500">{o.savings_ms ? `≈ ${sec(o.savings_ms)}` : o.display}</span></li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </Reveal>

      <Reveal>
        <Card className="overflow-hidden">
          <CardHeader icon={History} title="History" subtitle={d ? `${d.runs.length} most recent runs` : 'Every run is kept'} />
          {q.isLoading ? <div className="p-5"><Skeleton className="h-40 w-full" /></div> : !d || d.runs.length === 0 ? (
            <EmptyState icon={History} title="No runs yet" />
          ) : (
            <div className="max-h-[520px] overflow-auto">
              <table className="data-table">
                <caption className="sr-only">PageSpeed run history</caption>
                <thead>
                  <tr><th>Date</th><th>Page</th><th className="text-center">Perf.</th><th className="text-center">SEO</th><th className="text-center">Access.</th><th className="text-center">Best pr.</th><th>LCP</th><th>TBT</th><th>CLS</th><th>Field (LCP / INP / CLS)</th></tr>
                </thead>
                <tbody>
                  {d.runs.map((r) => (
                    <tr key={r.id}>
                      <td className="whitespace-nowrap text-xs">{formatDateTime(r.created_at)}</td>
                      <td className="max-w-[260px]">
                        <span className="flex items-center gap-1 truncate font-mono text-xs">{r.strategy === 'mobile' ? <Smartphone className="h-3 w-3 shrink-0" aria-label="Mobile" /> : <Monitor className="h-3 w-3 shrink-0" aria-label="Desktop" />}{shortUrl(r.url)}</span>
                        {r.error && <span className="flex items-start gap-1 text-[11px] text-red-600 dark:text-red-400"><X className="mt-px h-3 w-3 shrink-0" aria-hidden /><span className="line-clamp-2" title={r.error}>{r.error}</span></span>}
                      </td>
                      {[r.performance_score, r.seo_score, r.accessibility_score, r.best_practices_score].map((v, i) => (
                        <td key={i} className={clsx('text-center font-semibold tabular-nums', v === null ? 'text-slate-400' : v >= 90 ? 'text-emerald-600 dark:text-emerald-400' : v >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400')}>{v ?? '—'}</td>
                      ))}
                      <td className="whitespace-nowrap text-xs tabular-nums">{sec(r.lcp_ms)}</td>
                      <td className="whitespace-nowrap text-xs tabular-nums">{msFmt(r.tbt_ms)}</td>
                      <td className="whitespace-nowrap text-xs tabular-nums">{clsFmt(r.cls)}</td>
                      <td className="whitespace-nowrap text-xs tabular-nums">{r.field_lcp_ms === null && r.field_inp_ms === null ? <span className="text-slate-400">no field data</span> : `${sec(r.field_lcp_ms)} / ${msFmt(r.field_inp_ms)} / ${clsFmt(r.field_cls)}`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Reveal>
    </div>
  );
}
