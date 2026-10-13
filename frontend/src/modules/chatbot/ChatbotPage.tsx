import { lazy, Suspense } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BarChart3, ExternalLink, MessagesSquare, SlidersHorizontal } from 'lucide-react';
import { Button, CardSkeleton, PageHeader, Tabs } from '@/components/ui';
import { appUrl } from '@/lib/config';

const SettingsTab = lazy(() => import('./SettingsTab'));
const ConversationsTab = lazy(() => import('./ConversationsTab'));
const AnalyticsTab = lazy(() => import('./AnalyticsTab'));

const TABS = [
  { key: 'settings', label: 'Settings', icon: SlidersHorizontal },
  { key: 'conversations', label: 'Conversations', icon: MessagesSquare },
  { key: 'analytics', label: 'Analytics', icon: BarChart3 },
];

/** /admin/chatbot — website AI assistant: settings (providers, keys, persona, widget), conversations, analytics. */
export default function ChatbotPage() {
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? String(params.get('tab')) : 'settings';
  const setTab = (key: string) => {
    const next = new URLSearchParams();
    if (key !== 'settings') next.set('tab', key);
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="AI Chatbot"
        description="The website admission assistant — AI providers (Claude, OpenAI, Gemini) with an always-available rule-based fallback, grounded in live programme, fee, scholarship and FAQ data."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'Communication' }, { label: 'AI Chatbot' }]}
        actions={
          <Button variant="secondary" icon={ExternalLink} href={appUrl('/')} target="_blank">
            View on website
          </Button>
        }
      />
      <Tabs tabs={TABS} value={tab} onChange={setTab} className="mb-6" />
      <Suspense fallback={<CardSkeleton lines={8} />}>
        {tab === 'settings' && <SettingsTab />}
        {tab === 'conversations' && <ConversationsTab />}
        {tab === 'analytics' && <AnalyticsTab />}
      </Suspense>
    </>
  );
}
