import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Bot, Gauge, HelpCircle, MessageCircleQuestion, MessagesSquare, PhoneCall, ThumbsUp, TrendingUp, Trophy } from 'lucide-react';
import { Alert, Card, CardHeader, EmptyState, Reveal, Select, Skeleton, StatCard, Stagger } from '@/components/ui';
import { BarChart, DoughnutChart, LineChart, chartColor } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { INTENT_LABELS, LANG_LABELS, PROVIDER_LABELS, type Analytics } from './types';
import { msLabel, ProviderBadge } from './shared';

/** Conversations per day, leads, satisfaction, topics, top / unanswered questions, provider usage, latency and errors. */
export default function AnalyticsTab() {
  const [days, setDays] = useState('30');
  const { data: a, isLoading, error } = useApi<Analytics>(['chatbot', 'analytics', days], 'chatbot/analytics', { days });
  const t = a?.totals;

  if (error) return <Alert variant="error" title="Unable to load the analytics">{(error as ApiError).message}</Alert>;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-slate-500 dark:text-slate-400">Website chat activity — {days === '7' ? 'last 7 days' : days === '90' ? 'last 90 days' : 'last 30 days'}.</p>
        <div className="w-40">
          <Select inputSize="sm" aria-label="Period" value={days} onChange={(e) => setDays(e.target.value)} options={[{ value: '7', label: 'Last 7 days' }, { value: '30', label: 'Last 30 days' }, { value: '90', label: 'Last 90 days' }]} />
        </div>
      </div>

      <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="c" label="Conversations" icon={MessagesSquare} tone="navy" loading={isLoading} value={t?.conversations ?? 0} hint={t ? `${t.avg_messages} messages on average` : undefined} />,
          <StatCard key="q" label="Visitor questions" icon={MessageCircleQuestion} tone="blue" loading={isLoading} value={t?.questions ?? 0} />,
          <StatCard key="l" label="Leads captured" icon={PhoneCall} tone="green" loading={isLoading} value={t?.leads ?? 0} hint={t?.lead_rate != null ? `${t.lead_rate}% of conversations` : undefined} />,
          <StatCard key="s" label="Helpful answers" icon={ThumbsUp} tone="amber" loading={isLoading} value={t?.satisfaction != null ? `${t.satisfaction}%` : '—'} hint={t ? `👍 ${t.helpful} · 👎 ${t.not_helpful}` : undefined} />,
          <StatCard key="r" label="Avg. response" icon={Gauge} tone="cyan" loading={isLoading} value={t ? msLabel(t.avg_latency_ms) : '—'} hint="Server time per answer" />,
          <StatCard key="ai" label="Answered by AI" icon={Bot} tone="purple" loading={isLoading} value={t?.ai_share != null ? `${t.ai_share}%` : '—'} hint={t && t.tokens_in ? `${formatNumber(t.tokens_in)} in / ${formatNumber(t.tokens_out)} out tokens` : 'Rest: rule-based assistant'} />,
        ]}
      </Stagger>

      <div className="grid gap-6 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Daily activity" subtitle="Conversations started, visitor questions and leads per day" icon={TrendingUp} />
            <div className="card-body">
              {isLoading || !a ? (
                <Skeleton className="h-64 w-full" />
              ) : (
                <LineChart
                  labels={a.daily.labels}
                  height={270}
                  legend
                  series={[
                    { label: 'Conversations', data: a.daily.conversations, color: '#0B2A5B', fill: true },
                    { label: 'Questions', data: a.daily.questions, color: '#1D4ED8' },
                    { label: 'Leads', data: a.daily.leads, color: '#22943F' },
                  ]}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Who answered" subtitle="Share of answers per provider" icon={Bot} />
            <div className="card-body">
              {isLoading || !a ? (
                <Skeleton className="h-52 w-full" />
              ) : a.providers.filter((p) => p.answers > 0).length === 0 ? (
                <EmptyState icon={Bot} title="No answers yet" className="py-8" />
              ) : (
                <DoughnutChart
                  labels={a.providers.filter((p) => p.answers > 0).map((p) => PROVIDER_LABELS[p.provider] ?? p.provider)}
                  data={a.providers.filter((p) => p.answers > 0).map((p) => p.answers)}
                  colors={a.providers.filter((p) => p.answers > 0).map((p) => ({ anthropic: '#8B5CF6', openai: '#22943F', gemini: '#1D4ED8', offline: '#64748B' })[p.provider] ?? chartColor(0))}
                  centerValue={formatNumber(t?.answers ?? 0)}
                  centerLabel="answers"
                  valueFormat="number"
                />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Topics" subtitle="What visitors ask about (detected intent)" icon={HelpCircle} />
            <div className="card-body">
              {isLoading || !a ? (
                <Skeleton className="h-64 w-full" />
              ) : a.intents.length === 0 ? (
                <EmptyState icon={HelpCircle} title="No questions yet" className="py-8" />
              ) : (
                <BarChart horizontal height={Math.max(220, a.intents.length * 26)} labels={a.intents.map((i) => INTENT_LABELS[i.intent] ?? i.intent)} series={[{ label: 'Questions', data: a.intents.map((i) => i.count), color: '#F05A28' }]} />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Top questions" subtitle="Most frequent visitor messages" icon={Trophy} />
            <div className="card-body">
              {isLoading || !a ? (
                <Skeleton className="h-64 w-full" />
              ) : a.top_questions.length === 0 ? (
                <EmptyState icon={Trophy} title="No questions yet" className="py-8" />
              ) : (
                <ol className="space-y-2">
                  {a.top_questions.map((q, i) => (
                    <li key={q.question} className="flex items-start gap-3 text-sm">
                      <span className="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{i + 1}</span>
                      <span className="min-w-0 flex-1 break-words text-slate-800 dark:text-slate-100">{q.question}</span>
                      <span className="shrink-0 rounded-full bg-accent-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-accent-700 dark:bg-accent-500/10 dark:text-accent-300">{formatNumber(q.count)}</span>
                    </li>
                  ))}
                </ol>
              )}
              {a && Object.keys(a.languages).length > 0 && (
                <p className="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                  Languages: {Object.entries(a.languages).map(([k, v]) => `${LANG_LABELS[k] ?? k} ${formatNumber(v)}`).join(' · ')}
                </p>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Questions without a matching topic" subtitle="Candidates for new FAQs or knowledge" icon={MessageCircleQuestion} />
            <div className="card-body">
              {isLoading || !a ? (
                <Skeleton className="h-40 w-full" />
              ) : a.unanswered.length === 0 ? (
                <EmptyState icon={MessageCircleQuestion} title="Nothing here" description="Every question matched a known topic." className="py-8" />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {a.unanswered.map((u, i) => (
                    <li key={i} className="flex items-start justify-between gap-3 py-2 text-sm first:pt-0">
                      <span className="min-w-0 break-words text-slate-800 dark:text-slate-100">{u.question}</span>
                      <Link to={`/chatbot?tab=conversations&view=${u.conversation_id}`} className="shrink-0 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                        {timeAgo(u.created_at)}
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Provider performance" subtitle="Answers, latency, tokens and errors" icon={Gauge} />
            <div className="card-body">
              {isLoading || !a ? (
                <Skeleton className="h-40 w-full" />
              ) : a.providers.length === 0 ? (
                <EmptyState icon={Gauge} title="No provider activity" className="py-8" />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[30rem] text-sm">
                    <thead>
                      <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                        <th className="pb-2 font-semibold">Provider</th>
                        <th className="pb-2 text-right font-semibold">Answers</th>
                        <th className="pb-2 text-right font-semibold">Avg / max</th>
                        <th className="pb-2 text-right font-semibold">Tokens in / out</th>
                        <th className="pb-2 text-right font-semibold">👍 / 👎</th>
                        <th className="pb-2 text-right font-semibold">Errors</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {a.providers.map((p) => (
                        <tr key={p.provider}>
                          <td className="py-2"><ProviderBadge provider={p.provider} /></td>
                          <td className="py-2 text-right tabular-nums">{formatNumber(p.answers)}</td>
                          <td className="py-2 text-right tabular-nums">{msLabel(p.avg_ms)} / {msLabel(p.max_ms)}</td>
                          <td className="py-2 text-right tabular-nums">{p.tin ? `${formatNumber(p.tin)} / ${formatNumber(p.tout)}` : '—'}</td>
                          <td className="py-2 text-right tabular-nums">{p.up} / {p.down}</td>
                          <td className={p.errors ? 'py-2 text-right font-semibold tabular-nums text-red-600' : 'py-2 text-right tabular-nums'}>{p.errors}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {a && a.errors.length > 0 && (
                <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Recent errors</p>
                  <ul className="space-y-1.5 text-xs">
                    {a.errors.slice(0, 5).map((e, i) => (
                      <li key={i} className="flex items-start gap-1.5 text-slate-700 dark:text-slate-300">
                        <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0 text-red-500" />
                        <span className="break-words">
                          <strong>{PROVIDER_LABELS[e.provider] ?? e.provider}</strong> {e.kind === 'test' ? '(test) ' : ''}
                          {e.http_status ? `HTTP ${e.http_status} ` : ''}
                          {e.message} <span className="text-slate-400">· {formatDateTime(e.created_at)}</span>
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </div>
  );
}
