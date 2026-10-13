/** Shared types of the website AI chat widget (island ChatWidget). Props mirror chatbot_public_config() in PHP. */

export interface ChatSuggestion {
  label: string;
  /** Sent as the visitor's message when clicked. */
  message?: string;
  /** lead = open the callback form · whatsapp = open WhatsApp · link = navigate to url */
  action?: 'lead' | 'whatsapp' | 'link';
  url?: string;
}

export interface ChatSource {
  title: string;
  url: string;
}

export interface ChatConfig {
  enabled: boolean;
  botName: string;
  avatar: string | null;
  greeting: string;
  /** Seconds before the greeting bubble appears. */
  greetingDelay: number;
  welcome: string;
  welcomeSub: string;
  quickReplies: ChatSuggestion[];
  /** Counsellors available (working hours) or AI configured. */
  online: boolean;
  statusText: string;
  offlineMessage: string;
  hours: string;
  leadCapture: boolean;
  streaming: boolean;
  maxLength: number;
  whatsapp: string | null;
  phone: string;
  tel: string | null;
  privacyUrl: string;
  contactUrl: string;
  /** Small footer credit, e.g. "Powered by Appsgain Technologies" (text after "by " is the link). */
  credit: { text: string; url: string | null } | null;
  showOnMobile: boolean;
  programs: { value: string; label: string }[];
  endpoints: { message: string; stream: string; lead: string; feedback: string; history: string; config: string; csrf: string };
  basePath: string;
}

export type MessageRole = 'user' | 'assistant' | 'event';

export interface ChatMessage {
  /** Client id (stable React key). */
  key: string;
  /** Server message id (assistant messages, for feedback). */
  id?: number;
  role: MessageRole;
  text: string;
  at: number;
  sources?: ChatSource[];
  suggestions?: ChatSuggestion[];
  feedback?: 'up' | 'down' | null;
  /** user message that failed to send (shows Retry). */
  failed?: string;
  /** assistant message still streaming / being revealed. */
  pending?: boolean;
  /** Show the callback card after this message. */
  lead?: boolean;
  leadPrefill?: { phone?: string } | null;
}

export interface ChatReply {
  conversation_id: string;
  message_id: number;
  reply: string;
  sources: ChatSource[];
  suggestions: ChatSuggestion[];
  lead_prompt: boolean;
  lead_prefill: { phone?: string } | null;
  created_at: string;
}

export interface StoredChat {
  v: 1;
  cid: string | null;
  messages: ChatMessage[];
  open: boolean;
  leadDone: boolean;
}
