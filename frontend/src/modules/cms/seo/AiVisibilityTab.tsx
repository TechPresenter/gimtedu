import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Bot, Check, ClipboardList, Copy, Link2, MessageSquareQuote, Pencil, Plus, Quote, RotateCcw, Save, ShieldAlert } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, Field, Modal, Reveal, Skeleton, Stagger, StatTile, Textarea, useToast } from '@/components/ui';
import { BarChart } from '@/components/charts';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, type ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';

interface Stats {
  months: string[];
  series: { engine: string; label: string; data: (number | null)[] }[];
  by_engine: { engine: string; label: string; tests: number; mentioned: number; cited: number; mention_rate: number }[];
  totals: { tests: number; mentioned: number; cited: number; inaccurate: number; mention_rate: number; citation_rate: number; last_tested_on: string | null };
  engines: Record<string, string>;
}
interface Queries { queries: string[]; custom: boolean; defaults: string[]; engines: Record<string, string>; can: { edit: boolean } }

const monthLabel = (ym: string) => {
  const [y, m] = ym.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });
};

async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  }
}

export default function AiVisibilityTab() {
  const toast = useToast();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canEdit = can('seo', 'edit');
  const stats = useApi<Stats>(['seo', 'ai-stats'], 'seo-ai/ai-tests/stats', { months: 6 });
  const queries = useApi<Queries>(['seo', 'ai-queries'], 'seo-ai/ai-tests/queries');
  const meta = useCrudMeta('seo_ai_tests');
  const [copied, setCopied] = useState<number | 'all' | null>(null);
  const [logQuery, setLogQuery] = useState<string | null>(null);
  const [editOpen, setEditOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [draftError, setDraftError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const s = stats.data;
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['seo', 'ai-stats'] });
    void qc.invalidateQueries({ queryKey: ['crud', 'seo_ai_tests'] });
  };
  const copy = async (text: string, which: number | 'all') => {
    if (await copyText(text)) {
      setCopied(which);
      window.setTimeout(() => setCopied((c) => (c === which ? null : c)), 1500);
    } else {
      toast.error('Copy failed — select the text and copy it by hand.');
    }
  };
  const saveQueries = async (reset = false) => {
    setSaving(true);
    setDraftError(null);
    try {
      const res = await api.put('seo-ai/ai-tests/queries', { queries: reset ? '' : draft });
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['seo', 'ai-queries'] });
      setEditOpen(false);
    } catch (e) {
      const err = e as ApiError;
      setDraftError(err.errors?.queries ?? err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <Alert variant="info" title="Manual tests only">
        Ask each AI engine the standard queries yourself (signed out or in a private window), then log what it answered. Nothing on this page queries an AI engine automatically.
      </Alert>
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatTile key="t" label="Tests in the last 6 months" value={s?.totals.tests ?? 0} icon={ClipboardList} tone="navy" loading={stats.isLoading}
            sub={s?.totals.last_tested_on ? `Last on ${formatDate(s.totals.last_tested_on)}` : 'No tests yet'} />,
          <StatTile key="m" label="Mention rate" value={s ? `${s.totals.mention_rate}%` : '—'} icon={MessageSquareQuote} tone="green" loading={stats.isLoading} sub={s ? `${s.totals.mentioned} of ${s.totals.tests} answers` : undefined} />,
          <StatTile key="c" label="Citation rate" value={s ? `${s.totals.citation_rate}%` : '—'} icon={Link2} tone="blue" loading={stats.isLoading} sub={s ? `${s.totals.cited} linked the website` : undefined} />,
          <StatTile key="i" label="Inaccurate answers" value={s?.totals.inaccurate ?? 0} icon={ShieldAlert} tone={s?.totals.inaccurate ? 'red' : 'slate'} loading={stats.isLoading} sub="Need a corrective action" />,
        ]}
      </Stagger>
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <Reveal className="xl:col-span-3">
          <Card className="h-full">
            <CardHeader icon={Bot} title="Mention rate per engine" subtitle="Share of logged answers that mentioned the institute, by month" />
            <div className="card-body">
              {stats.error ? <Alert variant="error">{(stats.error as ApiError).message}</Alert> : stats.isLoading || !s ? <Skeleton className="h-64 w-full" /> : s.series.length === 0 ? (
                <EmptyState icon={Bot} title="No tests logged yet" description="Log the first results to see how often each AI engine mentions the institute."
                  action={canEdit ? <Button icon={Plus} onClick={() => setLogQuery('')}>Log a test</Button> : undefined} />
              ) : (
                <>
                  {/* months without tests are gaps (null), not 0 % */}
                  <BarChart height={260} labels={s.months.map(monthLabel)} series={s.series.map((x) => ({ label: x.label, data: x.data as unknown as number[] }))} percent max={100} beginAtZero
                    ariaLabel="Mention rate per AI engine per month" />
                  <div className="mt-4 overflow-x-auto">
                    <table className="data-table">
                      <caption className="sr-only">Results per AI engine (last 6 months)</caption>
                      <thead><tr><th>Engine</th><th className="text-right">Tests</th><th className="text-right">Mentioned</th><th className="text-right">Cited</th><th className="text-right">Mention rate</th></tr></thead>
                      <tbody>
                        {s.by_engine.map((e) => (
                          <tr key={e.engine}>
                            <td className="font-medium">{e.label}</td>
                            <td className="text-right tabular-nums">{e.tests}</td>
                            <td className="text-right tabular-nums">{e.mentioned}</td>
                            <td className="text-right tabular-nums">{e.cited}</td>
                            <td className="text-right font-semibold tabular-nums">{e.mention_rate}%</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80} className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader icon={Quote} title="Standard test queries" subtitle={queries.data?.custom ? 'Your list — run each one in every engine monthly' : 'Default list built from Settings › General'}
              actions={queries.data && (
                <>
                  <Button size="sm" variant="ghost" icon={copied === 'all' ? Check : Copy} onClick={() => void copy(queries.data!.queries.join('\n'), 'all')}>{copied === 'all' ? 'Copied' : 'Copy all'}</Button>
                  {canEdit && <Button size="sm" variant="secondary" icon={Pencil} onClick={() => { setDraft(queries.data!.queries.join('\n')); setDraftError(null); setEditOpen(true); }}>Edit</Button>}
                </>
              )} />
            {queries.error ? <div className="p-5"><Alert variant="error">{(queries.error as ApiError).message}</Alert></div> : queries.isLoading || !queries.data ? <div className="space-y-2 p-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-8 w-full" />)}</div> : (
              <ol className="divide-y divide-slate-100 dark:divide-slate-800">
                {queries.data.queries.map((qText, i) => (
                  <li key={i} className="flex items-center gap-2 px-4 py-2">
                    <span className="w-5 shrink-0 text-right text-xs font-semibold text-slate-400 tabular-nums">{i + 1}.</span>
                    <span className="min-w-0 flex-1 text-sm text-slate-700 dark:text-slate-200">{qText}</span>
                    <Button size="xs" variant="ghost" icon={copied === i ? Check : Copy} onClick={() => void copy(qText, i)} aria-label={`Copy query ${i + 1}`}>{copied === i ? 'Copied' : 'Copy'}</Button>
                    {canEdit && <Button size="xs" variant="secondary" icon={Plus} onClick={() => setLogQuery(qText)} aria-label={`Log a result for query ${i + 1}`}>Log</Button>}
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </Reveal>
      </div>
      <CrudTable
        module="seo_ai_tests"
        title="Test log"
        description="One row per engine and query. Note what was wrong and what you changed to correct it."
        addLabel="Log a test"
        emptyTitle="No tests logged yet"
        emptyText="Run a standard query in an AI engine and log whether it mentioned and cited the website."
        onSaved={refresh}
        onDeleted={refresh}
        onCreate={canEdit ? () => setLogQuery('') : undefined}
      />
      <CrudFormModal open={logQuery !== null} onClose={() => setLogQuery(null)} meta={meta.data} module="seo_ai_tests" id={null}
        defaults={logQuery ? { query_text: logQuery } : undefined} title="Log an AI visibility test" onSaved={() => { refresh(); setLogQuery(null); }}
        intro={<p className="text-sm text-slate-500">Record exactly what the engine answered. Paste plain text only.</p>} />
      <Modal open={editOpen} onClose={() => setEditOpen(false)} size="lg" title="Standard test queries" description="One query per line (up to 30). Leave empty to use the default list."
        footer={
          <>
            {queries.data?.custom && <Button variant="ghost" icon={RotateCcw} className="mr-auto" onClick={() => void saveQueries(true)} disabled={saving}>Use the defaults</Button>}
            <Button variant="secondary" onClick={() => setEditOpen(false)}>Cancel</Button>
            <Button icon={Save} loading={saving} onClick={() => void saveQueries(false)}>Save queries</Button>
          </>
        }>
        <Field label="Queries" htmlFor="ai-queries" error={draftError}>
          <Textarea id="ai-queries" rows={12} value={draft} invalid={!!draftError} onChange={(e) => { setDraft(e.target.value); setDraftError(null); }} />
        </Field>
      </Modal>
    </div>
  );
}
