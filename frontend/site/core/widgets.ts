import { $, $$, bindOnce, csrfToken, staticMode, within } from './dom';

/* ------------------------------------------------------------------ Accordion (details/summary) */

/**
 * Progressive enhancement of native <details>: smooth height animation + optional single-open groups.
 *   <div data-accordion="single"> <details class="acc-item"><summary>Q</summary><div>A</div></details> … </div>
 *   <details data-animate>…</details>   (stand-alone)
 * Without JS the native details element still works.
 */
const animations = new WeakMap<HTMLDetailsElement, Animation>();

export function setDetails(d: HTMLDetailsElement, open: boolean): void {
  if (d.open === open && !animations.has(d)) return;
  const summary = d.querySelector('summary');
  if (staticMode() || !summary || !d.animate) {
    d.open = open;
    d.classList.toggle('is-open', open);
    return;
  }
  animations.get(d)?.cancel();
  const startH = d.offsetHeight;
  d.open = true;
  const openH = d.offsetHeight;
  d.open = false;
  const closedH = d.offsetHeight;
  d.open = true;
  d.style.overflow = 'hidden';
  d.classList.toggle('is-open', open);
  const anim = d.animate({ height: [`${startH}px`, `${open ? openH : closedH}px`] }, { duration: open ? 320 : 260, easing: 'cubic-bezier(.2,.8,.2,1)' });
  animations.set(d, anim);
  anim.onfinish = () => {
    animations.delete(d);
    d.open = open;
    d.style.overflow = '';
  };
  anim.oncancel = () => animations.delete(d);
}

export function initAccordions(root: ParentNode = document): void {
  within<HTMLDetailsElement>('[data-accordion] details, details[data-animate]', root).forEach((d) => {
    if (!bindOnce(d, 'accordion')) return;
    d.classList.add('acc-js');
    d.classList.toggle('is-open', d.open);
    const summary = d.querySelector('summary');
    summary?.addEventListener('click', (e) => {
      e.preventDefault();
      const opening = !d.classList.contains('is-open');
      const group = d.closest('[data-accordion]');
      if (opening && group?.getAttribute('data-accordion') === 'single') {
        $$<HTMLDetailsElement>('details', group).forEach((o) => o !== d && o.closest('[data-accordion]') === group && setDetails(o, false));
      }
      setDetails(d, opening);
    });
  });
}

/* ------------------------------------------------------------------ Filter chips */

/**
 * <button data-filter="ug" data-filter-group="programs" class="chip">UG</button>  (value "all" shows everything)
 * <article data-filter-item="ug management" data-filter-group="programs">…</article>
 * Optional: [data-filter-count="programs"] shows the visible count, [data-filter-empty="programs"] the empty state.
 */
export function initFilters(root: ParentNode = document): void {
  within('[data-filter]', root).forEach((btn) => {
    if (!bindOnce(btn, 'filter')) return;
    btn.addEventListener('click', () => {
      const group = btn.getAttribute('data-filter-group') || 'default';
      const value = btn.getAttribute('data-filter') || 'all';
      $$(`[data-filter][data-filter-group="${group}"]`).forEach((b) => {
        const on = b === btn;
        b.classList.toggle('chip-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      let shown = 0;
      $$(`[data-filter-item][data-filter-group="${group}"]`).forEach((item) => {
        const tags = (item.getAttribute('data-filter-item') || '').split(/\s+/);
        const visible = value === 'all' || tags.includes(value);
        if (visible) {
          const wasHidden = item.classList.contains('hidden');
          item.classList.remove('hidden');
          if (wasHidden && !staticMode()) {
            item.style.setProperty('--filter-delay', `${Math.min(shown, 8) * 45}ms`);
            item.classList.remove('filter-in');
            void item.offsetWidth;
            item.classList.add('filter-in');
          }
          shown++;
        } else {
          item.classList.add('hidden');
        }
      });
      $$(`[data-filter-count="${group}"]`).forEach((c) => (c.textContent = String(shown)));
      $$(`[data-filter-empty="${group}"]`).forEach((c) => c.classList.toggle('hidden', shown > 0));
    });
  });
}

/* ------------------------------------------------------------------ Simple sliders (no React) */

/**
 * Horizontal scroll-snap slider:
 *   <div data-slider data-autoplay="5000">
 *     <div data-slider-track class="flex snap-x overflow-x-auto scrollbar-none">…cards…</div>
 *     <button data-slider-prev>‹</button><button data-slider-next>›</button>
 *   </div>
 * Autoplay pauses on hover/focus and when the tab is hidden; arrows disable at the ends.
 */
export function initSliders(root: ParentNode = document): void {
  within('[data-slider]', root).forEach((host) => {
    const track = $('[data-slider-track]', host);
    if (!track || !bindOnce(host, 'slider')) return;
    const prev = $<HTMLButtonElement>('[data-slider-prev]', host);
    const next = $<HTMLButtonElement>('[data-slider-next]', host);
    const behavior: ScrollBehavior = staticMode() ? 'auto' : 'smooth';
    const by = (dir: number) => track.scrollBy({ left: dir * Math.max(260, track.clientWidth * 0.8), behavior });
    const atEnd = () => track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    const sync = () => {
      if (prev) prev.disabled = track.scrollLeft <= 4;
      if (next) next.disabled = atEnd();
    };
    prev?.addEventListener('click', () => by(-1));
    next?.addEventListener('click', () => by(1));
    track.addEventListener('scroll', sync, { passive: true });
    track.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') by(1);
      if (e.key === 'ArrowLeft') by(-1);
    });
    sync();
    const auto = parseInt(host.getAttribute('data-autoplay') || '0', 10);
    if (auto > 0 && !staticMode()) {
      let paused = false;
      host.addEventListener('mouseenter', () => (paused = true));
      host.addEventListener('mouseleave', () => (paused = false));
      host.addEventListener('focusin', () => (paused = true));
      host.addEventListener('focusout', () => (paused = false));
      window.setInterval(() => {
        if (paused || document.hidden) return;
        if (atEnd()) track.scrollTo({ left: 0, behavior });
        else by(1);
      }, auto);
    }
  });

  /* Legacy hero slideshow: <div data-hero> with [data-slide] children (first visible) and [data-slide-dot] dots */
  within('[data-hero]', root).forEach((host) => {
    const slides = $$('[data-slide]', host);
    const dots = $$('[data-slide-dot]', host);
    if (slides.length < 2 || !bindOnce(host, 'hero')) return;
    let i = 0;
    let paused = false;
    const showSlide = (n: number) => {
      i = (n + slides.length) % slides.length;
      slides.forEach((s, k) => {
        s.classList.toggle('opacity-0', k !== i);
        s.classList.toggle('pointer-events-none', k !== i);
        s.setAttribute('aria-hidden', k !== i ? 'true' : 'false');
      });
      dots.forEach((d, k) => {
        d.classList.toggle('!bg-white', k === i);
        d.classList.toggle('w-8', k === i);
        d.setAttribute('aria-current', k === i ? 'true' : 'false');
      });
    };
    dots.forEach((d, k) => d.addEventListener('click', () => showSlide(k)));
    host.addEventListener('mouseenter', () => (paused = true));
    host.addEventListener('mouseleave', () => (paused = false));
    if (!staticMode()) window.setInterval(() => !paused && !document.hidden && showSlide(i + 1), 6500);
  });
}

/* ------------------------------------------------------------------ AJAX forms */

export interface ApiEnvelope<T = unknown> {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
  data?: T;
}

/** POST FormData to a JSON endpoint with the CSRF header. Never throws: network errors become { ok:false }. */
export async function postForm<T = unknown>(url: string, body: FormData): Promise<ApiEnvelope<T>> {
  try {
    const res = await fetch(url, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    });
    const json = (await res.json().catch(() => null)) as ApiEnvelope<T> | null;
    if (json && typeof json.ok === 'boolean') return json;
    return { ok: false, message: res.status === 404 ? 'This form is not available yet. Please try again later.' : 'Unexpected server response. Please try again.' };
  } catch {
    return { ok: false, message: 'Unable to submit right now. Please check your connection and try again.' };
  }
}

/**
 * <form data-ajax-form action="/api/public/contact" data-success="Thanks!"> … <p data-form-message></p></form>
 * Field errors go to [data-error-for="field"]; inputs get .is-invalid. Fires `form:success` with the response.
 * A `data-redirect` response (res.data.redirect) navigates away.
 */
export function initForms(root: ParentNode = document): void {
  within<HTMLFormElement>('form[data-ajax-form]', root).forEach((form) => {
    if (!bindOnce(form, 'ajax-form')) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector<HTMLButtonElement>('[type="submit"]');
      const msg = form.querySelector<HTMLElement>('[data-form-message]');
      $$('[data-error-for]', form).forEach((el) => (el.textContent = ''));
      $$('.is-invalid', form).forEach((el) => {
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
      });
      if (btn) {
        btn.disabled = true;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
      }
      const res = await postForm<{ redirect?: string }>(form.getAttribute('action') || '', new FormData(form));
      if (btn) {
        btn.disabled = false;
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-busy');
      }
      if (msg) {
        msg.setAttribute('role', res.ok ? 'status' : 'alert');
        msg.className = `form-message ${res.ok ? 'form-message-success' : 'form-message-error'}`;
        msg.textContent = res.ok ? res.message || form.getAttribute('data-success') || 'Submitted successfully.' : res.message || 'Please check the form and try again.';
      }
      if (res.ok) {
        form.reset();
        form.classList.add('is-success');
        window.setTimeout(() => form.classList.remove('is-success'), 2400);
        form.dispatchEvent(new CustomEvent('form:success', { detail: res }));
        if (res.data?.redirect) window.location.href = res.data.redirect;
        return;
      }
      let first: HTMLElement | null = null;
      Object.entries(res.errors || {}).forEach(([k, v]) => {
        const holder = form.querySelector<HTMLElement>(`[data-error-for="${CSS.escape(k)}"]`);
        if (holder) holder.textContent = v;
        const input = form.querySelector<HTMLElement>(`[name="${CSS.escape(k)}"]`);
        if (input) {
          input.classList.add('is-invalid');
          input.setAttribute('aria-invalid', 'true');
          first ??= input;
        }
      });
      (first as HTMLElement | null)?.focus();
    });
  });
}
