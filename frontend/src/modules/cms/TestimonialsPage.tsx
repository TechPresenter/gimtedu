import { useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowDown, ArrowUp, CheckCircle2, FilterX, GripVertical, LayoutGrid, MessageSquareQuote, MoreHorizontal, Pencil, PlayCircle, Plus, Power, Quote, Rows3, Star, StarOff, Trash2, X,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, Dropdown, EmptyState, PageHeader, Pagination, SearchInput, Select, Stagger, StatCard, StatusBadge, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { useCrudList } from '@/lib/queries';
import type { BadgeColor } from '@/lib/status';
import type { Row } from '@/lib/types';
import { ContentFormModal } from './content/ContentFormModal';
import { MediaField } from './content/MediaPicker';
import { Stars, StarInput, useReorder } from './content/widgets';

const TYPES: { value: string; label: string; color: BadgeColor }[] = [
  { value: 'student', label: 'Student', color: 'blue' },
  { value: 'alumni', label: 'Alumni', color: 'green' },
  { value: 'parent', label: 'Parent', color: 'amber' },
  { value: 'recruiter', label: 'Recruiter', color: 'purple' },
];
const typeOf = (v: unknown) => TYPES.find((t) => t.value === v) ?? { value: String(v), label: String(v), color: 'slate' as BadgeColor };

interface TestimonialSummary {
  total: number;
  active: number;
  featured: number;
  with_video: number;
  avg_rating: number;
  by_type: Record<string, number>;
}

/** Testimonials: KPI row, role pills, quote cards with drag & drop ordering + quick toggles, table view, form with photo picker and star rating. */
export default function TestimonialsPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canEdit = can('cms', 'edit');
  const [view, setView] = useLocalStorage<'cards' | 'table'>('gimt.testimonials.view', 'cards');
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [form, setForm] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useLocalStorage<number>('gimt.testimonials.perPage', 48);
  useEffect(() => setPage(1), [dq, type, status, perPage]);
  const list = useCrudList('testimonials', { page, per_page: perPage, q: dq || undefined, f: { type: type || undefined, status: status || undefined } });
  const rows = useMemo(() => list.data?.rows ?? [], [list.data]);
  const sum = (list.data?.summary ?? null) as TestimonialSummary | null;
  const filtered = !!(dq || type || status);
  // Manual ordering needs every testimonial on one page (sort_order is renumbered for the ids sent).
  const canReorder = canEdit && !filtered && (list.data?.pages ?? 1) <= 1;

  const refresh = () => qc.invalidateQueries({ queryKey: ['crud', 'testimonials'] });
  const reorder = useReorder(rows, (ids) => {
    void api
      .post('crud/testimonials/bulk', { action: 'reorder', ids })
      .then((res) => toast.success(res.message))
      .catch((e: ApiError) => toast.error(e.message))
      .finally(() => void refresh());
  }, canReorder);

  const patch = async (r: Row, data: Record<string, unknown>, msg: string) => {
    try {
      await api.post(`crud/testimonials/${r.id}`, data);
      toast.success(msg);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async (r: Row) => {
    const ok = await confirm({ title: 'Delete this testimonial?', message: <>The testimonial by <strong>{String(r.name)}</strong> will be removed from the website.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/testimonials/${r.id}`);
      toast.success(res.message || 'Testimonial deleted.');
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const clear = () => {
    setQ('');
    setType('');
    setStatus('');
  };

  const custom = {
    photo: ({ value, setValue, invalid }: { value: unknown; setValue: (v: unknown) => void; invalid: boolean }) => (
      <MediaField value={(value as string) || null} onChange={(p) => setValue(p ?? '')} folder="testimonials" invalid={invalid} compact placeholder="Choose a photo (square works best)" />
    ),
    rating: ({ value, setValue, invalid, id }: { value: unknown; setValue: (v: unknown) => void; invalid: boolean; id: string }) => <StarInput id={id} value={Number(value) || 0} onChange={(v) => setValue(v)} invalid={invalid} />,
  };

  return (
    <>
      <PageHeader
        title="Testimonials"
        description="Success stories from students, alumni, parents and recruiters — shown on the home, program and campus pages."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Testimonials' }]}
        actions={can('cms', 'create') && <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>Add testimonial</Button>}
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={60}>
        {[
          <StatCard key="t" label="Testimonials" icon={MessageSquareQuote} tone="navy" loading={list.isLoading} value={sum?.total ?? 0} hint={sum ? `${sum.with_video} with video` : undefined} />,
          <StatCard key="a" label="Active" icon={CheckCircle2} tone="green" loading={list.isLoading} value={sum?.active ?? 0} hint="Shown on the website" />,
          <StatCard key="f" label="Featured" icon={Star} tone="amber" loading={list.isLoading} value={sum?.featured ?? 0} hint="Highlighted on the home page" />,
          <StatCard key="r" label="Average rating" icon={Quote} tone="purple" loading={list.isLoading} value={sum ? <span className="inline-flex items-center gap-2">{sum.avg_rating.toFixed(1)}<Stars value={sum.avg_rating} size="sm" /></span> : '—'} hint="Active testimonials" />,
        ]}
      </Stagger>

      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        {view === 'cards' ? (
          <Tabs variant="pills" value={type} onChange={setType} tabs={[{ key: '', label: 'All', count: sum?.total }, ...TYPES.map((t) => ({ key: t.value, label: t.label, count: sum?.by_type[t.value] ?? 0 }))]} />
        ) : (
          <span />
        )}
        <Tabs variant="pills" value={view} onChange={(v) => setView(v as 'cards' | 'table')} tabs={[{ key: 'cards', label: 'Cards', icon: LayoutGrid }, { key: 'table', label: 'Table', icon: Rows3 }]} />
      </div>

      {view === 'table' ? (
        <CrudTable
          module="testimonials"
          urlState
          addLabel="Add testimonial"
          onCreate={() => setForm({ open: true, id: null })}
          onEdit={(r: Row) => setForm({ open: true, id: Number(r.id) })}
          emptyTitle="No testimonials yet"
          emptyText="Add quotes from students, alumni, parents and recruiters."
          renderers={{
            rating: (r: Row) => <Stars value={Number(r.rating)} size="xs" />,
            name: (r: Row) => (
              <div className="flex min-w-[14rem] max-w-xs items-center gap-3">
                <Avatar name={String(r.name)} src={(r.photo as string) || null} />
                <div className="min-w-0 leading-tight">
                  <div className="flex items-center gap-1.5 font-semibold text-slate-900 dark:text-white">
                    <span className="truncate">{String(r.name)}</span>
                    {!!Number(r.is_featured) && <Star className="h-3.5 w-3.5 shrink-0 fill-amber-400 text-amber-400" aria-label="Featured" />}
                  </div>
                  <div className="truncate text-xs text-slate-500 dark:text-slate-400">{[r.designation, r.company].filter(Boolean).join(' · ') || '—'}</div>
                </div>
              </div>
            ),
            type: (r: Row) => <Badge color={typeOf(r.type).color}>{typeOf(r.type).label}</Badge>,
            program_short: (r: Row) =>
              r.program_short || r.batch ? (
                <div className="whitespace-nowrap leading-tight">
                  <div>{r.program_short ? String(r.program_short) : '—'}</div>
                  {r.batch && <div className="text-xs text-slate-500">Batch {String(r.batch)}</div>}
                </div>
              ) : (
                <span className="text-slate-400">—</span>
              ),
          }}
          bulkActions={
            canEdit
              ? [
                  { label: 'Feature', icon: Star, onClick: (ids, clearSel) => void api.post('crud/testimonials/bulk', { action: 'feature', ids }).then((r) => { toast.success(r.message); clearSel(); void refresh(); }).catch((e: ApiError) => toast.error(e.message)) },
                  { label: 'Un-feature', icon: StarOff, onClick: (ids, clearSel) => void api.post('crud/testimonials/bulk', { action: 'unfeature', ids }).then((r) => { toast.success(r.message); clearSel(); void refresh(); }).catch((e: ApiError) => toast.error(e.message)) },
                ]
              : []
          }
        />
      ) : (
        <>
          <Card className="mb-4 flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search name, company, quote…" className="w-full sm:max-w-sm" size="sm" />
            <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} placeholder="Status: All" aria-label="Status" options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} className="sm:!w-40" />
            {filtered && (
              <button type="button" onClick={clear} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 sm:ml-auto">
                <FilterX className="h-3.5 w-3.5" /> Clear filters
              </button>
            )}
          </Card>
          {canEdit && rows.length > 1 && (
            <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
              {canReorder ? 'Drag cards (or use the arrows) to set the order used on the website.' : filtered ? 'Clear the filters to change the display order.' : 'Show all testimonials on one page (increase the page size) to change the display order.'}
            </p>
          )}

          {list.error ? (
            <Alert variant="error" title="Unable to load testimonials">{(list.error as ApiError).message}</Alert>
          ) : list.isLoading ? (
            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }).map((_, i) => <div key={i} className="skeleton h-60 rounded-2xl" />)}</div>
          ) : rows.length === 0 ? (
            <Card>
              <EmptyState
                icon={filtered ? FilterX : MessageSquareQuote}
                title={filtered ? 'No testimonials match your filters' : 'No testimonials yet'}
                description={filtered ? 'Try another search, role or status.' : 'Add quotes from students, alumni, parents and recruiters to build trust on the website.'}
                action={filtered ? <Button variant="secondary" icon={X} onClick={clear}>Clear filters</Button> : can('cms', 'create') ? <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>Add testimonial</Button> : undefined}
              />
            </Card>
          ) : (
            <ul className="grid gap-5 md:grid-cols-2 xl:grid-cols-3" aria-label="Testimonials">
              {reorder.items.map((r, idx) => {
                const t = typeOf(r.type);
                const featured = !!Number(r.is_featured);
                const inactive = r.status !== 'active';
                return (
                  <li
                    key={r.id}
                    {...reorder.dragProps(r.id)}
                    className={clsx(
                      'card group relative flex flex-col p-5 transition duration-200 hover:-translate-y-0.5 hover:shadow-soft',
                      featured && 'ring-1 ring-amber-200 dark:ring-amber-500/30',
                      reorder.overId === r.id && 'ring-2 ring-accent-500',
                      reorder.dragId === r.id && 'opacity-50',
                      inactive && 'opacity-70',
                    )}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><Quote className="h-4 w-4" /></span>
                      <div className="flex items-center gap-1">
                        {featured && <Badge color="amber"><Star className="h-3 w-3 fill-current" /> Featured</Badge>}
                        {inactive && <StatusBadge status="inactive" />}
                        {canReorder && <span className="hidden cursor-grab p-1 text-slate-300 active:cursor-grabbing sm:inline" aria-hidden><GripVertical className="h-4 w-4" /></span>}
                        <Dropdown
                          label={`Actions for ${r.name}`}
                          triggerClassName="btn-icon !h-8 !w-8 !rounded-lg"
                          trigger={<MoreHorizontal className="h-4 w-4" />}
                          items={[
                            canEdit && { label: 'Edit', icon: Pencil, onClick: () => setForm({ open: true, id: Number(r.id) }) },
                            canEdit && { label: featured ? 'Remove from featured' : 'Feature on home page', icon: featured ? StarOff : Star, onClick: () => void patch(r, { is_featured: !featured }, featured ? 'Removed from featured.' : 'Featured on the home page.') },
                            canEdit && { label: inactive ? 'Activate' : 'Deactivate', icon: Power, onClick: () => void patch(r, { status: inactive ? 'active' : 'inactive' }, inactive ? 'Testimonial activated.' : 'Testimonial hidden from the website.') },
                            canReorder && idx > 0 && { label: 'Move up', icon: ArrowUp, onClick: () => reorder.move(r.id, -1) },
                            canReorder && idx < reorder.items.length - 1 && { label: 'Move down', icon: ArrowDown, onClick: () => reorder.move(r.id, 1) },
                            r.video_url && { label: 'Watch video', icon: PlayCircle, href: String(r.video_url), target: '_blank' },
                            can('cms', 'delete') && { label: 'Delete', icon: Trash2, danger: true, onClick: () => void remove(r) },
                          ]}
                        />
                      </div>
                    </div>
                    <blockquote className="mt-4 flex-1 text-sm leading-relaxed text-slate-700 dark:text-slate-200">
                      <p className="line-clamp-6">“{String(r.content)}”</p>
                    </blockquote>
                    <div className="mt-4 flex items-center gap-1.5">
                      <Stars value={Number(r.rating)} size="sm" />
                      {r.video_url && (
                        <a href={String(r.video_url)} target="_blank" rel="noopener noreferrer" className="ml-auto inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                          <PlayCircle className="h-3.5 w-3.5" /> Video
                        </a>
                      )}
                    </div>
                    <div className="mt-4 flex items-center gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                      <Avatar name={String(r.name)} src={(r.photo as string) || null} size="lg" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold text-slate-900 dark:text-white">{String(r.name)}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{[r.designation, r.company].filter(Boolean).join(' · ') || '—'}</p>
                        <p className="mt-1 flex flex-wrap items-center gap-1.5">
                          <Badge color={t.color}>{t.label}</Badge>
                          {(r.program_short || r.batch) && <span className="text-[11px] text-slate-500">{[r.program_short, r.batch].filter(Boolean).join(' · ')}</span>}
                        </p>
                      </div>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
          {list.data && list.data.total > 0 && (list.data.pages > 1 || list.data.total > 12) && (
            <Card className="mt-5">
              <Pagination page={list.data.page} pages={list.data.pages} total={list.data.total} perPage={list.data.per_page} onPage={setPage} onPerPage={setPerPage} perPageOptions={[24, 48, 96]} />
            </Card>
          )}
        </>
      )}

      <ContentFormModal
        open={form.open}
        onClose={() => setForm({ open: false, id: null })}
        module="testimonials"
        id={form.id}
        title={form.id ? 'Edit testimonial' : 'Add testimonial'}
        onSaved={() => void refresh()}
        custom={custom}
      />
    </>
  );
}
