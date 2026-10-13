import type { ReactNode } from 'react';

/**
 * React twin of scribble() / scribble_text() in includes/components.php: a hand-drawn orange ellipse around a word.
 * The draw-in animation is started by core/motion.ts initScribbles() (runs on island content after mount).
 */
export function Scribble({ children, tone = 'orange' }: { children: ReactNode; tone?: 'orange' | 'light' | 'ink' }) {
  return (
    <span className={tone === 'orange' ? 'scribble' : `scribble scribble-${tone}`} data-draw="">
      {children}
      <svg className="scribble-svg" viewBox="0 0 200 70" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path pathLength={100} d="M176 10C134 1 52 3 22 19C1 31 5 53 39 62C83 73 161 67 187 49C205 36 196 16 158 9C138 5 116 4 97 6" />
      </svg>
    </span>
  );
}

/** Text with one (case-insensitive) word/phrase circled; returns the plain text when `word` is empty or not found. */
export function scribbleText(text: string, word?: string | null, tone: 'orange' | 'light' | 'ink' = 'orange'): ReactNode {
  if (!word) return text;
  const i = text.toLowerCase().indexOf(word.toLowerCase());
  if (i < 0) return text;
  return (
    <>
      {text.slice(0, i)}
      <Scribble tone={tone}>{text.slice(i, i + word.length)}</Scribble>
      {text.slice(i + word.length)}
    </>
  );
}
