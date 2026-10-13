import { useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowDown, ArrowUp, ChevronDown, CircleHelp, CheckCircle2, FilterX, GripVertical, Home, LayoutList, Mail, Pencil, Plus, Rows3, Trash2, X,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, EmptyState, PageHeader, Pagination, SearchInput, Select, Stagger, StatCard, StatusBadge, Tabs, Toggle, useConfirm, useToast,
} from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { useCrudList, useCrudMeta } from '@/lib/queries';
import type { Row } from '@/lib/types';
import type { BadgeColor } from '@/lib/status';
import { useReorder } from './content/widgets';

const CATEGORIES: { value: string; label: string; color: BadgeColor }[] = [
  { value: 'admissions', label: 'Admissions', color: 'blue' },
  { value: 'fees', label: 'Fees & Scholarships', color: 'green' },
  { value: 'academics', label: 'Academics', color: 'navy' },
  { value: 'hostel', label: 'Hostel & Transport', color: 'amber' },
  { value: 'placement', label: 'Placements', color: 'purple' },
  { value: 'campus', label: 'Campus Life', color: 'cyan' },
  { value: 'general', label: 'General', color: 'slate' },
];
const cat = (v: unknown) => CATEGORIES.find((c) => c.value === v) ?? { value: String(v), label: String(v), color: 'slate' as BadgeColor };

interface FaqSummary {
  total: number;
  active: number;
  home: number;
  contact: number;
  by_category: Record<string, number>;
}

/** FAQs: category navigation, searchable accordion list with drag & drop ordering and inline home/contact toggles; table view for bulk work. */
export default function FaqsPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canEdit = can('cms', 'edit');
  const meta = useCrudMeta('faqs');
  const [view, setView] = useLocalStorage<'list' | 'table'>('gimt.faqs.view', 'list');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [openId, setOpenId] = useState<number | null>(null);
  const [form, setForm] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useLocalStorage<number>('gimt.faqs.perPage', 50);
  useEffect(() => setPage(1), [dq, category, status, perPage]);

  const list = useCrudList('faqs', { page, per_page: perPage, q: dq || undefined, f: { category: category || undefined, status: status || undefined } }, view === 'list');
  const rows = useMemo(() => list.data?.rows ?? [], [list.data]);
  const summary = (list.data?.summary ?? null) as FaqSummary | null;
  const summaryAll = useCrudList('faqs', { per_page: 1 }, view === 'table');
  const sum = summary ?? ((summaryAll.data?.summary ?? null) as FaqSummary | null);
  const loadingSum = view === 'list' ? list.isLoading : summaryAll.isLoading;
  // Manual ordering needs the whole category on one page (sort_order is renumbered for the ids sent).
  const canReorder = canEdit && !!category && !dq && !status && (list.data?.pages ?? 1) <= 1;

  const refresh = () => qc.invalidateQueries({ queryKey: ['crud', 'faqs'] });
  const reorder = useReorder(rows, (ids) => {
    void api
      .post('crud/faqs/bulk', { action: 'reorder', ids })
      .then((res) => toast.success(res.message))
      .catch((e: ApiError) => toast.error(e.message))
      .finally(() => void refresh());
  }, canReorder);

  const patch = async (r: Row, data: Record<string, unknown>, msg: string) => {
    try {
      await api.post(`crud/faqs/${r.id}`, data);
      toast.success(msg);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async (r: Row) => {
    const ok = await confirm({ title: 'Delete this FAQ?', message: <>“{String(r.question)}” will be removed from the website.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/faqs/${r.id}`);
      toast.success(res.message || 'FAQ deleted.');
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const clear = () => {
    setQ('');
    setStatus('');
    setCategory('');
  };

  const formDefaults = useMemo(() => (!form.id && category ? { category } : undefined), [form.id, category]);
  const navItems = [{ value: '', label: 'All categories', color: 'slate' as BadgeColor }, ...CATEGORIES];

  return (
    <>
      <PageHeader
        title="FAQs"
        description="Questions and answers shown on the home, admissions, programs and contact pages."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'FAQs' }]}
        actions={
          <>
            <Tabs variant="pills" value={view} onChange={(v) => setView(v as 'list' | 'table')} tabs={[{ key: 'list', label: 'Manage', icon: LayoutList }, { key: 'table', label: 'Table', icon: Rows3 }]} />
            {can('cms', 'create') && <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>Add FAQ</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={60}>
        {[
          <StatCard key="t" label="Total FAQs" icon={CircleHelp} tone="navy" loading={loadingSum} value={sum?.total ?? 0} hint={sum ? `${Object.keys(sum.by_category).length} categories` : undefined} />,
          <StatCard key="a" label="Active" icon={CheckCircle2} tone="green" loading={loadingSum} value={sum?.active ?? 0} hint={sum ? `${sum.total - sum.active} inactive` : undefined} />,
          <StatCard key="h" label="On home page" icon={Home} tone="blue" loading={loadingSum} value={sum?.home ?? 0} hint="Home page FAQ section" />,
          <StatCard key="c" label="On contact page" icon={Mail} tone="purple" loading={loadingSum} value={sum?.contact ?? 0} hint="Contact Us page" />,
        ]}
      </Stagger>

      {view === 'table' ? (
        <CrudTable
          module="faqs"
          urlState
          addLabel="Add FAQ"
          emptyTitle="No FAQs yet"
          emptyText="Add the questions students and parents ask most often."
          renderers={{ category: (r: Row) => <Badge color={cat(r.category).color}>{cat(r.category).label}</Badge> }}
          bulkActions={
            canEdit
              ? [
                  { label: 'Show on home', icon: Home, onClick: (ids, clearSel) => void api.post('crud/faqs/bulk', { action: 'home_on', ids }).then((r) => { toast.success(r.message); clearSel(); void refresh(); }).catch((e: ApiError) => toast.error(e.message)) },
                  { label: 'Show on contact', icon: Mail, onClick: (ids, clearSel) => void api.post('crud/faqs/bulk', { action: 'contact_on', ids }).then((r) => { toast.success(r.message); clearSel(); void refresh(); }).catch((e: ApiError) => toast.error(e.message)) },
                ]
              : []
          }
        />
      ) : (
        <div className="grid items-start gap-6 lg:grid-cols-4">
          {/* Category navigation */}
          <nav aria-label="FAQ categories" className="min-w-0 lg:sticky lg:top-20">
            <Card className="p-2">
              <ul className="flex gap-1 overflow-x-auto scrollbar-none lg:flex-col">
                {navItems.map((c) => {
                  const n = c.value ? sum?.by_category[c.value] ?? 0 : sum?.total ?? 0;
                  const active = category === c.value;
                  return (
                    <li key={c.value || 'all'} className="shrink-0">
                      <button
                        type="button"
                        onClick={() => setCategory(c.value)}
                        aria-current={active ? 'true' : undefined}
                        className={clsx(
                          'flex w-full items-center justify-between gap-3 whitespace-nowrap rounded-xl px-3 py-2 text-sm transition',
                          active ? 'bg-brand-800 font-semibold text-white dark:bg-brand-600' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                        )}
                      >
                        {c.label}
                        <span className={clsx('rounded-full px-2 text-[11px] font-semibold', active ? 'bg-white/20' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>{n}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            </Card>
          </nav>

          {/* FAQ list */}
          <div className="min-w-0 lg:col-span-3">
            <Card className="mb-4 flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
              <SearchInput value={q} onChange={setQ} placeholder="Search questions and answers…" className="w-full sm:max-w-sm" size="sm" />
              <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} placeholder="Status: All" aria-label="Status" options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} className="sm:!w-40" />
              {(dq || status || category) && (
                <button type="button" onClick={clear} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 sm:ml-auto">
                  <FilterX className="h-3.5 w-3.5" /> Clear
                </button>
              )}
            </Card>
            {canEdit && rows.length > 1 && (
              <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
                {canReorder
                  ? 'Drag questions (or use the arrows) to change the order shown on the website.'
                  : category && !dq && !status
                    ? 'Show all questions of this category on one page (increase the page size) to reorder them.'
                    : 'Choose a category (without search or status filters) to reorder its questions.'}
              </p>
            )}

            {list.error ? (
              <Alert variant="error" title="Unable to load FAQs">{(list.error as ApiError).message}</Alert>
            ) : list.isLoading ? (
              <div className="space-y-3">{Array.from({ length: 6 }).map((_, i) => <div key={i} className="skeleton h-16 w-full rounded-2xl" />)}</div>
            ) : rows.length === 0 ? (
              <Card>
                <EmptyState
                  icon={dq || status ? FilterX : CircleHelp}
                  title={dq || status ? 'No FAQs match your search' : category ? `No ${cat(category).label} FAQs yet` : 'No FAQs yet'}
                  description={dq || status ? 'Try a different search term or clear the filters.' : 'Add the questions students and parents ask most often.'}
                  action={dq || status ? <Button variant="secondary" icon={X} onClick={clear}>Clear filters</Button> : can('cms', 'create') ? <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>Add FAQ</Button> : undefined}
                />
              </Card>
            ) : (
              <ul className="space-y-3">
                {reorder.items.map((r, idx) => {
                  const open = openId === r.id;
                  const c = cat(r.category);
                  return (
                    <li
                      key={r.id}
                      {...reorder.dragProps(r.id)}
                      className={clsx(
                        'card overflow-hidden transition',
                        reorder.overId === r.id && 'ring-2 ring-accent-500',
                        reorder.dragId === r.id && 'opacity-50',
                        r.status !== 'active' && 'opacity-75',
                      )}
                    >
                      <div className="flex items-start gap-2 p-3 sm:gap-3 sm:p-4">
                        {canReorder && (
                          <span className="mt-1 hidden cursor-grab text-slate-300 active:cursor-grabbing sm:block" aria-hidden><GripVertical className="h-4 w-4" /></span>
                        )}
                        <button type="button" onClick={() => setOpenId(open ? null : r.id)} aria-expanded={open} className="min-w-0 flex-1 text-left">
                          <span className="flex items-start gap-2">
                            <span className="min-w-0 flex-1 font-semibold text-slate-900 dark:text-white">{String(r.question)}</span>
                            <ChevronDown className={clsx('mt-0.5 h-4 w-4 shrink-0 text-slate-400 transition', open && 'rotate-180')} />
                          </span>
                          <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <Badge color={c.color}>{c.label}</Badge>
                            {r.status !== 'active' && <StatusBadge status={String(r.status)} />}
                            {!!Number(r.show_on_home) && <Badge color="blue"><Home className="h-3 w-3" /> Home</Badge>}
                            {!!Number(r.show_on_contact) && <Badge color="purple"><Mail className="h-3 w-3" /> Contact</Badge>}
                          </span>
                        </button>
                        <div className="flex shrink-0 items-center">
                          {canReorder && (
                            <>
                              <button type="button" className="btn-icon !h-8 !w-8 disabled:opacity-30" onClick={() => reorder.move(r.id, -1)} disabled={idx === 0} aria-label="Move up" title="Move up"><ArrowUp className="h-4 w-4" /></button>
                              <button type="button" className="btn-icon !h-8 !w-8 disabled:opacity-30" onClick={() => reorder.move(r.id, 1)} disabled={idx === reorder.items.length - 1} aria-label="Move down" title="Move down"><ArrowDown className="h-4 w-4" /></button>
                            </>
                          )}
                          {canEdit && <button type="button" className="btn-icon !h-8 !w-8 hover:!text-brand-700" onClick={() => setForm({ open: true, id: r.id })} aria-label="Edit FAQ" title="Edit"><Pencil className="h-4 w-4" /></button>}
                          {can('cms', 'delete') && <button type="button" className="btn-icon !h-8 !w-8 hover:!text-red-600" onClick={() => void remove(r)} aria-label="Delete FAQ" title="Delete"><Trash2 className="h-4 w-4" /></button>}
                        </div>
                      </div>
                      {open && (
                        <div className="animate-fade-in border-t border-slate-100 bg-slate-50/60 px-4 py-4 dark:border-slate-800 dark:bg-slate-800/30">
                          <div className="space-y-2 whitespace-pre-line text-sm leading-relaxed text-slate-600 dark:text-slate-300">{String(r.answer)}</div>
                          {canEdit && (
                            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-3 border-t border-slate-200/70 pt-3 dark:border-slate-700">
                              <Toggle id={`home-${r.id}`} checked={!!Number(r.show_on_home)} onChange={(v) => void patch(r, { show_on_home: v }, v ? 'Shown on the home page.' : 'Removed from the home page.')} label="Home page" />
                              <Toggle id={`contact-${r.id}`} checked={!!Number(r.show_on_contact)} onChange={(v) => void patch(r, { show_on_contact: v }, v ? 'Shown on the contact page.' : 'Removed from the contact page.')} label="Contact page" />
                              <Toggle id={`active-${r.id}`} checked={r.status === 'active'} onChange={(v) => void patch(r, { status: v ? 'active' : 'inactive' }, v ? 'FAQ activated.' : 'FAQ deactivated — hidden from the website.')} label="Active" />
                            </div>
                          )}
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
            {list.data && list.data.total > 0 && (list.data.pages > 1 || list.data.total > 10) && (
              <Card className="mt-4">
                <Pagination page={list.data.page} pages={list.data.pages} total={list.data.total} perPage={list.data.per_page} onPage={setPage} onPerPage={setPerPage} perPageOptions={[25, 50, 100]} />
              </Card>
            )}
          </div>
        </div>
      )}

      <CrudFormModal
        open={form.open}
        onClose={() => setForm({ open: false, id: null })}
        meta={meta.data}
        module="faqs"
        id={form.id}
        defaults={formDefaults}
        title={form.id ? 'Edit FAQ' : 'Add FAQ'}
        onSaved={() => void refresh()}
      />
    </>
  );
}
