import { ChartColumn } from 'lucide-react';
import { EmptyState, useInView } from '@/components/ui';
import { BarChart, chartColor, DoughnutChart, LineChart } from '@/components/charts';
import { formatMoneyShort, formatNumber } from '@/lib/format';
import type { ChartSpec } from '../lib';

interface Series {
  label: string;
  data: number[];
  color?: string;
}

/** Renders the chart a report returns (bar | hbar | stacked | line | doughnut). */
export function ReportChart({ chart }: { chart: ChartSpec }) {
  const hasData = chart.labels.length > 0 && chart.series.some((s) => s.data.some((v) => Number(v) !== 0));
  if (!hasData) {
    return <EmptyState icon={ChartColumn} title="Nothing to chart" description="There is no data for the selected filters. Widen the date range or clear a filter." />;
  }
  const money = chart.format === 'money';
  const percent = chart.format === 'percent';
  const series: Series[] = chart.series.map((s) => ({ label: s.label, data: s.data.map((v) => Number(v) || 0), ...(s.color ? { color: s.color } : {}) }));
  switch (chart.type) {
    case 'doughnut': {
      const data = series[0].data;
      const total = data.reduce((a, b) => a + b, 0);
      return (
        <DoughnutChart
          labels={chart.labels}
          data={data}
          height={220}
          valueFormat={money ? 'money' : 'number'}
          centerValue={money ? formatMoneyShort(total) : formatNumber(total)}
          centerLabel={series[0].label}
        />
      );
    }
    case 'line':
      return <LineChart labels={chart.labels} series={series} money={money} percent={percent} height={280} />;
    case 'hbar':
      return <RankedBars labels={chart.labels} series={series} format={chart.format} />;
    case 'stacked':
      return <BarChart stacked labels={chart.labels} series={series} money={money} percent={percent} height={280} legend={series.length > 1} />;
    default:
      return <BarChart labels={chart.labels} series={series} money={money} percent={percent} height={280} />;
  }
}

/**
 * Ranked horizontal bars as HTML (every label stays readable, wraps on mobile, works with long names).
 * Bars grow in with a transform (scaleX) once visible; static under reduced motion.
 */
function RankedBars({ labels, series, format }: { labels: string[]; series: Series[]; format: ChartSpec['format'] }) {
  const { ref, inView } = useInView<HTMLDivElement>();
  // Percentages are drawn against 100% (honest bar lengths); other measures against the largest value.
  const max = Math.max(...series.flatMap((s) => s.data), format === 'percent' ? 100 : 0) || 1;
  const fmt = (v: number) => (format === 'money' ? formatMoneyShort(v) : format === 'percent' ? `${v.toFixed(1)}%` : formatNumber(v));
  const color = (s: Series, i: number) => s.color ?? chartColor(i);
  return (
    <div ref={ref}>
      {series.length > 1 && (
        <div className="mb-3 flex flex-wrap gap-4 text-xs text-slate-600 dark:text-slate-300">
          {series.map((s, i) => (
            <span key={s.label} className="inline-flex items-center gap-1.5">
              <span className="h-2.5 w-2.5 rounded-sm" style={{ background: color(s, i) }} aria-hidden />
              {s.label}
            </span>
          ))}
        </div>
      )}
      <ul className="space-y-2.5">
        {labels.map((label, li) => (
          <li key={`${label}-${li}`} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)_auto]">
            <span className="truncate text-slate-700 dark:text-slate-200" title={label}>
              {label}
            </span>
            <span className="col-span-2 row-start-2 space-y-1 sm:col-span-1 sm:row-start-auto" aria-hidden>
              {series.map((s, si) => (
                <span key={s.label} className="block h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                  <span
                    className="block h-full w-full origin-left rounded-full transition-transform duration-500 ease-out motion-reduce:transition-none"
                    style={{ background: color(s, si), transform: `scaleX(${inView ? Math.max(0, s.data[li]) / max : 0})` }}
                  />
                </span>
              ))}
            </span>
            <span className="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{series.map((s) => fmt(s.data[li])).join(' / ')}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
