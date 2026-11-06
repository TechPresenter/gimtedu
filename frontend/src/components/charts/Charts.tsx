import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  ArcElement, BarElement, CategoryScale, Chart as ChartJS, Filler, Legend, LinearScale, LineElement, PointElement, RadialLinearScale, Tooltip,
  type ChartData, type ChartOptions, type ScriptableContext,
} from 'chart.js';
import { Bar, Doughnut, Line, Radar } from 'react-chartjs-2';
import { useTheme } from '@/lib/theme';
import { formatMoneyShort, formatNumber } from '@/lib/format';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, RadialLinearScale, Filler, Tooltip, Legend);
ChartJS.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
ChartJS.defaults.font.size = 11;

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const prefersReducedMotion = () => typeof window !== 'undefined' && !!window.matchMedia?.(REDUCED_MOTION).matches;
// Respect reduced-motion preferences (and keep automated screenshots deterministic).
if (prefersReducedMotion()) ChartJS.defaults.animation = false;

/** Live prefers-reduced-motion flag (charts re-render without animation when the OS setting changes). */
function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(prefersReducedMotion);
  useEffect(() => {
    const mq = window.matchMedia?.(REDUCED_MOTION);
    if (!mq) return;
    const on = () => setReduced(mq.matches);
    mq.addEventListener?.('change', on);
    return () => mq.removeEventListener?.('change', on);
  }, []);
  return reduced;
}

/* ---------------------------------------------------------------- palettes */

/** Brand-consistent categorical palette. */
export const CHART_COLORS = ['#1D4ED8', '#22943F', '#F97316', '#8B5CF6', '#06B6D4', '#F43F5E', '#F59E0B', '#0B2A5B', '#10B981', '#64748B'];
export const chartColor = (i: number) => CHART_COLORS[i % CHART_COLORS.length];

/** Named palettes any chart can use through its `palette` prop (or pass your own array of hex colours). */
export const CHART_PALETTES = {
  brand: CHART_COLORS,
  navy: ['#0B2A5B', '#1F4A94', '#2F5FB0', '#5480C8', '#89AADD', '#B8CDEC'],
  green: ['#166534', '#22943F', '#45B86D', '#72CF92', '#A8E4BB', '#D2F2DC'],
  warm: ['#D03E16', '#F05A28', '#F97316', '#F59E0B', '#FBBF24', '#FDE68A'],
  cool: ['#1D4ED8', '#06B6D4', '#10B981', '#8B5CF6', '#0EA5E9', '#14B8A6'],
  status: ['#10B981', '#F59E0B', '#EF4444', '#3B82F6', '#64748B'],
  mono: ['#0B2A5B', '#334155', '#64748B', '#94A3B8', '#CBD5E1'],
} as const;
export type ChartPalette = keyof typeof CHART_PALETTES | readonly string[];

function paletteColors(p?: ChartPalette): readonly string[] {
  if (!p) return CHART_COLORS;
  return typeof p === 'string' ? CHART_PALETTES[p] ?? CHART_COLORS : p.length ? p : CHART_COLORS;
}
const pick = (colors: readonly string[], i: number) => colors[i % colors.length];

/** '#1D4ED8' + alpha → 'rgba(29,78,216,a)' (any non-hex colour is returned unchanged). */
export function withAlpha(color: string, alpha: number): string {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color.trim());
  if (!m) return color;
  const h = m[1].length === 3 ? m[1].split('').map((c) => c + c).join('') : m[1];
  const n = parseInt(h, 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
}

/**
 * Scriptable gradient for bar fills / line areas: strong at the value end, faint at the axis.
 * `horizontal` runs it along the x axis (horizontal bars).
 */
function gradientFill<T extends 'bar' | 'line'>(color: string, from: number, to: number, horizontal = false) {
  return (ctx: ScriptableContext<T>) => {
    const { chart } = ctx;
    const area = chart.chartArea;
    if (!area) return withAlpha(color, from); // first render, before layout
    const g = horizontal ? chart.ctx.createLinearGradient(area.left, 0, area.right, 0) : chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
    g.addColorStop(0, withAlpha(color, to));
    g.addColorStop(1, withAlpha(color, from));
    return g;
  };
}

/** Deep merge of plain objects (arrays and functions are replaced) — used for the `options` pass-through. */
type Plain = Record<string, unknown>;
const isPlain = (v: unknown): v is Plain => !!v && typeof v === 'object' && !Array.isArray(v) && Object.getPrototypeOf(v) === Object.prototype;
export function mergeOptions<T>(base: T, extra?: unknown): T {
  if (!isPlain(extra)) return base;
  const out: Plain = { ...(base as Plain) };
  Object.entries(extra).forEach(([k, v]) => {
    out[k] = isPlain(v) && isPlain(out[k]) ? mergeOptions(out[k], v) : v;
  });
  return out as T;
}

/* ---------------------------------------------------------------- shared axis options */

function useAxisColors() {
  const { resolved } = useTheme();
  return resolved === 'dark' ? { grid: 'rgba(148,163,184,.12)', text: '#94A3B8', tooltipBg: '#0F172A', surface: '#0F172A' } : { grid: 'rgba(15,23,42,.06)', text: '#64748B', tooltipBg: '#0B2A5B', surface: '#FFFFFF' };
}

interface AxisOpts {
  stacked?: boolean;
  money?: boolean;
  legend?: boolean;
  horizontal?: boolean;
  percent?: boolean;
  /** Value-axis range (e.g. 0–10 for SGPA, 0–100 for attendance %); omit for auto-fit. */
  min?: number;
  max?: number;
  /** Start the value axis at zero (default true unless `min` is given). */
  beginAtZero?: boolean;
  stepSize?: number;
  /** Custom formatter for value-axis ticks and tooltips (wins over money / percent). */
  formatValue?: (v: number) => string;
  reducedMotion?: boolean;
}

function baseOptions(c: ReturnType<typeof useAxisColors>, opts: AxisOpts = {}): ChartOptions<'bar' | 'line'> {
  // Money axis ticks: ₹45K / ₹28.4 L / ₹20 Cr
  const tick = (v: number | string) => (opts.formatValue ? opts.formatValue(Number(v)) : opts.money ? formatMoneyShort(v) : opts.percent ? `${v}%` : formatNumber(v));
  const tip = (v: number) => (opts.formatValue ? opts.formatValue(v) : opts.money ? `₹${formatNumber(v)}` : opts.percent ? `${formatNumber(v, Number.isInteger(v) ? 0 : 1)}%` : formatNumber(v, Number.isInteger(v) ? 0 : 2));
  const valueAxis = opts.horizontal ? 'x' : 'y';
  // the value axis gets the range, the tick formatter and a tick limit; the category axis keeps every label
  const value = {
    stacked: opts.stacked, beginAtZero: opts.beginAtZero ?? opts.min === undefined, min: opts.min, max: opts.max,
    grid: { display: true, color: c.grid }, border: { display: false },
    ticks: { color: c.text, maxTicksLimit: 6, stepSize: opts.stepSize, callback: (v: number | string) => tick(v) },
  };
  const category = { stacked: opts.stacked, grid: { display: false, color: c.grid }, border: { display: false }, ticks: { color: c.text, autoSkip: !opts.horizontal } };
  return {
    responsive: true,
    maintainAspectRatio: false,
    ...(opts.reducedMotion ? { animation: false as const } : {}),
    interaction: { mode: 'index', intersect: false },
    indexAxis: opts.horizontal ? 'y' : 'x',
    plugins: {
      legend: { display: opts.legend ?? true, position: 'bottom', labels: { color: c.text, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16 } },
      tooltip: {
        backgroundColor: c.tooltipBg, padding: 10, cornerRadius: 8, titleFont: { weight: 600 }, boxPadding: 4, usePointStyle: true,
        callbacks: { label: (ctx) => ` ${ctx.dataset.label ?? ''}: ${tip(Number(ctx.parsed[valueAxis]))}` },
      },
    },
    scales: valueAxis === 'x' ? { x: value, y: category } : { x: category, y: value },
  } as ChartOptions<'bar' | 'line'>;
}

/* ---------------------------------------------------------------- cartesian charts */

export interface Series {
  label: string;
  data: number[];
  color?: string;
  /** dashed line (targets) */
  dashed?: boolean;
  fill?: boolean;
}

interface CartesianProps extends Omit<AxisOpts, 'reducedMotion'> {
  labels: string[];
  series: Series[];
  height?: number;
  /** Colours for series without their own `color`: a named palette ('brand' | 'navy' | 'green' | 'warm' | 'cool' | 'status' | 'mono') or an array. */
  palette?: ChartPalette;
  /** Gradient fills: bars fade towards the axis, filled line areas fade out. */
  gradient?: boolean;
  /** Chart.js options deep-merged over the defaults (anything not covered by a prop). */
  options?: ChartOptions<'bar'> | ChartOptions<'line'>;
  /** Accessible name for the canvas (defaults to the series names). */
  ariaLabel?: string;
  /** Called with the category index / label when a bar or point is clicked (drill-down). */
  onSelect?: (index: number, label: string) => void;
  className?: string;
}

interface BarChartProps extends CartesianProps {
  /** Corner radius in px; `true` = fully rounded (pill) bars, `false` = square. Default 6. */
  rounded?: boolean | number;
  /** Maximum bar thickness in px (default 22). */
  barThickness?: number;
}

function clickHandler(labels: string[], onSelect?: (index: number, label: string) => void) {
  if (!onSelect) return undefined;
  return {
    onClick: (_e: unknown, elements: { index: number }[]) => {
      const i = elements[0]?.index;
      if (i !== undefined) onSelect(i, labels[i]);
    },
    onHover: (e: { native: Event | null }, elements: unknown[]) => {
      const t = e.native?.target as HTMLElement | undefined;
      if (t) t.style.cursor = elements.length ? 'pointer' : 'default';
    },
  };
}

const describe = (series: Series[], fallback: string) => `${fallback}: ${series.map((s) => s.label).filter(Boolean).join(', ') || 'data'}`;

export function BarChart({ labels, series, height = 260, stacked, money, percent, legend, horizontal, min, max, beginAtZero, stepSize, formatValue, palette, gradient, rounded = 6, barThickness = 22, options, ariaLabel, onSelect, className }: BarChartProps) {
  const c = useAxisColors();
  const reduced = useReducedMotion();
  const colors = paletteColors(palette);
  const radius = rounded === true ? 999 : rounded === false ? 0 : rounded;
  const data: ChartData<'bar'> = useMemo(
    () => ({
      labels,
      datasets: series.map((s, i) => {
        const color = s.color ?? pick(colors, i);
        return {
          label: s.label, data: s.data, backgroundColor: gradient ? gradientFill<'bar'>(color, 1, 0.45, horizontal) : color, hoverBackgroundColor: color,
          borderRadius: radius, borderSkipped: false, maxBarThickness: barThickness, categoryPercentage: 0.7, barPercentage: 0.8,
        };
      }),
    }),
    [labels, series, colors, gradient, horizontal, radius, barThickness],
  );
  const opts = mergeOptions(
    { ...baseOptions(c, { stacked, money, percent, legend: legend ?? series.length > 1, horizontal, min, max, beginAtZero, stepSize, formatValue, reducedMotion: reduced }), ...clickHandler(labels, onSelect) },
    options,
  ) as ChartOptions<'bar'>;
  return (
    <div style={{ height }} className={className}>
      <Bar data={data} options={opts} aria-label={ariaLabel ?? describe(series, 'Bar chart')} role="img" />
    </div>
  );
}

export function LineChart({ labels, series, height = 260, money, percent, legend, min, max, beginAtZero, stepSize, formatValue, palette, gradient, options, ariaLabel, onSelect, className }: CartesianProps) {
  const c = useAxisColors();
  const reduced = useReducedMotion();
  const colors = paletteColors(palette);
  const data: ChartData<'line'> = useMemo(
    () => ({
      labels,
      datasets: series.map((s, i) => {
        const color = s.color ?? pick(colors, i);
        return {
          label: s.label, data: s.data, borderColor: color, backgroundColor: s.fill ? (gradient ? gradientFill<'line'>(color, 0.32, 0) : withAlpha(color, 0.13)) : color, fill: !!s.fill, tension: 0.35,
          borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: c.surface, pointBorderColor: color, borderDash: s.dashed ? [6, 5] : undefined,
        };
      }),
    }),
    [labels, series, colors, gradient, c.surface],
  );
  const opts = mergeOptions(
    { ...baseOptions(c, { money, percent, legend: legend ?? series.length > 1, min, max, beginAtZero, stepSize, formatValue, reducedMotion: reduced }), ...clickHandler(labels, onSelect) },
    options,
  ) as ChartOptions<'line'>;
  return (
    <div style={{ height }} className={className}>
      <Line data={data} options={opts} aria-label={ariaLabel ?? describe(series, 'Line chart')} role="img" />
    </div>
  );
}

/* ---------------------------------------------------------------- doughnut */

interface DoughnutProps {
  labels: string[];
  data: number[];
  colors?: string[];
  /** Named palette or array used when `colors` is not given. */
  palette?: ChartPalette;
  height?: number;
  /** Text in the centre */
  centerValue?: ReactNode;
  centerLabel?: string;
  /** Show a legend list next to the chart (dashboard style) */
  legendList?: boolean;
  /** Format for legend values */
  valueFormat?: 'percent' | 'number' | 'money';
  cutout?: string;
  /** Rounded segment ends (px). */
  rounded?: number;
  /** Chart.js options deep-merged over the defaults. */
  options?: ChartOptions<'doughnut'>;
  ariaLabel?: string;
  onSelect?: (index: number, label: string) => void;
}

export function DoughnutChart({ labels, data, colors, palette, height = 200, centerValue, centerLabel, legendList = true, valueFormat = 'percent', cutout = '72%', rounded = 0, options, ariaLabel, onSelect }: DoughnutProps) {
  const c = useAxisColors();
  const reduced = useReducedMotion();
  const total = data.reduce((a, b) => a + (Number(b) || 0), 0);
  const pal = paletteColors(palette);
  const fill = colors ?? labels.map((_, i) => pick(pal, i));
  const chartData: ChartData<'doughnut'> = { labels, datasets: [{ data, backgroundColor: fill, borderWidth: 3, borderColor: c.surface, hoverOffset: 4, borderRadius: rounded }] };
  const base: ChartOptions<'doughnut'> = {
    responsive: true, maintainAspectRatio: false, cutout, ...(reduced ? { animation: false as const } : {}),
    plugins: { legend: { display: false }, tooltip: { backgroundColor: c.tooltipBg, padding: 10, cornerRadius: 8, callbacks: { label: (ctx) => ` ${ctx.label}: ${valueFormat === 'money' ? `₹${formatNumber(ctx.parsed)}` : formatNumber(ctx.parsed)} (${total ? ((ctx.parsed / total) * 100).toFixed(1) : 0}%)` } } },
    ...(clickHandler(labels, onSelect) as Partial<ChartOptions<'doughnut'>>),
  };
  const fmt = (v: number) => (valueFormat === 'percent' ? `${total ? Math.round((v / total) * 100) : 0}%` : valueFormat === 'money' ? `₹${formatNumber(v)}` : formatNumber(v));
  return (
    <div className="flex flex-col items-center gap-5 sm:flex-row">
      <div className="relative shrink-0" style={{ height, width: height }}>
        <Doughnut data={chartData} options={mergeOptions(base, options)} aria-label={ariaLabel ?? `Doughnut chart: ${labels.map((l, i) => `${l} ${fmt(Number(data[i]) || 0)}`).join(', ')}`} role="img" />
        {(centerValue !== undefined || centerLabel) && (
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span className="font-display text-2xl font-bold text-slate-900 dark:text-white">{centerValue}</span>
            {centerLabel && <span className="text-xs text-slate-500 dark:text-slate-400">{centerLabel}</span>}
          </div>
        )}
      </div>
      {legendList && (
        <ul className="w-full min-w-0 flex-1 space-y-2.5">
          {labels.map((l, i) => (
            <li key={l} className="flex items-center justify-between gap-3 text-sm">
              <span className="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300">
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: fill[i] }} />
                <span className="truncate">{l}</span>
              </span>
              <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{fmt(Number(data[i]) || 0)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/* ---------------------------------------------------------------- radar */

export function RadarChart({ labels, series, height = 280, palette, max, options, ariaLabel }: { labels: string[]; series: Series[]; height?: number; palette?: ChartPalette; max?: number; options?: ChartOptions<'radar'>; ariaLabel?: string }) {
  const c = useAxisColors();
  const reduced = useReducedMotion();
  const colors = paletteColors(palette);
  const base: ChartOptions<'radar'> = {
    responsive: true, maintainAspectRatio: false, ...(reduced ? { animation: false as const } : {}),
    plugins: { legend: { position: 'bottom', labels: { color: c.text } }, tooltip: { backgroundColor: c.tooltipBg, padding: 10, cornerRadius: 8 } },
    scales: { r: { min: 0, max, grid: { color: c.grid }, angleLines: { color: c.grid }, pointLabels: { color: c.text }, ticks: { display: false } } },
  };
  return (
    <div style={{ height }}>
      <Radar
        data={{ labels, datasets: series.map((s, i) => ({ label: s.label, data: s.data, borderColor: s.color ?? pick(colors, i), backgroundColor: withAlpha(s.color ?? pick(colors, i), 0.2), pointRadius: 2 })) }}
        options={mergeOptions(base, options)}
        aria-label={ariaLabel ?? describe(series, 'Radar chart')}
        role="img"
      />
    </div>
  );
}
