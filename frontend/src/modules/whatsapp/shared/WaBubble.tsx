import clsx from 'clsx';
import {
  Bot,
  CalendarClock,
  Contact as ContactIcon,
  Copy,
  CornerDownLeft,
  Download,
  ExternalLink,
  FileText,
  Headphones,
  Image as ImageIcon,
  List,
  MapPin,
  Megaphone,
  Phone,
  Play,
  Reply,
  Settings2,
  UserRound,
  Zap,
} from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import { parseJson, waMediaUrl, waSafeUrl } from './api';
import { StatusTicks } from './StatusTicks';
import { parseServerDate } from './WindowBadge';
import type {
  WaDirection,
  WaInteractive,
  WaMessage,
  WaMessageStatus,
  WaMessageType,
  WaSenderType,
  WaTemplateButton,
  WaTemplatePreviewData,
} from './types';

/* ------------------------------------------------------------------ WhatsApp text formatting */

const TOKEN_RE =
  /(?<![\p{L}\p{N}*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\p{L}\p{N}*])|(?<![\p{L}\p{N}_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\p{L}\p{N}_])|(?<![\p{L}\p{N}~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\p{L}\p{N}~])|`([^`\n]+)`|(\{\{\s*[\w.]+\s*\}\})|(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/gu;

function renderInline(text: string, highlightVars: boolean, keyBase: string): ReactNode[] {
  const out: ReactNode[] = [];
  let last = 0;
  let i = 0;
  TOKEN_RE.lastIndex = 0;
  const re = new RegExp(TOKEN_RE.source, 'gu');
  let m: RegExpExecArray | null;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const k = `${keyBase}-${i++}`;
    if (m[1] !== undefined) out.push(<strong key={k} className="font-semibold">{renderInline(m[1], highlightVars, k)}</strong>);
    else if (m[2] !== undefined) out.push(<em key={k}>{renderInline(m[2], highlightVars, k)}</em>);
    else if (m[3] !== undefined) out.push(<s key={k}>{renderInline(m[3], highlightVars, k)}</s>);
    else if (m[4] !== undefined)
      out.push(
        <code key={k} className="rounded bg-black/5 px-1 font-mono text-[0.92em] dark:bg-white/10">
          {m[4]}
        </code>,
      );
    else if (m[5] !== undefined)
      out.push(
        highlightVars ? (
          <span key={k} className="rounded bg-accent-100 px-1 font-mono text-[0.9em] text-accent-800 dark:bg-accent-500/20 dark:text-accent-200">
            {m[5]}
          </span>
        ) : (
          m[5]
        ),
      );
    else if (m[6] !== undefined)
      out.push(
        <a key={k} href={m[6]} target="_blank" rel="noopener noreferrer" className="break-all text-[#027EB5] underline-offset-2 hover:underline dark:text-[#53BDEB]">
          {m[6]}
        </a>,
      );
    last = m.index + m[0].length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

/** Render WhatsApp-formatted text (*bold* _italic_ ~strike~ ```mono``` `code`, links, {{vars}}) as React nodes — no HTML injection. */
export function WaText({ text, highlightVars = false, className }: { text: string | null | undefined; highlightVars?: boolean; className?: string }) {
  if (!text) return null;
  const parts = String(text).split(/```([\s\S]*?)```/g);
  return (
    <span className={clsx('whitespace-pre-wrap break-words', className)}>
      {parts.map((p, i) =>
        i % 2 === 1 ? (
          <code key={i} className="block whitespace-pre-wrap rounded bg-black/5 px-1.5 py-1 font-mono text-[0.9em] dark:bg-white/10">
            {p}
          </code>
        ) : (
          <Fragment key={i}>{renderInline(p, highlightVars, String(i))}</Fragment>
        ),
      )}
    </span>
  );
}

/** One-line plain preview of a message (inbox lists, notifications). */
export function waPreviewText(m: Pick<WaMessage, 'type' | 'body' | 'file_name' | 'template_name'>): string {
  const body = (m.body ?? '').replace(/[*_~`]/g, '').replace(/\s+/g, ' ').trim();
  switch (m.type) {
    case 'image':
      return `📷 ${body || 'Photo'}`;
    case 'video':
      return `🎬 ${body || 'Video'}`;
    case 'audio':
      return '🎧 Audio';
    case 'document':
      return `📄 ${m.file_name || body || 'Document'}`;
    case 'sticker':
      return 'Sticker';
    case 'location':
      return `📍 ${body || 'Location'}`;
    case 'contacts':
      return `👤 ${body || 'Contact card'}`;
    case 'template':
      return body || `Template: ${m.template_name ?? ''}`;
    default:
      return body;
  }
}

/* ------------------------------------------------------------------ helpers */

function timeLabel(v: string | null | undefined): string {
  const d = parseServerDate(v ?? null);
  if (!d) return '';
  return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Kolkata' });
}

const SENDER: Partial<Record<WaSenderType, { label: string; icon: typeof Bot }>> = {
  bot: { label: 'AI Bot', icon: Bot },
  automation: { label: 'Automation', icon: Zap },
  event: { label: 'Event', icon: Zap },
  campaign: { label: 'Campaign', icon: Megaphone },
  followup: { label: 'Follow-up', icon: CalendarClock },
  system: { label: 'System', icon: Settings2 },
  agent: { label: 'Agent', icon: UserRound },
};

function buttonIcon(t: WaTemplateButton['type']) {
  switch (t) {
    case 'URL':
      return ExternalLink;
    case 'PHONE_NUMBER':
      return Phone;
    case 'COPY_CODE':
    case 'OTP':
      return Copy;
    default:
      return Reply;
  }
}

function MediaBlock({
  kind,
  url,
  fileName,
  mime,
  rounded = 'rounded-md',
}: {
  kind: 'image' | 'video' | 'audio' | 'document';
  url: string | null;
  fileName?: string | null;
  mime?: string | null;
  rounded?: string;
}) {
  url = waSafeUrl(url); // never javascript: / data: in href or src
  if (kind === 'image') {
    return url ? (
      <a href={url} target="_blank" rel="noopener noreferrer" className={clsx('block overflow-hidden bg-black/5', rounded)}>
        <img src={url} alt={fileName || 'Image'} loading="lazy" className="max-h-72 w-full object-cover" />
      </a>
    ) : (
      <div className={clsx('flex h-36 items-center justify-center bg-black/5 text-slate-400 dark:bg-white/5', rounded)}>
        <ImageIcon size={36} aria-hidden />
        <span className="sr-only">Image header</span>
      </div>
    );
  }
  if (kind === 'video') {
    return url ? (
      <video src={url} controls preload="metadata" className={clsx('max-h-72 w-full bg-black', rounded)} />
    ) : (
      <div className={clsx('flex h-36 items-center justify-center bg-slate-800/80 text-white', rounded)}>
        <span className="flex h-11 w-11 items-center justify-center rounded-full bg-black/40">
          <Play size={20} aria-hidden />
        </span>
        <span className="sr-only">Video header</span>
      </div>
    );
  }
  if (kind === 'audio') {
    return url ? (
      <audio src={url} controls preload="none" className="w-full min-w-[220px]" />
    ) : (
      <div className="flex items-center gap-2 py-1 text-slate-500">
        <Headphones size={18} aria-hidden /> Audio
      </div>
    );
  }
  const ext = (fileName ?? '').split('.').pop()?.toUpperCase() || (mime?.includes('pdf') ? 'PDF' : 'FILE');
  return (
    <div className={clsx('flex items-center gap-2.5 bg-black/5 px-2.5 py-2 dark:bg-white/10', rounded)}>
      <span className="flex h-9 w-8 shrink-0 items-center justify-center rounded bg-red-500 text-[9px] font-bold text-white">
        {ext.length > 4 ? <FileText size={16} aria-hidden /> : ext}
      </span>
      <span className="min-w-0 flex-1 truncate text-sm">{fileName || 'Document'}</span>
      {url && (
        <a href={url} target="_blank" rel="noopener noreferrer" download={fileName || undefined} className="rounded p-1 text-slate-500 hover:bg-black/5 hover:text-slate-700 dark:text-slate-300 dark:hover:bg-white/10" title="Download">
          <Download size={16} aria-hidden />
          <span className="sr-only">Download {fileName}</span>
        </a>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ bubble */

export interface WaBubbleProps {
  /** A stored message (wa_messages row). */
  message?: Partial<WaMessage> | null;
  /** Or preview data (template designer, campaign preview, bot simulator). Overrides the message fields. */
  direction?: WaDirection;
  type?: WaMessageType;
  body?: string | null;
  mediaUrl?: string | null;
  fileName?: string | null;
  interactive?: WaInteractive | null;
  /** Template structure to draw (header / body / footer / buttons). Defaults to message.interactive.template. */
  template?: WaTemplatePreviewData | null;
  time?: string | null;
  status?: WaMessageStatus | null;
  showStatus?: boolean;
  /** Show who sent an outbound message (AI Bot, Campaign, agent name …). */
  showSender?: boolean;
  /** Highlight {{1}} / {{student_name}} placeholders (designer previews). */
  highlightVars?: boolean;
  /** Called when a reply button / list row / template quick reply is clicked (bot simulator). Buttons are inert otherwise. */
  onButtonClick?: (b: { id?: string; title: string; type?: string }) => void;
  tail?: boolean;
  className?: string;
}

/**
 * WhatsApp chat bubble for any message type: text, media (image / video / audio / document / sticker), location,
 * contacts, interactive buttons / list, button & list replies, template (header media or text, body, footer, buttons)
 * and system notes. Outbound bubbles align right (green), inbound left (white); time + StatusTicks in the corner.
 */
export function WaBubble(props: WaBubbleProps) {
  const m = props.message ?? {};
  const direction: WaDirection = props.direction ?? (m.direction as WaDirection) ?? 'out';
  const type: WaMessageType = props.type ?? (m.type as WaMessageType) ?? (props.template ? 'template' : 'text');
  const body = props.body !== undefined ? props.body : m.body ?? null;
  const interactive: WaInteractive | null = props.interactive ?? parseJson<WaInteractive>(m.interactive ?? null);
  const template: WaTemplatePreviewData | null = props.template ?? (type === 'template' ? (interactive?.template as WaTemplatePreviewData | undefined) ?? null : null);
  const status = props.status !== undefined ? props.status : (m.status as WaMessageStatus | undefined) ?? null;
  const time = props.time !== undefined ? props.time : m.sent_at ?? m.created_at ?? null;
  const showStatus = props.showStatus ?? direction === 'out';
  const out = direction === 'out';
  const tail = props.tail ?? true;
  const [listOpen, setListOpen] = useState(false);

  const mediaUrl = waSafeUrl(
    props.mediaUrl !== undefined
      ? props.mediaUrl
      : m.id
        ? waMediaUrl({ id: m.id, media_url: m.media_url ?? null, media_local: m.media_local ?? null, direction, media_proxy_url: m.media_proxy_url })
        : m.media_url ?? null,
  );
  const fileName = props.fileName !== undefined ? props.fileName : m.file_name ?? null;

  if (type === 'system') {
    return (
      <div className={clsx('my-1 flex justify-center', props.className)}>
        <span className="max-w-[85%] rounded-lg bg-white/90 px-3 py-1 text-center text-xs text-slate-600 shadow-sm dark:bg-[#182229] dark:text-slate-300">
          <WaText text={body} />
        </span>
      </div>
    );
  }

  const bubbleTone = out ? 'bg-[#D9FDD3] text-slate-900 dark:bg-[#005C4B] dark:text-slate-50' : 'bg-white text-slate-900 dark:bg-[#202C33] dark:text-slate-100';
  const bgTone = out ? 'bg-[#D9FDD3] dark:bg-[#005C4B]' : 'bg-white dark:bg-[#202C33]';
  const tailTone = out ? 'border-t-[#D9FDD3] dark:border-t-[#005C4B]' : 'border-t-white dark:border-t-[#202C33]';
  const isSticker = type === 'sticker';

  const meta = (
    <span className={clsx('ml-2 inline-flex translate-y-1 items-center gap-1 whitespace-nowrap align-bottom text-[10.5px] leading-none', out ? 'text-slate-500 dark:text-slate-300/80' : 'text-slate-400 dark:text-slate-400')}>
      {time && <span>{timeLabel(time)}</span>}
      {showStatus && status && <StatusTicks status={status} size={14} />}
    </span>
  );

  const sender =
    props.showSender && out && m.sender_type ? (
      (() => {
        const s = SENDER[m.sender_type as WaSenderType];
        if (!s) return null;
        const Icon = s.icon;
        const label = m.sender_type === 'agent' && m.sent_by_name ? m.sent_by_name : s.label;
        return (
          <span className="mb-0.5 flex items-center gap-1 text-[11px] font-semibold text-accent-700 dark:text-accent-300">
            <Icon size={11} aria-hidden /> {label}
          </span>
        );
      })()
    ) : null;

  // buttons rendered below the bubble (template buttons, reply buttons, list opener)
  let below: ReactNode = null;
  const btnCls =
    'flex w-full items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium text-[#027EB5] transition dark:text-[#53BDEB] disabled:cursor-default';
  if (template?.buttons?.length) {
    below = (
      <div className={clsx('mt-0.5 grid divide-y divide-slate-200/80 overflow-hidden rounded-lg shadow-sm dark:divide-white/10', bgTone)}>
        {template.buttons.map((b, i) => {
          const Icon = buttonIcon(b.type);
          const clickable = !!props.onButtonClick && b.type === 'QUICK_REPLY';
          return (
            <button
              key={i}
              type="button"
              disabled={!clickable}
              onClick={() => clickable && props.onButtonClick?.({ title: b.text, type: b.type })}
              className={clsx(btnCls, clickable && 'hover:bg-black/5 dark:hover:bg-white/5')}
              title={b.type === 'URL' ? b.url : b.type === 'PHONE_NUMBER' ? b.phone_number : undefined}
            >
              <Icon size={15} aria-hidden />
              <span className="truncate">{b.text || 'Button'}</span>
            </button>
          );
        })}
      </div>
    );
  } else if (type === 'interactive_button' && interactive?.buttons?.length) {
    below = (
      <div className={clsx('mt-0.5 grid divide-y divide-slate-200/80 overflow-hidden rounded-lg shadow-sm dark:divide-white/10', bgTone)}>
        {interactive.buttons.map((b, i) => (
          <button
            key={b.id ?? i}
            type="button"
            disabled={!props.onButtonClick}
            onClick={() => props.onButtonClick?.({ id: b.id, title: b.title, type: 'reply' })}
            className={clsx(btnCls, props.onButtonClick && 'hover:bg-black/5 dark:hover:bg-white/5')}
          >
            <Reply size={15} aria-hidden />
            <span className="truncate">{b.title}</span>
          </button>
        ))}
      </div>
    );
  } else if (type === 'interactive_list' && interactive?.sections?.length) {
    below = (
      <div className={clsx('mt-0.5 overflow-hidden rounded-lg shadow-sm', bgTone)}>
        <button type="button" onClick={() => setListOpen((o) => !o)} aria-expanded={listOpen} className={clsx(btnCls, 'hover:bg-black/5 dark:hover:bg-white/5')}>
          <List size={15} aria-hidden />
          <span className="truncate">{interactive.button || 'View options'}</span>
        </button>
        {listOpen && (
          <div className="border-t border-slate-200/80 px-3 py-2 text-slate-800 dark:border-white/10 dark:text-slate-100">
            {interactive.sections.map((s, si) => (
              <div key={si} className="py-1">
                {s.title && <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{s.title}</p>}
                <ul className="space-y-0.5">
                  {s.rows.map((r) => (
                    <li key={r.id}>
                      <button
                        type="button"
                        disabled={!props.onButtonClick}
                        onClick={() => props.onButtonClick?.({ id: r.id, title: r.title, type: 'list' })}
                        className={clsx('w-full rounded px-1.5 py-1 text-left', props.onButtonClick && 'hover:bg-black/5 dark:hover:bg-white/5')}
                      >
                        <span className="block text-sm">{r.title}</span>
                        {r.description && <span className="block text-xs text-slate-500 dark:text-slate-400">{r.description}</span>}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  let content: ReactNode;
  if (template) {
    const ht = template.header_type ?? 'none';
    content = (
      <>
        {sender}
        {(ht === 'image' || ht === 'video' || ht === 'document') && (
          <div className="-mx-1 -mt-0.5 mb-1.5">
            <MediaBlock kind={ht} url={template.header_media_url ?? (m.media_url || null)} fileName={template.file_name ?? fileName} />
          </div>
        )}
        {ht === 'text' && template.header_text && (
          <p className="mb-1 font-semibold">
            <WaText text={template.header_text} highlightVars={props.highlightVars} />
          </p>
        )}
        <WaText text={template.body} highlightVars={props.highlightVars} />
        {template.footer ? (
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {template.footer}
            {meta}
          </p>
        ) : (
          meta
        )}
      </>
    );
  } else {
    switch (type) {
      case 'image':
      case 'video':
      case 'document':
        content = (
          <>
            {sender}
            <div className="-mx-1 -mt-0.5 mb-1">
              <MediaBlock kind={type} url={mediaUrl} fileName={fileName} mime={m.media_mime} />
            </div>
            {body && type !== 'document' ? <WaText text={body} highlightVars={props.highlightVars} /> : null}
            {body && type === 'document' && body !== fileName ? <WaText text={body} /> : null}
            {meta}
          </>
        );
        break;
      case 'audio':
        content = (
          <>
            {sender}
            <MediaBlock kind="audio" url={mediaUrl} fileName={fileName} />
            {meta}
          </>
        );
        break;
      case 'sticker':
        content = (
          <>
            {mediaUrl ? <img src={mediaUrl} alt="Sticker" loading="lazy" className="h-32 w-32 object-contain" /> : <span className="text-sm text-slate-500 dark:text-slate-400">Sticker</span>}
            <div className="text-right">{meta}</div>
          </>
        );
        break;
      case 'location': {
        const lat = interactive?.latitude;
        const lng = interactive?.longitude;
        const href = lat !== undefined && lng !== undefined ? `https://www.google.com/maps?q=${lat},${lng}` : null;
        content = (
          <>
            {sender}
            <div className="flex items-start gap-2.5 rounded-md bg-black/5 px-2.5 py-2 dark:bg-white/10">
              <MapPin size={20} className="mt-0.5 shrink-0 text-red-500" aria-hidden />
              <div className="min-w-0 text-sm">
                <p className="font-medium">{interactive?.name || body || 'Shared location'}</p>
                {interactive?.address && <p className="text-xs text-slate-500 dark:text-slate-400">{interactive.address}</p>}
                {href && (
                  <a href={href} target="_blank" rel="noopener noreferrer" className="text-xs text-[#027EB5] hover:underline dark:text-[#53BDEB]">
                    Open in Google Maps
                  </a>
                )}
              </div>
            </div>
            {meta}
          </>
        );
        break;
      }
      case 'contacts':
        content = (
          <>
            {sender}
            <div className="flex items-center gap-2.5 rounded-md bg-black/5 px-2.5 py-2 dark:bg-white/10">
              <ContactIcon size={20} className="shrink-0 text-slate-500" aria-hidden />
              <span className="text-sm">{body || 'Contact card'}</span>
            </div>
            {meta}
          </>
        );
        break;
      case 'button_reply':
      case 'list_reply':
        content = (
          <>
            <span className="mb-0.5 flex items-center gap-1 text-[11px] font-medium text-slate-500 dark:text-slate-400">
              <CornerDownLeft size={11} aria-hidden /> {type === 'button_reply' ? 'Tapped a button' : 'Picked from a list'}
            </span>
            <WaText text={body || interactive?.reply?.title || ''} />
            {meta}
          </>
        );
        break;
      case 'interactive_list':
      case 'interactive_button':
        content = (
          <>
            {sender}
            {interactive?.header && <p className="mb-1 font-semibold">{interactive.header}</p>}
            <WaText text={body} highlightVars={props.highlightVars} />
            {interactive?.footer ? (
              <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {interactive.footer}
                {meta}
              </p>
            ) : (
              meta
            )}
          </>
        );
        break;
      default:
        content = (
          <>
            {sender}
            <WaText text={body} highlightVars={props.highlightVars} />
            {meta}
          </>
        );
    }
  }

  return (
    <div className={clsx('flex w-full', out ? 'justify-end' : 'justify-start', props.className)}>
      <div className="max-w-[88%] sm:max-w-[78%]">
        <div
          className={clsx(
            'relative px-2.5 pb-1.5 pt-1.5 text-[14px] leading-snug',
            isSticker ? 'bg-transparent' : clsx(bubbleTone, 'rounded-lg shadow-sm'),
            tail && !isSticker && (out ? 'rounded-tr-none' : 'rounded-tl-none'),
          )}
        >
          {tail && !isSticker && (
            <span
              aria-hidden
              className={clsx('absolute top-0 h-0 w-0 border-t-[8px]', tailTone, out ? '-right-[7px] border-r-[8px] border-r-transparent' : '-left-[7px] border-l-[8px] border-l-transparent')}
            />
          )}
          {content}
        </div>
        {below}
        {status === 'failed' && (m.error || m.error_code) && (
          <p className={clsx('mt-0.5 text-[11px] text-red-600 dark:text-red-400', out ? 'text-right' : 'text-left')}>
            Not delivered: {m.error || m.error_code}
          </p>
        )}
      </div>
    </div>
  );
}

export default WaBubble;
