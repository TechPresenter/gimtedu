import { Component, type ComponentType, type ErrorInfo, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { createRoot, type Root } from 'react-dom/client';
import type { SlotItem } from '../lib/types';

/**
 * React side of the island runtime (loaded on demand together with the first island of a page).
 * Replaces the server-rendered fallback with the component without layout shift: the host keeps its current
 * height as min-height until the component has rendered at least as tall (or 1.5s passed).
 */

interface BoundaryProps {
  name: string;
  onError: () => void;
  children: ReactNode;
}

class IslandBoundary extends Component<BoundaryProps, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error(`[site] Island "${this.props.name}" crashed — restoring the server-rendered fallback.`, error, info.componentStack);
    this.props.onError();
  }

  render() {
    return this.state.failed ? null : this.props.children;
  }
}

const roots = new WeakMap<HTMLElement, Root>();

export function mountIsland(
  host: HTMLElement,
  name: string,
  Island: ComponentType<Record<string, unknown>>,
  props: Record<string, unknown>,
  slots: Record<string, SlotItem[]>,
): void {
  if (roots.has(host)) return;
  const fallbackHtml = host.innerHTML;
  const lockedHeight = host.offsetHeight;
  if (lockedHeight > 0) host.style.minHeight = `${lockedHeight}px`;

  const root = createRoot(host);
  roots.set(host, root);

  const restore = () => {
    window.setTimeout(() => {
      root.unmount();
      roots.delete(host);
      host.innerHTML = fallbackHtml;
      host.style.minHeight = '';
      host.setAttribute('data-island-state', 'error');
    }, 0);
  };

  flushSync(() => {
    root.render(
      <IslandBoundary name={name} onError={restore}>
        <Island {...props} slots={slots} />
      </IslandBoundary>,
    );
  });
  host.setAttribute('data-island-state', 'mounted');

  // re-run the vanilla enhancements for HTML injected from slots (reveal, lazy images, counters …)
  const runtime = window.__gimtSite;
  if (runtime && runtime !== 'fallback') runtime.enhance(host);

  // release the height lock once the island is at least as tall as the fallback was
  if (lockedHeight > 0) {
    // content height = extent of the rendered children (the host's own height is held by min-height)
    const contentHeight = () => {
      const top = host.getBoundingClientRect().top;
      let bottom = top;
      Array.from(host.children).forEach((c) => (bottom = Math.max(bottom, c.getBoundingClientRect().bottom)));
      return bottom - top;
    };
    let ro: ResizeObserver | null = null;
    let timer = 0;
    const release = () => {
      host.style.minHeight = '';
      ro?.disconnect();
      window.clearTimeout(timer);
    };
    const check = () => contentHeight() >= lockedHeight - 1 && release();
    if ('ResizeObserver' in window) {
      ro = new ResizeObserver(check);
      Array.from(host.children).forEach((c) => ro!.observe(c));
    }
    timer = window.setTimeout(release, 1500);
    requestAnimationFrame(check);
  }
}
