import { useMemo } from 'react';
import { Line } from 'react-chartjs-2';
import type { ChartData, ChartOptions } from 'chart.js';
import { chartColor } from '@/components/charts';
import { useTheme } from '@/lib/theme';

/**
 * Attendance-% line chart with a dashed minimum line and a y-axis that starts near the data
 * (the shared LineChart always starts at 0, which flattens 85-95% attendance curves).
 */
export function PercentTrend({ labels, values, min, height = 240, label = 'Attendance %', color = '#22943F' }: { labels: string[]; values: (number | null)[]; min: number; height?: number; label?: string; color?: string }) {
  const { resolved } = useTheme();
  const dark = resolved === 'dark';
  const axis = dark ? { grid: 'rgba(148,163,184,.12)', text: '#94A3B8', tip: '#0F172A' } : { grid: 'rgba(15,23,42,.06)', text: '#64748B', tip: '#0B2A5B' };
  const nums = values.filter((v): v is number => v !== null);
  const low = Math.max(0, Math.floor((Math.min(min, ...(nums.length ? nums : [min])) - 8) / 10) * 10);
  const data: ChartData<'line'> = useMemo(
    () => ({
      labels,
      datasets: [
        {
          label, data: values, borderColor: color, backgroundColor: `${color}22`, fill: 'start', tension: 0.35, borderWidth: 2.5,
          pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: dark ? '#0F172A' : '#fff', pointBorderColor: color, spanGaps: true,
        },
        { label: `Minimum ${min}%`, data: labels.map(() => min), borderColor: chartColor(5), borderDash: [6, 5], borderWidth: 1.5, pointRadius: 0, fill: false },
      ],
    }),
    [labels, values, min, label, color, dark],
  );
  const options: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { position: 'bottom', labels: { color: axis.text, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16 } },
      tooltip: { backgroundColor: axis.tip, padding: 10, cornerRadius: 8, usePointStyle: true, callbacks: { label: (c) => ` ${c.dataset.label}: ${c.parsed.y ?? 0}%` } },
    },
    scales: {
      x: { grid: { display: false }, border: { display: false }, ticks: { color: axis.text, maxRotation: 0, autoSkipPadding: 12 } },
      y: { min: low, max: 100, grid: { color: axis.grid }, border: { display: false }, ticks: { color: axis.text, maxTicksLimit: 6, callback: (v) => `${v}%` } },
    },
  };
  return (
    <div style={{ height }}>
      <Line data={data} options={options} aria-label={`${label} chart`} role="img" />
    </div>
  );
}
