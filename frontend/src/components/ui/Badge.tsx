import type { ReactNode } from 'react';
import clsx from 'clsx';
import { badgeClass, statusColor, type BadgeColor } from '@/lib/status';
import { labelize } from '@/lib/format';

export function Badge({ color = 'slate', children, dot, className }: { color?: BadgeColor; children: ReactNode; dot?: boolean; className?: string }) {
  return (
    <span className={clsx('badge', badgeClass[color], className)}>
      {dot && <span className="h-1.5 w-1.5 rounded-full bg-current opacity-80" aria-hidden />}
      {children}
    </span>
  );
}

/** Badge coloured from the status word. Upper-case result codes (PASS/FAIL) are shown as-is. */
export function StatusBadge({ status, label, colors, dot = true }: { status: string | null | undefined; label?: string; colors?: Record<string, BadgeColor>; dot?: boolean }) {
  if (status === null || status === undefined || status === '') return <span className="text-slate-400">—</span>;
  const s = String(status);
  const color = colors?.[s] ?? statusColor(s);
  const text = label ?? (s === s.toUpperCase() && s.length > 1 ? s : labelize(s));
  return (
    <Badge color={color} dot={dot}>
      {text}
    </Badge>
  );
}
