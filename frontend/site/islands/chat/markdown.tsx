import type { ReactNode } from 'react';
import { WA_PILL, WhatsAppBadge } from './icons';

/**
 * Safe "markdown-lite" for chat bubbles → React nodes (never innerHTML).
 * Supports paragraphs, line breaks, "- " / "• " / "1. " lists, **bold**, [label](url) links and bare http(s) URLs.
 * Links: only root-relative paths ("/programs/bba", prefixed with the install base path) and http(s) URLs;
 * external links open in a new tab with rel="noopener noreferrer". Anything else renders as plain text.
 * WhatsApp links (wa.me / api.whatsapp.com) render as the green "Chat on WhatsApp" pill; a bare URL never shows as
 * a long raw address — it gets a short label ("View course details", "Apply online", the page name…).
 * Incomplete syntax (while a reply is still streaming) simply shows as text until it is closed.
 */

/** True for a WhatsApp chat link. */
export function isWhatsAppUrl(href: string): boolean {
  try {
    const h = new URL(href, window.location.origin).hostname.toLowerCase();
    return h === 'wa.me' || h === 'api.whatsapp.com' || h === 'chat.whatsapp.com';
  } catch {
    return false;
  }
}

/** Short, readable label for a bare URL. */
export function linkLabel(href: string, basePath: string): string {
  try {
    const u = new URL(href, window.location.origin);
    if (isWhatsAppUrl(u.href)) return 'Chat on WhatsApp';
    if (u.host !== window.location.host) return u.hostname.replace(/^www\./, '');
    const base = basePath.replace(/\/$/, '');
    const path = (base && u.pathname.startsWith(base) ? u.pathname.slice(base.length) : u.pathname) || '/';
    if (/^\/programs\/[^/]+/.test(path)) return 'View course details';
    if (path.startsWith('/apply')) return 'Apply online';
    if (path.startsWith('/contact')) return 'Contact us';
    if (path.startsWith('/certifications')) return 'Certificate courses';
    if (path.startsWith('/programs')) return 'All programs';
    if (path === '/') return 'Visit our website';
    const seg = decodeURIComponent(path.split('/').filter(Boolean).pop() ?? '').replace(/[-_]+/g, ' ').trim();
    return seg ? seg.charAt(0).toUpperCase() + seg.slice(1) : 'Open page';
  } catch {
    return 'Open link';
  }
}

export function safeHref(url: string, basePath: string): { href: string; external: boolean } | null {
  const u = url.trim();
  if (/^\/(?!\/)[^\s<>"'`\\]*$/.test(u)) {
    const base = basePath.replace(/\/$/, '');
    return { href: base && !u.startsWith(base + '/') ? base + u : u, external: false };
  }
  if (/^https?:\/\/[^\s<>"'`]+$/i.test(u)) {
    try {
      const parsed = new URL(u);
      return { href: parsed.href, external: parsed.host !== window.location.host };
    } catch {
      return null;
    }
  }
  return null;
}

// **bold** | [label](url) | bare http(s) URL
const INLINE = /(\*\*([^*\n]+?)\*\*)|(\[([^\]\n]{1,200})\]\(([^)\s]{1,600})\))|(https?:\/\/[^\s<>"'`]{3,600})/g;

function linkNode(key: string, href: string, label: string, linkClass: string, external: boolean): ReactNode {
  const ext = external ? { target: '_blank', rel: 'noopener noreferrer' } : {};
  if (isWhatsAppUrl(href)) {
    return (
      <a key={key} href={href} className={`${WA_PILL} my-0.5 align-middle no-underline`} target="_blank" rel="noopener noreferrer">
        <WhatsAppBadge size={20} />
        {label}
        <span className="sr-only"> (opens WhatsApp)</span>
      </a>
    );
  }
  return (
    <a key={key} href={href} className={linkClass} {...ext}>
      {label}
      {external && <span className="sr-only"> (opens in a new tab)</span>}
    </a>
  );
}

function inline(text: string, basePath: string, keyBase: string, linkClass: string): ReactNode[] {
  const out: ReactNode[] = [];
  let last = 0;
  let m: RegExpExecArray | null;
  let i = 0;
  INLINE.lastIndex = 0;
  while ((m = INLINE.exec(text))) {
    let end = m.index + m[0].length;
    if (m.index > last) out.push(text.slice(last, m.index));
    const key = `${keyBase}-${i++}`;
    if (m[1]) {
      out.push(<strong key={key} className="font-semibold">{m[2]}</strong>);
    } else if (m[3]) {
      const link = safeHref(m[5], basePath);
      out.push(link ? linkNode(key, link.href, m[4], linkClass, link.external) : m[4]);
    } else {
      // bare URL: leave sentence punctuation after it as text ("…program." / "(see https://…)")
      let raw = m[6];
      const trail = /[.,;:!?)\]'"]+$/.exec(raw);
      if (trail && !(trail[0].startsWith(')') && raw.includes('('))) {
        raw = raw.slice(0, -trail[0].length);
        end -= trail[0].length;
      }
      const link = safeHref(raw, basePath);
      out.push(link ? linkNode(key, link.href, linkLabel(link.href, basePath), linkClass, link.external) : raw);
    }
    last = end;
    INLINE.lastIndex = end;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

export function Markdown({ text, basePath, linkClass }: { text: string; basePath: string; linkClass: string }) {
  const blocks: ReactNode[] = [];
  const lines = text.replace(/\r\n/g, '\n').split('\n');
  let para: string[] = [];
  let list: { ordered: boolean; items: string[] } | null = null;
  const flushPara = () => {
    if (!para.length) return;
    const k = `p${blocks.length}`;
    blocks.push(
      <p key={k}>
        {para.map((l, j) => (
          <span key={j}>
            {j > 0 && <br />}
            {inline(l, basePath, `${k}-${j}`, linkClass)}
          </span>
        ))}
      </p>,
    );
    para = [];
  };
  const flushList = () => {
    if (!list) return;
    const k = `l${blocks.length}`;
    const items = list.items.map((it, j) => <li key={j}>{inline(it, basePath, `${k}-${j}`, linkClass)}</li>);
    blocks.push(
      list.ordered ? (
        <ol key={k} className="list-decimal space-y-1 pl-5">{items}</ol>
      ) : (
        <ul key={k} className="list-disc space-y-1 pl-5 marker:text-accent-500">{items}</ul>
      ),
    );
    list = null;
  };
  lines.forEach((raw) => {
    const line = raw.trimEnd();
    const bullet = /^\s*(?:[-*•])\s+(.*)$/.exec(line);
    const numbered = /^\s*\d{1,2}[.)]\s+(.*)$/.exec(line);
    if (bullet || numbered) {
      flushPara();
      const ordered = !bullet;
      if (list && list.ordered !== ordered) flushList();
      list ??= { ordered, items: [] };
      list.items.push((bullet ?? numbered)![1]);
    } else if (line.trim() === '') {
      flushPara();
      flushList();
    } else {
      flushList();
      para.push(line);
    }
  });
  flushPara();
  flushList();
  return <div className="space-y-2 [overflow-wrap:anywhere]">{blocks}</div>;
}

/** Plain text of a markdown reply (for the screen-reader announcer). */
export function plainText(text: string): string {
  return text
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replace(/https?:\/\/[^\s<>"'`]+/g, (u) => (isWhatsAppUrl(u) ? 'Chat on WhatsApp' : 'link'))
    .replace(/\*\*/g, '')
    .replace(/^\s*[-*•]\s+/gm, '• ');
}
