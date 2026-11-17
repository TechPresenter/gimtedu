import { useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  Ban, CalendarCheck, CircleAlert, CircleCheck, CircleDashed, Download, ExternalLink, Globe, Hourglass, Info, Pencil, Plus, Send, ShieldCheck, TriangleAlert,
} from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, SearchInput, Select, Skeleton, Stagger, StatTile, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError, downloadFile } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { useApi, useCrudList, useCrudMeta } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';

interface Hint { tone: 'ok' | 'info' | 'warn' | 'error'; text: string }
interface PresencePayload {
  counts: Record<string, number>;
  total: number;
  by_category: Record<string, Record<string, number>>;
  hints: Record<string, Hint[]>;
  categories: Record<string, string>;
  statuses: Record<string, string>;
  site_url: string;
  is_local: boolean;
  checked_at: string;
  can: { edit: boolean };
}
interface PresenceRow {
  id: number;
  item_key: string;
  category: string;
  title: string;
  platform_url: string | null;
  status: string;
  verification_status: string | null;
  connection_status: string | null;
  submission_status: string | null;
  method: string | null;
  profile_url: string | null;
  owner_role: string | null;
  evidence: string | null;
  error_details: string | null;
  next_action: string | null;
  notes: string | null;
  last_checked_at: string | null;
  last_submitted_at: string | null;
  next_review_at: string | null;
  [k: string]: unknown;
}

const STATUS_COLOR: Record<string, BadgeColor> = { not_started: 'slate', in_progress: 'amber', submitted: 'blue', verified: 'green', not_applicable: 'slate', error: 'red' };
const SUB_COLOR: Record<string, BadgeColor> = {
  not_verified: 'slate', pending: 'amber', verified: 'green', failed: 'red', not_needed: 'slate', not_connected: 'slate', connected: 'green', error: 'red',
  not_submitted: 'slate', submitted: 'blue', accepted: 'green',
};
const SUB_LABEL: Record<string, string> = {
  not_verified: 'Not verified', pending: 'Pending', verified: 'Verified', failed: 'Failed', not_needed: 'Not needed', not_connected: 'Not connected', connected: 'Connected',
  error: 'Error', not_submitted: 'Not submitted', submitted: 'Submitted', accepted: 'Accepted',
};
const HINT_ICON = { ok: CircleCheck, info: Info, warn: TriangleAlert, error: CircleAlert };
const HINT_TONE = { ok: 'text-emerald-600 dark:text-emerald-400', info: 'text-slate-400', warn: 'text-amber-600 dark:text-amber-400', error: 'text-red-600 dark:text-red-400' };

function platformHref(url: string | null): string | null {
  if (!url) return null;
  return url.startsWith('/') ? appUrl(url.slice(1)) : url;
}

export default function PresenceTab() {
  const toast = useToast();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canEdit = can('seo', 'edit');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('');
  const [attention, setAttention] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [editId, setEditId] = useState<number | null | undefined>(undefined);
  const [busy, setBusy] = useState<number | null>(null);
  const presence = useApi<PresencePayload>(['seo', 'presence'], 'seo-ai/presence');
  const meta = useCrudMeta('seo_presence');
  const list = useCrudList<PresenceRow>('seo_presence', { per_page: 100, q: dq || undefined, f: { category: category || undefined, status: status || undefined, attention: attention || undefined } });
  const d = presence.data;
  const rows = list.data?.rows ?? [];
  const groups = useMemo(() => {
    const cats = d?.categories ?? {};
    const out: { key: string; label: string; rows: PresenceRow[] }[] = [];
    Object.entries(cats).forEach(([key, label]) => {
      const r = rows.filter((x) => x.category === key);
      if (r.length) out.push({ key, label, rows: r });
    });
    const other = rows.filter((x) => !cats[x.category]);
    if (other.length) out.push({ key: 'other', label: 'Other', rows: other });
    return out;
  }, [rows, d?.categories]);

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['crud', 'seo_presence'] });
    void qc.invalidateQueries({ queryKey: ['seo', 'presence'] });
  };
  const markChecked = async (r: PresenceRow) => {
    setBusy(r.id);
    try {
      const res = await api.post(`seo-ai/presence/${r.id}/checked`);
      toast.success(res.message);
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const kpis = [
    { key: 'verified', label: 'Verified / live', icon: ShieldCheck, tone: 'green' as const },
    { key: 'submitted', label: 'Submitted', icon: Send, tone: 'blue' as const },
    { key: 'in_progress', label: 'In progress', icon: Hourglass, tone: 'amber' as const },
    { key: 'not_started', label: 'Not started', icon: CircleDashed, tone: 'slate' as const },
    { key: 'not_applicable', label: 'Not applicable', icon: Ban, tone: 'purple' as const },
  ];
  const filtered = !!(category || status || attention || dq);

  return (
    <div className="space-y-6">
      <Stagger className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" step={50}>
        {kpis.map((k) => (
          <StatTile key={k.key} label={k.label} value={d ? d.counts[k.key] ?? 0 : '—'} icon={k.icon} tone={k.tone} loading={presence.isLoading}
            sub={d ? (k.key === 'not_started' && d.counts.error ? `${d.counts.error} with problems` : `of ${d.total}`) : undefined} />
        ))}
      </Stagger>
      {presence.error && <Alert variant="error" title="Unable to load the automatic checks">{(presence.error as ApiError).message}</Alert>}
      {d?.is_local && (
        <Alert variant="info" title="Local copy">
          This copy of the website runs on a local address, so search engines and Google / Microsoft tools cannot reach it. Statuses are set by people after checking each platform for the public website; the automatic hints show what this copy has configured.
        </Alert>
      )}
      <Card className="overflow-hidden">
        <CardHeader icon={Globe} title="Platforms & submissions"
          subtitle={d ? `Statuses are set by people. Automatic hints come from this website’s own settings (checked ${formatDateTime(d.checked_at)}) and never mark anything verified.` : 'Search engines, tools, AI search, technical files, profiles and directories'}
          actions={
            <>
              <Button size="sm" variant="ghost" icon={Download} onClick={() => downloadFile('crud/seo_presence/export', { format: 'csv' })}>Export</Button>
              {canEdit && <Button size="sm" icon={Plus} onClick={() => setEditId(null)}>Add platform</Button>}
            </>
          } />
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search platforms, actions, notes…" className="w-full lg:max-w-xs" />
            <div className="flex flex-wrap gap-2 lg:ml-auto">
              <Select inputSize="sm" aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className="w-full sm:w-auto"
                options={[{ value: '', label: 'Any status' }, ...Object.entries(d?.statuses ?? {}).map(([value, label]) => ({ value, label }))]} />
              <Select inputSize="sm" aria-label="Attention" value={attention} onChange={(e) => setAttention(e.target.value)} className="w-full sm:w-auto"
                options={[{ value: '', label: 'Everything' }, { value: 'open', label: 'Still to do' }, { value: 'errors', label: 'With problems' }, { value: 'review_due', label: 'Review due' }, { value: 'never_checked', label: 'Never checked' }]} />
            </div>
          </div>
          <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" role="group" aria-label="Category">
            {[['', 'All'], ...Object.entries(d?.categories ?? {})].map(([key, label]) => {
              const counts = key ? d?.by_category[key] ?? {} : d?.counts ?? {};
              const total = Object.values(counts).reduce((a, b) => a + b, 0);
              const done = (counts.verified ?? 0) + (counts.not_applicable ?? 0);
              return (
                <button key={key || 'all'} type="button" aria-pressed={category === key} onClick={() => setCategory(key)}
                  className={clsx('shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition', category === key ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700')}>
                  {label}<span className="ml-1 opacity-70 tabular-nums">{done}/{total}</span>
                </button>
              );
            })}
          </div>
        </div>
        {list.error ? <div className="p-5"><Alert variant="error">{(list.error as ApiError).message}</Alert></div> : list.isLoading ? (
          <div className="space-y-3 p-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-16 w-full" />)}</div>
        ) : groups.length === 0 ? (
          <EmptyState icon={Globe} title={filtered ? 'No platforms match these filters' : 'The checklist is empty'}
            description={filtered ? 'Try clearing the filters.' : 'Run the SEO presence seeder or add a platform.'}
            action={filtered ? <Button variant="secondary" onClick={() => { setCategory(''); setStatus(''); setAttention(''); setQ(''); }}>Clear filters</Button> : canEdit ? <Button icon={Plus} onClick={() => setEditId(null)}>Add platform</Button> : undefined} />
        ) : (
          <div className={clsx('divide-y divide-slate-100 transition-opacity dark:divide-slate-800', list.isFetching && 'opacity-70')}>
            {groups.map((g) => {
              const done = g.rows.filter((r) => r.status === 'verified' || r.status === 'not_applicable').length;
              return (
                <section key={g.key} aria-label={g.label}>
                  <header className="sticky top-0 z-[1] flex items-center gap-3 bg-slate-50/95 px-4 py-2 backdrop-blur dark:bg-slate-800/80">
                    <h3 className="text-xs font-bold uppercase tracking-wider text-brand-800 dark:text-brand-200">{g.label}</h3>
                    <span className="h-1.5 w-24 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" aria-hidden>
                      <span className="block h-full rounded-full bg-emerald-500" style={{ width: `${(done / g.rows.length) * 100}%` }} />
                    </span>
                    <span className="text-[11px] text-slate-500 tabular-nums">{done} of {g.rows.length} done</span>
                  </header>
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {g.rows.map((r) => (
                      <PresenceItem key={r.id} r={r} hints={d?.hints[r.item_key] ?? []} statusLabel={d?.statuses[r.status] ?? r.status} canEdit={canEdit}
                        busy={busy === r.id} onEdit={() => setEditId(r.id)} onChecked={() => void markChecked(r)} />
                    ))}
                  </ul>
                </section>
              );
            })}
          </div>
        )}
      </Card>
      <CrudFormModal open={editId !== undefined} onClose={() => setEditId(undefined)} meta={meta.data} module="seo_presence" id={editId ?? null}
        defaults={category ? { category } : undefined} title={editId ? 'Edit platform' : 'Add platform'} onSaved={() => refresh()}
        intro={<p className="text-sm text-slate-500">Set “Verified / live” only after confirming it inside the platform itself.</p>} />
    </div>
  );
}

function PresenceItem({ r, hints, statusLabel, canEdit, busy, onEdit, onChecked }: { r: PresenceRow; hints: Hint[]; statusLabel: string; canEdit: boolean; busy: boolean; onEdit: () => void; onChecked: () => void }) {
  const platform = platformHref(r.platform_url);
  const subs = ([['Verification', r.verification_status], ['Connection', r.connection_status], ['Submission', r.submission_status]] as const).filter(([, v]) => v);
  return (
    <li className={clsx('grid grid-cols-1 gap-3 px-4 py-3.5 md:grid-cols-[minmax(0,1.1fr)_minmax(0,0.8fr)_minmax(0,1.5fr)_auto] md:gap-4', r.status === 'not_applicable' && 'opacity-70')}>
      <div className="min-w-0">
        <div className="flex items-start gap-1.5">
          <button type="button" onClick={onEdit} className="text-left text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.title}</button>
          {platform && (
            <a href={platform} target="_blank" rel="noreferrer noopener" className="mt-0.5 shrink-0 text-slate-400 hover:text-brand-700 dark:hover:text-brand-300" aria-label={`Open ${r.title}`} title={r.platform_url ?? ''}>
              <ExternalLink className="h-3.5 w-3.5" />
            </a>
          )}
        </div>
        {r.method && <p className="text-xs text-slate-500">{r.method}</p>}
        {r.profile_url && (
          <a href={r.profile_url} target="_blank" rel="noreferrer noopener" className="mt-0.5 inline-flex max-w-full items-center gap-1 text-xs text-brand-700 hover:underline dark:text-brand-300">
            <span className="truncate">{r.profile_url.replace(/^https?:\/\//, '')}</span>
          </a>
        )}
      </div>
      <div className="min-w-0 space-y-1">
        <Badge color={STATUS_COLOR[r.status] ?? 'slate'} dot>{statusLabel}</Badge>
        {subs.length > 0 && (
          <div className="flex flex-wrap gap-1">
            {subs.map(([label, v]) => <Badge key={label} color={SUB_COLOR[v as string] ?? 'slate'} className="!text-[10px]">{label}: {SUB_LABEL[v as string] ?? v}</Badge>)}
          </div>
        )}
        <p className="text-[11px] text-slate-500">
          {r.last_checked_at ? `Checked ${formatDate(r.last_checked_at)}` : 'Never checked'}
          {r.last_submitted_at && ` · Submitted ${formatDate(r.last_submitted_at)}`}
          {r.next_review_at && ` · Review ${formatDate(r.next_review_at)}`}
        </p>
      </div>
      <div className="min-w-0 space-y-1.5">
        {r.error_details && <p className="flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400"><CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />{r.error_details}</p>}
        {r.next_action && <p className="text-xs leading-relaxed text-slate-700 dark:text-slate-200"><span className="font-semibold">Next: </span>{r.next_action}</p>}
        {r.notes && <p className="line-clamp-2 text-xs text-slate-500" title={r.notes}>{r.notes}</p>}
        {hints.length > 0 && (
          <ul className="space-y-1 rounded-lg bg-slate-50 px-2.5 py-2 dark:bg-slate-800/50" aria-label="Automatic checks">
            {hints.map((h, i) => {
              const Icon = HINT_ICON[h.tone];
              return (
                <li key={i} className="flex items-start gap-1.5 text-[11px] leading-snug text-slate-600 dark:text-slate-300">
                  <Icon className={clsx('mt-px h-3.5 w-3.5 shrink-0', HINT_TONE[h.tone])} aria-hidden />
                  <span className="min-w-0 break-words">{h.text}</span>
                </li>
              );
            })}
          </ul>
        )}
      </div>
      {canEdit && (
        <div className="flex items-start gap-1.5 md:flex-col md:items-end">
          <Button size="xs" variant="secondary" icon={Pencil} onClick={onEdit}>Update</Button>
          <Button size="xs" variant="ghost" icon={CalendarCheck} loading={busy} onClick={onChecked} title="Set “last checked” to today">Checked today</Button>
        </div>
      )}
    </li>
  );
}
