import { csrfToken } from '../../core/dom';
import type { ChatConfig, ChatReply, StoredChat } from './types';

/** api_ok / api_error envelope (+ HTTP status). */
export interface Envelope<T> {
  ok: boolean;
  message: string;
  data?: T;
  errors?: Record<string, string>;
  status: number;
}

let token = '';
const currentToken = () => token || csrfToken();

/** Fresh CSRF token after a 419 (long-open page / expired session); also updates <meta name="csrf-token">. */
async function refreshToken(cfg: ChatConfig): Promise<boolean> {
  try {
    const res = await fetch(cfg.endpoints.csrf, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const json = (await res.json()) as { ok: boolean; data?: { token?: string } };
    if (json.ok && json.data?.token) {
      token = json.data.token;
      document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.setAttribute('content', token);
      return true;
    }
  } catch {
    /* ignore */
  }
  return false;
}

const NETWORK = 'You seem to be offline or the server is unreachable. Please check your connection and try again.';

/** POST JSON with CSRF header; retries once with a fresh token on 419. */
export async function postJson<T>(cfg: ChatConfig, url: string, body: Record<string, unknown>, retried = false): Promise<Envelope<T>> {
  let res: Response;
  try {
    res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': currentToken() },
      body: JSON.stringify(body),
    });
  } catch {
    return { ok: false, message: NETWORK, status: 0 };
  }
  if (res.status === 419 && !retried && (await refreshToken(cfg))) return postJson<T>(cfg, url, body, true);
  const json = (await res.json().catch(() => null)) as Omit<Envelope<T>, 'status'> | null;
  if (json && typeof json.ok === 'boolean') return { ...json, status: res.status };
  return { ok: false, message: res.status >= 500 ? 'The assistant had a problem answering. Please try again.' : `Request failed (${res.status}).`, status: res.status };
}

export async function getJson<T>(url: string): Promise<Envelope<T>> {
  try {
    const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const json = (await res.json().catch(() => null)) as Omit<Envelope<T>, 'status'> | null;
    return json && typeof json.ok === 'boolean' ? { ...json, status: res.status } : { ok: false, message: `Request failed (${res.status}).`, status: res.status };
  } catch {
    return { ok: false, message: NETWORK, status: 0 };
  }
}

interface StreamHandlers {
  onMeta?: (conversationId: string) => void;
  onDelta: (text: string) => void;
}

/**
 * POST /api/chat/stream — Server-Sent Events (meta, delta, done, error) when streaming is on; the server answers with
 * the normal JSON envelope otherwise (or for errors), which is handled the same way as postJson().
 */
export async function streamMessage(cfg: ChatConfig, body: Record<string, unknown>, h: StreamHandlers, retried = false): Promise<Envelope<ChatReply>> {
  let res: Response;
  try {
    res = await fetch(cfg.endpoints.stream, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream, application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': currentToken() },
      body: JSON.stringify(body),
    });
  } catch {
    return { ok: false, message: NETWORK, status: 0 };
  }
  if (res.status === 419 && !retried && (await refreshToken(cfg))) return streamMessage(cfg, body, h, true);
  if (!(res.headers.get('content-type') || '').includes('text/event-stream') || !res.body) {
    const json = (await res.json().catch(() => null)) as Omit<Envelope<ChatReply>, 'status'> | null;
    return json && typeof json.ok === 'boolean' ? { ...json, status: res.status } : { ok: false, message: `Request failed (${res.status}).`, status: res.status };
  }
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let result: Envelope<ChatReply> = { ok: false, message: 'The answer was interrupted. Please try again.', status: res.status };
  const handle = (block: string) => {
    let event = 'message';
    const data: string[] = [];
    block.split('\n').forEach((line) => {
      if (line.startsWith('event:')) event = line.slice(6).trim();
      else if (line.startsWith('data:')) data.push(line.slice(5).replace(/^ /, ''));
    });
    if (!data.length) return;
    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(data.join('\n')) as Record<string, unknown>;
    } catch {
      return;
    }
    if (event === 'meta' && typeof payload.conversation_id === 'string') h.onMeta?.(payload.conversation_id);
    else if (event === 'delta' && typeof payload.t === 'string') h.onDelta(payload.t);
    else if (event === 'done') result = { ok: true, message: '', data: payload as unknown as ChatReply, status: 200 };
    else if (event === 'error') result = { ok: false, message: String(payload.message || 'Something went wrong.'), status: 500 };
  };
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true }).replace(/\r\n/g, '\n');
      let i: number;
      while ((i = buffer.indexOf('\n\n')) >= 0) {
        handle(buffer.slice(0, i));
        buffer = buffer.slice(i + 2);
      }
    }
    if (buffer.trim()) handle(buffer);
  } catch {
    /* connection dropped: result stays an error unless "done" arrived */
  }
  return result;
}

/* ---------------------------------------------------------------- sessionStorage (per tab, survives navigation) */

const KEY = 'gimt-chat:v1';
const GREET_KEY = 'gimt-chat:greeted';

export function loadChat(): StoredChat | null {
  try {
    const raw = window.sessionStorage.getItem(KEY);
    const parsed = raw ? (JSON.parse(raw) as StoredChat) : null;
    return parsed && parsed.v === 1 && Array.isArray(parsed.messages) ? parsed : null;
  } catch {
    return null;
  }
}

export function saveChat(state: StoredChat): void {
  try {
    const messages = state.messages
      .filter((m) => !m.pending)
      .slice(-40)
      .map((m) => ({ ...m, suggestions: m.suggestions?.slice(0, 5) }));
    window.sessionStorage.setItem(KEY, JSON.stringify({ ...state, messages }));
  } catch {
    /* storage full / disabled: the conversation simply is not resumed */
  }
}

export function clearChat(): void {
  try {
    window.sessionStorage.removeItem(KEY);
  } catch {
    /* ignore */
  }
}

export function greetingDismissed(): boolean {
  try {
    return window.sessionStorage.getItem(GREET_KEY) === '1';
  } catch {
    return false;
  }
}

export function dismissGreeting(): void {
  try {
    window.sessionStorage.setItem(GREET_KEY, '1');
  } catch {
    /* ignore */
  }
}

/** utm_* of the landing URL (kept for the session so later pages still attribute the chat). */
export function utmParams(): Record<string, string> {
  const out: Record<string, string> = {};
  try {
    const saved = window.sessionStorage.getItem('gimt-chat:utm');
    if (saved) return JSON.parse(saved) as Record<string, string>;
    const q = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach((k) => {
      const v = q.get(k);
      if (v) out[k] = v.slice(0, 100);
    });
    if (Object.keys(out).length) window.sessionStorage.setItem('gimt-chat:utm', JSON.stringify(out));
  } catch {
    /* ignore */
  }
  return out;
}
