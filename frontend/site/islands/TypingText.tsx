import clsx from 'clsx';
import { useEffect, useRef, useState } from 'react';
import { useInView, usePageVisible, useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface TypingTextProps extends IslandBaseProps {
  phrases: string[];
  prefix?: string;
  suffix?: string;
  /** ms per typed character (default 65) */
  typeSpeed?: number;
  /** ms per deleted character (default 35) */
  deleteSpeed?: number;
  /** ms to hold a finished phrase (default 1800) */
  pause?: number;
  loop?: boolean;
  /** Show the animated three-dot typing indicator between phrases. */
  dots?: boolean;
  className?: string;
  /** Classes for the typed phrase (e.g. "text-gradient"). */
  textClass?: string;
}

/**
 * Rotating typed phrases with a blinking caret (and optional typing-dots indicator). The box is sized to the longest
 * phrase so surrounding text never reflows; screen readers get the full phrase list once.
 */
export default function TypingText({ phrases, prefix = '', suffix = '', typeSpeed = 65, deleteSpeed = 35, pause = 1800, loop = true, dots = false, className, textClass = 'text-accent-600' }: TypingTextProps) {
  const isStatic = useStaticMode();
  const visible = usePageVisible();
  const ref = useRef<HTMLSpanElement>(null);
  const inView = useInView(ref, { once: false, threshold: 0 });
  const [i, setI] = useState(0);
  const [text, setText] = useState(phrases[0] ?? '');
  const [phase, setPhase] = useState<'hold' | 'delete' | 'think' | 'type'>('hold');
  const longest = phrases.reduce((a, b) => (b.length > a.length ? b : a), '');

  useEffect(() => {
    if (isStatic || !visible || !inView || phrases.length < 2) return;
    const phrase = phrases[i];
    let t = 0;
    if (phase === 'hold') {
      if (!loop && i === phrases.length - 1) return;
      t = window.setTimeout(() => setPhase('delete'), pause);
    } else if (phase === 'delete') {
      if (text.length === 0) {
        t = window.setTimeout(() => {
          setI((n) => (n + 1) % phrases.length);
          setPhase(dots ? 'think' : 'type');
        }, 120);
      } else t = window.setTimeout(() => setText(text.slice(0, -1)), deleteSpeed);
    } else if (phase === 'think') {
      t = window.setTimeout(() => setPhase('type'), 900);
    } else {
      if (text === phrase) t = window.setTimeout(() => setPhase('hold'), 50);
      else t = window.setTimeout(() => setText(phrase.slice(0, text.length + 1)), typeSpeed + (Math.random() * 40 - 20));
    }
    return () => window.clearTimeout(t);
  }, [isStatic, visible, inView, phrases, i, text, phase, pause, typeSpeed, deleteSpeed, loop, dots]);

  return (
    <span ref={ref} className={clsx('typing', className)}>
      <span className="sr-only">
        {prefix}
        {phrases.join(', ')}
        {suffix}
      </span>
      <span aria-hidden="true">
        {prefix}
        <span className="typing-box">
          <span className="typing-sizer">{longest}</span>
          <span className={clsx('typing-text', textClass)}>
            {phase === 'think' ? (
              <span className="typing-dots">
                <span />
                <span />
                <span />
              </span>
            ) : (
              text
            )}
            {!isStatic && phase !== 'think' && <span className="typing-caret" />}
          </span>
        </span>
        {suffix}
      </span>
    </span>
  );
}
