import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BarChart3, Bot, BookOpenCheck, FlaskConical, GraduationCap, MessagesSquare, Power, Settings2, ShieldCheck, Sparkles, UserCheck, Users } from 'lucide-react';
import { Alert, Badge, Button, PageHeader, Stagger, StatCard, Tabs, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, formatPercent } from '@/lib/format';
import { ModeBanner, useWaRefresh } from '../shared';
import { useBotOverview, useBotSettings } from './api';
import { CoursesTab } from './CoursesTab';
import { KnowledgeTab } from './KnowledgeTab';
import { OverviewTab } from './OverviewTab';
import { SessionsTab } from './SessionsTab';
import { SettingsTab } from './SettingsTab';
import { TestConsole } from './TestConsole';

const TABS = ['overview', 'settings', 'knowledge', 'courses', 'test', 'sessions'] as const;
type Tab = (typeof TABS)[number];

/**
 * AI WhatsApp Chatbot (Module 9): answers WhatsApp chats only from verified GIMT data (programs, fees, eligibility,
 * knowledge base, FAQs), qualifies new leads, hands chats to counsellors, summarises conversations. Tabs: overview
 * (analytics + unanswered questions), settings, knowledge base & FAQs, course information, test console, sessions.
 */
export default function ChatbotPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview';
  const [days, setDays] = useState(30);
  const settings = useBotSettings();
  const overview = useBotOverview(days);
  const [toggling, setToggling] = useState(false);
  const enabled = settings.data?.values.enabled ?? overview.data?.enabled ?? false;
  const k = overview.data?.kpis;

  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    if (t === 'overview') next.delete('tab');
    else next.set('tab', t);
    next.delete('session');
    setParams(next, { replace: true });
  };

  const toggle = async () => {
    const on = !enabled;
    if (on) {
      const ok = await confirm({
        title: 'Switch on the AI bot?',
        message: 'The bot will answer incoming WhatsApp chats that are not assigned to a team member, using only verified GIMT data. Chats are handed to a counsellor on request or when the bot is not sure.',
        confirmText: 'Switch on',
      });
      if (!ok) return;
    }
    setToggling(true);
    try {
      const r = await api.post('whatsapp/bot/toggle', { enabled: on });
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setToggling(false);
    }
  };

  return (
    <>
      <PageHeader
        title="AI WhatsApp Chatbot"
        description="Answers students on WhatsApp in English, Hindi or Hinglish — only from verified GIMT data — qualifies new leads and hands chats to counsellors."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'AI Chatbot' }]}
        actions={
          <>
            <Button variant="secondary" icon={FlaskConical} onClick={() => setTab('test')}>
              Test the bot
            </Button>
            {can('whatsapp_automation', 'publish') && (
              <Button variant={enabled ? 'secondary' : 'primary'} icon={Power} loading={toggling} disabled={settings.isLoading} onClick={toggle}>
                {enabled ? 'Switch off' : 'Switch on'}
              </Button>
            )}
          </>
        }
      >
        <div className="flex flex-wrap items-center gap-2 text-xs">
          {settings.isLoading ? (
            <span className="skeleton h-5 w-40" />
          ) : (
            <>
              <Badge color={enabled ? 'green' : 'slate'} dot>
                {enabled ? 'Bot is on' : 'Bot is off'}
              </Badge>
              {settings.data && (
                <>
                  <Badge color={settings.data.ai.configured && settings.data.values.use_ai ? 'purple' : 'slate'}>
                    {settings.data.ai.configured && settings.data.values.use_ai ? `AI: ${settings.data.ai.chain[0]?.label ?? 'on'}` : 'Rule-based answers (no AI provider)'}
                  </Badge>
                  <Badge color="navy">{settings.data.values.languages.includes('hi') ? 'English · Hindi · Hinglish' : 'English only'}</Badge>
                  <Badge color={settings.data.values.hours_mode === 'always' ? 'blue' : 'amber'}>
                    {settings.data.values.hours_mode === 'always' ? 'Answers 24×7' : 'Answers outside business hours'}
                  </Badge>
                </>
              )}
            </>
          )}
        </div>
      </PageHeader>
      <ModeBanner />

      {settings.error && (
        <Alert variant="error" className="mb-4" title="Could not load the bot settings" action={<Button size="sm" variant="secondary" onClick={() => settings.refetch()}>Retry</Button>}>
          {(settings.error as ApiError).message}
        </Alert>
      )}

      {overview.error ? (
        <Alert variant="error" className="mb-5" title="Could not load bot analytics" action={<Button size="sm" variant="secondary" onClick={() => overview.refetch()}>Retry</Button>}>
          {(overview.error as ApiError).message}
        </Alert>
      ) : (
        <Stagger className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
          {[
            <StatCard key="c" label={`Chats handled (${days} d)`} icon={MessagesSquare} tone="navy" loading={overview.isLoading} value={k?.conversations ?? 0} hint={k ? `${formatNumber(k.messages)} bot turns` : undefined} />,
            <StatCard key="a" label="Answer rate" icon={ShieldCheck} tone="green" loading={overview.isLoading} value={k?.answer_rate !== null && k?.answer_rate !== undefined ? formatPercent(k.answer_rate, 1) : '—'} hint={k ? `${formatNumber(k.answered)} of ${formatNumber(k.questions)} questions` : undefined} />,
            <StatCard key="b" label="Resolved by bot" icon={Bot} tone="purple" loading={overview.isLoading} value={k?.containment !== null && k?.containment !== undefined ? formatPercent(k.containment, 1) : '—'} hint={k ? `${formatNumber(k.handled_by_bot)} chats without a hand-over` : undefined} />,
            <StatCard key="h" label="Handed to counsellors" icon={Users} tone="orange" loading={overview.isLoading} value={k?.handed_over ?? 0} hint="Requests, sensitive or unsure" />,
            <StatCard key="q" label="Leads qualified" icon={UserCheck} tone="blue" loading={overview.isLoading} value={k?.qualified ?? 0} hint="Name, program, city, qualification, intake" />,
            <StatCard key="u" label="Unanswered questions" icon={Sparkles} tone="red" loading={overview.isLoading} value={k?.unanswered_open ?? 0} hint="Add them to the knowledge base" />,
          ]}
        </Stagger>
      )}

      <Tabs
        className="mb-4"
        variant="pills"
        value={tab}
        onChange={setTab}
        tabs={[
          { key: 'overview', label: 'Overview', icon: BarChart3 },
          { key: 'settings', label: 'Settings', icon: Settings2 },
          { key: 'knowledge', label: 'Knowledge base', icon: BookOpenCheck },
          { key: 'courses', label: 'Course information', icon: GraduationCap },
          { key: 'test', label: 'Test console', icon: FlaskConical },
          { key: 'sessions', label: 'Sessions', icon: MessagesSquare },
        ]}
      />

      {tab === 'overview' && <OverviewTab overview={overview.data} loading={overview.isLoading} days={days} onDays={setDays} />}
      {tab === 'settings' && <SettingsTab query={settings} />}
      {tab === 'knowledge' && <KnowledgeTab />}
      {tab === 'courses' && <CoursesTab />}
      {tab === 'test' && <TestConsole enabled={enabled} />}
      {tab === 'sessions' && <SessionsTab />}
    </>
  );
}
