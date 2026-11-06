import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { CalendarClock, ChevronRight, CircleAlert, LayoutTemplate, Plus, Search, Workflow, Zap } from 'lucide-react';
import { Badge, Button, Card, EmptyState, Reveal, Select, SearchInput, Skeleton, Toggle } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { categoryTone } from './api';
import type { WaEvent } from './types';

interface Props {
  events: WaEvent[] | undefined;
  categories: Record<string, string>;
  sources: Record<string, string>;
  loading: boolean;
  onOpen: (e: WaEvent) => void;
  onToggle: (e: WaEvent, active: boolean) => void;
  toggling: number | null;
  onCreate: () => void;
}

const STATUS_FILTERS = [
  { value: '', label: 'All events' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'no_template', label: 'No template' },
  { value: 'scheduled', label: 'Scheduled (daily)' },
  { value: 'custom', label: 'Custom events' },
  { value: 'failing', label: 'With failures (30 days)' },
];

const RECIPIENT_LABEL: Record<string, string> = {
  contact: 'Contact',
  student: 'Student',
  guardian: 'Parent / guardian',
  counsellor: 'Counsellor / staff',
  custom_field: 'Payload phone',
};

/** Event catalogue grouped by category with search, filters and active toggles. */
export function EventList({ events, categories, sources, loading, onOpen, onToggle, toggling, onCreate }: Props) {
  const { can } = useAuth();
  const [q, setQ] = useState('');
  const [cat, setCat] = useState('');
  const [status, setStatus] = useState('');

  const groups = useMemo(() => {
    const term = q.trim().toLowerCase();
    const list = (events ?? []).filter((e) => {
      if (cat && e.category !== cat) return false;
      if (status === 'active' && !e.is_active) return false;
      if (status === 'inactive' && e.is_active) return false;
      if (status === 'no_template' && e.template_id) return false;
      if (status === 'scheduled' && !e.is_scheduled) return false;
      if (status === 'custom' && e.is_system) return false;
      if (status === 'failing' && !(e.failed_30d ?? 0)) return false;
      if (!term) return true;
      return [e.name, e.code, e.description ?? '', e.template_name ?? ''].some((s) => s.toLowerCase().includes(term));
    });
    const by: Record<string, WaEvent[]> = {};
    list.forEach((e) => (by[e.category] ??= []).push(e));
    return Object.keys(categories)
      .filter((k) => by[k]?.length)
      .map((k) => ({ key: k, label: categories[k], items: by[k] }));
  }, [events, q, cat, status, categories]);

  const canPublish = can('whatsapp_automation', 'publish');

  return (
    <div className="space-y-4">
      <Card className="p-3 sm:p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_200px_200px]">
          <SearchInput value={q} onChange={setQ} placeholder="Search events, codes or templates…" />
          <Select aria-label="Category" value={cat} onChange={(e) => setCat(e.target.value)} options={[{ value: '', label: 'All categories' }, ...Object.entries(categories).map(([value, label]) => ({ value, label }))]} />
          <Select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} options={STATUS_FILTERS} />
        </div>
      </Card>

      {loading && !events ? (
        <div className="space-y-4">
          {[0, 1].map((i) => (
            <Card key={i} className="p-4">
              <Skeleton className="mb-4 h-5 w-48" />
              {[0, 1, 2, 3].map((j) => (
                <Skeleton key={j} className="mb-3 h-14 w-full" />
              ))}
            </Card>
          ))}
        </div>
      ) : !groups.length ? (
        <Card>
          <EmptyState
            icon={q || cat || status ? Search : Zap}
            title={q || cat || status ? 'No events match these filters' : 'No events yet'}
            description={q || cat || status ? 'Try another search term or clear the filters.' : 'Run the WhatsApp event catalogue seeder, or create a custom event for an external system.'}
            action={
              q || cat || status ? (
                <Button variant="secondary" onClick={() => { setQ(''); setCat(''); setStatus(''); }}>Clear filters</Button>
              ) : can('whatsapp_automation', 'create') ? (
                <Button icon={Plus} onClick={onCreate}>New custom event</Button>
              ) : undefined
            }
          />
        </Card>
      ) : (
        groups.map((g, gi) => {
          const tone = categoryTone(g.key);
          const active = g.items.filter((e) => e.is_active).length;
          return (
            <Reveal key={g.key} delay={Math.min(gi, 4) * 40}>
              <Card className="overflow-hidden">
                <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                  <div className="flex min-w-0 items-center gap-2.5">
                    <span className={clsx('h-2.5 w-2.5 shrink-0 rounded-full', tone.dot)} aria-hidden />
                    <h3 className="truncate font-display text-sm font-semibold text-slate-900 dark:text-white">{g.label}</h3>
                    <span className="text-xs text-slate-400">{g.items.length}</span>
                  </div>
                  <span className="shrink-0 text-xs text-slate-500 dark:text-slate-400">
                    <span className="font-semibold text-emerald-600 dark:text-emerald-400">{active}</span> active
                  </span>
                </div>
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {g.items.map((e) => {
                    const tplLabel = e.template_display || e.template_name;
                    return (
                      <li key={e.id} className="group relative flex flex-col gap-3 px-4 py-3 transition hover:bg-slate-50/80 sm:flex-row sm:items-center dark:hover:bg-slate-800/40">
                        <button
                          type="button"
                          onClick={() => onOpen(e)}
                          className="flex min-w-0 flex-1 items-start gap-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 rounded-lg dark:focus-visible:ring-offset-slate-900"
                          aria-label={`Open ${e.name}`}
                        >
                          <span className={clsx('mt-1.5 h-2 w-2 shrink-0 rounded-full', e.is_active ? 'bg-emerald-500 shadow-[0_0_0_3px_rgba(16,185,129,0.15)]' : 'bg-slate-300 dark:bg-slate-600')} aria-hidden />
                          <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                              <span className="font-medium text-slate-900 dark:text-white">{e.name}</span>
                              {!e.is_system && <Badge color="purple">Custom</Badge>}
                              {e.is_scheduled && (
                                <span className="inline-flex items-center gap-1 text-[11px] font-medium text-amber-700 dark:text-amber-300">
                                  <CalendarClock className="h-3 w-3" aria-hidden /> Daily
                                </span>
                              )}
                            </span>
                            <span className="mt-0.5 block truncate font-mono text-[11px] text-slate-500 dark:text-slate-400">{e.code}</span>
                            <span className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                              {tplLabel ? (
                                <span className="inline-flex max-w-full items-center gap-1 truncate rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                  <LayoutTemplate className="h-3 w-3 shrink-0" aria-hidden />
                                  <span className="truncate">{tplLabel}</span>
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                  <CircleAlert className="h-3 w-3" aria-hidden /> {e.suggested_template ? 'Template available — link it' : 'No template'}
                                </span>
                              )}
                              {e.template_status && e.template_status !== 'approved' && <Badge color="amber">{e.template_status}</Badge>}
                              {e.is_active && !!e.workflow_overlaps?.length && (
                                <span
                                  className="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                                  title={`Also messaged by the active workflow${e.workflow_overlaps.length > 1 ? 's' : ''} ${e.workflow_overlaps.map((o) => `"${o.name}"`).join(', ')} — contacts get both messages`}
                                >
                                  <Workflow className="h-3 w-3" aria-hidden /> {e.workflow_overlaps.some((o) => o.same_template) ? 'Workflow sends the same template' : 'Workflow also messages'}
                                </span>
                              )}
                              <span className={clsx('rounded-md px-1.5 py-0.5 ring-1 ring-inset', categoryTone(e.category).chip)}>{sources[e.source] ?? e.source}</span>
                              <span className="text-slate-500 dark:text-slate-400">→ {RECIPIENT_LABEL[e.recipient] ?? e.recipient}</span>
                              {e.delay_minutes > 0 && <span className="text-slate-500 dark:text-slate-400">· after {e.delay_minutes} min</span>}
                            </span>
                          </span>
                        </button>
                        <div className="flex items-center justify-between gap-4 pl-5 sm:justify-end sm:pl-0">
                          <div className="text-left text-xs sm:w-40 sm:text-right">
                            <div className="text-slate-700 dark:text-slate-200">
                              {e.last_executed_at ? <>Last run {timeAgo(e.last_executed_at)}</> : <span className="text-slate-400">Never fired</span>}
                            </div>
                            <div className="mt-0.5 text-slate-500 dark:text-slate-400">
                              {formatNumber(e.execution_count)} total
                              {(e.messages_30d ?? 0) > 0 && <> · {formatNumber(e.messages_30d)} sent (30d)</>}
                              {(e.failed_30d ?? 0) > 0 && <span className="text-red-600 dark:text-red-400"> · {e.failed_30d} failed</span>}
                            </div>
                          </div>
                          <div className="flex items-center gap-2">
                            <span className={clsx(toggling === e.id && 'pointer-events-none opacity-60')} title={!canPublish ? 'Your role cannot activate events' : !e.template_id && !e.suggested_template ? 'Link a template first' : undefined}>
                              <Toggle
                                checked={e.is_active}
                                onChange={(v) => onToggle(e, v)}
                                disabled={!canPublish || toggling === e.id || (!e.is_active && !e.template_id && !e.suggested_template)}
                                id={`evt-active-${e.id}`}
                                label={<span className="sr-only">{e.is_active ? 'Deactivate' : 'Activate'} {e.name}</span>}
                              />
                            </span>
                            <ChevronRight className="hidden h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500 sm:block" aria-hidden />
                          </div>
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </Card>
            </Reveal>
          );
        })
      )}
    </div>
  );
}
