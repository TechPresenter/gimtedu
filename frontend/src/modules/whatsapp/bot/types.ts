/** WhatsApp AI chatbot (Module 9) — shapes returned by /api/whatsapp/bot/* (api/routes/whatsapp/bot.php). */

export type BotLang = 'en' | 'hi' | 'hing';

export interface BotQuestion {
  key: string;
  type: 'text' | 'choice' | 'program';
  enabled: boolean;
  label: string;
  question: Record<BotLang, string>;
  options: string[];
}

export interface BotSettingsValues {
  enabled: boolean;
  languages: ('en' | 'hi')[];
  hours_mode: 'always' | 'after_hours';
  cover_assigned: boolean;
  use_ai: boolean;
  summary_ai: boolean;
  use_buttons: boolean;
  max_turns: number;
  max_per_hour: number;
  low_conf_limit: number;
  min_confidence: number;
  handover_keywords: string;
  sensitive_keywords: string;
  handover_users: number[];
  persona: string;
  qualify_enabled: boolean;
  /** Create / link the ERP admission enquiry (Admissions › Enquiries) when the bot finishes qualifying a lead. */
  create_enquiry: boolean;
  qualify_questions: BotQuestion[];
}

export interface BotText {
  key: string;
  label: string;
  help: string;
  values: Record<BotLang, string>;
}

export interface BotAiStatus {
  configured: boolean;
  enabled: boolean;
  primary: string;
  chain: { key: string; label: string; model: string }[];
  daily_limit: number;
  used_today_web: number;
  used_today_whatsapp: number;
}

export interface BotSettings {
  values: BotSettingsValues;
  texts: BotText[];
  ai: BotAiStatus;
  business_hours: { label: string; open_now: boolean };
  users: { value: number; label: string; sub: string | null }[];
  pool: { id: number; name: string }[];
  intake_options: string[];
  mode: 'test' | 'live';
  /** The WhatsApp CRM's enquiry helper is loaded (false = the enquiry switch has no effect). */
  enquiry_available: boolean;
}

export interface BotCount {
  key: string;
  count: number;
  label?: string;
}

export interface BotOverview {
  days: number;
  enabled: boolean;
  kpis: {
    conversations: number;
    messages: number;
    questions: number;
    answered: number;
    answer_rate: number | null;
    handled_by_bot: number;
    handed_over: number;
    containment: number | null;
    fallbacks: number;
    qualified: number;
    avg_confidence: number | null;
    avg_latency_ms: number | null;
    ai_answers: number;
    tokens: number;
    unanswered_open: number;
  };
  daily: { labels: string[]; series: { answered: number[]; fallback: number[]; handover: number[] } };
  intents: BotCount[];
  languages: BotCount[];
  providers: BotCount[];
  handover_reasons: BotCount[];
  top_questions: { question: string; count: number; intent: string | null }[];
  ai: BotAiStatus;
}

export interface BotUnanswered {
  question: string;
  count: number;
  contacts: number;
  last_at: string;
  language: BotLang | null;
  turn_ids: number[];
}

export interface BotKnowledge {
  id: number;
  category: string;
  question: string;
  keywords: string | null;
  answer_en: string;
  answer_hi: string | null;
  status: 'active' | 'draft' | 'archived';
  verified_by_name: string | null;
  verified_at: string | null;
  hits: number;
  last_used_at: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface BotKnowledgeList {
  rows: BotKnowledge[];
  categories: Record<string, string>;
  counts: Partial<Record<'active' | 'draft' | 'archived', number>>;
  placeholders: Record<string, string>;
}

export interface BotFaq {
  id: number;
  category: string | null;
  question: string;
  answer: string;
  status: string;
  whatsapp: boolean;
  excluded: boolean;
}

export interface BotCourse {
  id: number;
  name: string;
  short_name: string;
  code: string | null;
  level: string;
  department: string | null;
  duration: string | null;
  seats: number | null;
  fee: string | null;
  fee_per_year: number | null;
  eligibility: string | null;
  specialisations: string[];
  url: string | null;
  verified: boolean;
  explicit: boolean;
  note: string | null;
  checked_by_name: string | null;
  checked_at: string | null;
  issues: string[];
}

export interface BotSessionRow {
  id: number;
  contact_id: number;
  conversation_id: number | null;
  name: string | null;
  phone: string | null;
  contact_type: string | null;
  stage: string | null;
  program_name: string | null;
  city: string | null;
  language: BotLang;
  state: 'active' | 'qualifying' | 'handed_over' | 'closed';
  qualification: Record<string, string>;
  qualification_done: boolean;
  pending_question: string | null;
  summary: string | null;
  messages_count: number;
  handed_over_at: string | null;
  handover_reason: string | null;
  handover_label: string | null;
  last_activity_at: string | null;
  created_at: string;
  bot_active: boolean | null;
  assigned_name: string | null;
}

export interface BotFact {
  id: string;
  type: string;
  title: string;
  score?: number;
}

export interface BotTurn {
  id: number;
  question: string | null;
  reply: string | null;
  action: string;
  intent: string | null;
  confidence: number | null;
  answered: boolean;
  provider: string | null;
  handover: boolean;
  facts: BotFact[];
  created_at: string;
}

export interface BotSessionDetail extends BotSessionRow {
  qualification_items: { key: string; label: string; value: string }[];
  turns: BotTurn[];
  bot_enabled: boolean;
}

export interface BotSessionsPage {
  rows: BotSessionRow[];
  meta: { total: number; page: number; per_page: number; pages: number };
  counts: Partial<Record<BotSessionRow['state'], number>>;
}

/** One message produced by the engine (text or interactive). */
export interface BotOutMessage {
  type: 'text' | 'interactive_button' | 'interactive_list';
  body: string;
  interactive?: {
    buttons?: { id: string; title: string }[];
    button?: string;
    sections?: { title?: string; rows: { id: string; title: string; description?: string }[] }[];
  };
}

export interface BotTestState {
  lang?: BotLang | null;
  answers?: Record<string, string>;
  flow?: Record<string, unknown>;
}

export interface BotDecision {
  action: string;
  intent: string | null;
  intents: string[];
  confidence: number | null;
  answered: number;
  provider: string | null;
  model: string | null;
  facts: BotFact[];
  usage: { input?: number; output?: number } | null;
  errors: string[];
  latency_ms: number;
  handover: string | null;
  handover_to: string | null;
  pool_size: number;
  lang: BotLang;
  state: string;
  qualified: boolean;
  contact_updates: Record<string, unknown>;
  stage: string | null;
  note?: string;
}

export interface BotTestResult {
  messages: BotOutMessage[];
  state: BotTestState;
  decision: BotDecision;
}
