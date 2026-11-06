import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  BookmarkPlus, Columns3, ContactRound, Download, FileSpreadsheet, Filter, Layers, MessageCircleMore, MoreHorizontal, Pencil, RefreshCw, Send, ShieldCheck,
  ShieldOff, Tag, Tags, Trash2, Upload, UserCheck, UserPlus, Users, UsersRound, X, Eye, GitMerge, ArrowRightLeft, Clock3,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, Combobox, DataTable, Drawer, Dropdown, EmptyState, Field, IconButton, Input, PageHeader, Pagination, SearchInput,
  Select, Stagger, StatCard, Tabs, useConfirm, useToast, type Column, type SortState,
} from '@/components/ui';
import { api, downloadFile, type Query } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { crmKeys, joinList, showPhone, splitList, useCrmRefresh, useFacets, TYPE_LABELS, type ContactsSummary, type Paged, type Segment, type WaContact } from './crm';
import {
  AssignDialog, ContactFormModal, DialogShell, LastMessage, OptDialog, OptInBadge, PaymentBadge, SendTemplateModal, StageBadge, StageDialog, TagChips, TagsDialog, TypeBadge,
  WindowBadge, fieldErrors,
} from './crmUi';
import { ImportWizard } from './ImportWizard';
import { SegmentsPanel } from './SegmentsPanel';
import { DuplicatesPanel } from './DuplicatesPanel';

const CRUMBS = [{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Student Contacts' }];

/** URL filter keys (comma lists for multi-value filters). */
const FILTER_KEYS = ['scope', 'type', 'stage', 'program_id', 'counsellor_id', 'tag', 'tag_mode', 'opt_in', 'window', 'payment_status', 'admission_status', 'city', 'state', 'batch', 'lead_source', 'source', 'last_from', 'last_to', 'created_from', 'created_to', 'status', 'has_followup', 'segment'] as const;
const MULTI = new Set(['type', 'stage', 'program_id', 'counsellor_id', 'tag', 'payment_status', 'admission_status', 'city', 'state', 'batch', 'lead_source', 'source', 'status']);

const COLUMNS: { key: string; label: string; default: boolean }[] = [
  { key: 'contact_type', label: 'Type', default: false },
  { key: 'stage', label: 'Stage', default: true },
  { key: 'program_name', label: 'Programme', default: true },
  { key: 'counsellor_name', label: 'Counsellor', default: true },
  { key: 'tags', label: 'Tags', default: true },
  { key: 'opt_in', label: 'Opt-in', default: false },
  { key: 'window', label: '24 h window', default: false },
  { key: 'last_message_at', label: 'Last message', default: true },
  { key: 'next_followup_at', label: 'Next follow-up', default: false },
  { key: 'city', label: 'City', default: false },
  { key: 'state', label: 'State', default: false },
  { key: 'batch', label: 'Batch', default: false },
  { key: 'payment_status', label: 'Payment', default: false },
  { key: 'admission_status', label: 'Admission status', default: false },
  { key: 'lead_source', label: 'Lead source', default: false },
  { key: 'created_at', label: 'Added', default: false },
];

type BulkAction = 'tag' | 'untag' | 'assign' | 'stage' | 'opt_in' | 'opt_out' | 'delete' | null;

export default function ContactsPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCrmRefresh();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'contacts';
  const summary = useQuery({ queryKey: crmKeys.summary, queryFn: ({ signal }) => api.get<ContactsSummary>('whatsapp/contacts/summary', undefined, { signal }) });

  const [showAdd, setShowAdd] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [syncing, setSyncing] = useState(false);

  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    if (t === 'contacts') next.delete('tab');
    else next.set('tab', t);
    setParams(next, { replace: true });
  };

  const sync = async () => {
    if (!(await confirm({ title: 'Sync from the admission portal?', message: 'Contacts are created or refreshed from enquiries, applications, students and their guardians. Manual edits (names, counsellors, manual stages) are kept.', confirmText: 'Sync now' }))) return;
    setSyncing(true);
    try {
      const r = await api.post<{ created: number; updated: number }>('whatsapp/contacts/sync', { scope: 'all' });
      toast.success(r.message);
      refresh();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setSyncing(false);
    }
  };

  const s = summary.data;
  return (
    <>
      <PageHeader
        title="Student Contacts"
        description="One WhatsApp contact per number — leads, applicants, students and parents synced from the admission portal."
        breadcrumbs={CRUMBS}
        actions={
          <>
            {can('whatsapp_contacts', 'import') && (
              <Button variant="secondary" icon={RefreshCw} loading={syncing} onClick={sync}>
                Sync from portal
              </Button>
            )}
            {can('whatsapp_contacts', 'import') && (
              <Button variant="secondary" icon={Upload} onClick={() => setShowImport(true)}>
                Import
              </Button>
            )}
            {can('whatsapp_contacts', 'create') && (
              <Button icon={UserPlus} onClick={() => setShowAdd(true)}>
                Add contact
              </Button>
            )}
          </>
        }
      />
      {summary.error ? (
        <Alert variant="error" title="Could not load the contact summary" className="mb-6" action={<Button size="sm" variant="secondary" onClick={() => summary.refetch()}>Retry</Button>}>
          {(summary.error as Error).message}
        </Alert>
      ) : (
        <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
          {[
            <StatCard key="t" label="Total contacts" icon={ContactRound} tone="navy" loading={summary.isLoading} value={s?.total ?? 0} hint={s ? `${formatNumber(s.students)} students` : undefined} />,
            <StatCard key="o" label="Opted in" icon={ShieldCheck} tone="green" loading={summary.isLoading} value={s?.opted_in ?? 0} hint={s && s.total ? `${Math.round((s.opted_in * 100) / s.total)}% of contacts` : undefined} />,
            <StatCard key="w" label="24 h window open" icon={MessageCircleMore} tone="cyan" loading={summary.isLoading} value={s?.window_open ?? 0} hint="Free-form replies OK" />,
            <StatCard key="l" label="Leads & applicants" icon={UsersRound} tone="orange" loading={summary.isLoading} value={s?.leads ?? 0} to="/whatsapp/leads" />,
            <StatCard key="u" label="Unassigned leads" icon={UserCheck} tone="amber" loading={summary.isLoading} value={s?.unassigned_leads ?? 0} hint="No counsellor yet" to="/whatsapp/contacts?type=lead,applicant&counsellor_id=none" />,
            <StatCard key="n" label="New this week" icon={UserPlus} tone="purple" loading={summary.isLoading} value={s?.new_7d ?? 0} hint={s?.last_sync_at ? `Last sync ${timeAgo(s.last_sync_at)}` : 'Not synced yet'} />,
          ]}
        </Stagger>
      )}
      <Tabs
        variant="pills"
        className="mb-4"
        value={tab}
        onChange={setTab}
        tabs={[
          { key: 'contacts', label: 'Contacts', icon: Users, count: s ? formatNumber(s.total) : undefined },
          { key: 'segments', label: 'Segments', icon: Layers },
          { key: 'duplicates', label: 'Duplicates', icon: GitMerge },
        ]}
      />
      {tab === 'segments' ? (
        <SegmentsPanel
          onApply={(filters) => {
            const next = new URLSearchParams();
            Object.entries(filters).forEach(([k, v]) => {
              const val = Array.isArray(v) ? joinList(v) : String(v);
              if (val) next.set(k, val);
            });
            setParams(next);
          }}
        />
      ) : tab === 'duplicates' ? (
        <DuplicatesPanel />
      ) : (
        <ContactsTable />
      )}
      <ContactFormModal open={showAdd} onClose={() => setShowAdd(false)} onSaved={(id) => navigate(`/whatsapp/contacts/${id}`)} />
      <ImportWizard open={showImport} onClose={() => setShowImport(false)} />
    </>
  );
}

/* ================================================================================================ list */

function ContactsTable() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCrmRefresh();
  const [params, setParams] = useSearchParams();
  const { data: facets } = useFacets();
  const segments = useQuery({ queryKey: crmKeys.segments, queryFn: ({ signal }) => api.get<Segment[]>('whatsapp/contacts/segments', undefined, { signal }), enabled: !!params.get('segment'), staleTime: 60_000 });
  const canExport = can('whatsapp_contacts', 'export') && facets?.can_export !== false;
  const [q, setQ] = useState(params.get('q') ?? '');
  const debouncedQ = useDebounce(q, 350);
  const [cols, setCols] = useLocalStorage<string[]>('wa-contacts-columns', COLUMNS.filter((c) => c.default).map((c) => c.key));
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [allMatching, setAllMatching] = useState(false);
  const [bulk, setBulk] = useState<BulkAction>(null);
  const [showFilters, setShowFilters] = useState(false);
  const [showSegment, setShowSegment] = useState(false);
  const [editing, setEditing] = useState<WaContact | null>(null);
  const [templateFor, setTemplateFor] = useState<WaContact | null>(null);
  const [rowAction, setRowAction] = useState<{ type: 'stage' | 'assign' | 'opt_in' | 'opt_out'; contact: WaContact } | null>(null);

  const page = Number(params.get('page') ?? 1) || 1;
  const perPage = Number(params.get('per_page') ?? 25) || 25;
  const sort: SortState = { key: params.get('sort') ?? 'last_message_at', dir: (params.get('dir') as 'asc' | 'desc') ?? 'desc' };

  const update = (patch: Record<string, string | null>, resetPage = true) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, v)));
    if (resetPage) next.delete('page');
    setParams(next, { replace: true });
  };
  useEffect(() => {
    if ((params.get('q') ?? '') !== debouncedQ) update({ q: debouncedQ || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedQ]);

  const filters = useMemo(() => {
    const f: Record<string, string> = {};
    FILTER_KEYS.forEach((k) => {
      const v = params.get(k);
      if (v) f[k] = v;
    });
    const qq = params.get('q');
    if (qq) f.q = qq;
    return f;
  }, [params]);
  const query: Query = { ...filters, page, per_page: perPage, sort: sort.key, dir: sort.dir };
  const list = useQuery({
    queryKey: crmKeys.list(query),
    queryFn: ({ signal }) => api.get<Paged<WaContact>>('whatsapp/contacts', query, { signal }),
    placeholderData: keepPreviousData,
  });
  useEffect(() => {
    setSelected(new Set());
    setAllMatching(false);
  }, [JSON.stringify(filters)]); // eslint-disable-line react-hooks/exhaustive-deps

  const rows = list.data?.data ?? [];
  const total = list.data?.meta.total ?? 0;
  const activeFilterCount = FILTER_KEYS.filter((k) => k !== 'tag_mode' && params.get(k)).length;
  const selectedCount = allMatching ? total : selected.size;
  const pageAllSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));

  const bulkPayload = () => (allMatching ? { all: 1, filters: Object.fromEntries(Object.entries(filters).map(([k, v]) => [k, MULTI.has(k) ? splitList(v) : v])) } : { ids: [...selected] });
  const runBulk = async (action: string, extra: Record<string, unknown> = {}) => {
    const r = await api.post<{ affected: number }>('whatsapp/contacts/bulk', { action, ...bulkPayload(), ...extra });
    toast.success(r.message);
    setSelected(new Set());
    setAllMatching(false);
    refresh();
  };
  const exportFile = (format: 'csv' | 'xlsx', onlySelected = false) => {
    const qy: Query = onlySelected && !allMatching ? { format, ids: [...selected].map(Number), sort: sort.key, dir: sort.dir } : { ...filters, format, sort: sort.key, dir: sort.dir };
    downloadFile('whatsapp/contacts/export', qy);
  };
  const removeContact = async (c: WaContact) => {
    if (!(await confirm({ title: `Delete ${c.name || showPhone(c.phone)}?`, message: 'The contact, its conversation, notes and follow-ups are deleted permanently. ERP records are not touched.', confirmText: 'Delete', danger: true }))) return;
    try {
      const r = await api.del(`whatsapp/contacts/${c.id}`);
      toast.success(r.message);
      refresh();
    } catch (e) {
      toast.error((e as Error).message);
    }
  };

  const optList = (list?: { value: string | number; label: string }[]) => (list ?? []).map((o) => ({ value: String(o.value), label: o.label }));
  const visible = new Set(cols);
  const columnDefs: Column<WaContact>[] = [
    {
      key: 'name',
      header: 'Contact',
      sortable: true,
      // PersonCell layout with a bounded width (the default columns fit a 1440 px screen without sliding under the pinned
      // Actions column): the unread badge follows the name, the number never truncates, a long type label does
      render: (r) => (
        <div className="flex min-w-0 max-w-[15rem] items-center gap-3">
          <Avatar name={r.name || showPhone(r.phone)} />
          <div className="min-w-0 leading-tight">
            <Link to={`/whatsapp/contacts/${r.id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
              {r.name || showPhone(r.phone)}
            </Link>
            {!!r.unread_count && <Badge color="green" className="ml-1.5 !px-1.5 !py-0 align-middle !text-[10px]">{r.unread_count} new</Badge>}
            <div className="flex min-w-0 items-center gap-1.5 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
              <span className="shrink-0">{showPhone(r.phone)}</span>
              {!visible.has('contact_type') && (
                <span className="min-w-0 truncate text-slate-400" title={TYPE_LABELS[r.contact_type] ?? r.contact_type}>
                  · {r.contact_type === 'guardian' ? 'Parent' : TYPE_LABELS[r.contact_type] ?? r.contact_type}
                </span>
              )}
              {!visible.has('opt_in') && (r.opt_in ? <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-label="Opted in" /> : r.opted_out_at ? <ShieldOff className="h-3.5 w-3.5 shrink-0 text-red-500" aria-label="Opted out" /> : null)}
            </div>
          </div>
        </div>
      ),
    },
    { key: 'contact_type', header: 'Type', sortable: true, hidden: !visible.has('contact_type'), render: (r) => <TypeBadge type={r.contact_type} /> },
    { key: 'stage', header: 'Stage', sortable: true, hidden: !visible.has('stage'), render: (r) => <StageBadge stage={r.stage} facets={facets} /> },
    {
      key: 'program_name',
      header: 'Programme',
      sortable: true,
      hidden: !visible.has('program_name'),
      render: (r) => (r.program_name ? <span className="block max-w-[6.5rem] truncate text-sm" title={r.program_name}>{r.program_code || r.program_name}{r.batch && <span className="block truncate text-xs text-slate-500">{r.batch}</span>}</span> : <span className="text-slate-400">—</span>),
    },
    { key: 'counsellor_name', header: 'Counsellor', sortable: true, hidden: !visible.has('counsellor_name'), render: (r) => (r.counsellor_name ? <span className="block max-w-[8rem] truncate text-sm" title={r.counsellor_name}>{r.counsellor_name}</span> : <span className="text-xs text-slate-400">Unassigned</span>) },
    { key: 'tags', header: 'Tags', hidden: !visible.has('tags'), render: (r) => <TagChips tags={r.tags} max={2} className="max-w-[9rem]" /> },
    { key: 'opt_in', header: 'Opt-in', sortable: true, hidden: !visible.has('opt_in'), render: (r) => <OptInBadge contact={r} compact /> },
    { key: 'window', header: 'Window', hidden: !visible.has('window'), render: (r) => <WindowBadge open={r.window_open} expires={r.window_expires_at} lastInbound={r.last_inbound_at} /> },
    // the header may wrap onto two lines when space is short ("!": the table's `thead th` nowrap rule is more specific)
    { key: 'last_message_at', header: <span className="block text-left">Last message</span>, sortable: true, hidden: !visible.has('last_message_at'), headerClassName: '!whitespace-normal leading-tight', render: (r) => <LastMessage at={r.last_message_at} /> },
    {
      key: 'next_followup_at',
      header: 'Next follow-up',
      hidden: !visible.has('next_followup_at'),
      render: (r) => (r.next_followup_at ? <span className={clsx('whitespace-nowrap text-xs', new Date(r.next_followup_at.replace(' ', 'T')) < new Date() ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300')}>{formatDateTime(r.next_followup_at)}</span> : <span className="text-slate-400">—</span>),
    },
    { key: 'city', header: 'City', sortable: true, hidden: !visible.has('city') },
    { key: 'state', header: 'State', sortable: true, hidden: !visible.has('state') },
    { key: 'batch', header: 'Batch', sortable: true, hidden: !visible.has('batch') },
    { key: 'payment_status', header: 'Payment', sortable: true, hidden: !visible.has('payment_status'), render: (r) => <PaymentBadge status={r.payment_status} /> },
    { key: 'admission_status', header: 'Admission status', sortable: true, hidden: !visible.has('admission_status'), render: (r) => (r.admission_status ? <span className="text-xs capitalize">{r.admission_status.replace(/_/g, ' ')}</span> : <span className="text-slate-400">—</span>) },
    { key: 'lead_source', header: 'Lead source', hidden: !visible.has('lead_source'), render: (r) => (r.lead_source ? <span className="text-xs capitalize">{r.lead_source.replace(/_/g, ' ')}</span> : <span className="text-slate-400">—</span>) },
    { key: 'created_at', header: 'Added', sortable: true, hidden: !visible.has('created_at'), render: (r) => <span className="whitespace-nowrap text-xs">{timeAgo(r.created_at)}</span> },
  ];
  // slightly tighter cells than the table default, so more columns fit before the table has to scroll
  const columns = columnDefs.map((c) => ({ ...c, className: clsx('!px-3', c.className), headerClassName: clsx('!px-3', c.headerClassName) }));

  const chips: { key: string; label: string }[] = [];
  const lookup = (list: { value: string | number; label: string }[] | undefined, v: string) => list?.find((o) => String(o.value) === v)?.label ?? v;
  FILTER_KEYS.forEach((k) => {
    const v = params.get(k);
    if (!v || k === 'tag_mode') return;
    const vals = MULTI.has(k) || k === 'tag' ? splitList(v) : [v];
    const names = vals.map((x) => {
      switch (k) {
        case 'type': return TYPE_LABELS[x] ?? x;
        case 'stage': return lookup(facets?.stages, x);
        case 'program_id': return lookup(facets?.programs, x);
        case 'counsellor_id': return x === 'none' ? 'Unassigned' : x === 'me' ? 'Me' : lookup(facets?.counsellors, x);
        case 'tag': return lookup(facets?.tags, x);
        case 'opt_in': return x === '1' ? 'Opted in' : 'Not opted in';
        case 'window': return x === 'open' ? 'Window open' : 'Window closed';
        case 'has_followup': return x === '1' ? 'Has follow-up' : 'No follow-up';
        case 'segment': return segments.data?.find((sg) => String(sg.id) === x)?.name ?? `#${x}`;
        case 'scope': return x === 'leads' ? 'Admission leads' : x;
        default: return x.replace(/_/g, ' ');
      }
    });
    const label = { scope: 'Scope', last_from: 'Last message from', last_to: 'Last message to', created_from: 'Added from', created_to: 'Added to', payment_status: 'Payment', admission_status: 'Admission', lead_source: 'Source', counsellor_id: 'Counsellor', program_id: 'Programme', has_followup: 'Follow-up', opt_in: 'Opt-in', type: 'Type', stage: 'Stage', tag: 'Tags', city: 'City', state: 'State', batch: 'Batch', window: 'Window', status: 'Status', segment: 'Segment', source: 'Added via' }[k] ?? k;
    chips.push({ key: k, label: `${label}: ${names.join(', ')}` });
  });

  return (
    <>
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800 lg:flex-row lg:items-center">
          <SearchInput value={q} onChange={setQ} placeholder="Search name, phone, email or application no…" className="lg:w-80" />
          <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
            <Select inputSize="sm" aria-label="Contact type" className="sm:!w-40" value={splitList(params.get('type')).length === 1 ? params.get('type')! : ''} onChange={(e) => update({ type: e.target.value || null })} options={TYPE_LABELS} placeholder="All types" />
            <Select inputSize="sm" aria-label="Stage" className="sm:!w-48" value={splitList(params.get('stage')).length === 1 ? params.get('stage')! : ''} onChange={(e) => update({ stage: e.target.value || null })} options={optList(facets?.stages)} placeholder="All stages" />
            <Select inputSize="sm" aria-label="Opt-in" className="sm:!w-36" value={params.get('opt_in') ?? ''} onChange={(e) => update({ opt_in: e.target.value || null })} options={{ '1': 'Opted in', '0': 'Not opted in' }} placeholder="Any opt-in" />
            <Button size="sm" variant={activeFilterCount ? 'soft' : 'secondary'} icon={Filter} onClick={() => setShowFilters(true)}>
              Filters{activeFilterCount ? ` (${activeFilterCount})` : ''}
            </Button>
          </div>
          <div className="flex items-center gap-2 lg:ml-auto">
            <ColumnChooser cols={cols} setCols={setCols} />
            {activeFilterCount > 0 && can('whatsapp_contacts', 'edit') && (
              <Button size="sm" variant="ghost" icon={BookmarkPlus} onClick={() => setShowSegment(true)}>
                Save segment
              </Button>
            )}
            {canExport && (
              <Dropdown
                label="Export contacts"
                trigger={<><Download className="h-4 w-4" /> Export</>}
                items={[
                  { label: 'CSV (current filters)', icon: FileSpreadsheet, onClick: () => exportFile('csv') },
                  { label: 'Excel (current filters)', icon: FileSpreadsheet, onClick: () => exportFile('xlsx'), disabled: facets ? !facets.xlsx : false, hint: facets && !facets.xlsx ? 'Needs the PHP zip extension' : undefined },
                ]}
              />
            )}
          </div>
        </div>
        {chips.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
            {chips.map((c) => (
              <span key={c.key} className="inline-flex max-w-full items-center gap-1 rounded-full bg-brand-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">
                <span className="truncate capitalize">{c.label}</span>
                <button type="button" className="rounded-full p-0.5 hover:bg-brand-100 dark:hover:bg-brand-500/25" aria-label={`Remove filter ${c.label}`} onClick={() => update({ [c.key]: null, ...(c.key === 'tag' ? { tag_mode: null } : {}) })}>
                  <X className="h-3 w-3" />
                </button>
              </span>
            ))}
            <button type="button" className="text-xs font-semibold text-accent-600 hover:underline dark:text-accent-400" onClick={() => setParams(new URLSearchParams(), { replace: true })}>
              Clear all
            </button>
          </div>
        )}
        {selectedCount > 0 && (
          <div className="flex flex-col gap-2 border-b border-brand-100 bg-brand-50/70 px-4 py-2.5 dark:border-brand-500/20 dark:bg-brand-500/10 md:flex-row md:items-center">
            <div className="text-sm text-brand-900 dark:text-brand-100">
              <strong>{formatNumber(selectedCount)}</strong> selected
              {!allMatching && pageAllSelected && total > rows.length && (
                <button type="button" className="ml-2 font-semibold text-accent-600 hover:underline dark:text-accent-400" onClick={() => setAllMatching(true)}>
                  Select all {formatNumber(total)} matching
                </button>
              )}
              <button type="button" className="ml-3 text-xs text-slate-500 hover:underline" onClick={() => { setSelected(new Set()); setAllMatching(false); }}>
                Clear
              </button>
            </div>
            <div className="flex flex-wrap gap-1.5 md:ml-auto">
              {can('whatsapp_contacts', 'edit') && (
                <>
                  <Button size="xs" variant="secondary" icon={Tag} onClick={() => setBulk('tag')}>Tag</Button>
                  <Button size="xs" variant="secondary" icon={Tags} onClick={() => setBulk('untag')}>Untag</Button>
                  <Button size="xs" variant="secondary" icon={UserCheck} onClick={() => setBulk('assign')}>Assign</Button>
                  <Button size="xs" variant="secondary" icon={ArrowRightLeft} onClick={() => setBulk('stage')}>Stage</Button>
                  <Button size="xs" variant="secondary" icon={ShieldCheck} onClick={() => setBulk('opt_in')}>Opt in</Button>
                  <Button size="xs" variant="secondary" icon={ShieldOff} onClick={() => setBulk('opt_out')}>Opt out</Button>
                </>
              )}
              {canExport && (
                <Button size="xs" variant="secondary" icon={Download} onClick={() => exportFile('csv', true)}>Export</Button>
              )}
              {can('whatsapp_contacts', 'delete') && (
                <Button size="xs" variant="danger" icon={Trash2} onClick={() => setBulk('delete')}>Delete</Button>
              )}
            </div>
          </div>
        )}
        {list.error ? (
          <div className="p-4">
            <Alert variant="error" title="Contacts could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>
              {(list.error as Error).message}
            </Alert>
          </div>
        ) : (
          <DataTable<WaContact>
            caption="WhatsApp contacts"
            columns={columns}
            rows={rows}
            loading={list.isLoading || list.isFetching}
            sort={sort}
            onSort={(s2) => update({ sort: s2.key, dir: s2.dir }, false)}
            selectable={can('whatsapp_contacts', 'edit') || can('whatsapp_contacts', 'delete') || canExport}
            selected={selected}
            onSelectedChange={(s2) => {
              setSelected(s2);
              setAllMatching(false);
            }}
            onRowClick={(r) => navigate(`/whatsapp/contacts/${r.id}`)}
            empty={
              <EmptyState
                icon={ContactRound}
                title={activeFilterCount || params.get('q') ? 'No contacts match these filters' : 'No WhatsApp contacts yet'}
                description={activeFilterCount || params.get('q') ? 'Try removing a filter or searching by phone number.' : 'Sync leads, applicants, students and parents from the admission portal, import a file or add a contact.'}
                action={activeFilterCount || params.get('q') ? <Button variant="secondary" onClick={() => { setQ(''); setParams(new URLSearchParams(), { replace: true }); }}>Clear filters</Button> : undefined}
              />
            }
            actions={(r) => (
              <Dropdown
                label={`Actions for ${r.name ?? r.phone}`}
                triggerClassName="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" trigger={<MoreHorizontal className="h-4 w-4" />}
                items={[
                  { label: 'View profile', icon: Eye, to: `/whatsapp/contacts/${r.id}` },
                  can('whatsapp', 'view') && { label: 'Open conversation', icon: MessageCircleMore, to: `/whatsapp/inbox?contact=${r.id}` },
                  can('whatsapp', 'create') && { label: 'Send template', icon: Send, onClick: () => setTemplateFor(r) },
                  can('whatsapp_contacts', 'edit') && { label: 'Edit', icon: Pencil, onClick: () => setEditing(r), divider: true },
                  can('whatsapp_contacts', 'edit') && { label: 'Change stage', icon: ArrowRightLeft, onClick: () => setRowAction({ type: 'stage', contact: r }) },
                  can('whatsapp_contacts', 'edit') && { label: 'Assign counsellor', icon: UserCheck, onClick: () => setRowAction({ type: 'assign', contact: r }) },
                  can('whatsapp_contacts', 'edit') && (r.opt_in ? { label: 'Opt out', icon: ShieldOff, onClick: () => setRowAction({ type: 'opt_out', contact: r }) } : { label: 'Record opt-in', icon: ShieldCheck, onClick: () => setRowAction({ type: 'opt_in', contact: r }) }),
                  can('whatsapp_contacts', 'delete') && { label: 'Delete', icon: Trash2, danger: true, divider: true, onClick: () => removeContact(r) },
                ]}
              />
            )}
          />
        )}
        {total > 0 && (
          <Pagination
            className="border-t border-slate-100 dark:border-slate-800"
            page={list.data?.meta.page ?? page}
            pages={list.data?.meta.pages ?? 1}
            total={total}
            perPage={perPage}
            onPage={(p) => update({ page: String(p) }, false)}
            onPerPage={(n) => update({ per_page: String(n) })}
          />
        )}
      </Card>

      <FiltersDrawer open={showFilters} onClose={() => setShowFilters(false)} params={params} onApply={(patch) => update(patch)} />
      <SaveSegmentDialog open={showSegment} onClose={() => setShowSegment(false)} filters={filters} />
      <ContactFormModal open={!!editing} onClose={() => setEditing(null)} contact={editing} />
      {templateFor && <SendTemplateModal open={!!templateFor} onClose={() => setTemplateFor(null)} contactId={templateFor.id} contactName={templateFor.name} />}

      {/* single-row quick actions */}
      <StageDialog open={rowAction?.type === 'stage'} onClose={() => setRowAction(null)} current={rowAction?.contact.stage} onSubmit={async (stage, note) => {
        const r = await api.post(`whatsapp/contacts/${rowAction!.contact.id}/stage`, { stage, note });
        toast.success(r.message);
        refresh();
      }} />
      <AssignDialog open={rowAction?.type === 'assign'} onClose={() => setRowAction(null)} onSubmit={async (id) => {
        const r = await api.post(`whatsapp/contacts/${rowAction!.contact.id}/assign`, { counsellor_id: id });
        toast.success(r.message);
        refresh();
      }} />
      <OptDialog open={rowAction?.type === 'opt_in' || rowAction?.type === 'opt_out'} optIn={rowAction?.type === 'opt_in'} onClose={() => setRowAction(null)} onSubmit={async (source) => {
        const r = await api.post(`whatsapp/contacts/${rowAction!.contact.id}/opt`, { opt_in: rowAction!.type === 'opt_in' ? 1 : 0, source });
        toast.success(r.message);
        refresh();
      }} />

      {/* bulk */}
      <TagsDialog open={bulk === 'tag'} onClose={() => setBulk(null)} title="Add tags" submitLabel="Add tags" count={selectedCount} onSubmit={(tags) => runBulk('tag', { tags })} />
      <TagsDialog open={bulk === 'untag'} onClose={() => setBulk(null)} title="Remove tags" submitLabel="Remove tags" count={selectedCount} allowNew={false} onSubmit={(tags) => runBulk('untag', { tags })} />
      <AssignDialog open={bulk === 'assign'} onClose={() => setBulk(null)} count={selectedCount} onSubmit={(id) => runBulk('assign', { counsellor_id: id })} />
      <StageDialog open={bulk === 'stage'} onClose={() => setBulk(null)} count={selectedCount} onSubmit={(stage) => runBulk('stage', { stage })} />
      <OptDialog open={bulk === 'opt_in' || bulk === 'opt_out'} optIn={bulk === 'opt_in'} onClose={() => setBulk(null)} count={selectedCount} onSubmit={(source) => runBulk(bulk!, { source })} />
      <BulkDeleteDialog open={bulk === 'delete'} onClose={() => setBulk(null)} count={selectedCount} onSubmit={() => runBulk('delete')} />
    </>
  );
}

function BulkDeleteDialog({ open, onClose, count, onSubmit }: { open: boolean; onClose: () => void; count: number; onSubmit: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <DialogShell
      open={open}
      onClose={onClose}
      title={`Delete ${formatNumber(count)} contact(s)?`}
      busy={busy}
      danger
      submitLabel="Delete permanently"
      onSubmit={async () => {
        setBusy(true);
        setError(null);
        try {
          await onSubmit();
          onClose();
        } catch (e) {
          setError((e as Error).message);
        } finally {
          setBusy(false);
        }
      }}
    >
      <Alert variant="warning">Their conversations, notes and follow-ups are deleted too. Enquiries, applications and student records are not touched — a later sync recreates contacts that still exist in the portal.</Alert>
      {error && <Alert variant="error">{error}</Alert>}
    </DialogShell>
  );
}

/* ================================================================================================ column chooser */

function ColumnChooser({ cols, setCols }: { cols: string[]; setCols: (v: string[]) => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);
  return (
    <div className="relative" ref={ref}>
      <IconButton icon={Columns3} label="Choose columns" aria-expanded={open} onClick={() => setOpen((o) => !o)} />
      {open && (
        <div className="menu-panel absolute right-0 z-30 mt-2 w-60 p-2" role="dialog" aria-label="Visible columns">
          <p className="px-2 pb-1 pt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Columns</p>
          <div className="max-h-80 overflow-y-auto">
            {COLUMNS.map((c) => (
              <label key={c.key} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                <input type="checkbox" className="form-checkbox" checked={cols.includes(c.key)} onChange={() => setCols(cols.includes(c.key) ? cols.filter((k) => k !== c.key) : [...cols, c.key])} />
                {c.label}
              </label>
            ))}
          </div>
          <button type="button" className="mt-1 w-full rounded-lg px-2 py-1.5 text-left text-xs font-semibold text-accent-600 hover:bg-slate-50 dark:text-accent-400 dark:hover:bg-slate-800" onClick={() => setCols(COLUMNS.filter((c) => c.default).map((c) => c.key))}>
            Reset to default
          </button>
        </div>
      )}
    </div>
  );
}

/* ================================================================================================ advanced filters */

function FiltersDrawer({ open, onClose, params, onApply }: { open: boolean; onClose: () => void; params: URLSearchParams; onApply: (patch: Record<string, string | null>) => void }) {
  const { data: facets } = useFacets();
  const [v, setV] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    const init: Record<string, string> = {};
    FILTER_KEYS.forEach((k) => (init[k] = params.get(k) ?? ''));
    setV(init);
  }, [open, params]);
  const multi = (k: string) => splitList(v[k]);
  const setMulti = (k: string, vals: (string | number)[]) => setV((p) => ({ ...p, [k]: joinList(vals) }));
  const set = (k: string, val: string) => setV((p) => ({ ...p, [k]: val }));
  const opts = (list?: { value: string | number; label: string; sub?: string | null }[]) => (list ?? []).map((o) => ({ value: String(o.value), label: o.label, sub: o.sub ?? undefined }));
  const apply = () => {
    const patch: Record<string, string | null> = {};
    FILTER_KEYS.forEach((k) => (patch[k] = v[k] || null));
    onApply(patch);
    onClose();
  };
  const reset = () => {
    const patch: Record<string, string | null> = {};
    FILTER_KEYS.forEach((k) => (patch[k] = null));
    onApply(patch);
    onClose();
  };
  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="Filter contacts"
      description="Combine filters; save the result as a segment for campaigns."
      footer={
        <>
          <Button variant="ghost" onClick={reset}>
            Reset
          </Button>
          <Button onClick={apply}>Apply filters</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Contact type">
          <Combobox multiple options={Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label }))} value={multi('type')} onChange={(x) => setMulti('type', x)} placeholder="Any type" />
        </Field>
        <Field label="Stage">
          <Combobox multiple options={opts(facets?.stages)} value={multi('stage')} onChange={(x) => setMulti('stage', x)} placeholder="Any stage" />
        </Field>
        <Field label="Programme">
          <Combobox multiple options={opts(facets?.programs)} value={multi('program_id')} onChange={(x) => setMulti('program_id', x)} placeholder="Any programme" />
        </Field>
        <Field label="Counsellor">
          <Combobox multiple options={[{ value: 'none', label: 'Unassigned' }, { value: 'me', label: 'Assigned to me' }, ...opts(facets?.counsellors)]} value={multi('counsellor_id')} onChange={(x) => setMulti('counsellor_id', x)} placeholder="Anyone" />
        </Field>
        <Field label="Tags">
          <div className="space-y-2">
            <Combobox multiple options={opts(facets?.tags)} value={multi('tag')} onChange={(x) => setMulti('tag', x)} placeholder="Any tag" />
            {multi('tag').length > 1 && (
              <Select inputSize="sm" value={v.tag_mode || 'any'} onChange={(e) => set('tag_mode', e.target.value === 'any' ? '' : e.target.value)} options={{ any: 'Has any of these tags', all: 'Has all of these tags' }} aria-label="Tag match" />
            )}
          </div>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="WhatsApp opt-in">
            <Select value={v.opt_in ?? ''} onChange={(e) => set('opt_in', e.target.value)} options={{ '1': 'Opted in', '0': 'Not opted in' }} placeholder="Any" />
          </Field>
          <Field label="24-hour window">
            <Select value={v.window ?? ''} onChange={(e) => set('window', e.target.value)} options={{ open: 'Open', closed: 'Closed' }} placeholder="Any" />
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Payment status">
            <Combobox multiple options={opts(facets?.payment_statuses)} value={multi('payment_status')} onChange={(x) => setMulti('payment_status', x)} placeholder="Any" />
          </Field>
          <Field label="Follow-up">
            <Select value={v.has_followup ?? ''} onChange={(e) => set('has_followup', e.target.value)} options={{ '1': 'Scheduled', '0': 'None scheduled' }} placeholder="Any" />
          </Field>
        </div>
        <Field label="Admission status">
          <Combobox multiple options={opts(facets?.admission_statuses)} value={multi('admission_status')} onChange={(x) => setMulti('admission_status', x)} placeholder="Any" />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="City">
            <Combobox multiple options={opts(facets?.cities)} value={multi('city')} onChange={(x) => setMulti('city', x)} placeholder="Any" />
          </Field>
          <Field label="State">
            <Combobox multiple options={opts(facets?.states)} value={multi('state')} onChange={(x) => setMulti('state', x)} placeholder="Any" />
          </Field>
        </div>
        <Field label="Batch / intake">
          <Combobox multiple options={opts(facets?.batches)} value={multi('batch')} onChange={(x) => setMulti('batch', x)} placeholder="Any" />
        </Field>
        <Field label="Lead source">
          <Combobox multiple options={opts(facets?.lead_sources)} value={multi('lead_source')} onChange={(x) => setMulti('lead_source', x)} placeholder="Any" />
        </Field>
        <Field label="Last message between">
          <div className="grid grid-cols-2 gap-2">
            <Input type="date" value={v.last_from ?? ''} onChange={(e) => set('last_from', e.target.value)} aria-label="Last message from" />
            <Input type="date" value={v.last_to ?? ''} onChange={(e) => set('last_to', e.target.value)} aria-label="Last message to" />
          </div>
        </Field>
        <Field label="Added between">
          <div className="grid grid-cols-2 gap-2">
            <Input type="date" value={v.created_from ?? ''} onChange={(e) => set('created_from', e.target.value)} aria-label="Added from" />
            <Input type="date" value={v.created_to ?? ''} onChange={(e) => set('created_to', e.target.value)} aria-label="Added to" />
          </div>
        </Field>
        <Field label="Status">
          <Combobox multiple options={[{ value: 'active', label: 'Active' }, { value: 'blocked', label: 'Blocked' }, { value: 'archived', label: 'Archived' }]} value={multi('status')} onChange={(x) => setMulti('status', x)} placeholder="Active & blocked" />
        </Field>
      </div>
    </Drawer>
  );
}

/* ================================================================================================ save segment */

function SaveSegmentDialog({ open, onClose, filters }: { open: boolean; onClose: () => void; filters: Record<string, string> }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const payload = useMemo(() => Object.fromEntries(Object.entries(filters).filter(([k]) => k !== 'segment').map(([k, v]) => [k, MULTI.has(k) ? splitList(v) : v])), [filters]);
  const count = useQuery({
    queryKey: ['wa', 'crm', 'count', payload],
    queryFn: () => api.post<{ total: number; opted_in: number }>('whatsapp/contacts/count', { filters: payload }).then((r) => r.data),
    enabled: open,
  });
  useEffect(() => {
    if (open) {
      setName('');
      setDescription('');
      setErrors({});
    }
  }, [open]);
  const submit = async () => {
    setBusy(true);
    setErrors({});
    try {
      const r = await api.post('whatsapp/contacts/segments', { name, description, filters: payload });
      toast.success(r.message);
      refresh();
      onClose();
    } catch (e) {
      setErrors(fieldErrors(e));
      if (!Object.keys(fieldErrors(e)).length) toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={open} onClose={onClose} title="Save as segment" description="Segments are saved filter sets — the contact list updates itself as contacts change. Campaigns can target a segment." busy={busy} onSubmit={submit} submitLabel="Save segment">
      <div className="flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
        <Clock3 className="h-4 w-4 text-slate-400" aria-hidden />
        {count.isLoading ? 'Counting…' : count.data ? (
          <span>
            <strong>{formatNumber(count.data.total)}</strong> contacts match now, <strong>{formatNumber(count.data.opted_in)}</strong> opted in.
          </span>
        ) : 'Count unavailable.'}
      </div>
      <Field label="Segment name" required error={errors.name ?? errors.filters} htmlFor="wa-seg-name">
        <Input id="wa-seg-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoFocus />
      </Field>
      <Field label="Description" htmlFor="wa-seg-desc">
        <Input id="wa-seg-desc" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} />
      </Field>
      <p className="text-xs text-slate-500">
        Manage segments on the <Link to="/whatsapp/contacts?tab=segments" className="font-semibold text-accent-600 hover:underline dark:text-accent-400" onClick={onClose}>Segments</Link> tab.
      </p>
    </DialogShell>
  );
}
