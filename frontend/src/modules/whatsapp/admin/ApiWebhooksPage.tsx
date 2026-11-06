import { lazy, Suspense } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertTriangle, BookOpen, KeyRound, ListRestart, Plug, Route, ScrollText, Webhook } from 'lucide-react';
import { Button, CardSkeleton, PageHeader, Tabs } from '@/components/ui';
import { ModeBanner } from '../shared';

const WebhookLogs = lazy(() => import('./apiTabs/WebhookLogs'));
const ApiLogs = lazy(() => import('./apiTabs/ApiLogs'));
const ErrorMonitor = lazy(() => import('./apiTabs/ErrorMonitor'));
const RetryQueue = lazy(() => import('./apiTabs/RetryQueue'));
const MessageTracking = lazy(() => import('./apiTabs/MessageTracking'));
const ApiTokens = lazy(() => import('./apiTabs/ApiTokens'));
const ApiDocs = lazy(() => import('./apiTabs/ApiDocs'));

const TABS = [
  { key: 'webhooks', label: 'Webhook logs', icon: Webhook },
  { key: 'api', label: 'API requests', icon: ScrollText },
  { key: 'errors', label: 'Error monitoring', icon: AlertTriangle },
  { key: 'queue', label: 'Retry queue', icon: ListRestart },
  { key: 'track', label: 'Message tracking', icon: Route },
  { key: 'tokens', label: 'API tokens', icon: KeyRound },
  { key: 'docs', label: 'API docs', icon: BookOpen },
];

/**
 * API & Webhooks (Module 11): webhook event logs with payload viewer + reprocess, Interakt API request history, error
 * monitoring, the retry queue, message-id tracking, API tokens for external systems and copy-ready API documentation.
 */
export default function ApiWebhooksPage() {
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : 'webhooks';
  const setTab = (t: string) => {
    const next = new URLSearchParams();
    if (t !== 'webhooks') next.set('tab', t);
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="API & Webhooks"
        description="Everything that flows between GIMT and Interakt — webhooks, API calls, failures, the send queue and API access for other systems."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'API & Webhooks' }]}
        actions={
          <Button variant="secondary" icon={Plug} to="/whatsapp/integrations">
            Integrations
          </Button>
        }
      />
      <ModeBanner />
      <div className="-mx-4 mb-5 overflow-x-auto px-4 scrollbar-none sm:mx-0 sm:px-0">
        <Tabs tabs={TABS} value={tab} onChange={setTab} className="min-w-max" />
      </div>
      <Suspense fallback={<CardSkeleton lines={8} />}>
        {tab === 'webhooks' && <WebhookLogs />}
        {tab === 'api' && <ApiLogs />}
        {tab === 'errors' && <ErrorMonitor />}
        {tab === 'queue' && <RetryQueue />}
        {tab === 'track' && <MessageTracking />}
        {tab === 'tokens' && <ApiTokens />}
        {tab === 'docs' && <ApiDocs />}
      </Suspense>
    </>
  );
}
