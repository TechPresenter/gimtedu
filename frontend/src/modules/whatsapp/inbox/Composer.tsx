import { useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent } from 'react';
import clsx from 'clsx';
import {
  Contact,
  FileAudio,
  FileText,
  Film,
  Image as ImageIcon,
  LayoutTemplate,
  List,
  Lock,
  MapPin,
  MousePointerClick,
  Paperclip,
  Plus,
  SendHorizontal,
  ShieldBan,
  X,
  Zap,
} from 'lucide-react';
import { Button, Dropdown, Spinner, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { humanFileSize } from '@/lib/format';
import type { WaMediaUpload } from '../shared';
import { useQuickReplies } from './hooks';
import { InteractiveBuilder } from './InteractiveBuilder';
import { ContactCardModal, LocationModal } from './ShareModals';
import { TemplatePicker } from './TemplatePicker';
import type { InboxMessage, QuickReply, SendPayload } from './types';
import { ATTACH_RULES, type AttachKind, uploadWithProgress, validateAttachment } from './utils';

interface Attachment {
  kind: AttachKind;
  file: File;
  preview: string | null;
  progress: number;
  uploaded: WaMediaUpload | null;
  error: string | null;
  abort: AbortController;
}

interface Props {
  convId: number;
  contactName: string;
  windowOpen: boolean;
  canSend: boolean;
  blocked: boolean;
  onSend: (payload: SendPayload, preview: Partial<InboxMessage>) => Promise<unknown>;
  onManageQuickReplies: () => void;
}

const ATTACH_ICON: Record<AttachKind, typeof ImageIcon> = { image: ImageIcon, video: Film, document: FileText, audio: FileAudio };
/** Unsent text per conversation (kept while the inbox stays open). */
const DRAFTS = new Map<number, string>();

export function Composer({ convId, contactName, windowOpen, canSend, blocked, onSend, onManageQuickReplies }: Props) {
  const toast = useToast();
  const [text, setText] = useState('');
  const [att, setAtt] = useState<Attachment | null>(null);
  const [qrOpen, setQrOpen] = useState(false);
  const [qrIndex, setQrIndex] = useState(0);
  const [modal, setModal] = useState<null | 'template' | 'buttons' | 'list' | 'location' | 'contact'>(null);
  const taRef = useRef<HTMLTextAreaElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const pickKind = useRef<AttachKind>('image');
  const [slashOff, setSlashOff] = useState(false);

  // keep a draft per conversation
  useEffect(() => {
    setText(DRAFTS.get(convId) ?? '');
    setAtt(null);
    setQrOpen(false);
    return () => undefined;
  }, [convId]);
  useEffect(() => {
    DRAFTS.set(convId, text);
    if (!text.startsWith('/')) setSlashOff(false);
  }, [text, convId]);

  // auto-grow textarea
  useLayoutEffect(() => {
    const ta = taRef.current;
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = `${Math.min(ta.scrollHeight, 160)}px`;
  }, [text]);

  const slash = text.startsWith('/') && !text.includes('\n') ? text.slice(1) : null;
  const showQr = qrOpen || (slash !== null && !slashOff);
  const qrQuery = useDebounce(slash ?? '', 200);
  const qr = useQuickReplies(qrQuery, convId, showQr && canSend);
  const qrRows = qr.data?.rows ?? [];
  useEffect(() => setQrIndex(0), [qrQuery]);

  const insertQuickReply = async (r: QuickReply) => {
    setQrOpen(false);
    let body = r.filled ?? r.body;
    try {
      const res = await api.post<{ body: string }>(`whatsapp/quick-replies/${r.id}/use`, { conversation_id: convId });
      body = res.data.body;
    } catch {
      /* counting a use is best-effort */
    }
    setText(body);
    requestAnimationFrame(() => taRef.current?.focus());
  };

  const send = async (payload: SendPayload, preview: Partial<InboxMessage>) => {
    try {
      await onSend(payload, preview);
    } catch (e) {
      toast.error((e as ApiError).message);
      throw e;
    }
  };

  const submit = () => {
    if (!canSend) return;
    if (att) {
      if (!att.uploaded) return;
      const up = att.uploaded;
      const typed = text.trim();
      // WhatsApp shows no caption under audio: send the audio, then the typed text as its own message
      const caption = att.kind === 'audio' ? '' : typed;
      const followUp = att.kind === 'audio' ? typed : '';
      void send(
        { type: att.kind, media_url: up.url, file_name: up.name, media_mime: up.mime, body: caption || undefined },
        { type: att.kind, media_url: up.url, file_name: up.name, media_mime: up.mime, body: caption || (att.kind === 'document' ? up.name : null) },
      )
        .then(() => (followUp ? send({ type: 'text', body: followUp }, { type: 'text', body: followUp }) : undefined))
        .catch(() => undefined);
      if (att.preview) URL.revokeObjectURL(att.preview);
      setAtt(null);
      setText('');
      return;
    }
    const body = text.trim();
    if (!body) return;
    if (body.length > 4096) {
      toast.error('A WhatsApp message can be at most 4,096 characters.');
      return;
    }
    setText('');
    void send({ type: 'text', body }, { type: 'text', body }).catch(() => undefined);
  };

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (showQr && qrRows.length) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setQrIndex((i) => (i + 1) % qrRows.length);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        setQrIndex((i) => (i - 1 + qrRows.length) % qrRows.length);
        return;
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        void insertQuickReply(qrRows[Math.min(qrIndex, qrRows.length - 1)]);
        return;
      }
    }
    if (e.key === 'Escape' && showQr) {
      e.preventDefault();
      setQrOpen(false);
      if (slash !== null) setSlashOff(true);
      return;
    }
    // "/xyz" with the picker still loading or empty: never send the shortcut text by accident (Esc first to send it)
    if (e.key === 'Enter' && !e.shiftKey && showQr && slash !== null) {
      e.preventDefault();
      return;
    }
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault();
      submit();
    }
  };

  const choose = (kind: AttachKind) => {
    pickKind.current = kind;
    if (fileRef.current) {
      fileRef.current.accept = ATTACH_RULES[kind].accept;
      fileRef.current.value = '';
      fileRef.current.click();
    }
  };

  const onFile = (file: File | undefined) => {
    if (!file) return;
    const kind = pickKind.current;
    const err = validateAttachment(kind, file);
    if (err) {
      toast.error(err);
      return;
    }
    if (att?.preview) URL.revokeObjectURL(att.preview);
    att?.abort.abort();
    const abort = new AbortController();
    const preview = kind === 'image' ? URL.createObjectURL(file) : null;
    const next: Attachment = { kind, file, preview, progress: 0, uploaded: null, error: null, abort };
    setAtt(next);
    uploadWithProgress(file, kind, (pct) => setAtt((a) => (a && a.file === file ? { ...a, progress: pct } : a)), abort.signal)
      .then((up) => setAtt((a) => (a && a.file === file ? { ...a, uploaded: up, progress: 100 } : a)))
      .catch((e: Error) => {
        if (e.name === 'AbortError') return;
        setAtt((a) => (a && a.file === file ? { ...a, error: e.message } : a));
      });
    requestAnimationFrame(() => taRef.current?.focus());
  };

  const removeAttachment = () => {
    att?.abort.abort();
    if (att?.preview) URL.revokeObjectURL(att.preview);
    setAtt(null);
  };

  const modals = (
    <>
      <TemplatePicker open={modal === 'template'} onClose={() => setModal(null)} convId={convId} contactName={contactName} windowOpen={windowOpen} onSend={(p, prev) => send(p, { type: 'template', ...prev })} />
      <InteractiveBuilder open={modal === 'buttons' || modal === 'list'} mode={modal === 'list' ? 'list' : 'buttons'} onClose={() => setModal(null)} onSend={(p, prev) => send(p, prev)} />
      <LocationModal open={modal === 'location'} onClose={() => setModal(null)} onSend={(p, prev) => send(p, prev)} />
      <ContactCardModal open={modal === 'contact'} onClose={() => setModal(null)} onSend={(p, prev) => send(p, prev)} />
    </>
  );

  if (!canSend) {
    return (
      <div className="border-t border-slate-200 bg-white px-4 py-3 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
        You can read this conversation. Your role does not allow sending WhatsApp messages.
      </div>
    );
  }

  if (blocked) {
    return (
      <div className="flex items-center justify-center gap-2 border-t border-slate-200 bg-white px-4 py-3 text-sm text-red-600 dark:border-slate-800 dark:bg-slate-900 dark:text-red-400">
        <ShieldBan className="h-4 w-4" aria-hidden /> This contact is blocked. Unblock them in Contacts to send messages.
      </div>
    );
  }

  if (!windowOpen) {
    return (
      <div className="border-t border-slate-200 bg-white px-3 py-3 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex flex-row items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5 sm:flex-col sm:items-stretch sm:px-4 sm:py-3 2xl:flex-row 2xl:items-center dark:bg-slate-800/60">
          <p className="flex min-w-0 flex-1 items-center gap-3 text-sm text-slate-600 sm:items-start dark:text-slate-300">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300">
              <Lock className="h-4 w-4" aria-hidden />
            </span>
            <span className="sm:hidden">
              <span className="font-semibold text-slate-800 dark:text-slate-100">Window closed</span> — templates only until {contactName} replies.
            </span>
            <span className="hidden sm:inline">
              <span className="font-semibold text-slate-800 dark:text-slate-100">24-hour window closed.</span> {contactName} has not messaged in the last 24 hours, so WhatsApp only allows
              pre-approved templates. Free-form replies unlock as soon as they reply.
            </span>
          </p>
          <Button icon={LayoutTemplate} onClick={() => setModal('template')} aria-label="Send template" className="shrink-0 sm:self-end 2xl:self-auto">
            <span className="sm:hidden">Template</span>
            <span className="hidden sm:inline">Send template</span>
          </Button>
        </div>
        {modals}
      </div>
    );
  }

  const busy = !!att && !att.uploaded && !att.error;
  const canSubmit = att ? !!att.uploaded : text.trim().length > 0;

  return (
    <div className="relative border-t border-slate-200 bg-white px-2 py-2 sm:px-3 dark:border-slate-800 dark:bg-slate-900">
      {showQr && (
        <div className="absolute inset-x-2 bottom-full z-20 mb-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-pop sm:inset-x-3 dark:border-slate-700 dark:bg-slate-900" role="listbox" aria-label="Quick replies">
          <div className="flex items-center justify-between border-b border-slate-100 px-3 py-2 dark:border-slate-800">
            <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300">
              <Zap className="h-3.5 w-3.5 text-accent-600" aria-hidden /> Quick replies {slash ? <span className="font-normal text-slate-400">matching “{slash}”</span> : <span className="font-normal text-slate-400">— type / to search</span>}
            </span>
            <span className="flex items-center gap-1">
              {qr.data?.can_manage && (
                <button type="button" onClick={() => { setQrOpen(false); onManageQuickReplies(); }} className="rounded px-1.5 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">Manage</button>
              )}
              <button type="button" onClick={() => { setQrOpen(false); if (slash !== null) setSlashOff(true); }} className="rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Close quick replies"><X className="h-3.5 w-3.5" /></button>
            </span>
          </div>
          <ul className="max-h-64 overflow-y-auto py-1">
            {qr.isLoading ? (
              <li className="flex justify-center py-4"><Spinner className="h-4 w-4" /></li>
            ) : qrRows.length === 0 ? (
              <li className="px-3 py-4 text-center text-sm text-slate-500">{slash ? `No quick reply matches “${slash}”.` : 'No quick replies yet.'}</li>
            ) : (
              qrRows.map((r, i) => (
                <li key={r.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={i === qrIndex}
                    onMouseEnter={() => setQrIndex(i)}
                    onClick={() => void insertQuickReply(r)}
                    className={clsx('flex w-full items-start gap-3 px-3 py-2 text-left', i === qrIndex ? 'bg-slate-100 dark:bg-slate-800' : '')}
                  >
                    <span className="mt-0.5 shrink-0 rounded bg-accent-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">/{r.shortcut}</span>
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{r.title}</span>
                      <span className="line-clamp-1 text-xs text-slate-500 dark:text-slate-400">{r.filled ?? r.body}</span>
                    </span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}

      {att && (
        <div className="mb-2 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800/60">
          {att.preview ? (
            <img src={att.preview} alt="" className="h-12 w-12 shrink-0 rounded-lg object-cover" />
          ) : (
            (() => {
              const Icon = ATTACH_ICON[att.kind];
              return <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"><Icon className="h-5 w-5" aria-hidden /></span>;
            })()
          )}
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{att.file.name}</p>
            {att.error ? (
              <p className="text-xs text-red-600 dark:text-red-400">{att.error}</p>
            ) : att.uploaded ? (
              <p className="text-xs text-emerald-600 dark:text-emerald-400">{humanFileSize(att.file.size)} · ready — {att.kind === 'audio' ? 'audio has no caption; any text you type is sent as a separate message' : 'add a caption (optional) and send'}</p>
            ) : (
              <div className="mt-1 flex items-center gap-2">
                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" aria-valuenow={att.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Upload progress">
                  <div className="h-full rounded-full bg-accent-600 transition-[width]" style={{ width: `${att.progress}%` }} />
                </div>
                <span className="text-[11px] tabular-nums text-slate-500">{att.progress}%</span>
              </div>
            )}
          </div>
          <button type="button" onClick={removeAttachment} className="rounded-full p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700" aria-label="Remove attachment">
            <X className="h-4 w-4" />
          </button>
        </div>
      )}

      <div className="flex items-end gap-1">
        <Dropdown
          align="left"
          label="Attach a file"
          triggerClassName="btn-icon shrink-0"
          trigger={<Paperclip className="h-[18px] w-[18px]" aria-hidden />}
          items={(Object.keys(ATTACH_RULES) as AttachKind[]).map((k) => ({ label: ATTACH_RULES[k].label, icon: ATTACH_ICON[k], hint: `${Math.round(ATTACH_RULES[k].max / 1048576)} MB`, onClick: () => choose(k) }))}
        />
        <Dropdown
          align="left"
          label="More message types"
          triggerClassName="btn-icon shrink-0"
          trigger={<Plus className="h-[18px] w-[18px]" aria-hidden />}
          width="w-60"
          items={[
            { label: 'Template', icon: LayoutTemplate, onClick: () => setModal('template') },
            { label: 'Reply buttons', icon: MousePointerClick, onClick: () => setModal('buttons'), divider: true },
            { label: 'List message', icon: List, onClick: () => setModal('list') },
            { label: 'Location (Maps link)', icon: MapPin, onClick: () => setModal('location'), divider: true },
            { label: 'Contact card (text)', icon: Contact, onClick: () => setModal('contact') },
          ]}
        />
        <button type="button" onClick={() => setQrOpen((o) => !o)} className={clsx('btn-icon hidden shrink-0 sm:inline-flex', showQr && 'bg-slate-100 text-accent-700 dark:bg-slate-800 dark:text-accent-300')} aria-label="Quick replies" title="Quick replies (type /)">
          <Zap className="h-[18px] w-[18px]" aria-hidden />
        </button>
        <label htmlFor={`composer-${convId}`} className="sr-only">Message</label>
        <textarea
          id={`composer-${convId}`}
          ref={taRef}
          rows={1}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={onKeyDown}
          maxLength={att && att.kind !== 'audio' ? 1024 : 4096}
          placeholder={att ? (att.kind === 'audio' ? 'Optional text, sent after the audio…' : 'Add a caption…') : 'Type a message'}
          className="form-input min-h-[2.5rem] flex-1 resize-none py-2 leading-snug"
        />
        <button
          type="button"
          onClick={submit}
          disabled={!canSubmit || busy}
          className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent-600 text-white shadow-sm transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40 dark:focus-visible:ring-offset-slate-900"
          aria-label="Send message"
          title="Send (Enter)"
        >
          {busy ? <Spinner className="h-4 w-4" /> : <SendHorizontal className="h-[18px] w-[18px]" aria-hidden />}
        </button>
      </div>
      <p className="mt-1 hidden px-1 text-[11px] text-slate-400 sm:block">Enter to send · Shift + Enter for a new line · / for quick replies</p>
      <input ref={fileRef} type="file" className="hidden" onChange={(e) => onFile(e.target.files?.[0])} tabIndex={-1} aria-hidden />
      {modals}
    </div>
  );
}
