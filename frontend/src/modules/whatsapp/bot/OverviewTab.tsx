import { useState } from 'react';
import { CircleHelp, Languages, ListChecks, MessageCircleQuestion, PlusCircle, Route, Sparkles, X } from 'lucide-react';
import { BarChart, DoughnutChart } from '@/components/charts';
import { Alert, Badge, Button, Card, CardBody, CardHeader, CardSkeleton, EmptyState, IconButton, ProgressBar, Reveal, Select, Skeleton, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { useWaRefresh } from '../shared';
import { LANG_LABEL, PROVIDER_LABEL, intentLabel, useBotKnowledge, useBotUnanswered } from './api';
import { KnowledgeModal } from './KnowledgeModal';
import type { BotLang, BotOverview, BotUnanswered } from './types';

/** Analytics: bot handled vs handed over, intents, languages, answer sources, top questions, unanswered questions. */
export function OverviewTab({ overview, loading, days, onDays }: { overview?: BotOverview; loading: boolean; days: number; onDays: (d: number) => void }) {
  const o = overview;
  return (
    <div className="space-y-5">
      <div className="grid gap-5 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader
            title="Bot conversations"
            subtitle="Answered from verified data, not sure (fallback) and handed to a counsellor — per day"
            icon={Route}
            actions={
              <Select inputSize="sm" aria-label="Period" value={String(days)} onChange={(e) => onDays(Number(e.target.value))} options={{ '7': 'Last 7 days', '30': 'Last 30 days', '90': 'Last 90 days' }} className="w-40" />
            }
          />
          <CardBody>
            {loading || !o ? (
              <Skeleton className="h-64 w-full" />
            ) : o.kpis.messages === 0 ? (
              <EmptyState icon={Route} title="No bot conversations yet" description="Switch the bot on, or try it in the Test console. Conversations appear here as soon as students chat." />
            ) : (
              <BarChart
                labels={o.daily.labels}
                stacked
                height={260}
                ariaLabel="Bot conversations per day"
                series={[
                  { label: 'Answered', data: o.daily.series.answered, color: '#22943F' },
                  { label: 'Not sure', data: o.daily.series.fallback, color: '#F59E0B' },
                  { label: 'Handed over', data: o.daily.series.handover, color: '#D03E16' },
                ]}
                legend
              />
            )}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Bot handled vs handed over" subtitle={`Chats in the last ${days} days`} icon={Sparkles} />
          <CardBody>
            {loading || !o ? (
              <Skeleton className="h-56 w-full" />
            ) : o.kpis.conversations === 0 ? (
              <EmptyState icon={Sparkles} title="Nothing to compare yet" />
            ) : (
              <div className="space-y-4">
                <div className="flex justify-center">
                  <DoughnutChart
                    labels={['Resolved by the bot', 'Handed to a counsellor']}
                    data={[o.kpis.handled_by_bot, o.kpis.handed_over]}
                    colors={['#0B2A5B', '#F05A28']}
                    centerValue={o.kpis.containment !== null ? `${o.kpis.containment}%` : '—'}
                    centerLabel="by bot"
                    legendList={false}
                    height={200}
                  />
                </div>
                {/* own legend under the chart: the card is too narrow for a side legend at xl (labels were cut to one letter) */}
                <ul className="space-y-2 text-sm">
                  {[
                    { label: 'Resolved by the bot', value: o.kpis.handled_by_bot, color: '#0B2A5B' },
                    { label: 'Handed to a counsellor', value: o.kpis.handed_over, color: '#F05A28' },
                  ].map((r) => (
                    <li key={r.label} className="flex items-center justify-between gap-3">
                      <span className="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300">
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full ring-1 ring-white/40" style={{ background: r.color }} aria-hidden />
                        {r.label}
                      </span>
                      <span className="font-semibold tabular-nums text-slate-900 dark:text-white">
                        {formatNumber(r.value)} <span className="font-normal text-slate-500 dark:text-slate-400">chats</span>
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Top intents" subtitle="What students ask about" icon={ListChecks} />
            <CardBody>{loading || !o ? <CardSkeleton lines={5} /> : <CountList rows={o.intents.map((r) => ({ label: intentLabel(r.key), count: r.count }))} empty="No questions yet." />}</CardBody>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Languages & answer sources" icon={Languages} />
            <CardBody className="space-y-5">
              {loading || !o ? (
                <CardSkeleton lines={5} />
              ) : (
                <>
                  <CountList rows={o.languages.map((r) => ({ label: LANG_LABEL[r.key as BotLang] ?? r.key, count: r.count }))} empty="No chats yet." />
                  <div className="border-t border-slate-100 pt-4 dark:border-slate-800">
                    <CountList rows={o.providers.map((r) => ({ label: PROVIDER_LABEL[r.key] ?? r.key, count: r.count }))} empty="No answers yet." />
                  </div>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {o.ai.configured
                      ? `AI answers today: ${formatNumber(o.ai.used_today_whatsapp)} on WhatsApp + ${formatNumber(o.ai.used_today_web)} on the website (daily limit ${formatNumber(o.ai.daily_limit)}).`
                      : 'No AI provider is configured — answers come from program data, verified answers and the rule-based engine.'}
                  </p>
                </>
              )}
            </CardBody>
          </Card>
        </Reveal>
        <Reveal delay={120}>
          <Card className="h-full">
            <CardHeader title="Why chats were handed over" icon={Route} />
            <CardBody>{loading || !o ? <CardSkeleton lines={5} /> : <CountList rows={o.handover_reasons.map((r) => ({ label: r.label ?? r.key, count: r.count }))} empty="No hand-overs in this period." tone="orange" />}</CardBody>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Most asked (answered)" icon={MessageCircleQuestion} />
            <CardBody className="p-0">
              {loading || !o ? (
                <div className="p-5">
                  <CardSkeleton lines={6} />
                </div>
              ) : o.top_questions.length === 0 ? (
                <EmptyState icon={MessageCircleQuestion} title="No answered questions yet" />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {o.top_questions.map((q, i) => (
                    <li key={i} className="flex items-start justify-between gap-3 px-5 py-2.5 text-sm">
                      <span className="min-w-0 text-slate-700 dark:text-slate-200">
                        {q.question}
                        <span className="mt-0.5 block text-xs text-slate-400">{intentLabel(q.intent)}</span>
                      </span>
                      <Badge color="navy">{formatNumber(q.count)}</Badge>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-3" delay={60}>
          <UnansweredCard />
        </Reveal>
      </div>
    </div>
  );
}

function CountList({ rows, empty, tone = 'navy' }: { rows: { label: string; count: number }[]; empty: string; tone?: 'navy' | 'orange' }) {
  if (!rows.length) return <p className="text-sm text-slate-500 dark:text-slate-400">{empty}</p>;
  const max = Math.max(...rows.map((r) => r.count), 1);
  return (
    <ul className="space-y-2.5">
      {rows.map((r, i) => (
        <li key={i}>
          <div className="mb-1 flex items-center justify-between gap-2 text-sm">
            <span className="truncate text-slate-700 dark:text-slate-200">{r.label}</span>
            <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.count)}</span>
          </div>
          <ProgressBar value={(r.count / max) * 100} tone={tone === 'orange' ? 'orange' : i % 2 ? 'blue' : 'navy'} />
        </li>
      ))}
    </ul>
  );
}

/** Questions the bot could not answer → add a verified answer (marks them reviewed) or dismiss. */
function UnansweredCard() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const q = useBotUnanswered();
  const kb = useBotKnowledge({}); // categories + live placeholder values for the answer form
  const [adding, setAdding] = useState<BotUnanswered | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const dismiss = async (row: BotUnanswered) => {
    setBusy(row.question);
    try {
      const r = await api.post('whatsapp/bot/unanswered/dismiss', { turn_ids: row.turn_ids });
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="h-full">
      <CardHeader title="Unanswered questions" subtitle="The bot said it was not sure — add a verified answer so it can help next time" icon={CircleHelp} />
      <CardBody className="p-0">
        {q.error ? (
          <div className="p-5">
            <Alert variant="error" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
              {(q.error as ApiError).message}
            </Alert>
          </div>
        ) : q.isLoading ? (
          <div className="p-5">
            <CardSkeleton lines={6} />
          </div>
        ) : !q.data?.length ? (
          <EmptyState icon={CircleHelp} title="Nothing waiting" description="Every recent question was answered from verified data." />
        ) : (
          <ul className="max-h-[26rem] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            {q.data.map((row) => (
              <li key={row.question} className="flex flex-wrap items-center gap-3 px-5 py-3 sm:flex-nowrap">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-slate-800 dark:text-slate-100">{row.question}</p>
                  <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    Asked {formatNumber(row.count)}× by {formatNumber(row.contacts)} {row.contacts === 1 ? 'contact' : 'contacts'} · last {timeAgo(row.last_at)}
                    {row.language ? ` · ${LANG_LABEL[row.language] ?? row.language}` : ''}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  {can('whatsapp_automation', 'create') && (
                    <Button size="xs" variant="soft" icon={PlusCircle} onClick={() => setAdding(row)}>
                      Add answer
                    </Button>
                  )}
                  {can('whatsapp_automation', 'edit') && <IconButton icon={X} label="Dismiss question" size="sm" disabled={busy === row.question} onClick={() => dismiss(row)} />}
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
      <KnowledgeModal
        open={!!adding}
        onClose={() => setAdding(null)}
        onSaved={() => void refresh('bot')}
        prefill={adding ? { question: adding.question, turn_ids: adding.turn_ids } : null}
        categories={kb.data?.categories ?? { general: 'General' }}
        placeholders={kb.data?.placeholders ?? {}}
      />
    </Card>
  );
}
