/** Shared island types. */

/** One child element of a `[data-slot]` container in the server-rendered fallback. */
export interface SlotItem {
  /** outerHTML of the element (server-rendered, already escaped by PHP). */
  html: string;
  /** The element's data-* attributes (camelCased keys, e.g. data-filter-item -> filterItem). */
  data: Record<string, string>;
}

/** Props every island receives in addition to its own (decoded from data-props). */
export interface IslandBaseProps {
  slots?: Record<string, SlotItem[]>;
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
