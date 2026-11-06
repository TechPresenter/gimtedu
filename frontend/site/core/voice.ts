import { bindOnce, within } from './dom';

/* ------------------------------------------------------------------ Voice input (client request 2026-10-26) */

/**
 * <button type="button" class="voice-btn" data-voice-input="#site-search-input" data-voice-submit hidden
 *         aria-label="Search by voice">…mic icon…</button>
 * Speech-to-text with the browser's Web Speech API (Chrome, Edge, Safari — Chrome sends the audio to its own speech
 * service; nothing reaches our server until the visitor submits). Buttons stay hidden where the API is missing
 * (Firefox) and the page keeps working by typing. The transcript fills the target field live (input event dispatched);
 * data-voice-submit submits the field's form on the final result. Language: data-voice-lang, else <html lang> + "-IN".
 * The chat window (islands/chat/ChatWindow.tsx) uses listen() directly. Needs Permissions-Policy microphone=(self).
 */

interface SpeechAlternative {
  transcript: string;
}
interface SpeechResult {
  readonly isFinal: boolean;
  readonly length: number;
  [index: number]: SpeechAlternative;
}
interface SpeechResultEvent {
  readonly results: { readonly length: number; [index: number]: SpeechResult };
}
interface Recognizer {
  lang: string;
  interimResults: boolean;
  continuous: boolean;
  maxAlternatives: number;
  onresult: ((e: SpeechResultEvent) => void) | null;
  onerror: ((e: { error: string }) => void) | null;
  onend: (() => void) | null;
  start(): void;
  stop(): void;
}
type RecognizerCtor = new () => Recognizer;

/** The browser's speech-recognition constructor, or null when voice input is not available. */
export function speechCtor(): RecognizerCtor | null {
  const w = window as unknown as { SpeechRecognition?: RecognizerCtor; webkitSpeechRecognition?: RecognizerCtor };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

/** Recognition language: the element's data-voice-lang, else the page language as Indian English / Hindi etc. */
export function voiceLang(el?: Element | null): string {
  const own = el?.getAttribute('data-voice-lang');
  if (own) return own;
  return `${(document.documentElement.lang || 'en').split('-')[0]}-IN`;
}

/** Friendly message for a recognition error code (null = nothing worth telling the visitor). */
export function voiceErrorText(code?: string): string | null {
  if (!code || code === 'no-speech' || code === 'aborted') return null;
  if (code === 'not-allowed' || code === 'service-not-allowed') return 'Microphone access is blocked — allow it in your browser to use voice input.';
  if (code === 'audio-capture') return 'No microphone was found on this device.';
  if (code === 'network') return 'Voice input needs an internet connection — please type instead.';
  return 'Voice input did not work — please type instead.';
}

export interface VoiceSession {
  stop: () => void;
}

/**
 * Listen once: onText(text, final) gets the live and the final transcript; onEnd always runs last (error code when the
 * recognition failed). Returns null when speech recognition is unavailable or could not start.
 */
export function listen(o: { lang: string; onText: (text: string, final: boolean) => void; onEnd: (error?: string) => void }): VoiceSession | null {
  const Ctor = speechCtor();
  if (!Ctor) return null;
  const rec = new Ctor();
  rec.lang = o.lang;
  rec.interimResults = true;
  rec.continuous = false;
  rec.maxAlternatives = 1;
  let error: string | undefined;
  rec.onresult = (e) => {
    let text = '';
    let final = false;
    for (let i = 0; i < e.results.length; i++) {
      text += e.results[i][0]?.transcript ?? '';
      final = e.results[i].isFinal;
    }
    o.onText(text.trim(), final);
  };
  rec.onerror = (e) => {
    error = e.error;
  };
  rec.onend = () => o.onEnd(error);
  try {
    rec.start();
  } catch {
    return null;
  }
  return {
    stop: () => {
      try {
        rec.stop();
      } catch {
        /* already stopped */
      }
    },
  };
}

/** Mic buttons for plain fields (website search). Idempotent. */
export function initVoiceInputs(root: ParentNode = document): void {
  if (!speechCtor()) return;
  within('[data-voice-input]', root).forEach((btn) => {
    const field = document.querySelector<HTMLInputElement | HTMLTextAreaElement>(btn.getAttribute('data-voice-input') || '');
    if (!field || !bindOnce(btn, 'voice')) return;
    const idleLabel = btn.getAttribute('aria-label') || 'Search by voice';
    const placeholder = field.getAttribute('placeholder') || '';
    let session: VoiceSession | null = null;
    const setListening = (on: boolean) => {
      btn.classList.toggle('voice-listening', on);
      btn.setAttribute('aria-pressed', String(on));
      btn.setAttribute('aria-label', on ? 'Stop listening' : idleLabel);
      field.setAttribute('placeholder', on ? 'Listening… speak now' : placeholder);
    };
    btn.hidden = false;
    btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', () => {
      if (session) {
        session.stop();
        return;
      }
      const before = field.value;
      session = listen({
        lang: voiceLang(btn),
        onText: (text, final) => {
          field.value = text;
          field.dispatchEvent(new Event('input', { bubbles: true }));
          if (final && text && btn.hasAttribute('data-voice-submit') && field.form) {
            if (typeof field.form.requestSubmit === 'function') field.form.requestSubmit();
            else field.form.submit();
          }
        },
        onEnd: (error) => {
          session = null;
          setListening(false);
          if (!field.value) field.value = before;
          const msg = voiceErrorText(error);
          if (msg) field.setAttribute('placeholder', msg);
          field.focus();
        },
      });
      if (session) setListening(true);
    });
  });
}
