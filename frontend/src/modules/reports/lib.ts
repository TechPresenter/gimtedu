import {
  Activity, AlarmClock, ArrowLeftRight, Award, BadgeCheck, BedDouble, BookCopy, BookOpenCheck, Briefcase, Building, Building2, Bus, CalendarCheck, CalendarDays,
  CalendarRange, ChartColumn, ChartPie, ClipboardList, Clock, Contact, FileBadge, Files, FileText, Gauge, Globe, GraduationCap, HandHeart, IdCard, KeyRound, Layers,
  Library, List, Mail, MapPin, Megaphone, MessageSquareText, Newspaper, Presentation, Receipt, Route, Share2, Target, UserCheck, UserPlus, Users, Wallet,
  type LucideIcon,
} from 'lucide-react';
import type { Tone } from '@/components/ui';

/* ---------------------------------------------------------------- API shapes */

export interface CatalogArea {
  key: string;
  label: string;
  icon: string;
  description: string;
  count: number;
}

export interface CatalogReport {
  key: string;
  area: string;
  title: string;
  description: string;
  icon: string;
  module: string;
}

export interface Catalog {
  areas: CatalogArea[];
  reports: CatalogReport[];
  total_reports: number;
  can_export: boolean;
  session: string | null;
  activity: { exports_30d: number; my_last_export: { description: string; at: string } | null };
}

export interface FilterOption {
  value: string;
  label: string;
  department_id?: string | null;
}

export interface FilterMeta {
  name: string;
  param: string;
  label: string;
  type: 'daterange' | 'select';
  options?: FilterOption[];
  all_label?: string;
  default?: string | null;
  required?: boolean;
}

export type ColumnFormat =
  | 'text' | 'strong' | 'person' | 'code' | 'number' | 'decimal' | 'money' | 'percent' | 'progress' | 'date' | 'datetime' | 'month' | 'badge' | 'label' | 'boolean' | 'days' | 'lpa' | 'hours';

export interface ColumnMeta {
  key: string;
  label: string;
  format: ColumnFormat;
  sub?: string;
  labels?: Record<string, string>;
  suffix?: string;
  sortable?: boolean;
  warn_below?: number;
}

export interface ReportMeta {
  key: string;
  title: string;
  description: string;
  area: string;
  area_label: string;
  module: string;
  icon: string;
  filters: FilterMeta[];
  search: string | null;
  columns: ColumnMeta[];
  default_sort: { key: string; dir: 'asc' | 'desc' };
  note: string | null;
  can_export: boolean;
  sessions: { id: number; name: string; start_date: string; end_date: string }[];
  today: string;
}

export type KpiFormat = 'number' | 'money' | 'percent' | 'decimal' | 'text' | 'lpa' | 'hours';

export interface Kpi {
  label: string;
  value: number | string | null;
  format: KpiFormat;
  tone: Tone;
  hint: string | null;
}

export interface ChartSpec {
  type: 'bar' | 'hbar' | 'stacked' | 'line' | 'doughnut';
  title: string;
  labels: string[];
  series: { label: string; data: number[]; color?: string }[];
  format: 'number' | 'money' | 'percent';
}

export interface ReportPayload {
  key: string;
  kpis: Kpi[];
  chart: ChartSpec | null;
  rows: Record<string, unknown>[];
  pagination: { page: number; per_page: number; total: number; pages: number; from: number; to: number };
  sort: { key: string; dir: 'asc' | 'desc' };
  applied: string[];
  generated_at: string;
}

/* ---------------------------------------------------------------- Icons */

const ICONS: Record<string, LucideIcon> = {
  'graduation-cap': GraduationCap, 'user-plus': UserPlus, 'calendar-check': CalendarCheck, wallet: Wallet, 'clipboard-list': ClipboardList, award: Award,
  presentation: Presentation, library: Library, building: Building, bus: Bus, briefcase: Briefcase, users: Users, globe: Globe,
  list: List, layers: Layers, 'file-text': FileText, share: Share2, gauge: Gauge, 'user-check': UserCheck, 'calendar-days': CalendarDays, receipt: Receipt,
  'alarm-clock': AlarmClock, 'calendar-range': CalendarRange, 'book-open-check': BookOpenCheck, 'file-badge': FileBadge, 'chart-column': ChartColumn, 'id-card': IdCard,
  clock: Clock, 'arrow-left-right': ArrowLeftRight, 'book-copy': BookCopy, 'bed-double': BedDouble, 'key-round': KeyRound, route: Route, 'map-pin': MapPin,
  target: Target, 'building-2': Building2, 'badge-check': BadgeCheck, contact: Contact, 'hand-heart': HandHeart, 'message-square-text': MessageSquareText,
  megaphone: Megaphone, mail: Mail, newspaper: Newspaper, files: Files, activity: Activity,
};

export const reportIcon = (name: string | undefined): LucideIcon => (name && ICONS[name]) || ChartPie;

/** Accent per area (catalogue section headers + card icons). Literal classes so Tailwind keeps them. */
export const AREA_TONES: Record<string, string> = {
  students: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
  admissions: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
  attendance: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
  fees: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  exams: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  results: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
  faculty: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  library: 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
  hostel: 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
  transport: 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
  placement: 'bg-green-50 text-green-700 dark:bg-green-500/15 dark:text-green-300',
  alumni: 'bg-fuchsia-50 text-fuchsia-700 dark:bg-fuchsia-500/15 dark:text-fuchsia-300',
  website: 'bg-slate-100 text-slate-700 dark:bg-slate-700/40 dark:text-slate-200',
};
export const areaTone = (area: string) => AREA_TONES[area] ?? AREA_TONES.website;

/* ---------------------------------------------------------------- URL state */

/** Search params that are not report filters. */
export const RESERVED_PARAMS = ['r', 'page', 'per_page', 'sort', 'dir', 'area'];

/** Effective value of a filter: URL value, else its default ('' = all). */
export function filterValue(params: URLSearchParams, f: FilterMeta): string {
  const v = params.get(f.param);
  if (v !== null) return v === 'all' ? '' : v;
  return f.default ?? '';
}

/** Query sent to the API for the current URL state (page/sort excluded). */
export function apiFilters(meta: ReportMeta, params: URLSearchParams): Record<string, string> {
  const q: Record<string, string> = {};
  meta.filters.forEach((f) => {
    if (f.type === 'daterange') {
      const from = params.get('from');
      const to = params.get('to');
      if (from) q.from = from;
      if (to) q.to = to;
      return;
    }
    const v = filterValue(params, f);
    if (v) q[f.param] = v;
    else if (f.default) q[f.param] = 'all'; // explicit "All" overrides the server default (e.g. current session)
  });
  const search = params.get('q');
  if (search && meta.search) q.q = search;
  return q;
}

/** Number of filters that differ from their defaults (reset button + presets). */
export function activeFilterCount(meta: ReportMeta, params: URLSearchParams): number {
  let n = 0;
  meta.filters.forEach((f) => {
    if (f.type === 'daterange') {
      if (params.get('from') || params.get('to')) n++;
    } else if (params.has(f.param) && filterValue(params, f) !== (f.default ?? '')) n++;
  });
  if (params.get('q')) n++;
  return n;
}

/** The filter part of the URL (for presets): everything except the report key and paging. */
export function filterQueryString(params: URLSearchParams): string {
  const p = new URLSearchParams();
  params.forEach((v, k) => {
    if (!['r', 'page', 'area'].includes(k) && v !== '') p.append(k, v);
  });
  return p.toString();
}

/* ---------------------------------------------------------------- Per-user browser storage */

function storageGet<T>(key: string, fallback: T): T {
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function storageSet(key: string, value: unknown) {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* storage full / blocked — presets simply are not persisted */
  }
}

export interface Preset {
  id: string;
  name: string;
  query: string;
  created_at: string;
}

const presetKey = (userId: number | undefined) => `gimt:report-presets:${userId ?? 0}`;
const recentKey = (userId: number | undefined) => `gimt:reports-recent:${userId ?? 0}`;

export function loadPresets(userId: number | undefined, report: string): Preset[] {
  const all = storageGet<Record<string, Preset[]>>(presetKey(userId), {});
  return Array.isArray(all[report]) ? all[report] : [];
}

export function savePresets(userId: number | undefined, report: string, presets: Preset[]) {
  const all = storageGet<Record<string, Preset[]>>(presetKey(userId), {});
  if (presets.length) all[report] = presets;
  else delete all[report];
  storageSet(presetKey(userId), all);
}

export function loadRecent(userId: number | undefined): string[] {
  const list = storageGet<string[]>(recentKey(userId), []);
  return Array.isArray(list) ? list.filter((k) => typeof k === 'string') : [];
}

export function pushRecent(userId: number | undefined, report: string) {
  const list = [report, ...loadRecent(userId).filter((k) => k !== report)].slice(0, 6);
  storageSet(recentKey(userId), list);
}

/* ---------------------------------------------------------------- Dates */

export function isoDay(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

export function shiftDays(iso: string, days: number): string {
  const d = new Date(`${iso}T00:00:00`);
  d.setDate(d.getDate() + days);
  return isoDay(d);
}

export function monthLabel(ym: unknown): string {
  const s = String(ym ?? '');
  const d = new Date(`${s}-01T00:00:00`);
  return Number.isNaN(d.getTime()) ? s : d.toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
}
