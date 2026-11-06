import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { MessagesSquare, Users, Zap } from 'lucide-react';
import { Button, Drawer, EmptyState, PageHeader, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber } from '@/lib/format';
import { useDebounce, useLocalStorage, useMediaQuery } from '@/lib/hooks';
import { ModeBanner } from '../shared';
import { ChatWindow } from './ChatWindow';
import { ContactPanel } from './ContactPanel';
import { ConversationList, FILTERS } from './ConversationList';
import { useAgents, useConversation, useInboxList, useInboxRefresh } from './hooks';
import { QuickRepliesManager } from './QuickRepliesManager';
import { TeamDrawer } from './TeamDrawer';
import type { InboxFilter } from './types';

/** WhatsApp Web-style shared inbox: conversations · chat · contact profile. Deep links: ?c=<conversation>, ?contact=<contact id>. */
export default function InboxPage() {
  const [params, setParams] = useSearchParams();
  const { session, can } = useAuth();
  const toast = useToast();
  const filterParam = params.get('filter') as InboxFilter | null;
  const filter: InboxFilter = filterParam && FILTERS.some((f) => f.key === filterParam) ? filterParam : 'all';
  const convId = Number(params.get('c')) || null;
  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounce(q.trim(), 300);
  const wide = useMediaQuery('(min-width: 1280px)');
  const [panelPref, setPanelPref] = useLocalStorage('wa-inbox-panel', true);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [qrOpen, setQrOpen] = useState(false);
  const [teamOpen, setTeamOpen] = useState(false);
  const refresh = useInboxRefresh();
  const meId = session?.user?.id ?? 0;
  const isManager = can('whatsapp', 'manage');

  const update = useCallback(
    (patch: Record<string, string | null>) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev);
          Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
          return next;
        },
        { replace: true },
      );
    },
    [setParams],
  );

  useEffect(() => {
    if ((params.get('q') ?? '') !== dq) update({ q: dq || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);

  // ?contact=ID — open (or start) that contact's conversation
  const contactParam = params.get('contact');
  useEffect(() => {
    const contactId = Number(contactParam);
    if (!contactId) return;
    api
      .post<{ id: number }>('whatsapp/inbox/start', { contact_id: contactId })
      .then((r) => update({ contact: null, c: String(r.data.id) }))
      .catch((e: ApiError) => {
        toast.error(e.message);
        update({ contact: null });
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [contactParam]);

  const list = useInboxList(filter, dq);
  const rows = useMemo(() => {
    const seen = new Set<number>();
    return (list.data?.pages ?? []).flatMap((p) => p.rows).filter((r) => (seen.has(r.id) ? false : (seen.add(r.id), true)));
  }, [list.data]);
  const counts = list.data?.pages[0]?.counts;
  const detail = useConversation(convId);
  const agents = useAgents();

  const onChanged = useCallback(
    (extra: ('notes' | 'activity' | 'tags' | 'agents')[] = []) => void refresh(convId, extra),
    [refresh, convId],
  );

  const showPanel = !!convId && (wide ? panelPref : drawerOpen);
  const togglePanel = () => (wide ? setPanelPref(!panelPref) : setDrawerOpen((o) => !o));

  const description = counts
    ? [
        counts.unread ? `${formatNumber(counts.unread)} unread` : 'All caught up',
        counts.unassigned ? `${formatNumber(counts.unassigned)} unassigned` : null,
        counts.bot ? `${formatNumber(counts.bot)} with the AI bot` : null,
      ]
        .filter(Boolean)
        .join(' · ')
    : 'Reply to students, parents and applicants on WhatsApp — together as a team.';

  const panel = convId ? (
    <ContactPanel
      convId={convId}
      detail={detail.data}
      loading={detail.isLoading}
      agents={agents.data ?? []}
      meId={meId}
      embedded={!wide}
      onClose={() => (wide ? setPanelPref(false) : setDrawerOpen(false))}
      onChanged={onChanged}
    />
  ) : null;

  return (
    <>
      {/* on phones an open chat takes the whole screen (header + banner hidden) */}
      <div className={clsx(convId && 'hidden lg:block')}>
      <PageHeader
        title="WhatsApp Inbox"
        description={description}
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'WhatsApp Inbox' }]}
        actions={
          <>
            <Button variant="secondary" icon={Zap} onClick={() => setQrOpen(true)}>Quick replies</Button>
            <Button variant="secondary" icon={Users} onClick={() => setTeamOpen(true)}>Team</Button>
          </>
        }
      />
      <ModeBanner />
      </div>

      <div className="card overflow-hidden">
        <div
          className={clsx(
            'grid grid-cols-1 lg:h-[calc(100vh-17.5rem)] lg:min-h-[34rem]',
            convId ? 'h-[calc(100dvh-6.5rem)] min-h-[28rem]' : 'h-[calc(100dvh-8rem)] min-h-[30rem]',
            showPanel && wide ? 'lg:grid-cols-[320px_minmax(0,1fr)_340px]' : 'lg:grid-cols-[340px_minmax(0,1fr)]',
          )}
        >
          <aside className={clsx('min-h-0 min-w-0 border-slate-200 lg:border-r dark:border-slate-800', convId ? 'hidden lg:block' : 'block')} aria-label="Conversation list">
            <ConversationList
              rows={rows}
              counts={counts}
              filter={filter}
              onFilter={(f) => update({ filter: f === 'all' ? null : f })}
              q={q}
              onQ={setQ}
              activeId={convId}
              onOpen={(id) => update({ c: String(id) })}
              loading={list.isLoading}
              error={list.error}
              onRetry={() => list.refetch()}
              hasMore={!!list.hasNextPage}
              loadingMore={list.isFetchingNextPage}
              onLoadMore={() => void list.fetchNextPage()}
            />
          </aside>

          <section className={clsx('min-h-0 min-w-0', convId ? 'block' : 'hidden lg:block')} aria-label="Chat">
            {convId ? (
              <ChatWindow
                key={convId}
                convId={convId}
                detail={detail.data}
                detailError={detail.error}
                agents={agents.data ?? []}
                showInfo={showPanel}
                onToggleInfo={togglePanel}
                onBack={() => update({ c: null })}
                onChanged={onChanged}
                onManageQuickReplies={() => setQrOpen(true)}
              />
            ) : (
              <div className="flex h-full items-center justify-center bg-slate-50/60 p-6 dark:bg-slate-900/40">
                <EmptyState
                  icon={MessagesSquare}
                  title="Select a conversation"
                  description="Pick a chat on the left to read it and reply. Use “Unassigned” to pick up new chats, and type / in the composer for quick replies."
                  action={counts?.unassigned ? <Button variant="secondary" onClick={() => update({ filter: 'unassigned' })}>Show {formatNumber(counts.unassigned)} unassigned</Button> : undefined}
                />
              </div>
            )}
          </section>

          {showPanel && wide && <aside className="hidden min-h-0 min-w-0 border-l border-slate-200 xl:block dark:border-slate-800" aria-label="Contact details">{panel}</aside>}
        </div>
      </div>

      {!wide && (
        <Drawer open={drawerOpen && !!convId} onClose={() => setDrawerOpen(false)} title={detail.data?.profile.name || detail.data?.conversation.phone_display || 'Contact'} width="max-w-md">
          <div className="-mx-5 -my-5">{panel}</div>
        </Drawer>
      )}

      <QuickRepliesManager open={qrOpen} onClose={() => setQrOpen(false)} />
      <TeamDrawer open={teamOpen} onClose={() => setTeamOpen(false)} isManager={isManager} />
    </>
  );
}
