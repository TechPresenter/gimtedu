import clsx from 'clsx';
import { ArrowLeft, ArrowRight, BedDouble, Bus, CircleCheck, Cpu, Landmark, Library, List, Map as MapIcon, MapPin, Presentation, School, Trees, Trophy, Utensils, type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { useStaticMode } from '../lib/hooks';
import type { IslandBaseProps, LinkProp } from '../lib/types';

export interface CampusZone {
  id: string;
  n: number;
  name: string;
  category: string;
  icon: string;
  /** Pin position in % of the map image. */
  x: number;
  y: number;
  summary: string;
  description: string;
  facts: string[];
  image: string | null;
  imageAlt: string;
  link: LinkProp | null;
}

export interface CampusExplorerProps extends IslandBaseProps {
  map: { src: string; width: number; height: number; alt: string };
  /** category key → label (filter chips). */
  categories: Record<string, string>;
  zones: CampusZone[];
}

/** Zone icons (local map: the shared Icon set has no utensils). */
const ZONE_ICONS: Record<string, LucideIcon> = {
  landmark: Landmark,
  school: School,
  presentation: Presentation,
  cpu: Cpu,
  library: Library,
  'bed-double': BedDouble,
  trophy: Trophy,
  utensils: Utensils,
  bus: Bus,
  trees: Trees,
};

const TINT: Record<string, string> = {
  academic: 'bg-brand-50 text-brand-900',
  facility: 'bg-accent-50 text-accent-700',
  residential: 'bg-[#F1EDFF] text-brand-900',
  sports: 'bg-[#FEF3EF] text-accent-700',
  services: 'bg-[#F3E9DC] text-brand-900',
  green: 'bg-[#E9F7EE] text-emerald-800',
};

/**
 * Campus Explorer: the original illustrated campus map (assets/images/site/campus-map/campus-map.svg) with numbered,
 * keyboard-accessible hotspot buttons (roving focus: ←/→/↑/↓, Home/End) → info panel with photo, description and live
 * facilities; category filter chips; Map / List toggle (the list view is the accessible text alternative); a chip row
 * to jump between places.
 */
export default function CampusExplorer({ map, categories, zones }: CampusExplorerProps) {
  const isStatic = useStaticMode();
  const [activeId, setActiveId] = useState(zones[0]?.id ?? '');
  const [filter, setFilter] = useState('all');
  const [view, setView] = useState<'map' | 'list'>('map');
  const pinRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const panelRef = useRef<HTMLDivElement>(null);
  const focusPin = useRef<string | null>(null);

  const visible = useMemo(() => zones.filter((z) => filter === 'all' || z.category === filter), [zones, filter]);
  const active = zones.find((z) => z.id === activeId) ?? zones[0];
  const usedCats = useMemo(() => Object.keys(categories).filter((c) => zones.some((z) => z.category === c)), [categories, zones]);

  useEffect(() => {
    if (focusPin.current) {
      pinRefs.current[focusPin.current]?.focus();
      focusPin.current = null;
    }
  }, [activeId]);

  // keep the active place inside the filter
  useEffect(() => {
    if (visible.length && !visible.some((z) => z.id === activeId)) setActiveId(visible[0].id);
  }, [visible, activeId]);

  if (!active) return null;

  const pos = visible.findIndex((z) => z.id === active.id);
  const select = (id: string, focus = false) => {
    if (focus) focusPin.current = id;
    setActiveId(id);
  };
  const move = (delta: number, focus = false) => {
    if (!visible.length) return;
    const next = visible[(Math.max(0, pos) + delta + visible.length) % visible.length];
    select(next.id, focus);
  };
  const onPinKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    const map: Record<string, () => void> = {
      ArrowRight: () => move(1, true),
      ArrowDown: () => move(1, true),
      ArrowLeft: () => move(-1, true),
      ArrowUp: () => move(-1, true),
      Home: () => visible[0] && select(visible[0].id, true),
      End: () => visible.length && select(visible[visible.length - 1].id, true),
    };
    if (map[e.key]) {
      e.preventDefault();
      map[e.key]();
    }
  };

  const chip = (key: string, label: string) => (
    <button key={key} type="button" onClick={() => setFilter(key)} aria-pressed={filter === key} className={clsx('chip !px-3.5 !py-1.5 !text-[13px]', filter === key && 'chip-active')}>
      {label}
    </button>
  );

  return (
    <div className="campus-explorer grid grid-cols-1 gap-5 lg:grid-cols-12">
      <div className="min-w-0 lg:col-span-8">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <div className="pill-row scrollbar-none w-full min-w-0 sm:w-auto" role="group" aria-label="Filter places">
            {chip('all', 'All places')}
            {usedCats.map((c) => chip(c, categories[c]))}
          </div>
          <div className="inline-flex rounded-full border border-warm-2 bg-white p-1" role="group" aria-label="View">
            {(['map', 'list'] as const).map((v) => (
              <button
                key={v}
                type="button"
                onClick={() => setView(v)}
                aria-pressed={view === v}
                className={clsx('inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition', view === v ? 'bg-brand-900 text-white' : 'text-brand-900 hover:bg-warm')}
              >
                {v === 'map' ? <MapIcon className="h-4 w-4" aria-hidden="true" /> : <List className="h-4 w-4" aria-hidden="true" />}
                {v === 'map' ? 'Map' : 'List'}
              </button>
            ))}
          </div>
        </div>

        {view === 'map' ? (
          <div className="relative overflow-hidden rounded-[28px] border border-warm-2 bg-[#F4EFE8]">
            <img src={map.src} alt={map.alt} className="block h-auto w-full select-none" width={map.width} height={map.height} loading="lazy" decoding="async" draggable={false} />
            <div role="group" aria-label="Places on the campus map">
              {visible.map((z) => {
                const on = z.id === active.id;
                return (
                  <button
                    key={z.id}
                    ref={(el) => (pinRefs.current[z.id] = el)}
                    type="button"
                    onClick={() => select(z.id)}
                    onKeyDown={onPinKey}
                    tabIndex={on ? 0 : -1}
                    aria-pressed={on}
                    aria-controls="campus-explorer-panel"
                    aria-label={`${z.n}. ${z.name}`}
                    className="group absolute z-[2] -translate-x-1/2 -translate-y-1/2 rounded-full focus-visible:outline-none"
                    style={{ left: `${z.x}%`, top: `${z.y}%` }}
                  >
                    {on && !isStatic && <span className="pulse-ring absolute inset-0 rounded-full text-accent-500" aria-hidden="true" />}
                    <span
                      className={clsx(
                        'relative flex h-7 w-7 items-center justify-center rounded-full border-2 border-white font-display text-[12px] font-extrabold shadow-[0_8px_18px_-6px_rgba(7,26,59,.6)] transition duration-200 sm:h-9 sm:w-9 sm:text-[13.5px]',
                        on ? 'scale-110 bg-accent-600 text-white' : 'bg-brand-900 text-white group-hover:scale-110 group-hover:bg-brand-700',
                        'group-focus-visible:ring-4 group-focus-visible:ring-accent-500/50',
                      )}
                    >
                      {z.n}
                    </span>
                    <span
                      className={clsx(
                        'pointer-events-none absolute bottom-full left-1/2 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded-full bg-brand-950 px-3 py-1 text-[12px] font-semibold text-white shadow-lg sm:block',
                        on ? 'opacity-100' : 'opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100',
                      )}
                      aria-hidden="true"
                    >
                      {z.name}
                    </span>
                  </button>
                );
              })}
            </div>
            <p className="pointer-events-none absolute bottom-3 left-4 hidden rounded-full bg-white/85 px-3 py-1 text-[12px] font-semibold text-slate-600 sm:block">Illustration — not to scale</p>
          </div>
        ) : (
          <ol className="grid gap-2.5 sm:grid-cols-2" aria-label="Campus places">
            {visible.map((z) => {
              const on = z.id === active.id;
              return (
                <li key={z.id}>
                  <button
                    type="button"
                    onClick={() => select(z.id)}
                    aria-pressed={on}
                    aria-controls="campus-explorer-panel"
                    className={clsx('flex h-full w-full items-start gap-3 rounded-2xl border p-4 text-left transition', on ? 'border-brand-900 bg-brand-900 text-white' : 'border-warm-2 bg-white hover:border-[#E2DAD0]')}
                  >
                    <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full font-display text-[13px] font-extrabold', on ? 'bg-accent-600 text-white' : 'bg-brand-900 text-white')}>{z.n}</span>
                    <span className="min-w-0">
                      <span className={clsx('block text-[15px] font-bold', on ? 'text-white' : 'text-brand-900')}>{z.name}</span>
                      <span className={clsx('mt-0.5 block text-[13px] leading-snug', on ? 'text-white/75' : 'text-slate-500')}>{z.summary}</span>
                    </span>
                  </button>
                </li>
              );
            })}
          </ol>
        )}
      </div>

      <div className="min-w-0 lg:col-span-4">
        <div ref={panelRef} id="campus-explorer-panel" className="flex h-full flex-col overflow-hidden rounded-[28px] border border-warm-2 bg-white" aria-live="polite">
          <div key={active.id} className={clsx('flex flex-1 flex-col', !isStatic && 'animate-fade-in')}>
            {active.image ? (
              <img src={active.image} alt={active.imageAlt} className="aspect-[16/9] w-full object-cover" loading="lazy" decoding="async" />
            ) : (
              <div className={clsx('flex aspect-[16/9] items-center justify-center', TINT[active.category] ?? 'bg-warm text-brand-900')}>
                <span className="inline-flex h-20 w-20 items-center justify-center rounded-[26px] bg-white/80 shadow-sm">
                  {(() => {
                    const ZoneIcon = ZONE_ICONS[active.icon] ?? MapPin;
                    return <ZoneIcon className="h-9 w-9" aria-hidden="true" />;
                  })()}
                </span>
              </div>
            )}
            <div className="flex flex-1 flex-col p-6">
              <p className="flex items-center gap-2 text-[12px] font-bold uppercase tracking-[0.12em] text-accent-700">
                <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-900 font-display text-[11px] text-white">{active.n}</span>
                {categories[active.category] ?? ''}
              </p>
              <h4 className="mt-2 font-display text-xl font-extrabold text-brand-900">{active.name}</h4>
              <p className="mt-2 text-[14.5px] leading-relaxed text-slate-600">{active.description}</p>
              {active.facts.length > 0 && (
                <ul className="mt-4 space-y-1.5">
                  {active.facts.map((f) => (
                    <li key={f} className="flex items-start gap-2 text-[14px] text-slate-700">
                      <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-accent-600" aria-hidden="true" />
                      {f}
                    </li>
                  ))}
                </ul>
              )}
              <div className="mt-auto flex items-center justify-between gap-3 pt-6">
                {active.link ? (
                  <a href={active.link.url} className="link-arrow text-[14px]">
                    {active.link.label}
                    <ArrowRight className="h-4 w-4" aria-hidden="true" />
                  </a>
                ) : (
                  <span />
                )}
                <span className="flex items-center gap-1.5">
                  <button type="button" onClick={() => move(-1)} aria-label="Previous place" className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-warm-2 text-brand-900 transition hover:bg-brand-900 hover:text-white">
                    <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                  </button>
                  <span className="min-w-[3.25rem] text-center text-[13px] font-semibold tabular-nums text-slate-500">
                    {Math.max(0, pos) + 1} / {visible.length}
                  </span>
                  <button type="button" onClick={() => move(1)} aria-label="Next place" className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-warm-2 text-brand-900 transition hover:bg-brand-900 hover:text-white">
                    <ArrowRight className="h-4 w-4" aria-hidden="true" />
                  </button>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <ol className="grid grid-cols-2 gap-2 lg:col-span-12 lg:grid-cols-5" aria-label="Jump to a place">
        {zones.map((z) => {
          const on = z.id === active.id;
          return (
            <li key={z.id}>
              <button
                type="button"
                onClick={() => {
                  setFilter('all');
                  select(z.id);
                }}
                aria-pressed={on}
                aria-controls="campus-explorer-panel"
                className={clsx('flex h-full w-full items-start gap-2.5 rounded-2xl border p-2.5 text-left transition sm:gap-3 sm:p-3', on ? 'border-accent-300 bg-accent-50' : 'border-warm-2 bg-white hover:border-[#E2DAD0]')}
              >
                <span className={clsx('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full font-display text-[12px] font-extrabold text-white', on ? 'bg-accent-600' : 'bg-brand-900')}>{z.n}</span>
                <span className="min-w-0">
                  <span className="block text-[13px] font-bold leading-snug text-brand-900 sm:text-[14px]">{z.name}</span>
                  <span className="mt-0.5 hidden text-[12.5px] leading-snug text-slate-500 sm:block">{z.summary}</span>
                </span>
              </button>
            </li>
          );
        })}
      </ol>
    </div>
  );
}
