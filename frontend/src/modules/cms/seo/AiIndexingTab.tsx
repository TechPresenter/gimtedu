import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Activity, Bot, CircleCheck, ExternalLink, FileText, ListChecks, Radar, Send, Settings2, ShieldBan, ShieldCheck, Zap } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, Reveal, Select, Skeleton, useToast } from '@/components/ui';
import { BarChart } from '@/components/charts';
import { api, type ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';

interface PolicyRow { bot: string; vendor: string; purpose: 'search' | 'user' | 'training' | string; allowed: boolean; robots?: string; doc?: string; note?: string; overridden?: boolean }
interface AiStatus {
  llms: { enabled: boolean; available: boolean; url: string; full_url: string; status: number | null; bytes: number | null; method: string; error: string | null; custom: boolean };
  policy: PolicyRow[];
  policy_available: boolean;
  ai_search_crawlers: string;
  ai_training_crawlers: string;
  robots_custom: boolean;
  robots_url: string;
  block_indexing: boolean;
  can: { edit: boolean; settings: boolean };
}
interface IndexNowRun { id: number; trigger_type: string; url_count: number; urls_sample: string | null; http_status: number | null; response: string | null; created_at: string; created_by_name: string | null; ok: boolean }
interface IndexNow {
  enabled: boolean; key: string; key_url: string | null; endpoint: string; pending_count: number; pending_sample?: string[]; submitted_urls?: number; sitemap_urls?: number;
  blocked_reason?: string | null; last_run?: string | null; last_runs: IndexNowRun[]; can?: { submit: boolean; key: boolean };
}
interface Bots {
  days: number; total: number; tracking: boolean; recorder: boolean;
  bots: { bot: string; label: string; vendor: string | null; purpose: string; allowed: boolean | null; hits: number; last_seen: string | null; last_path: string | null }[];
  by_purpose: Record<string, number>; purpose_labels: Record<string, string>; labels: string[]; series: { purpose: string; label: string; data: number[] }[];
}
interface Overview { index_status: Record<string, number>; index_status_labels: Record<string, string>; total: number }

const PURPOSE_COLOR: Record<string, BadgeColor> = { search: 'blue', ai_search: 'green', ai_user: 'cyan', ai_training: 'purple', social: 'navy', seo_tool: 'amber', other: 'slate' };
const PURPOSE_HEX: Record<string, string> = { search: '#2563EB', ai_search: '#10B981', ai_user: '#06B6D4', ai_training: '#8B5CF6', social: '#183C7A', seo_tool: '#F59E0B', other: '#94A3B8' };
const POLICY_PURPOSE: Record<string, string> = { search: 'AI search', user: 'User fetch', training: 'AI training' };
const INDEX_COLOR: Record<string, string> = { indexed: 'bg-emerald-500', submitted: 'bg-blue-500', excluded: 'bg-amber-500', error: 'bg-red-500', unknown: 'bg-slate-300 dark:bg-slate-600' };

export default function AiIndexingTab() {
  const toast = useToast();
  const qc = useQueryClient();
  const { can } = useAuth();
  const [days, setDays] = useState(30);
  const [submitting, setSubmitting] = useState(false);
  const status = useApi<AiStatus>(['seo', 'ai-status'], 'seo-ai/ai-status');
  const indexnow = useApi<IndexNow>(['seo', 'indexnow'], 'integrations/indexnow', undefined, { retry: false });
  const bots = useApi<Bots>(['seo', 'bots', days], 'seo-ai/bots', { days });
  const overview = useApi<Overview>(['seo', 'overview'], 'seo/overview');
  const s = status.data;
  const inw = indexnow.data;
  const b = bots.data;
  const inwMissing = (indexnow.error as ApiError | null)?.status === 404;

  const submit = async () => {
    setSubmitting(true);
    try {
      const res = await api.post<{ submitted: number; http_status: number | null; message: string }>('integrations/indexnow/submit', { mode: 'changed' });
      toast.success(res.message || `Submitted ${res.data.submitted} URL(s).`);
      void qc.invalidateQueries({ queryKey: ['seo', 'indexnow'] });
      void qc.invalidateQueries({ queryKey: ['seo', 'presence'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSubmitting(false);
    }
  };

  const idx = overview.data;
  const idxTotal = idx ? Object.values(idx.index_status).reduce((a, n) => a + n, 0) : 0;

  return (
    <div className="space-y-6">
      {s?.block_indexing && <Alert variant="warning" title="Indexing is switched off">Settings › Integrations › Search engines discourages indexing: robots.txt disallows everything and IndexNow submissions are blocked.</Alert>}
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        {/* IndexNow */}
        <Card className="h-full">
          <CardHeader icon={Zap} title="IndexNow" subtitle="Tells Bing, Yandex, Naver, Seznam and others about changed URLs right away (Google does not take part)"
            actions={inw && (inw.can?.submit ?? can('seo', 'edit')) && inw.enabled && (
              <Button size="sm" icon={Send} loading={submitting} disabled={!!inw.blocked_reason} onClick={() => void submit()}>Submit changed URLs</Button>
            )} />
          <div className="card-body space-y-3">
            {inwMissing ? <Alert variant="info">IndexNow is not installed on this server yet.</Alert> : indexnow.error ? <Alert variant="error">{(indexnow.error as ApiError).message}</Alert> : indexnow.isLoading || !inw ? <Skeleton className="h-36 w-full" /> : (
              <>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge color={inw.enabled ? 'green' : 'slate'} dot>{inw.enabled ? 'Enabled' : 'Switched off'}</Badge>
                  {inw.enabled && <Badge color={inw.pending_count ? 'amber' : 'green'}>{inw.pending_count ? `${formatNumber(inw.pending_count)} URL(s) waiting` : 'Nothing waiting'}</Badge>}
                  {inw.submitted_urls !== undefined && inw.sitemap_urls !== undefined && <span className="text-xs text-slate-500">{formatNumber(inw.submitted_urls)} of {formatNumber(inw.sitemap_urls)} sitemap URLs submitted at least once</span>}
                </div>
                {inw.blocked_reason && <Alert variant="warning">{inw.blocked_reason}</Alert>}
                {!inw.enabled && <p className="text-sm text-slate-600 dark:text-slate-300">Switch IndexNow on under Settings › Integrations — a key and its key file are created automatically.</p>}
                {inw.enabled && inw.key_url && (
                  <p className="text-xs text-slate-500">Key file: <a href={inw.key_url} target="_blank" rel="noreferrer noopener" className="break-all font-mono text-brand-700 hover:underline dark:text-brand-300">{inw.key_url}</a></p>
                )}
                {inw.pending_sample && inw.pending_sample.length > 0 && (
                  <details className="text-xs text-slate-500"><summary className="cursor-pointer">Waiting to be submitted</summary>
                    <ul className="mt-1 space-y-0.5 font-mono">{inw.pending_sample.map((u) => <li key={u} className="truncate">{u}</li>)}</ul>
                  </details>
                )}
                {inw.last_runs.length === 0 ? <p className="text-sm text-slate-500">Nothing submitted yet.</p> : (
                  <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 text-xs dark:divide-slate-800 dark:border-slate-700">
                    {inw.last_runs.slice(0, 5).map((r) => (
                      <li key={r.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                        <Badge color={r.ok ? 'green' : 'red'}>{r.http_status ? `HTTP ${r.http_status}` : 'No answer'}</Badge>
                        <span className="font-semibold tabular-nums">{formatNumber(r.url_count)} URL(s)</span>
                        <span className="text-slate-500">{r.trigger_type}</span>
                        <span className="ml-auto text-slate-500">{formatDateTime(r.created_at)}</span>
                        {r.response && <span className="w-full truncate text-slate-500" title={r.response}>{r.response}</span>}
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}
          </div>
        </Card>
        {/* llms.txt */}
        <Card className="h-full">
          <CardHeader icon={FileText} title="llms.txt" subtitle="A plain-text guide to the website for AI agents, generated from the website’s own data" />
          <div className="card-body space-y-3">
            {status.error ? <Alert variant="error">{(status.error as ApiError).message}</Alert> : status.isLoading || !s ? <Skeleton className="h-36 w-full" /> : (
              <>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge color={s.llms.enabled ? 'green' : 'slate'} dot>{s.llms.enabled ? 'Enabled' : 'Switched off'}</Badge>
                  {s.llms.enabled && <Badge color={s.llms.status === 200 ? 'green' : 'amber'}>{s.llms.status === 200 ? `Answers 200 · ${formatNumber(s.llms.bytes ?? 0)} bytes` : s.llms.status ? `HTTP ${s.llms.status}` : 'Not answering'}</Badge>}
                  {s.llms.custom && <Badge color="blue">Custom text</Badge>}
                </div>
                {s.llms.error && <Alert variant="warning">{s.llms.error}</Alert>}
                {s.llms.enabled && (
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="secondary" icon={ExternalLink} href={s.llms.url} target="_blank">/llms.txt</Button>
                    <Button size="sm" variant="secondary" icon={ExternalLink} href={s.llms.full_url} target="_blank">/llms-full.txt</Button>
                  </div>
                )}
                <p className="text-xs leading-relaxed text-slate-500">
                  Google Search ignores llms.txt (it neither helps nor harms rankings); some other AI tools read it. Switch it on or off, or replace the generated text, under Settings › Integrations.
                  {s.llms.method === 'inprocess' && ' On this development server the file is checked by generating it directly.'}
                </p>
              </>
            )}
          </div>
        </Card>
      </div>

      {/* Crawler activity */}
      <Reveal>
        <Card className="overflow-hidden">
          <CardHeader icon={Activity} title="Crawler activity" subtitle="Daily visits by search engine and AI crawlers (identified by user agent; no IP addresses are stored)"
            actions={<Select inputSize="sm" aria-label="Period" value={String(days)} onChange={(e) => setDays(Number(e.target.value))} options={[{ value: '7', label: 'Last 7 days' }, { value: '30', label: 'Last 30 days' }, { value: '90', label: 'Last 90 days' }]} />} />
          {bots.error ? <div className="p-5"><Alert variant="error">{(bots.error as ApiError).message}</Alert></div> : bots.isLoading || !b ? <div className="p-5"><Skeleton className="h-64 w-full" /></div> : b.total === 0 ? (
            <EmptyState icon={Radar} title="No crawler visits recorded yet" description={b.recorder ? 'Visits from Googlebot, Bingbot, GPTBot, ClaudeBot, PerplexityBot and others appear here once they crawl the website.' : 'Crawler visit counting is not installed on this server yet.'} />
          ) : (
            <>
              <div className="flex flex-wrap gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                {Object.entries(b.by_purpose).map(([p, n]) => <Badge key={p} color={PURPOSE_COLOR[p] ?? 'slate'}>{b.purpose_labels[p] ?? p}: <span className="tabular-nums">{formatNumber(n)}</span></Badge>)}
                <span className="ml-auto text-xs text-slate-500">{formatNumber(b.total)} visits since {formatDate(b.labels[0])}</span>
              </div>
              <div className="p-4">
                <BarChart height={220} stacked labels={b.labels.map((l) => formatDate(l).replace(/ \d{4}$/, ''))} series={b.series.map((x) => ({ label: x.label, data: x.data, color: PURPOSE_HEX[x.purpose] }))} ariaLabel="Crawler visits per day by crawler type" barThickness={14} />
              </div>
              <div className="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
                <table className="data-table">
                  <caption className="sr-only">Crawler visits by bot</caption>
                  <thead><tr><th>Crawler</th><th>Type</th><th className="text-right">Visits</th><th>Last seen</th><th className="hidden md:table-cell">Last page</th><th>robots.txt</th></tr></thead>
                  <tbody>
                    {b.bots.map((x) => (
                      <tr key={x.bot}>
                        <td><span className="font-medium">{x.label}</span>{x.vendor && <span className="block text-xs text-slate-500">{x.vendor}</span>}</td>
                        <td><Badge color={PURPOSE_COLOR[x.purpose] ?? 'slate'}>{b.purpose_labels[x.purpose] ?? x.purpose}</Badge></td>
                        <td className="text-right font-semibold tabular-nums">{formatNumber(x.hits)}</td>
                        <td className="whitespace-nowrap text-xs">{x.last_seen ? formatDate(x.last_seen) : '—'}</td>
                        <td className="hidden max-w-[260px] truncate font-mono text-xs md:table-cell" title={x.last_path ?? ''}>{x.last_path ?? '—'}</td>
                        <td>{x.allowed === null ? <span className="text-xs text-slate-400">—</span> : x.allowed ? <Badge color="green">Allowed</Badge> : <Badge color="red">Blocked</Badge>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </Card>
      </Reveal>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {/* AI crawler policy */}
        <Reveal className="xl:col-span-2">
          <Card className="h-full overflow-hidden">
            <CardHeader icon={Bot} title="AI crawler policy (robots.txt)"
              subtitle={s ? `AI search crawlers ${s.ai_search_crawlers === 'block' ? 'blocked' : 'allowed'} · AI training crawlers ${s.ai_training_crawlers === 'block' ? 'blocked' : 'allowed'}` : 'Which AI crawlers may read the website'}
              actions={s && (
                <>
                  <Button size="sm" variant="ghost" icon={ExternalLink} href={s.robots_url} target="_blank">robots.txt</Button>
                  {s.can.settings && <Button size="sm" variant="secondary" icon={Settings2} to="/settings?tab=integrations">Change</Button>}
                </>
              )} />
            {status.isLoading || !s ? <div className="p-5"><Skeleton className="h-48 w-full" /></div> : !s.policy_available ? (
              <div className="p-5"><Alert variant="info">The AI crawler policy is not installed on this server yet.</Alert></div>
            ) : (
              <>
                {s.robots_custom && <div className="px-4 pt-4"><Alert variant="info">robots.txt uses custom rules; the managed AI block is added to them, except for tokens the custom text already names (marked “custom rule”).</Alert></div>}
                <div className="max-h-[420px] overflow-auto">
                  <table className="data-table">
                    <caption className="sr-only">AI crawler policy</caption>
                    <thead><tr><th>Token</th><th>Purpose</th><th>Status</th><th className="hidden lg:table-cell">What it does</th></tr></thead>
                    <tbody>
                      {s.policy.map((p) => (
                        <tr key={p.bot}>
                          <td>
                            <span className="font-mono text-xs font-semibold">{p.bot}</span>
                            <span className="block text-xs text-slate-500">{p.vendor}{p.doc && <> · <a href={p.doc} target="_blank" rel="noreferrer noopener" className="link">docs</a></>}</span>
                          </td>
                          <td><Badge color={p.purpose === 'training' ? 'purple' : p.purpose === 'user' ? 'cyan' : 'green'}>{POLICY_PURPOSE[p.purpose] ?? p.purpose}</Badge></td>
                          <td className="whitespace-nowrap">
                            {p.allowed ? <span className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400"><ShieldCheck className="h-3.5 w-3.5" aria-hidden />Allowed</span>
                              : <span className="inline-flex items-center gap-1 text-xs font-semibold text-red-600 dark:text-red-400"><ShieldBan className="h-3.5 w-3.5" aria-hidden />Blocked</span>}
                            {p.overridden && <span className="block text-[11px] text-amber-600 dark:text-amber-400">custom rule</span>}
                          </td>
                          <td className="hidden max-w-[360px] text-xs text-slate-600 lg:table-cell dark:text-slate-300">{p.note}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </Card>
        </Reveal>
        {/* Indexing status of URLs */}
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader icon={ListChecks} title="Indexing status" subtitle="Recorded per URL in the SEO drawer after checking Search Console" />
            <div className="card-body">
              {overview.isLoading || !idx ? <Skeleton className="h-40 w-full" /> : (
                <>
                  <div className="flex h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label="Indexing status of public URLs">
                    {Object.entries(idx.index_status).map(([k, n]) => n > 0 && <span key={k} className={clsx('h-full', INDEX_COLOR[k] ?? 'bg-slate-400')} style={{ width: `${(n / Math.max(1, idxTotal)) * 100}%` }} />)}
                  </div>
                  <ul className="mt-4 space-y-1.5 text-sm">
                    {Object.entries(idx.index_status).map(([k, n]) => (
                      <li key={k} className="flex items-center gap-2">
                        <span className={clsx('h-2.5 w-2.5 rounded-full', INDEX_COLOR[k] ?? 'bg-slate-400')} aria-hidden />
                        <Link to={`/cms/seo?tab=routes&index=${k}`} className="text-slate-600 hover:text-brand-700 dark:text-slate-300 dark:hover:text-brand-300">{idx.index_status_labels[k] ?? k}</Link>
                        <span className="ml-auto font-semibold tabular-nums">{formatNumber(n)}</span>
                      </li>
                    ))}
                  </ul>
                  <p className="mt-4 flex items-start gap-1.5 text-xs text-slate-500"><CircleCheck className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden />Open a URL’s “Edit SEO” drawer to record what URL Inspection reports. Nothing here is read from Google automatically.</p>
                </>
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </div>
  );
}
