/** Types of the AI chatbot admin API (api/routes/chatbot.php). */

export interface QuickReply {
  label: string;
  message?: string;
  action?: 'lead' | 'whatsapp' | 'link';
  url?: string;
}

export interface ChatbotValues {
  enabled: string;
  bot_name: string;
  avatar: string;
  greeting: string;
  greeting_delay: string;
  welcome: string;
  welcome_sub: string;
  quick_replies: QuickReply[];
  provider: 'anthropic' | 'openai' | 'gemini' | 'offline';
  fallback_order: string[];
  anthropic_model: string;
  openai_model: string;
  gemini_model: string;
  effort: 'low' | 'medium' | 'high';
  temperature: string;
  max_tokens: string;
  timeout: string;
  claude_fallbacks: string;
  persona: string;
  hours_days: number[];
  hours_start: string;
  hours_end: string;
  offline_message: string;
  lead_capture: string;
  streaming: string;
  retention_days: string;
  sources: Record<string, boolean>;
  max_input: string;
  rate_ip: string;
  daily_ai_limit: string;
  credit_text: string;
  credit_url: string;
  show_mobile: string;
}

export interface KeyStatus {
  configured: boolean;
  source: 'env' | 'settings' | null;
  hint: string | null;
  env: string;
  undecryptable: boolean;
  encrypted: boolean;
}

export interface ProviderInfo {
  key: 'anthropic' | 'openai' | 'gemini';
  label: string;
  env: string;
  default_model: string;
  models: { id: string; label: string }[];
  console: string;
  model: string;
  status: KeyStatus;
}

export interface ChatbotSettings {
  values: ChatbotValues;
  avatar_url: string | null;
  providers: ProviderInfo[];
  chain: string[];
  sources: { key: string; label: string; count: number }[];
  knowledge: { snippets: number; prompt_chars: number };
  warnings: string[];
  hours_label: string;
  open_now: boolean;
  ai_today: number;
  purge: { last: string | null; due: number };
  server: { sapi: string; curl: boolean; sodium: boolean; openssl: boolean };
}

export interface TestResult {
  ok: boolean;
  message: string;
  reply?: string;
  latency_ms: number;
  model: string;
  error_type?: string;
}

export interface Suggestion {
  label: string;
  message?: string;
  action?: string;
  url?: string;
}

export interface TryResult {
  reply: string;
  sources: { title: string; url: string }[];
  suggestions: Suggestion[];
  lead_prompt: boolean;
  intent: string;
  intents: string[];
  lang: string;
  programs: string[];
  provider: string;
  model: string | null;
  usage: { input: number; output: number; cached: number } | null;
  latency_ms: number;
  errors: string[];
}

export interface ConversationRow {
  id: number;
  title: string | null;
  language: string | null;
  message_count: number;
  user_message_count: number;
  last_provider: string | null;
  lead_status: 'none' | 'offered' | 'captured';
  enquiry_id: number | null;
  enquiry_status: string | null;
  lead_name: string | null;
  feedback_up: number;
  feedback_down: number;
  page_url: string | null;
  utm_source: string | null;
  utm_campaign: string | null;
  source: string;
  device: string;
  started_at: string;
  last_message_at: string | null;
}

export interface ConversationList {
  items: ConversationRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

export interface TranscriptMessage {
  id: number;
  role: 'user' | 'assistant' | 'event';
  content: string;
  feedback: number | null;
  sources: { title: string; url: string }[];
  created_at: string;
  intent?: string | null;
  provider?: string | null;
  model?: string | null;
  input_tokens?: number | null;
  output_tokens?: number | null;
  cached_tokens?: number | null;
  latency_ms?: number | null;
  error?: string | null;
}

export interface ConversationDetail extends Omit<ConversationRow, 'enquiry_status'> {
  user_agent: string | null;
  referrer: string | null;
  utm_medium: string | null;
  visitor: string | null;
  status: string;
  enquiry_name: string | null;
  enquiry_phone: string | null;
  enquiry_status: string | null;
  messages: TranscriptMessage[];
}

export interface Analytics {
  days: number;
  totals: {
    conversations: number;
    questions: number;
    answers: number;
    leads: number;
    lead_offers: number;
    lead_rate: number | null;
    helpful: number;
    not_helpful: number;
    satisfaction: number | null;
    avg_latency_ms: number | null;
    avg_messages: number;
    ai_share: number | null;
    tokens_in: number;
    tokens_out: number;
    tokens_cached: number;
  };
  daily: { labels: string[]; conversations: number[]; questions: number[]; leads: number[] };
  providers: { provider: string; answers: number; avg_ms: number; max_ms: number; tin: number; tout: number; tcache: number; up: number; down: number; errors: number }[];
  intents: { intent: string; count: number }[];
  top_questions: { question: string; count: number }[];
  unanswered: { question: string; created_at: string; conversation_id: number }[];
  errors: { provider: string; model: string | null; kind: string; error_type: string | null; http_status: number | null; message: string | null; created_at: string }[];
  languages: Record<string, number>;
}

export interface ProviderLog {
  id: number;
  provider: string;
  model: string | null;
  kind: 'chat' | 'test';
  status: 'ok' | 'error';
  http_status: number | null;
  error_type: string | null;
  message: string | null;
  latency_ms: number | null;
  created_at: string;
  user_name: string | null;
  hint: string;
}

export const PROVIDER_LABELS: Record<string, string> = { anthropic: 'Claude', openai: 'OpenAI', gemini: 'Gemini', offline: 'Rule-based' };
export const PROVIDER_COLORS: Record<string, 'purple' | 'green' | 'blue' | 'slate'> = { anthropic: 'purple', openai: 'green', gemini: 'blue', offline: 'slate' };
export const LEAD_LABELS: Record<string, string> = { none: 'No lead', offered: 'Callback offered', captured: 'Lead captured' };
export const LANG_LABELS: Record<string, string> = { en: 'English', hi: 'Hindi', hinglish: 'Hinglish' };
export const INTENT_LABELS: Record<string, string> = {
  programs: 'Programs', program_detail: 'Program details', fees: 'Fees', scholarship: 'Scholarships', eligibility: 'Eligibility', admission: 'Admission process',
  dates: 'Dates & deadlines', documents: 'Documents', entrance: 'Entrance / interview', hostel: 'Hostel', transport: 'Transport', placement: 'Placements',
  location: 'Location', contact: 'Contact', counsellor: 'Counsellor / callback', whatsapp: 'WhatsApp', notices: 'Notices', events: 'Events', departments: 'Departments',
  campus: 'Campus facilities', affiliation: 'Affiliation', greeting: 'Greeting', thanks: 'Thanks', bye: 'Goodbye', offtopic: 'Off-topic', abuse: 'Abusive', unknown: 'Other / unknown', faq: 'FAQ',
};
