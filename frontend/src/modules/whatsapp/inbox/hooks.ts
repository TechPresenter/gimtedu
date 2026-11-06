import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import type {
  ActivityItem,
  FeedRow,
  InboxAgent,
  InboxDetail,
  InboxFilter,
  InboxMessage,
  InboxNote,
  InboxPage,
  InboxSummary,
  InboxTag,
  MessagesPage,
  QuickReplyList,
  SendPayload,
} from './types';
import { newClientRef, serverNow } from './utils';

export const inboxKeys = {
  list: (filter?: InboxFilter, q?: string) => (filter === undefined ? (['wa', 'inbox', 'list'] as const) : (['wa', 'inbox', 'list', filter, q ?? ''] as const)),
  conv: (id: number | null) => ['wa', 'inbox', 'conv', id] as const,
  messages: (id: number | null) => ['wa', 'inbox', 'messages', id] as const,
  notes: (id: number | null) => ['wa', 'inbox', 'notes', id] as const,
  activity: (id: number | null) => ['wa', 'inbox', 'activity', id] as const,
  agents: ['wa', 'inbox', 'agents'] as const,
  tags: ['wa', 'inbox', 'tags'] as const,
  quick: (q: string, convId: number | null) => ['wa', 'inbox', 'quick', q, convId] as const,
  feed: (userId: number | null) => ['wa', 'inbox', 'feed', userId] as const,
};

/** Conversation list with cursor paging (infinite scroll) and 10 s background refresh. */
export function useInboxList(filter: InboxFilter, q: string) {
  return useInfiniteQuery({
    queryKey: inboxKeys.list(filter, q),
    queryFn: ({ pageParam, signal }) =>
      api.get<InboxPage>('whatsapp/inbox/conversations', { filter, q: q || undefined, cursor: pageParam || undefined, limit: 25 }, { signal, background: true }),
    initialPageParam: '',
    getNextPageParam: (last) => last.next_cursor ?? undefined,
    refetchInterval: 10_000,
    refetchIntervalInBackground: false,
    staleTime: 5_000,
  });
}

export function useConversation(id: number | null) {
  return useQuery({
    queryKey: inboxKeys.conv(id),
    queryFn: ({ signal }) => api.get<InboxDetail>(`whatsapp/inbox/conversations/${id}`, undefined, { signal, background: true }),
    enabled: !!id,
    staleTime: 15_000,
    // the right panel (status, assignee, bot, tags) follows changes made by colleagues, inbound replies and the bot
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    retry: (n, e) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && n < 2,
  });
}

export function useAgents(enabled = true) {
  return useQuery({
    queryKey: inboxKeys.agents,
    queryFn: ({ signal }) => api.get<InboxAgent[]>('whatsapp/inbox/agents', undefined, { signal, background: true }),
    staleTime: 60_000,
    enabled,
  });
}

export function useAllTags(enabled = true) {
  return useQuery({
    queryKey: inboxKeys.tags,
    queryFn: ({ signal }) => api.get<InboxTag[]>('whatsapp/inbox/tags', undefined, { signal }),
    staleTime: 60_000,
    enabled,
  });
}

export function useNotes(convId: number | null) {
  return useQuery({
    queryKey: inboxKeys.notes(convId),
    queryFn: ({ signal }) => api.get<InboxNote[]>(`whatsapp/inbox/conversations/${convId}/notes`, undefined, { signal }),
    enabled: !!convId,
  });
}

export function useActivity(convId: number | null, enabled = true) {
  return useQuery({
    queryKey: inboxKeys.activity(convId),
    queryFn: ({ signal }) => api.get<ActivityItem[]>(`whatsapp/inbox/conversations/${convId}/activity`, undefined, { signal }),
    enabled: !!convId && enabled,
  });
}

export function useQuickReplies(q: string, convId: number | null, enabled = true) {
  return useQuery({
    queryKey: inboxKeys.quick(q, convId),
    queryFn: ({ signal }) => api.get<QuickReplyList>('whatsapp/quick-replies', { q: q || undefined, conversation_id: convId || undefined }, { signal }),
    enabled,
    staleTime: 30_000,
    placeholderData: (prev) => prev,
  });
}

export function useActivityFeed(userId: number | null, enabled: boolean) {
  return useInfiniteQuery({
    queryKey: inboxKeys.feed(userId),
    queryFn: ({ pageParam, signal }) =>
      api.get<{ rows: FeedRow[]; has_more: boolean }>('whatsapp/inbox/activity', { user_id: userId || undefined, before_id: pageParam || undefined, limit: 30 }, { signal }),
    initialPageParam: 0,
    getNextPageParam: (last) => (last.has_more && last.rows.length ? last.rows[last.rows.length - 1].id : undefined),
    enabled,
  });
}

/**
 * Apply the conversation state an action returned (assign / status / bot / tags) to the cached right panel and chat
 * header at once, so the UI does not wait for the next poll on a busy server.
 */
export function useApplyConversation() {
  const qc = useQueryClient();
  return useCallback(
    (convId: number, patch: Partial<InboxSummary> | null | undefined) => {
      if (!patch) return;
      qc.setQueryData<InboxDetail>(inboxKeys.conv(convId), (old) =>
        old
          ? {
              ...old,
              conversation: { ...old.conversation, ...patch },
              permissions: 'assigned_to' in patch ? { ...old.permissions, claim: old.permissions.send && patch.assigned_to === null } : old.permissions,
            }
          : old,
      );
      qc.setQueryData<MessagesPage>(inboxKeys.messages(convId), (old) => (old?.conversation ? { ...old, conversation: { ...old.conversation, ...patch } } : old));
    },
    [qc],
  );
}

/** Refresh list + current conversation header after an action. */
export function useInboxRefresh() {
  const qc = useQueryClient();
  return useCallback(
    (convId?: number | null, extra: ('notes' | 'activity' | 'tags' | 'agents')[] = []) => {
      const jobs: Promise<unknown>[] = [qc.invalidateQueries({ queryKey: inboxKeys.list() })];
      if (convId) {
        jobs.push(qc.invalidateQueries({ queryKey: inboxKeys.conv(convId) }));
        // the chat header reads the polled thread summary: refresh it now instead of on the next 4.5 s poll
        jobs.push(qc.invalidateQueries({ queryKey: inboxKeys.messages(convId) }));
        jobs.push(qc.invalidateQueries({ queryKey: inboxKeys.activity(convId) }));
        // the bot part's session card in the right panel (take over / hand back change its state)
        jobs.push(qc.invalidateQueries({ queryKey: ['wa', 'bot', 'session'] }));
        if (extra.includes('notes')) jobs.push(qc.invalidateQueries({ queryKey: inboxKeys.notes(convId) }));
      }
      if (extra.includes('tags')) jobs.push(qc.invalidateQueries({ queryKey: inboxKeys.tags }));
      if (extra.includes('agents')) jobs.push(qc.invalidateQueries({ queryKey: inboxKeys.agents }));
      return Promise.all(jobs);
    },
    [qc],
  );
}

/**
 * Chat thread state: polls the latest page every 4.5 s (also triggers the worker tick on the server), pages older
 * messages on demand and keeps optimistic sends until the server confirms them.
 */
export function useThread(convId: number | null) {
  const qc = useQueryClient();
  const [older, setOlder] = useState<InboxMessage[]>([]);
  const [olderHasMore, setOlderHasMore] = useState<boolean | null>(null);
  const [loadingOlder, setLoadingOlder] = useState(false);
  const [confirmed, setConfirmed] = useState<Record<number, InboxMessage>>({});
  const [pending, setPending] = useState<InboxMessage[]>([]);
  const tempId = useRef(-1);

  useEffect(() => {
    setOlder([]);
    setOlderHasMore(null);
    setConfirmed({});
    setPending([]);
  }, [convId]);

  const latest = useQuery({
    queryKey: inboxKeys.messages(convId),
    queryFn: ({ signal }) => api.get<MessagesPage>(`whatsapp/inbox/conversations/${convId}/messages`, { poll: 1, limit: 40 }, { signal, background: true }),
    enabled: !!convId,
    refetchInterval: 4_500,
    refetchIntervalInBackground: false,
    staleTime: 2_000,
    retry: (n, e) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && n < 2,
  });

  const messages = useMemo(() => {
    const map = new Map<number, InboxMessage>();
    older.forEach((m) => map.set(m.id, m));
    Object.values(confirmed).forEach((m) => map.set(m.id, m));
    latest.data?.messages.forEach((m) => map.set(m.id, m));
    // chronological (created_at, id) — the server pages with the same order
    const list = [...map.values()].sort((a, b) => (a.created_at === b.created_at ? a.id - b.id : a.created_at < b.created_at ? -1 : 1));
    return [...list, ...pending];
  }, [older, confirmed, latest.data, pending]);

  const hasMore = olderHasMore ?? latest.data?.has_more ?? false;

  const loadOlder = useCallback(async () => {
    if (!convId || loadingOlder || !hasMore) return false;
    const firstId = messages.find((m) => m.id > 0)?.id;
    if (!firstId) return false;
    setLoadingOlder(true);
    try {
      const page = await api.get<MessagesPage>(`whatsapp/inbox/conversations/${convId}/messages`, { before_id: firstId, limit: 40 });
      setOlder((o) => [...page.messages, ...o]);
      setOlderHasMore(page.has_more);
      return true;
    } finally {
      setLoadingOlder(false);
    }
  }, [convId, loadingOlder, hasMore, messages]);

  /** Optimistic send. Resolves with the server message, rejects with ApiError (the pending bubble shows the error). */
  const send = useCallback(
    async (payload: SendPayload, preview: Partial<InboxMessage>) => {
      if (!convId) throw new Error('No conversation');
      const ref = payload.client_ref ?? newClientRef();
      const id = tempId.current--;
      const optimistic: InboxMessage = {
        id,
        conversation_id: convId,
        contact_id: 0,
        direction: 'out',
        type: 'text',
        body: null,
        media_url: null,
        media_local: null,
        media_mime: null,
        file_name: null,
        interactive: null,
        template_id: null,
        template_name: null,
        template_vars: null,
        status: 'queued',
        error_code: null,
        error: null,
        sender_type: 'agent',
        sent_by: null,
        reply_to_id: null,
        campaign_id: null,
        created_at: serverNow(),
        sent_at: null,
        delivered_at: null,
        read_at: null,
        failed_at: null,
        ...preview,
        pending: 'sending',
        client_ref: ref,
        payload: { ...payload, client_ref: ref },
      };
      setPending((p) => [...p.filter((x) => x.client_ref !== ref), optimistic]);
      try {
        const res = await api.post<{ message: InboxMessage; conversation: InboxSummary }>(`whatsapp/inbox/conversations/${convId}/send`, { ...payload, client_ref: ref });
        const msg = res.data.message;
        setConfirmed((c) => ({ ...c, [msg.id]: msg }));
        setPending((p) => p.filter((x) => x.client_ref !== ref));
        void qc.invalidateQueries({ queryKey: inboxKeys.messages(convId) });
        return res;
      } catch (e) {
        const err = e as ApiError;
        setPending((p) => p.map((x) => (x.client_ref === ref ? { ...x, pending: 'error', pending_error: err.message } : x)));
        throw e;
      }
    },
    [convId, qc],
  );

  const discard = useCallback((ref: string) => setPending((p) => p.filter((x) => x.client_ref !== ref)), []);

  const replaceMessage = useCallback((m: InboxMessage) => setConfirmed((c) => ({ ...c, [m.id]: m })), []);

  return { latest, messages, hasMore, loadOlder, loadingOlder, send, discard, replaceMessage, summary: latest.data?.conversation };
}
