import { useMemo, type ReactNode } from 'react';
import {
  ArcElement, BarElement, CategoryScale, Chart as ChartJS, Filler, Legend, LinearScale, LineElement, PointElement, RadialLinearScale, Tooltip,
  type ChartData, type ChartOptions,
} from 'chart.js';
import { Bar, Doughnut, Line, Radar } from 'react-chartjs-2';
import { useTheme } from '@/lib/theme';
import { formatNumber } from '@/lib/format';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, RadialLinearScale, Filler, Tooltip, Legend);
ChartJS.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
ChartJS.defaults.font.size = 11;

/** Brand-consistent categorical palette. */
export const CHART_COLORS = ['#1D4ED8', '#22943F', '#F97316', '#8B5CF6', '#06B6D4', '#F43F5E', '#F59E0B', '#0B2A5B', '#10B981', '#64748B'];
export const chartColor = (i: number) => CHART_COLORS[i % CHART_COLORS.length];

function useAxisColors() {
  const { resolved } = useTheme();
  return resolved === 'dark' ? { grid: 'rgba(148,163,184,.12)', text: '#94A3B8', tooltipBg: '#0F172A' } : { grid: 'rgba(15,23,42,.06)', text: '#64748B', tooltipBg: '#0B2A5B' };
}

function baseOptions(c: ReturnType<typeof useAxisColors>, opts: { stacked?: boolean; money?: boolean; legend?: boolean; horizontal?: boolean; percent?: boolean } = {}): ChartOptions<'bar' | 'line'> {
  const fmt = (v: number | string) => (opts.money ? `₹${formatNumber(Number(v) >= 1e5 ? Number(v) / 1e5 : v)}${Number(v) >= 1e5 ? 'L' : ''}` : opts.percent ? `${v}%` : formatNumber(v));
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    indexAxis: opts.horizontal ? 'y' : 'x',
    plugins: {
      legend: { display: opts.legend ?? true, position: 'bottom', labels: { color: c.text, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16 } },
      tooltip: {
        backgroundColor: c.tooltipBg, padding: 10, cornerRadius: 8, titleFont: { weight: 600 }, boxPadding: 4, usePointStyle: true,
        callbacks: { label: (ctx) => ` ${ctx.dataset.label ?? ''}: ${opts.money ? `₹${formatNumber(ctx.parsed[opts.horizontal ? 'x' : 'y'])}` : opts.percent ? `${ctx.parsed[opts.horizontal ? 'x' : 'y']}%` : formatNumber(ctx.parsed[opts.horizontal ? 'x' : 'y'])}` },
      },
    },
    scales: {
      x: { stacked: opts.stacked, grid: { display: !!opts.horizontal, color: c.grid }, border: { display: false }, ticks: { color: c.text, ...(opts.horizontal ? { callback: (v) => fmt(v) } : {}) } },
      y: { stacked: opts.stacked, beginAtZero: true, grid: { display: !opts.horizontal, color: c.grid }, border: { display: false }, ticks: { color: c.text, maxTicksLimit: 6, ...(!opts.horizontal ? { callback: (v) => fmt(v) } : {}) } },
    },
  };
}

export interface Series {
  label: string;
  data: number[];
  color?: string;
  /** dashed line (targets) */
  dashed?: boolean;
  fill?: boolean;
}

interface CartesianProps {
  labels: string[];
  series: Series[];
  height?: number;
  stacked?: boolean;
  money?: boolean;
  percent?: boolean;
  legend?: boolean;
  horizontal?: boolean;
}

export function BarChart({ labels, series, height = 260, stacked, money, percent, legend, horizontal }: CartesianProps) {
  const c = useAxisColors();
  const data: ChartData<'bar'> = useMemo(
    () => ({
      labels,
      datasets: series.map((s, i) => ({ label: s.label, data: s.data, backgroundColor: s.color ?? chartColor(i), borderRadius: 6, borderSkipped: false, maxBarThickness: 22, categoryPercentage: 0.7, barPercentage: 0.8 })),
    }),
    [labels, series],
  );
  return (
    <div style={{ height }}>
      <Bar data={data} options={baseOptions(c, { stacked, money, percent, legend: legend ?? series.length > 1, horizontal }) as ChartOptions<'bar'>} />
    </div>
  );
}

export function LineChart({ labels, series, height = 260, money, percent, legend }: CartesianProps) {
  const c = useAxisColors();
  const data: ChartData<'line'> = useMemo(
    () => ({
      labels,
      datasets: series.map((s, i) => {
        const color = s.color ?? chartColor(i);
        return {
          label: s.label, data: s.data, borderColor: color, backgroundColor: s.fill ? `${color}22` : color, fill: !!s.fill, tension: 0.35,
          borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: '#fff', pointBorderColor: color, borderDash: s.dashed ? [6, 5] : undefined,
        };
      }),
    }),
    [labels, series],
  );
  return (
    <div style={{ height }}>
      <Line data={data} options={baseOptions(c, { money, percent, legend: legend ?? series.length > 1 }) as ChartOptions<'line'>} />
    </div>
  );
}

interface DoughnutProps {
  labels: string[];
  data: number[];
  colors?: string[];
  height?: number;
  /** Text in the centre */
  centerValue?: ReactNode;
  centerLabel?: string;
  /** Show a legend list next to the chart (dashboard style) */
  legendList?: boolean;
  /** Format for legend values */
  valueFormat?: 'percent' | 'number' | 'money';
  cutout?: string;
}

export function DoughnutChart({ labels, data, colors, height = 200, centerValue, centerLabel, legendList = true, valueFormat = 'percent', cutout = '72%' }: DoughnutProps) {
  const { resolved } = useTheme();
  const total = data.reduce((a, b) => a + (Number(b) || 0), 0);
  const palette = colors ?? labels.map((_, i) => chartColor(i));
  const chartData: ChartData<'doughnut'> = { labels, datasets: [{ data, backgroundColor: palette, borderWidth: 3, borderColor: resolved === 'dark' ? '#0F172A' : '#fff', hoverOffset: 4 }] };
  const options: ChartOptions<'doughnut'> = {
    responsive: true, maintainAspectRatio: false, cutout,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${formatNumber(ctx.parsed)} (${total ? ((ctx.parsed / total) * 100).toFixed(1) : 0}%)` } } },
  };
  const fmt = (v: number) => (valueFormat === 'percent' ? `${total ? Math.round((v / total) * 100) : 0}%` : valueFormat === 'money' ? `₹${formatNumber(v)}` : formatNumber(v));
  return (
    <div className="flex flex-col items-center gap-5 sm:flex-row">
      <div className="relative shrink-0" style={{ height, width: height }}>
        <Doughnut data={chartData} options={options} />
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
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: palette[i] }} />
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

export function RadarChart({ labels, series, height = 280 }: { labels: string[]; series: Series[]; height?: number }) {
  const c = useAxisColors();
  return (
    <div style={{ height }}>
      <Radar
        data={{ labels, datasets: series.map((s, i) => ({ label: s.label, data: s.data, borderColor: s.color ?? chartColor(i), backgroundColor: `${s.color ?? chartColor(i)}33`, pointRadius: 2 })) }}
        options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: c.text } } }, scales: { r: { grid: { color: c.grid }, angleLines: { color: c.grid }, pointLabels: { color: c.text }, ticks: { display: false } } } }}
      />
    </div>
  );
}
