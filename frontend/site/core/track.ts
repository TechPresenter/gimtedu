/**
 * Analytics events for the website bundle (docs/WEBSITE.md §17 "Analytics events").
 *
 * Every event goes through window.gimtTrack(), defined by the consent layer's <head> script
 * (includes/partials/consent.php → consent_head()). It pushes {event, …params} to window.dataLayer only when a tag ID
 * (GA4 / GTM / Meta Pixel / Clarity) is configured, and the consent layer forwards it to the loaded tags under the
 * visitor's consent. Without that script (admin preview, no tags) this is a no-op.
 *
 * Never pass personal data: no names, phone numbers, e-mail addresses, message text or free-text answers — only
 * short identifiers such as a form key, a program code / slug or a location name. Empty / object values are dropped.
 */
export type TrackParams = Record<string, string | number | boolean | null | undefined>;

type TrackFn = (event: string, params?: TrackParams) => void;

export function trackEvent(event: string, params: TrackParams = {}): void {
  try {
    const fn = (window as unknown as { gimtTrack?: TrackFn }).gimtTrack;
    if (typeof fn === 'function') fn(event, params);
  } catch {
    /* analytics must never break the page */
  }
}
