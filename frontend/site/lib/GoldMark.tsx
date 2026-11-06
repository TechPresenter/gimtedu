import type { CSSProperties, ReactNode } from 'react';
import { Icon } from './Icon';

/**
 * React twin of gold_highlight() / gold_text() in includes/components.php — the yellow-gold highlight (Design v3
 * addition, client request 2026-10-13). Renders the same markup as the PHP fallback, so islands never shift layout:
 *   marker  <span class="hl-gold" data-draw><span class="hl-gold-w">Free</span> <span class="hl-gold-w" style="--w:1">Counselling</span></span>
 *   pill    <span class="pill-gold">Most Popular</span>
 *   soft    <span class="hl-gold-soft">up to 40%</span>   (gold text for navy / dark bands)
 * The marker draw-in is started by core/motion.ts initScribbles() (runs on island content after mount; static in
 * reduced motion / automated browsers). Use sparingly: max ~1–2 per screen, never on body text, never with a scribble.
 */
export type GoldVariant = 'marker' | 'pill' | 'soft';

export function GoldMark({
  text,
  variant = 'marker',
  draw = true,
  tall = false,
  icon,
  className,
}: {
  text: string;
  variant?: GoldVariant;
  draw?: boolean;
  tall?: boolean;
  /** pill only: lucide icon name before the text */
  icon?: string;
  className?: string;
}) {
  const value = text.trim();
  if (!value) return null;
  const extra = className ? ` ${className}` : '';
  if (variant === 'pill') {
    return (
      <span className={`pill-gold${extra}`}>
        {icon && <Icon name={icon} className="" />}
        {value}
      </span>
    );
  }
  if (variant === 'soft') return <span className={`hl-gold-soft${extra}`}>{value}</span>;
  const words = value.split(/\s+/);
  const nodes: ReactNode[] = [];
  words.forEach((w, i) => {
    if (i) nodes.push(' ');
    nodes.push(
      <span key={i} className="hl-gold-w" style={i ? ({ '--w': i } as CSSProperties) : undefined}>
        {w}
      </span>,
    );
  });
  return (
    <span className={`hl-gold${tall ? ' hl-gold-tall' : ''}${extra}`} {...(draw ? { 'data-draw': '' } : {})}>
      {nodes}
    </span>
  );
}

/**
 * Text with one (case-insensitive) phrase gold-highlighted; `phrase` undefined = the part written in [square brackets]
 * (brackets removed). Returns the plain text when nothing matches — same rules as gold_text() in PHP.
 */
export function goldText(text: string, phrase?: string | null, variant: GoldVariant = 'marker'): ReactNode {
  let value = text;
  let mark = phrase;
  if (mark === undefined) {
    const m = /\[([^[\]]+)\]/.exec(value);
    if (!m) return value;
    value = value.replace(m[0], m[1]);
    mark = m[1];
  }
  value = value.replace(/[[\]]/g, '');
  if (!mark || !mark.trim()) return value;
  const i = value.toLowerCase().indexOf(mark.toLowerCase());
  if (i < 0) return value;
  return (
    <>
      {value.slice(0, i)}
      <GoldMark text={value.slice(i, i + mark.length)} variant={variant} />
      {value.slice(i + mark.length)}
    </>
  );
}
