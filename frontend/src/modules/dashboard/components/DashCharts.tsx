import { useMemo, type ReactNode } from 'react';
import {
  ArcElement, BarElement, CategoryScale, Chart as ChartJS, Filler, Legend, LinearScale, LineElement, PointElement, Tooltip,
  type ChartData, type ChartOptions, type ScriptableContext, type TooltipOptions,
} from 'chart.js';
import { Bar, Doughnut, Line } from 'react-chartjs-2';
import { prefersReducedMotion } from '@/components/ui';
import { useTheme } from '@/lib/theme';
import { formatMoneyShort, formatNumber } from '@/lib/format';
import { alpha } from './hues';

/**
 * Dashboard-local chart wrappers (modern style without touching components/charts): gradient-filled rounded bars,
 * smooth gradient areas, rounded-arc doughnuts with a value legend, white/slate card tooltips and an HTML legend.
 * Colours come from the module hue map (hues.ts) so charts match the KPI accents.
 */
ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Filler, Tooltip, Legend);

const FONT = 'Inter, ui-sans-serif, system-ui, sans-serif';
const DISPLAY = '"Plus Jakarta Sans", Inter, ui-sans-serif, sans-serif';

type Fmt = 'number' | 'money' | 'percent';
const fmtTick = (v: number | string, f: Fmt) => (f === 'money' ? formatMoneyShort(v) : f === 'percent' ? `${v}%` : formatNumber(v));
const fmtValue = (v: number, f: Fmt) => (f === 'money' ? `₹${formatNumber(v)}` : f === 'percent' ? `${v}%` : formatNumber(v));

function useChartTheme() {
  const { resolved } = useTheme();
  const dark = resolved === 'dark';
  return {
    dark,
    grid: dark ? 'rgba(148,163,184,.10)' : 'rgba(15,23,42,.06)',
    text: dark ? '#94A3B8' : '#64748B',
    surface: dark ? '#0F172A' : '#FFFFFF',
  };
}
type Theme = ReturnType<typeof useChartTheme>;

function tooltip(t: Theme): Partial<TooltipOptions<'bar' | 'line' | 'doughnut'>> {
  return {
    backgroundColor: t.dark ? 'rgba(30,41,59,.97)' : 'rgba(255,255,255,.98)',
    titleColor: t.dark ? '#F8FAFC' : '#0F172A',
    bodyColor: t.dark ? '#CBD5E1' : '#334155',
    borderColor: t.dark ? 'rgba(148,163,184,.25)' : 'rgba(15,23,42,.10)',
    borderWidth: 1,
    padding: { x: 12, y: 10 },
    cornerRadius: 12,
    caretSize: 6,
    boxPadding: 5,
    boxWidth: 8,
    boxHeight: 8,
    usePointStyle: true,
    titleFont: { family: DISPLAY, weight: 700, size: 12 },
    bodyFont: { family: FONT, size: 12 },
    titleMarginBottom: 6,
  };
}

const animation = () => (prefersReducedMotion() ? false : { duration: 700, easing: 'easeOutQuart' as const });

/** Vertical (or horizontal) gradient for a bar/area, computed against the chart area. */
function gradient(hex: string, from: number, to: number, horizontal = false) {
  return (ctx: ScriptableContext<'bar'> | ScriptableContext<'line'>) => {
    const { chart } = ctx;
    const area = chart.chartArea;
    if (!area) return alpha(hex, from);
    const g = horizontal ? chart.ctx.createLinearGradient(area.left, 0, area.right, 0) : chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
    g.addColorStop(0, alpha(hex, to));
    g.addColorStop(1, alpha(hex, from));
    return g;
  };
}

export interface DashSeries {
  label: string;
  data: number[];
  color: string;
  dashed?: boolean;
  fill?: boolean;
}

/** HTML legend (dots + labels) rendered above a chart. */
export function ChartLegend({ items }: { items: { label: string; color: string; dashed?: boolean }[] }) {
  if (items.length < 2) return null;
  return (
    <ul className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
      {items.map((i) => (
        <li key={i.label} className="inline-flex items-center gap-1.5">
          {i.dashed ? (
            <span className="inline-block h-0 w-4 border-t-2 border-dashed" style={{ borderColor: i.color }} aria-hidden />
          ) : (
            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ background: i.color }} aria-hidden />
          )}
          {i.label}
        </li>
      ))}
    </ul>
  );
}

function axes(t: Theme, f: Fmt, horizontal = false, stacked = false): ChartOptions<'bar'>['scales'] {
  const valueTicks = { color: t.text, maxTicksLimit: 6, font: { family: FONT, size: 11 }, callback: (v: number | string) => fmtTick(v, f) };
  const catTicks = { color: t.text, font: { family: FONT, size: 11 }, autoSkipPadding: 8 };
  return horizontal
    ? {
      x: { stacked, beginAtZero: true, grid: { color: t.grid }, border: { display: false }, ticks: valueTicks },
      y: { stacked, grid: { display: false }, border: { display: false }, ticks: catTicks },
    }
    : {
      x: { stacked, grid: { display: false }, border: { display: false }, ticks: catTicks },
      y: { stacked, beginAtZero: true, grid: { color: t.grid }, border: { display: false }, ticks: valueTicks },
    };
}

/** Rounded, gradient-filled bars. */
export function GradientBarChart({ labels, series, height = 240, format = 'number', horizontal = false, stacked = false, legend = true }: {
  labels: string[]; series: DashSeries[]; height?: number; format?: Fmt; horizontal?: boolean; stacked?: boolean; legend?: boolean;
}) {
  const t = useChartTheme();
  const data: ChartData<'bar'> = useMemo(() => ({
    labels,
    datasets: series.map((s) => ({
      label: s.label,
      data: s.data,
      backgroundColor: gradient(s.color, 1, 0.55, horizontal),
      hoverBackgroundColor: s.color,
      borderRadius: 8,
      borderSkipped: false,
      maxBarThickness: horizontal ? 16 : 20,
      categoryPercentage: 0.7,
      barPercentage: 0.82,
    })),
  }), [labels, series, horizontal]);
  const options: ChartOptions<'bar'> = {
    responsive: true,
    maintainAspectRatio: false,
    animation: animation(),
    indexAxis: horizontal ? 'y' : 'x',
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: { ...(tooltip(t) as TooltipOptions<'bar'>), callbacks: { label: (ctx) => ` ${ctx.dataset.label ?? ''}: ${fmtValue(Number(ctx.parsed[horizontal ? 'x' : 'y']), format)}` } },
    },
    scales: axes(t, format, horizontal, stacked),
  };
  return (
    <div>
      {legend && <ChartLegend items={series.map((s) => ({ label: s.label, color: s.color }))} />}
      <div style={{ height }}>
        <Bar data={data} options={options} />
      </div>
    </div>
  );
}

/** Smooth lines with gradient area fills; dashed series for targets. */
export function AreaLineChart({ labels, series, height = 240, format = 'number', legend = true }: {
  labels: string[]; series: DashSeries[]; height?: number; format?: Fmt; legend?: boolean;
}) {
  const t = useChartTheme();
  const data: ChartData<'line'> = useMemo(() => ({
    labels,
    datasets: series.map((s) => ({
      label: s.label,
      data: s.data,
      borderColor: s.color,
      backgroundColor: s.fill ? gradient(s.color, 0.32, 0) : s.color,
      fill: s.fill ? 'origin' : false,
      tension: 0.42,
      cubicInterpolationMode: 'monotone' as const,
      borderWidth: s.dashed ? 2 : 2.75,
      borderDash: s.dashed ? [6, 5] : undefined,
      pointRadius: 0,
      pointHitRadius: 12,
      pointHoverRadius: 5,
      pointHoverBorderWidth: 2.5,
      pointHoverBackgroundColor: t.surface,
      pointHoverBorderColor: s.color,
      spanGaps: false,
    })),
  }), [labels, series, t.surface]);
  const options: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    animation: animation(),
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: { ...(tooltip(t) as TooltipOptions<'line'>), callbacks: { label: (ctx) => ` ${ctx.dataset.label ?? ''}: ${fmtValue(Number(ctx.parsed.y), format)}` } },
    },
    scales: axes(t, format) as ChartOptions<'line'>['scales'],
  };
  return (
    <div>
      {legend && <ChartLegend items={series.map((s) => ({ label: s.label, color: s.color, dashed: s.dashed }))} />}
      <div style={{ height }}>
        <Line data={data} options={options} />
      </div>
    </div>
  );
}

/** Doughnut with rounded arcs and gaps, a centre value and a legend list with share bars. */
export function RingDonut({ labels, data, colors, size = 168, centerValue, centerLabel, valueFormat = 'percent' }: {
  labels: string[]; data: number[]; colors: string[]; size?: number; centerValue?: ReactNode; centerLabel?: string; valueFormat?: 'percent' | 'number';
}) {
  const t = useChartTheme();
  const total = data.reduce((a, b) => a + (Number(b) || 0), 0);
  const chartData: ChartData<'doughnut'> = useMemo(() => ({
    labels,
    datasets: [{ data, backgroundColor: colors, hoverBackgroundColor: colors, borderWidth: 0, borderRadius: 6, spacing: 3, hoverOffset: 6 }],
  }), [labels, data, colors]);
  const options: ChartOptions<'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '76%',
    animation: animation(),
    layout: { padding: 6 },
    plugins: {
      legend: { display: false },
      tooltip: { ...(tooltip(t) as TooltipOptions<'doughnut'>), callbacks: { label: (ctx) => ` ${ctx.label}: ${formatNumber(ctx.parsed)} (${total ? ((ctx.parsed / total) * 100).toFixed(1) : 0}%)` } },
    },
  };
  return (
    <div className="flex flex-col items-center gap-5 sm:flex-row">
      <div className="relative shrink-0" style={{ height: size, width: size }}>
        <Doughnut data={chartData} options={options} />
        {(centerValue !== undefined || centerLabel) && (
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span className="font-display text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">{centerValue}</span>
            {centerLabel && <span className="text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">{centerLabel}</span>}
          </div>
        )}
      </div>
      <ul className="w-full min-w-0 flex-1 space-y-2.5">
        {labels.map((l, i) => {
          const v = Number(data[i]) || 0;
          const pct = total ? (v / total) * 100 : 0;
          return (
            <li key={l} className="text-[13px]">
              <div className="flex items-center justify-between gap-3">
                <span className="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300">
                  <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: colors[i] }} aria-hidden />
                  <span className="truncate">{l}</span>
                </span>
                <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{valueFormat === 'percent' ? `${Math.round(pct)}%` : formatNumber(v)}</span>
              </div>
              <div className="mt-1 h-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden>
                <div className="h-full rounded-full" style={{ width: `${pct}%`, background: colors[i] }} />
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
