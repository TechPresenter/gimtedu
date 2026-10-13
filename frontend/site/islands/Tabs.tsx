import clsx from 'clsx';
import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react';
import { Icon } from '../lib/Icon';
import type { IslandBaseProps } from '../lib/types';

export interface TabsProps extends IslandBaseProps {
  /** Tab buttons, in the same order as the `panels` slot children. */
  tabs: { key: string; label: string; icon?: string }[];
  variant?: 'pills' | 'underline';
  initial?: string;
  /** Sync the active tab with location.hash (#tab-key). */
  syncHash?: boolean;
  /** Centre the tab list. */
  center?: boolean;
}

/**
 * Accessible tabs (WAI-ARIA tabs pattern, automatic activation, roving tabindex) with a sliding indicator and
 * fading panels. Panel content is the server-rendered HTML of `<div data-slot="panels">` children.
 */
export default function Tabs({ tabs, variant = 'pills', initial, syncHash = false, center = false, slots }: TabsProps) {
  const uid = useId();
  const panels = slots?.panels ?? [];
  const fromHash = syncHash ? window.location.hash.slice(1) : '';
  const [active, setActive] = useState(() => {
    const keys = tabs.map((t) => t.key);
    return keys.includes(fromHash) ? fromHash : initial && keys.includes(initial) ? initial : keys[0];
  });
  const listRef = useRef<HTMLDivElement>(null);
  const btns = useRef<Record<string, HTMLButtonElement | null>>({});
  const [ind, setInd] = useState({ x: 0, w: 0 });

  const measure = () => {
    const b = btns.current[active];
    if (b) setInd({ x: b.offsetLeft, w: b.offsetWidth });
  };
  useLayoutEffect(measure, [active]);
  useEffect(() => {
    const ro = 'ResizeObserver' in window && listRef.current ? new ResizeObserver(measure) : null;
    if (ro && listRef.current) ro.observe(listRef.current);
    return () => ro?.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active]);

  const select = (key: string, focus = false) => {
    setActive(key);
    if (focus) btns.current[key]?.focus();
    if (syncHash) window.history.replaceState(null, '', `#${key}`);
    // panels may contain counters / reveal / lazy images that were hidden
    requestAnimationFrame(() => {
      const runtime = window.__gimtSite;
      const panel = document.getElementById(`${uid}-panel-${key}`);
      if (runtime && runtime !== 'fallback' && panel) runtime.enhance(panel);
    });
  };

  const onKey = (e: KeyboardEvent) => {
    const i = tabs.findIndex((t) => t.key === active);
    let n = -1;
    if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
    else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    if (n >= 0) {
      e.preventDefault();
      select(tabs[n].key, true);
    }
  };

  return (
    <div className={clsx('tabs-island', `tabs-${variant}`)}>
      <div className={clsx('tabs-scroll scrollbar-none', center && 'justify-center')}>
        <div ref={listRef} role="tablist" aria-orientation="horizontal" className="tabs-list" onKeyDown={onKey}>
          <span className="tabs-indicator" aria-hidden="true" style={{ transform: `translateX(${ind.x}px)`, width: ind.w } as CSSProperties} />
          {tabs.map((t) => {
            const on = t.key === active;
            return (
              <button
                key={t.key}
                ref={(el) => (btns.current[t.key] = el)}
                id={`${uid}-tab-${t.key}`}
                role="tab"
                type="button"
                aria-selected={on}
                aria-controls={`${uid}-panel-${t.key}`}
                tabIndex={on ? 0 : -1}
                className={clsx('tabs-tab', on && 'is-active')}
                onClick={() => select(t.key)}
              >
                {t.icon && <Icon name={t.icon} className="h-4 w-4" />}
                {t.label}
              </button>
            );
          })}
        </div>
      </div>
      {tabs.map((t, i) => {
        const on = t.key === active;
        return (
          <div
            key={t.key}
            id={`${uid}-panel-${t.key}`}
            role="tabpanel"
            aria-labelledby={`${uid}-tab-${t.key}`}
            tabIndex={0}
            hidden={!on}
            className={clsx('tabs-panel', on && 'is-active')}
            dangerouslySetInnerHTML={{ __html: panels[i]?.html ?? '' }}
          />
        );
      })}
    </div>
  );
}
