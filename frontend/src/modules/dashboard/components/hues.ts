/**
 * Dashboard colour system — the ONE place that maps a hue name to its classes / hex values.
 * Vivid-but-tasteful accents on top of the navy/green admin brand. Every class is written out literally so Tailwind
 * generates it (no string building). Solid chips use the 500→600 range so white icons keep >= 3:1 contrast.
 */
export type Hue = 'indigo' | 'emerald' | 'amber' | 'rose' | 'sky' | 'violet' | 'teal' | 'orange' | 'navy' | 'slate';

export interface HueStyle {
  /** main colour (charts, SVG strokes) */
  hex: string;
  /** lighter partner for gradients */
  hex2: string;
  /** soft gradient tint for KPI / tile backgrounds */
  tint: string;
  /** subtle inner border */
  ring: string;
  /** solid gradient icon chip (white icon) */
  chip: string;
  /** coloured glow on hover */
  glow: string;
  /** soft pill / avatar */
  soft: string;
  /** coloured text */
  text: string;
  /** gradient stops for accent edges (use with bg-gradient-to-r / -to-b) */
  edge: string;
  /** solid dot */
  dot: string;
}

export const HUES: Record<Hue, HueStyle> = {
  indigo: {
    hex: '#6366F1', hex2: '#A5B4FC',
    tint: 'bg-gradient-to-br from-indigo-50 via-white to-white dark:from-indigo-500/[.14] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-indigo-100 dark:ring-indigo-400/15',
    chip: 'bg-gradient-to-br from-indigo-500 to-indigo-600 text-white shadow-md shadow-indigo-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(99,102,241,.6)]',
    soft: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    text: 'text-indigo-600 dark:text-indigo-300',
    edge: 'from-indigo-500 to-violet-500',
    dot: 'bg-indigo-500',
  },
  emerald: {
    hex: '#10B981', hex2: '#6EE7B7',
    tint: 'bg-gradient-to-br from-emerald-50 via-white to-white dark:from-emerald-500/[.13] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-emerald-100 dark:ring-emerald-400/15',
    chip: 'bg-gradient-to-br from-emerald-500 to-emerald-600 text-white shadow-md shadow-emerald-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(16,185,129,.6)]',
    soft: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    text: 'text-emerald-600 dark:text-emerald-300',
    edge: 'from-emerald-500 to-teal-400',
    dot: 'bg-emerald-500',
  },
  amber: {
    hex: '#F59E0B', hex2: '#FCD34D',
    tint: 'bg-gradient-to-br from-amber-50 via-white to-white dark:from-amber-500/[.13] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-amber-100 dark:ring-amber-400/15',
    chip: 'bg-gradient-to-br from-amber-500 to-amber-600 text-white shadow-md shadow-amber-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(245,158,11,.6)]',
    soft: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    text: 'text-amber-600 dark:text-amber-300',
    edge: 'from-amber-400 to-orange-500',
    dot: 'bg-amber-500',
  },
  rose: {
    hex: '#F43F5E', hex2: '#FDA4AF',
    tint: 'bg-gradient-to-br from-rose-50 via-white to-white dark:from-rose-500/[.13] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-rose-100 dark:ring-rose-400/15',
    chip: 'bg-gradient-to-br from-rose-500 to-rose-600 text-white shadow-md shadow-rose-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(244,63,94,.55)]',
    soft: 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    text: 'text-rose-600 dark:text-rose-300',
    edge: 'from-rose-500 to-pink-400',
    dot: 'bg-rose-500',
  },
  sky: {
    hex: '#0EA5E9', hex2: '#7DD3FC',
    tint: 'bg-gradient-to-br from-sky-50 via-white to-white dark:from-sky-500/[.13] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-sky-100 dark:ring-sky-400/15',
    chip: 'bg-gradient-to-br from-sky-500 to-sky-600 text-white shadow-md shadow-sky-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(14,165,233,.6)]',
    soft: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    text: 'text-sky-600 dark:text-sky-300',
    edge: 'from-sky-400 to-blue-500',
    dot: 'bg-sky-500',
  },
  violet: {
    hex: '#8B5CF6', hex2: '#C4B5FD',
    tint: 'bg-gradient-to-br from-violet-50 via-white to-white dark:from-violet-500/[.14] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-violet-100 dark:ring-violet-400/15',
    chip: 'bg-gradient-to-br from-violet-500 to-violet-600 text-white shadow-md shadow-violet-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(139,92,246,.6)]',
    soft: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    text: 'text-violet-600 dark:text-violet-300',
    edge: 'from-violet-500 to-fuchsia-500',
    dot: 'bg-violet-500',
  },
  teal: {
    hex: '#14B8A6', hex2: '#5EEAD4',
    tint: 'bg-gradient-to-br from-teal-50 via-white to-white dark:from-teal-500/[.13] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-teal-100 dark:ring-teal-400/15',
    chip: 'bg-gradient-to-br from-teal-500 to-teal-600 text-white shadow-md shadow-teal-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(20,184,166,.6)]',
    soft: 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
    text: 'text-teal-600 dark:text-teal-300',
    edge: 'from-teal-400 to-cyan-500',
    dot: 'bg-teal-500',
  },
  orange: {
    hex: '#F97316', hex2: '#FDBA74',
    tint: 'bg-gradient-to-br from-orange-50 via-white to-white dark:from-orange-500/[.13] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-orange-100 dark:ring-orange-400/15',
    chip: 'bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-md shadow-orange-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(249,115,22,.55)]',
    soft: 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
    text: 'text-orange-600 dark:text-orange-300',
    edge: 'from-orange-500 to-amber-400',
    dot: 'bg-orange-500',
  },
  navy: {
    hex: '#1F4A94', hex2: '#89AADD',
    tint: 'bg-gradient-to-br from-brand-50 via-white to-white dark:from-brand-500/[.16] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-brand-100 dark:ring-brand-400/15',
    chip: 'bg-gradient-to-br from-brand-600 to-brand-800 text-white shadow-md shadow-brand-700/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(31,74,148,.6)]',
    soft: 'bg-brand-50 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200',
    text: 'text-brand-700 dark:text-brand-300',
    edge: 'from-brand-700 to-brand-400',
    dot: 'bg-brand-600',
  },
  slate: {
    hex: '#64748B', hex2: '#CBD5E1',
    tint: 'bg-gradient-to-br from-slate-100/80 via-white to-white dark:from-slate-500/[.14] dark:via-slate-900 dark:to-slate-900',
    ring: 'ring-1 ring-inset ring-slate-200/80 dark:ring-slate-400/15',
    chip: 'bg-gradient-to-br from-slate-500 to-slate-600 text-white shadow-md shadow-slate-500/25',
    glow: 'hover:shadow-[0_16px_36px_-16px_rgba(100,116,139,.55)]',
    soft: 'bg-slate-100 text-slate-700 dark:bg-slate-700/60 dark:text-slate-200',
    text: 'text-slate-600 dark:text-slate-300',
    edge: 'from-slate-400 to-slate-300',
    dot: 'bg-slate-500',
  },
};

/** Categorical order for charts and repeated items (matches the KPI hues). */
export const HUE_ORDER: Hue[] = ['indigo', 'emerald', 'amber', 'rose', 'sky', 'violet', 'teal', 'orange'];
export const hueAt = (i: number): Hue => HUE_ORDER[((i % HUE_ORDER.length) + HUE_ORDER.length) % HUE_ORDER.length];
export const hueHex = (i: number): string => HUES[hueAt(i)].hex;

/** Stable hue for a name (avatars). */
export function hueFor(name: string | null | undefined): Hue {
  const s = name ?? '';
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return hueAt(Math.abs(h));
}

/** '#RRGGBB' + alpha (0–1) → rgba() for canvas gradients. */
export function alpha(hex: string, a: number): string {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
}
