/** Shared island types. */

/** One child element of a `[data-slot]` container in the server-rendered fallback. */
export interface SlotItem {
  /** outerHTML of the element (server-rendered, already escaped by PHP). */
  html: string;
  /** The element's data-* attributes (camelCased keys, e.g. data-filter-item -> filterItem). */
  data: Record<string, string>;
  /**
   * The server-rendered element itself (detached from the page once the island renders). An island may move it into
   * its own markup instead of re-parsing `html` — no new DOM, images keep their loaded state (ProgramExplorer).
   */
  node?: HTMLElement;
}

/** What the visitor already did in the server-rendered fallback before the island mounted (core/islands.ts). */
export interface FallbackState {
  /** data-filter key of the pressed [data-filter] button (vanilla filtering ran on the fallback). */
  filter?: string;
  /** Value typed into the fallback's search field. */
  query?: string;
  /** The fallback's search field had the focus. */
  searchFocused?: boolean;
}

/** Props every island receives in addition to its own (decoded from data-props). */
export interface IslandBaseProps {
  slots?: Record<string, SlotItem[]>;
  fallbackState?: FallbackState;
}

export interface LinkProp {
  label: string;
  url: string;
  icon?: string;
}

/** Global handle exposed by the site bundle (window.__gimtSite). */
export interface SiteRuntime {
  version: string;
  /** Re-run the vanilla enhancements (reveal, counters, lazy images, accordions, forms…) on a subtree. */
  enhance: (root: ParentNode) => void;
  /** Mount any not-yet-mounted islands inside root (e.g. after injecting HTML). */
  mountIslands: (root?: ParentNode) => void;
  openLightbox: (items: { src: string; caption: string }[], start?: number) => void;
}

declare global {
  interface Window {
    __gimtSite?: SiteRuntime | 'fallback';
  }
}
