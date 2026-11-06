import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import type { BadgeColor } from '@/lib/status';
import type {
  BotCourse,
  BotFaq,
  BotKnowledgeList,
  BotLang,
  BotOverview,
  BotSessionDetail,
  BotSessionsPage,
  BotSettings,
  BotUnanswered,
} from './types';

/** Query keys of the AI chatbot screen — all under ['wa', 'bot'] so useWaRefresh()('bot') refreshes everything. */
export const botKeys = {
  settings: ['wa', 'bot', 'settings'] as const,
  overview: (days: number) => ['wa', 'bot', 'overview', days] as const,
  unanswered: ['wa', 'bot', 'unanswered'] as const,
  knowledge: (q: Query) => ['wa', 'bot', 'knowledge', q] as const,
  faqs: ['wa', 'bot', 'faqs'] as const,
  courses: ['wa', 'bot', 'courses'] as const,
  sessions: (q: Query) => ['wa', 'bot', 'sessions', q] as const,
  session: (contactId: number) => ['wa', 'bot', 'session', contactId] as const,
};

export function useBotSettings() {
  return useQuery({ queryKey: botKeys.settings, queryFn: ({ signal }) => api.get<BotSettings>('whatsapp/bot/settings', undefined, { signal }), staleTime: 30_000 });
}

export function useBotOverview(days: number) {
  return useQuery({
    queryKey: botKeys.overview(days),
    queryFn: ({ signal }) => api.get<BotOverview>('whatsapp/bot/overview', { days }, { signal }),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}

export function useBotUnanswered() {
  return useQuery({ queryKey: botKeys.unanswered, queryFn: ({ signal }) => api.get<BotUnanswered[]>('whatsapp/bot/unanswered', { days: 90, limit: 50 }, { signal }) });
}

export function useBotKnowledge(q: Query) {
  return useQuery({
    queryKey: botKeys.knowledge(q),
    queryFn: ({ signal }) => api.get<BotKnowledgeList>('whatsapp/bot/knowledge', q, { signal }),
    placeholderData: keepPreviousData,
  });
}

export function useBotFaqs() {
  return useQuery({ queryKey: botKeys.faqs, queryFn: ({ signal }) => api.get<{ rows: BotFaq[]; can_edit_cms: boolean }>('whatsapp/bot/faqs', undefined, { signal }) });
}

export function useBotCourses() {
  return useQuery({ queryKey: botKeys.courses, queryFn: ({ signal }) => api.get<{ rows: BotCourse[]; can_edit_programs: boolean }>('whatsapp/bot/courses', undefined, { signal }) });
}

export function useBotSessions(q: Query) {
  return useQuery({
    queryKey: botKeys.sessions(q),
    queryFn: ({ signal }) => api.get<BotSessionsPage>('whatsapp/bot/sessions', q, { signal }),
    placeholderData: keepPreviousData,
  });
}

/** One contact's bot session (null when the bot never talked to the contact). Also used by the inbox right panel. */
export function useBotSession(contactId: number | null | undefined) {
  return useQuery({
    queryKey: botKeys.session(contactId ?? 0),
    queryFn: ({ signal }) => api.get<BotSessionDetail | null>(`whatsapp/bot/sessions/${contactId}`, undefined, { signal }),
    enabled: !!contactId,
    staleTime: 15_000,
  });
}

/* ------------------------------------------------------------------ labels */

export const LANG_LABEL: Record<BotLang, string> = { en: 'English', hi: 'Hindi', hing: 'Hinglish' };

export const STATE_META: Record<string, { label: string; color: BadgeColor }> = {
  active: { label: 'Bot answering', color: 'green' },
  qualifying: { label: 'Qualifying lead', color: 'blue' },
  handed_over: { label: 'Handed over', color: 'amber' },
  closed: { label: 'Closed', color: 'slate' },
};

export const PROVIDER_LABEL: Record<string, string> = {
  kb: 'Verified answer',
  programs: 'Program data',
  offline: 'Rule-based engine',
  flow: 'Conversation flow',
  anthropic: 'Claude (AI)',
  openai: 'OpenAI (AI)',
  gemini: 'Gemini (AI)',
};

export const ACTION_LABEL: Record<string, string> = {
  answer: 'Answered',
  greeting: 'Greeting',
  qualify: 'Qualification',
  language: 'Language switch',
  handover: 'Handed over',
  fallback: 'Not sure',
  media: 'Media received',
  limit: 'Rate limit',
  skipped: 'Silent',
  ack: 'Acknowledged',
};

export function intentLabel(intent: string | null | undefined): string {
  if (!intent) return '—';
  if (intent.startsWith('qualify:')) return `Qualification · ${intent.slice(8).replace(/_/g, ' ')}`;
  const map: Record<string, string> = { program_detail: 'Program details', student_record: 'Personal record', faq: 'FAQ', unknown: 'Unknown', counsellor: 'Asked for counsellor', low_confidence: 'Not sure (handed over)', max_turns: 'Long chat', sensitive: 'Sensitive topic', limit: 'Rate limit', media: 'Photo / document', promise: 'Asked for a guarantee', ack: 'Acknowledgement (ok / thanks)', location: 'Address / location', offtopic: 'Off-topic', about: 'About the institute' };
  return map[intent] ?? intent.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export function confidenceTone(c: number | null | undefined): 'green' | 'amber' | 'red' {
  if (c === null || c === undefined) return 'amber';
  return c >= 0.75 ? 'green' : c >= 0.45 ? 'amber' : 'red';
}
