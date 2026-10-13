import type { ReactNode } from 'react';

/**
 * Safe "markdown-lite" for chat bubbles → React nodes (never innerHTML).
 * Supports paragraphs, line breaks, "- " / "• " / "1. " lists, **bold** and [label](url) links.
 * Links: only root-relative paths ("/programs/bba", prefixed with the install base path) and http(s) URLs;
 * external links open in a new tab with rel="noopener noreferrer". Anything else renders as plain text.
 * Incomplete syntax (while a reply is still streaming) simply shows as text until it is closed.
 */

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

const INLINE = /(\*\*([^*\n]+?)\*\*)|(\[([^\]\n]{1,200})\]\(([^)\s]{1,600})\))/g;

function inline(text: string, basePath: string, keyBase: string, linkClass: string): ReactNode[] {
  const out: ReactNode[] = [];
  let last = 0;
  let m: RegExpExecArray | null;
  let i = 0;
  INLINE.lastIndex = 0;
  while ((m = INLINE.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const key = `${keyBase}-${i++}`;
    if (m[1]) {
      out.push(<strong key={key} className="font-semibold">{m[2]}</strong>);
    } else {
      const link = safeHref(m[5], basePath);
      out.push(
        link ? (
          <a key={key} href={link.href} className={linkClass} {...(link.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
            {m[4]}
            {link.external && <span className="sr-only"> (opens in a new tab)</span>}
          </a>
        ) : (
          m[4]
        ),
      );
    }
    last = m.index + m[0].length;
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
    .replace(/\*\*/g, '')
    .replace(/^\s*[-*•]\s+/gm, '• ');
}
