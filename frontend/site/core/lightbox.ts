import { $$ } from './dom';
import { closeLayer, openLayer } from './overlays';

/**
 * Vanilla lightbox for images and videos (no React needed):
 *   <a href="/assets/images/site/campus-main.jpg" data-lightbox="campus" data-caption="Main campus">…thumb…</a>
 *   <a href="https://www.youtube.com/watch?v=xxxx" data-lightbox>Watch the campus tour</a>
 *   <button data-video="https://www.youtube.com/embed/xxxx">  (legacy attribute, still supported)
 * Items sharing a data-lightbox value form a gallery (arrows, ← → keys, swipe). Esc / backdrop closes.
 */

/** Convert YouTube/Vimeo page URLs into privacy-friendly embed URLs; returns null for unsupported hosts. */
export function toEmbedUrl(url: string): string | null {
  try {
    const u = new URL(url, window.location.href);
    const host = u.hostname.replace(/^www\./, '');
    let id = '';
    if (host === 'youtu.be') id = u.pathname.slice(1);
    else if (/(^|\.)youtube(-nocookie)?\.com$/.test(host)) {
      if (u.pathname.startsWith('/embed/')) id = u.pathname.split('/')[2] ?? '';
      else if (u.pathname.startsWith('/shorts/')) id = u.pathname.split('/')[2] ?? '';
      else id = u.searchParams.get('v') ?? '';
    }
    if (id && /^[\w-]{6,20}$/.test(id)) return `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1`;
    if (host === 'vimeo.com' && /^\/\d+/.test(u.pathname)) return `https://player.vimeo.com/video/${u.pathname.split('/')[1]}?autoplay=1`;
    if (host === 'player.vimeo.com') return `${u.origin}${u.pathname}?autoplay=1`;
  } catch {
    /* ignore */
  }
  return null;
}

export const isVideoFile = (url: string) => /\.(mp4|webm|ogg)(\?|#|$)/i.test(url);
export const isImageFile = (url: string) => /\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test(url);

interface Item {
  src: string;
  caption: string;
}

let box: HTMLElement | null = null;

function build(): HTMLElement {
  const el = document.createElement('div');
  el.className = 'lightbox hidden';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-modal', 'true');
  el.setAttribute('aria-label', 'Media viewer');
  el.innerHTML = `
    <div class="lightbox-backdrop" data-lb-close></div>
    <div class="lightbox-stage" data-lb-stage></div>
    <p class="lightbox-caption" data-lb-caption aria-live="polite"></p>
    <button type="button" class="lightbox-btn lightbox-close" data-lb-close aria-label="Close">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <button type="button" class="lightbox-btn lightbox-prev" data-lb-prev aria-label="Previous">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg></button>
    <button type="button" class="lightbox-btn lightbox-next" data-lb-next aria-label="Next">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m9 18 6-6-6-6"/></svg></button>`;
  document.body.appendChild(el);
  return el;
}

function render(items: Item[], index: number) {
  if (!box) return;
  const stage = box.querySelector<HTMLElement>('[data-lb-stage]')!;
  const caption = box.querySelector<HTMLElement>('[data-lb-caption]')!;
  const item = items[index];
  stage.textContent = '';
  const embed = toEmbedUrl(item.src);
  let node: HTMLElement;
  if (embed) {
    node = document.createElement('div');
    node.className = 'lightbox-video';
    const iframe = document.createElement('iframe');
    iframe.src = embed;
    iframe.title = item.caption || 'Video';
    iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    iframe.allowFullscreen = true;
    node.appendChild(iframe);
  } else if (isVideoFile(item.src)) {
    node = document.createElement('div');
    node.className = 'lightbox-video';
    const v = document.createElement('video');
    v.src = item.src;
    v.controls = true;
    v.autoplay = true;
    v.playsInline = true;
    node.appendChild(v);
  } else {
    const img = document.createElement('img');
    img.src = item.src;
    img.alt = item.caption;
    img.className = 'lightbox-img';
    node = img;
  }
  node.classList.add('lightbox-media');
  stage.appendChild(node);
  caption.textContent = items.length > 1 ? `${item.caption}${item.caption ? ' · ' : ''}${index + 1} / ${items.length}` : item.caption;
  box.classList.toggle('is-gallery', items.length > 1);
}

export function openLightbox(items: Item[], start = 0): void {
  if (!items.length) return;
  box ??= build();
  let index = start;
  render(items, index);
  const go = (d: number) => {
    index = (index + d + items.length) % items.length;
    render(items, index);
  };
  const onKey = (e: KeyboardEvent) => {
    if (items.length < 2) return;
    if (e.key === 'ArrowRight') go(1);
    if (e.key === 'ArrowLeft') go(-1);
  };
  let sx = 0;
  const onDown = (e: PointerEvent) => (sx = e.clientX);
  const onUp = (e: PointerEvent) => {
    const dx = e.clientX - sx;
    if (items.length > 1 && Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
  };
  const onClick = (e: MouseEvent) => {
    const t = e.target as Element;
    if (t.closest('[data-lb-close]')) closeLayer(box!);
    else if (t.closest('[data-lb-prev]')) go(-1);
    else if (t.closest('[data-lb-next]')) go(1);
  };
  const el = box;
  el.addEventListener('click', onClick);
  el.addEventListener('pointerdown', onDown);
  el.addEventListener('pointerup', onUp);
  document.addEventListener('keydown', onKey);
  openLayer(el, {
    focus: el.querySelector<HTMLElement>('.lightbox-close'),
    onClose: () => {
      el.removeEventListener('click', onClick);
      el.removeEventListener('pointerdown', onDown);
      el.removeEventListener('pointerup', onUp);
      document.removeEventListener('keydown', onKey);
      // stop playback
      window.setTimeout(() => {
        const stage = el.querySelector('[data-lb-stage]');
        if (stage) stage.textContent = '';
      }, 300);
    },
  });
}

export function initLightbox(): void {
  document.addEventListener('click', (e) => {
    const trigger = (e.target as Element).closest<HTMLElement>('[data-lightbox], [data-video]');
    if (!trigger || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey) return;
    const src = trigger.getAttribute('data-video') || trigger.getAttribute('data-src') || trigger.getAttribute('href') || '';
    if (!src || !(toEmbedUrl(src) || isVideoFile(src) || isImageFile(src))) return;
    e.preventDefault();
    const group = trigger.getAttribute('data-lightbox');
    const members = group ? $$(`[data-lightbox="${CSS.escape(group)}"]`) : [trigger];
    const items = members.map((m) => ({
      src: m.getAttribute('data-video') || m.getAttribute('data-src') || m.getAttribute('href') || '',
      caption: m.getAttribute('data-caption') || m.querySelector('img')?.alt || m.getAttribute('aria-label') || '',
    }));
    openLightbox(items, Math.max(0, members.indexOf(trigger)));
  });
}
