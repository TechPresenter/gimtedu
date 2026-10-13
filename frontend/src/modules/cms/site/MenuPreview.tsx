import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { ChevronDown, ChevronRight, Lock, Menu, Phone, Search, User, UserRound } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { CmsIcon } from './icons';
import type { MenuItemNode, PreviewData } from './types';

const active = (n: MenuItemNode) => n.status !== 'inactive';

/** Desktop header (floating warm-grey pill bar, Design v3 — includes/navbar.php) with the dropdown / mega panel of the chosen item. */
export function HeaderPreview({ items, settings, focusKey, programs = [] }: { items: MenuItemNode[]; settings?: PreviewData['settings']; focusKey?: string | null; programs?: PreviewData['programs'] }) {
  const visible = items.filter(active);
  // like includes/navbar.php: a plain link to the home page (no sub-items) is left out of the bar — the logo links home
  const isHome = (n: MenuItemNode) => (n.url ?? '').replace(/[?#].*$/, '').replace(/\/+$/, '') === '' && !n.children.some(active);
  const bar = visible.filter((n) => !isHome(n));
  const [open, setOpen] = useState<string | null>(null);
  const hasPanel = (n: MenuItemNode) => n.children.some(active) || n.is_mega;
  useEffect(() => {
    if (focusKey && items.some((n) => n.key === focusKey && (n.children.length || n.is_mega))) setOpen(focusKey);
  }, [focusKey, items]);
  const openItem = visible.find((n) => n.key === open) ?? visible.find(hasPanel);
  const auto = !!openItem?.is_mega && !openItem.children.some(active);
  const levels = Array.from(new Set(programs.map((p) => p.level)));
  return (
    <div className="bg-white p-4">
      <div className="flex items-center gap-4 rounded-[22px] border border-[#EDE8E2] bg-[#F5F2EE]/90 px-4 py-2.5 shadow-[0_8px_24px_-14px_rgba(11,42,91,.3)]">
        {settings?.logo ? <img src={appUrl(settings.logo)} alt="" className="h-8 w-auto" /> : <span className="font-display text-lg font-bold text-[#0B2A5B]">GIMT</span>}
        <nav className="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-x-4 gap-y-1" aria-label="Header preview">
          {bar.map((n) => (
            <button key={n.key} type="button" onMouseEnter={() => hasPanel(n) && setOpen(n.key)} onClick={() => setOpen(n.key)}
              className={clsx('inline-flex items-center gap-0.5 whitespace-nowrap text-[13px] font-semibold', openItem?.key === n.key ? 'text-[#C93C14]' : 'text-[#0B2A5B]')}>
              {n.title || 'Untitled'}
              {hasPanel(n) && <ChevronDown className="h-3 w-3" />}
            </button>
          ))}
        </nav>
        <span className="hidden shrink-0 rounded-full bg-[#D03E16] px-4 py-1.5 text-xs font-semibold text-white sm:inline-flex">{settings?.header_cta_text ?? 'Apply Now'}</span>
        <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-[#0B2A5B]"><Search className="h-4 w-4" /></span>
        <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-[#0B2A5B]"><Menu className="h-4 w-4" /></span>
      </div>
      {openItem && hasPanel(openItem) && (
        <div className="mx-auto mt-2 max-w-5xl rounded-[22px] bg-white p-5 shadow-[0_20px_50px_-20px_rgba(0,0,0,.3)]">
          {auto ? (
            <div className="grid grid-cols-[150px_minmax(0,1fr)] gap-4">
              <div className="space-y-1 border-r border-[#EDE8E2] pr-3">
                <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-[#64748B]">By level</p>
                <p className="flex justify-between rounded-full bg-[#0B2A5B] px-3 py-1 text-[11px] font-semibold text-white">All programs<span>{programs.length}</span></p>
                {levels.map((lv) => <p key={lv} className="flex justify-between px-3 py-1 text-[11px] text-[#0B2A5B]">{lv}<span className="text-[#64748B]">{programs.filter((p) => p.level === lv).length}</span></p>)}
              </div>
              <div>
                <div className="grid grid-cols-3 gap-2">
                  {programs.slice(0, 6).map((p) => (
                    <div key={p.id} className="rounded-2xl border border-[#EDE8E2] bg-[#FAF8F5] p-3">
                      <span className="inline-block max-w-full truncate rounded-full bg-[#0B2A5B] px-2 py-0.5 text-[9px] font-semibold text-white">{p.short_name}</span>
                      <p className="mt-1.5 line-clamp-2 text-[12px] font-semibold leading-snug text-[#0B2A5B]">{p.name}</p>
                      <p className="mt-1 text-[10px] text-[#64748B]">{p.duration_label}</p>
                    </div>
                  ))}
                </div>
                <p className="mt-3 rounded-full border border-[#0B2A5B] py-1.5 text-center text-[11px] font-semibold text-[#0B2A5B]">View all programs →</p>
              </div>
            </div>
          ) : openItem.is_mega ? (
            <div className="grid gap-5" style={{ gridTemplateColumns: `repeat(${Math.max(1, Math.min(4, openItem.children.filter(active).length))}, minmax(0, 1fr))` }}>
              {openItem.children.filter(active).map((col) => (
                <div key={col.key} className="min-w-0">
                  <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-[#D03E16]">{col.title}</p>
                  <div className="space-y-1">
                    {(col.children.filter(active).length ? col.children.filter(active) : [col]).map((l) => <MenuLink key={l.key} n={l} />)}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="grid max-w-md grid-cols-1 gap-1">{openItem.children.filter(active).map((l) => <MenuLink key={l.key} n={l} />)}</div>
          )}
          <p className="mt-4 border-t border-[#EDE8E2] pt-3 text-xs text-[#64748B]">
            {auto ? `Automatic mega menu of “${openItem.title}” — every active program with level and department filters` : `${openItem.is_mega ? 'Mega menu' : 'Dropdown'} of “${openItem.title}”`} · hover another item to preview it
            {visible.some(isHome) && ' · the home link is reached from the logo and listed in the side drawer'}
          </p>
        </div>
      )}
    </div>
  );
}

function MenuLink({ n }: { n: MenuItemNode }) {
  return (
    <div className="flex items-start gap-2.5 rounded-xl p-2 hover:bg-[#F5F2EE]">
      {n.icon && <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#D03E16]/10 text-[#D03E16]"><CmsIcon name={n.icon} className="h-4 w-4" /></span>}
      <span className="min-w-0">
        <span className="block truncate text-[13px] font-semibold text-[#0B2A5B]">{n.title}{n.target === '_blank' && ' ↗'}</span>
        {n.description && <span className="block truncate text-[11px] text-[#64748B]">{n.description}</span>}
      </span>
    </div>
  );
}

/** Mobile drawer list. */
export function DrawerPreview({ items }: { items: MenuItemNode[] }) {
  return (
    <div className="mx-auto w-[300px] overflow-hidden rounded-[26px] border-[6px] border-[#0B2A5B] bg-white shadow-xl">
      <div className="flex items-center justify-between border-b border-[#EDE8E2] px-4 py-3"><span className="font-display font-extrabold text-[#0B2A5B]">GIMT</span><Menu className="h-5 w-5 text-[#0B2A5B]" /></div>
      <div className="max-h-[420px] overflow-y-auto p-2">
        {items.filter(active).map((n) => (
          <div key={n.key} className="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm font-medium text-[#0B2A5B]">
            {n.title}
            {n.children.some(active) && <ChevronRight className="h-4 w-4 text-[#64748B]" />}
          </div>
        ))}
        {!items.filter(active).length && <p className="p-4 text-center text-xs text-[#64748B]">Empty — the drawer shows the header menu.</p>}
      </div>
      <div className="border-t border-[#EDE8E2] p-3"><span className="block rounded-full bg-[#D03E16] py-2 text-center text-xs font-semibold text-white">Apply Now</span></div>
    </div>
  );
}

/** Top bar with the location's links on the right. */
export function TopbarPreview({ items, settings }: { items: MenuItemNode[]; settings?: PreviewData['settings'] }) {
  const icons = [User, UserRound, Lock];
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 bg-[#0B2A5B] px-5 py-2.5 text-xs text-white/80">
      <span className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-[#D03E16]" />{settings?.admissions_open_text ?? 'Admissions Open'}<span className="hidden items-center gap-1 sm:inline-flex"><Phone className="ml-3 h-3 w-3" />{settings?.phone}</span></span>
      <span className="flex flex-wrap items-center gap-4">
        {items.filter(active).map((n, i) => {
          const I = icons[i % icons.length];
          return <span key={n.key} className="inline-flex items-center gap-1.5">{n.icon ? <CmsIcon name={n.icon} className="h-3 w-3" /> : <I className="h-3 w-3" />}{n.title}</span>;
        })}
        {!items.filter(active).length && <span className="text-white/40">No links</span>}
      </span>
    </div>
  );
}

const footerColumns: Record<string, string> = { footer_quick: 'Institute', footer_academics: 'Programs', footer_admissions: 'Admissions', footer_support: 'Student Corner' };

/** Footer card (cream backdrop) highlighting the column being edited; legal links in the bottom bar. */
export function FooterPreview({ location, items }: { location: string; items: MenuItemNode[] }) {
  const cols = Object.entries(footerColumns);
  return (
    <div className="bg-[#F5F2EE] p-5">
      <div className="grid grid-cols-2 gap-5 rounded-[24px] bg-white p-6 lg:grid-cols-5">
        <div className="col-span-2 lg:col-span-1">
          <p className="font-display text-lg font-bold text-[#0B2A5B]">GIMT</p>
          <p className="mt-1 text-xs text-[#64748B]">Education • Innovation • Opportunity</p>
          <div className="mt-3 space-y-1.5">{[70, 55, 62].map((w) => <div key={w} className="h-2 rounded bg-[#FAF8F5]" style={{ width: `${w}%` }} />)}</div>
        </div>
        {cols.map(([key, title]) => (
          <div key={key} className={clsx('rounded-xl p-2 transition', key === location && 'bg-[#D03E16]/10 ring-2 ring-[#D03E16]/40')}>
            <p className="font-display text-sm font-bold text-[#0B2A5B]">{title}</p>
            <ul className="mt-2 space-y-1.5 text-xs text-[#4A5568]">
              {key === location
                ? items.filter(active).map((n) => <li key={n.key}>{n.title}</li>)
                : [60, 75, 50, 68].map((w) => <li key={w}><div className="h-2 rounded bg-[#FAF8F5]" style={{ width: `${w}%` }} /></li>)}
              {key === location && !items.filter(active).length && <li className="text-[#64748B]">Empty — the website shows its default links.</li>}
            </ul>
          </div>
        ))}
      </div>
      <div className={clsx('mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl px-2 py-1.5 text-[11px] text-[#4A5568]', location === 'legal' && 'bg-[#D03E16]/10 ring-2 ring-[#D03E16]/40')}>
        <span>© {new Date().getFullYear()} Global Institute of Management & Technology</span>
        <span className="flex flex-wrap gap-3">
          {location === 'legal' ? items.filter(active).map((n) => <span key={n.key}>{n.title}</span>) : ['Privacy Policy', 'Terms', 'Refund Policy'].map((t) => <span key={t} className="opacity-60">{t}</span>)}
        </span>
      </div>
    </div>
  );
}
