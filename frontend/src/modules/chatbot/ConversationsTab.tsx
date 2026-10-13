import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Download, Eye, FileSpreadsheet, MessagesSquare, RotateCcw, ThumbsDown, ThumbsUp, Trash2 } from 'lucide-react';
import {
  Alert, Badge, Button, Card, DataTable, Dropdown, EmptyState, Input, Pagination, SearchInput, Select, StatusBadge, useConfirm, useToast, type Column,
} from '@/components/ui';
import { api, ApiError, downloadFile } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { useApi } from '@/lib/queries';
import { formatDateTime, timeAgo } from '@/lib/format';
import { LANG_LABELS, LEAD_LABELS, type ConversationList, type ConversationRow } from './types';
import { ProviderBadge } from './shared';
import { TranscriptDrawer } from './TranscriptDrawer';

const LEAD_COLORS = { none: 'slate', offered: 'amber', captured: 'green' } as const;
const FILTER_KEYS = ['q', 'lead', 'feedback', 'provider', 'language', 'from', 'to'] as const;

/** Conversation list (search, filters, sort, pagination, export, delete) + transcript drawer (?view=id). */
export default function ConversationsTab() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('q') ?? '');
  const q = useDebounce(search, 350);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const page = Math.max(1, Number(params.get('page')) || 1);
  const perPage = Number(params.get('per_page')) || 25;
  const sort = params.get('sort') ?? 'started_at';
  const dir = (params.get('dir') ?? 'desc') as 'asc' | 'desc';
  const viewId = Number(params.get('view')) || null;

  const setParam = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
    if (!('page' in patch) && !('view' in patch)) next.delete('page');
    setParams(next, { replace: true });
  };

  useEffect(() => {
    if ((params.get('q') ?? '') !== q) setParam({ q: q || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const query = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? undefined]));
  const { data, isLoading, isFetching, error } = useApi<ConversationList>(['chatbot', 'conversations', query, page, perPage, sort, dir], 'chatbot/conversations', { ...query, page, per_page: perPage, sort, dir });
  const filtered = FILTER_KEYS.some((k) => params.get(k));

  const refresh = () => qc.invalidateQueries({ queryKey: ['chatbot'] });

  const remove = async (id: number) => {
    const ok = await confirm({ title: 'Delete this conversation?', message: 'The transcript is deleted permanently. A captured lead stays in Enquiries.', confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`chatbot/conversations/${id}`);
      toast.success(res.message || 'Conversation deleted.');
      if (viewId === id) setParam({ view: null });
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const removeMany = async () => {
    const ids = [...selected].map(Number);
    const ok = await confirm({ title: `Delete ${ids.length} conversations?`, message: 'The selected transcripts are deleted permanently.', confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.post('chatbot/conversations/delete', { ids });
      toast.success(res.message);
      setSelected(new Set());
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const exportAs = (format: 'csv' | 'xlsx') => downloadFile('chatbot/conversations/export', { ...query, format });

  const columns: Column<ConversationRow>[] = [
    {
      key: 'started_at', header: 'Started', sortable: true, width: '9rem',
      render: (r) => (
        <div className="leading-tight">
          <span className="block whitespace-nowrap text-sm text-slate-800 dark:text-slate-100">{formatDateTime(r.started_at)}</span>
          <span className="text-xs text-slate-500">{r.last_message_at ? `last ${timeAgo(r.last_message_at)}` : ''}</span>
        </div>
      ),
    },
    {
      key: 'title', header: 'First question',
      render: (r) => (
        <button type="button" onClick={() => setParam({ view: String(r.id) })} className="block max-w-[22rem] text-left">
          <span className="line-clamp-2 font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.title || '(no question)'}</span>
          <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            {r.language && r.language !== 'en' && <Badge color="cyan">{LANG_LABELS[r.language] ?? r.language}</Badge>}
            {r.source === 'demo' && <Badge color="slate">demo</Badge>}
            <span className="truncate">{r.page_url || ''}</span>
          </span>
        </button>
      ),
    },
    { key: 'message_count', header: 'Questions', sortable: true, align: 'center', render: (r) => <span className="tabular-nums">{r.user_message_count}</span> },
    {
      key: 'lead_status', header: 'Lead', sortable: true,
      render: (r) => (
        <div className="leading-tight">
          <StatusBadge status={r.lead_status} label={LEAD_LABELS[r.lead_status]} colors={LEAD_COLORS} />
          {r.lead_name && <span className="mt-0.5 block text-xs text-slate-600 dark:text-slate-300">{r.lead_name}</span>}
          {r.enquiry_id && (
            <Link to={`/enquiries?q=${encodeURIComponent(r.lead_name ?? '')}`} className="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
              Enquiry #{r.enquiry_id}
            </Link>
          )}
        </div>
      ),
    },
    {
      key: 'feedback', header: 'Feedback', sortable: true,
      render: (r) =>
        r.feedback_up + r.feedback_down === 0 ? (
          <span className="text-slate-400">—</span>
        ) : (
          <span className="inline-flex items-center gap-2 text-sm tabular-nums">
            <span className="inline-flex items-center gap-0.5 text-emerald-700 dark:text-emerald-400"><ThumbsUp className="h-3.5 w-3.5" />{r.feedback_up}</span>
            <span className="inline-flex items-center gap-0.5 text-red-700 dark:text-red-400"><ThumbsDown className="h-3.5 w-3.5" />{r.feedback_down}</span>
          </span>
        ),
    },
    { key: 'last_provider', header: 'Answered by', render: (r) => <ProviderBadge provider={r.last_provider} /> },
  ];

  return (
    <>
      <Card>
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
          <div className="flex flex-wrap items-center gap-2">
            <SearchInput value={search} onChange={setSearch} placeholder="Search questions, answers, names, pages…" className="min-w-[14rem] flex-1" />
            <div className="flex flex-wrap gap-2">
              {selected.size > 0 && can('communication', 'delete') && (
                <Button variant="danger" size="sm" icon={Trash2} onClick={() => void removeMany()}>
                  Delete {selected.size}
                </Button>
              )}
              {can('communication', 'export') && (
                <Dropdown
                  label="Export conversations"
                  triggerClassName="btn btn-secondary btn-sm"
                  trigger={<><Download className="h-4 w-4" />Export</>}
                  items={[
                    { label: 'CSV (with transcripts)', icon: Download, onClick: () => exportAs('csv') },
                    { label: 'Excel (.xlsx)', icon: FileSpreadsheet, onClick: () => exportAs('xlsx') },
                  ]}
                />
              )}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <Select inputSize="sm" aria-label="Lead status" value={params.get('lead') ?? ''} onChange={(e) => setParam({ lead: e.target.value || null })} options={[{ value: '', label: 'All leads' }, { value: 'captured', label: 'Lead captured' }, { value: 'offered', label: 'Callback offered' }, { value: 'none', label: 'No lead' }]} />
            <Select inputSize="sm" aria-label="Feedback" value={params.get('feedback') ?? ''} onChange={(e) => setParam({ feedback: e.target.value || null })} options={[{ value: '', label: 'Any feedback' }, { value: 'up', label: 'Has 👍 helpful' }, { value: 'down', label: 'Has 👎 not helpful' }, { value: 'any', label: 'Rated' }]} />
            <Select inputSize="sm" aria-label="Provider" value={params.get('provider') ?? ''} onChange={(e) => setParam({ provider: e.target.value || null })} options={[{ value: '', label: 'All providers' }, { value: 'anthropic', label: 'Claude' }, { value: 'openai', label: 'OpenAI' }, { value: 'gemini', label: 'Gemini' }, { value: 'offline', label: 'Rule-based' }]} />
            <Select inputSize="sm" aria-label="Language" value={params.get('language') ?? ''} onChange={(e) => setParam({ language: e.target.value || null })} options={[{ value: '', label: 'All languages' }, { value: 'en', label: 'English' }, { value: 'hi', label: 'Hindi' }, { value: 'hinglish', label: 'Hinglish' }]} />
            <Input inputSize="sm" type="date" aria-label="From date" value={params.get('from') ?? ''} onChange={(e) => setParam({ from: e.target.value || null })} />
            <Input inputSize="sm" type="date" aria-label="To date" value={params.get('to') ?? ''} onChange={(e) => setParam({ to: e.target.value || null })} />
          </div>
          {filtered && (
            <div>
              <Button size="xs" variant="ghost" icon={RotateCcw} onClick={() => { setSearch(''); setParams(new URLSearchParams({ tab: 'conversations' }), { replace: true }); }}>
                Clear filters
              </Button>
            </div>
          )}
        </div>
        {error ? (
          <div className="p-4"><Alert variant="error" title="Unable to load conversations">{(error as ApiError).message}</Alert></div>
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={data?.items ?? []}
              loading={isLoading || (isFetching && !data)}
              sort={{ key: sort, dir }}
              onSort={(s) => setParam({ sort: s.key, dir: s.dir })}
              selectable={can('communication', 'delete')}
              selected={selected}
              onSelectedChange={setSelected}
              caption="AI chatbot conversations"
              empty={
                <EmptyState
                  icon={MessagesSquare}
                  title={filtered ? 'No conversations match these filters' : 'No conversations yet'}
                  description={filtered ? 'Try clearing the filters.' : 'Conversations appear here as soon as visitors chat with the assistant on the website.'}
                  action={filtered ? <Button size="sm" variant="secondary" onClick={() => setParams(new URLSearchParams({ tab: 'conversations' }), { replace: true })}>Clear filters</Button> : <Button size="sm" variant="secondary" href="/" target="_blank">Open the website</Button>}
                />
              }
              actions={(r) => (
                <div className="flex justify-end gap-1">
                  <button type="button" onClick={() => setParam({ view: String(r.id) })} className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-700 dark:hover:bg-slate-800" aria-label={`View conversation ${r.id}`}>
                    <Eye className="h-4 w-4" />
                  </button>
                  {can('communication', 'delete') && (
                    <button type="button" onClick={() => void remove(r.id)} className="rounded-lg p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label={`Delete conversation ${r.id}`}>
                      <Trash2 className="h-4 w-4" />
                    </button>
                  )}
                </div>
              )}
            />
            {data && data.total > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3 text-sm text-slate-500 dark:border-slate-800">
                <span className="hidden sm:inline">{data.items.filter((r) => r.source === 'demo').length ? 'Rows marked “demo” are sample data.' : ''}</span>
                <Pagination page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={(p) => setParam({ page: String(p) })} onPerPage={(n) => setParam({ per_page: String(n), page: null })} />
              </div>
            )}
          </>
        )}
      </Card>
      <TranscriptDrawer id={viewId} onClose={() => setParam({ view: null })} onDelete={can('communication', 'delete') ? (id) => void remove(id) : undefined} />
    </>
  );
}
