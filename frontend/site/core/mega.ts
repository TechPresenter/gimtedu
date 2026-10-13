import { $$, bindOnce, staticMode, within } from './dom';

/**
 * Programs mega menu filters (Design v2, includes/navbar.php):
 *   <div data-mega>
 *     <button class="mega-filter" data-mega-filter="all|level-ug|dept-3" aria-pressed>…</button>   (left sidebar)
 *     <a class="mega-card" data-mega-item="all level-ug dept-3">…</a>                              (card grid)
 *     <p data-mega-empty class="hidden">…</p>
 *   </div>
 * Clicking (or Enter/Space on) a filter shows only matching cards; cards that appear animate in (staggered
 * card-in keyframes, transform/opacity only — skipped in static mode). Without JS every card is listed.
 */
export function initMega(root: ParentNode = document): void {
  within('[data-mega]', root).forEach((mega) => {
    if (!bindOnce(mega, 'mega')) return;
    const filters = $$<HTMLButtonElement>('[data-mega-filter]', mega);
    const cards = $$<HTMLElement>('[data-mega-item]', mega);
    const empty = mega.querySelector<HTMLElement>('[data-mega-empty]');
    const apply = (value: string, animate: boolean) => {
      filters.forEach((f) => {
        const on = f.getAttribute('data-mega-filter') === value;
        f.classList.toggle('is-active', on);
        f.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      let shown = 0;
      cards.forEach((card) => {
        const tags = (card.getAttribute('data-mega-item') || '').split(/\s+/);
        const match = value === 'all' || tags.includes(value);
        card.hidden = !match;
        card.classList.remove('is-entering');
        if (match) {
          if (animate && !staticMode()) {
            card.style.setProperty('--d', `${Math.min(shown, 9) * 35}ms`);
            void card.offsetWidth;
            card.classList.add('is-entering');
          }
          shown++;
        }
      });
      empty?.classList.toggle('hidden', shown > 0);
      const grid = cards[0]?.parentElement;
      if (grid) grid.scrollTop = 0;
    };
    filters.forEach((f) =>
      f.addEventListener('click', (e) => {
        e.preventDefault();
        apply(f.getAttribute('data-mega-filter') || 'all', true);
      }),
    );
    // resting the mouse on a filter (hover intent, 140ms) selects it too — passing over others on the way doesn't
    let timer = 0;
    filters.forEach((f) => {
      f.addEventListener('pointerenter', (e) => {
        if (e.pointerType !== 'mouse' || f.classList.contains('is-active')) return;
        window.clearTimeout(timer);
        timer = window.setTimeout(() => apply(f.getAttribute('data-mega-filter') || 'all', true), 140);
      });
      f.addEventListener('pointerleave', () => window.clearTimeout(timer));
    });
  });
}
