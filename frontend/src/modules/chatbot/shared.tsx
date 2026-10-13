import type { ReactNode } from 'react';
import { Badge } from '@/components/ui';
import { appUrl } from '@/lib/config';
import { PROVIDER_COLORS, PROVIDER_LABELS } from './types';

export function ProviderBadge({ provider }: { provider: string | null | undefined }) {
  if (!provider) return <span className="text-slate-400">—</span>;
  return <Badge color={PROVIDER_COLORS[provider] ?? 'slate'}>{PROVIDER_LABELS[provider] ?? provider}</Badge>;
}

/** Bold, links and "- " bullets of a chatbot reply (admin preview / transcript), rendered as React nodes. */
export function ReplyText({ text }: { text: string }) {
  const inline = (line: string, key: string): ReactNode[] => {
    const out: ReactNode[] = [];
    const re = /(\*\*([^*\n]+?)\*\*)|(\[([^\]\n]+)\]\(([^)\s]+)\))/g;
    let last = 0;
    let m: RegExpExecArray | null;
    let i = 0;
    while ((m = re.exec(line))) {
      if (m.index > last) out.push(line.slice(last, m.index));
      if (m[1]) out.push(<strong key={`${key}-${i++}`}>{m[2]}</strong>);
      else {
        const url = m[5];
        const ok = /^\/(?!\/)/.test(url) || /^https?:\/\//i.test(url);
        out.push(
          ok ? (
            <a key={`${key}-${i++}`} href={url.startsWith('/') ? appUrl(url) : url} target="_blank" rel="noopener noreferrer" className="font-medium text-brand-700 underline underline-offset-2 dark:text-brand-300">
              {m[4]}
            </a>
          ) : (
            m[4]
          ),
        );
      }
      last = m.index + m[0].length;
    }
    if (last < line.length) out.push(line.slice(last));
    return out;
  };
  const lines = text.split('\n');
  return (
    <div className="space-y-1 [overflow-wrap:anywhere]">
      {lines.map((l, i) => {
        const b = /^\s*[-*•]\s+(.*)$/.exec(l);
        if (b) {
          return (
            <div key={i} className="flex gap-2 pl-1">
              <span className="mt-[0.55em] h-1.5 w-1.5 shrink-0 rounded-full bg-accent-500" aria-hidden />
              <span>{inline(b[1], `l${i}`)}</span>
            </div>
          );
        }
        return l.trim() === '' ? <div key={i} className="h-1" /> : <p key={i}>{inline(l, `l${i}`)}</p>;
      })}
    </div>
  );
}

export const msLabel = (ms: number | null | undefined) => (ms == null ? '—' : ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`);
