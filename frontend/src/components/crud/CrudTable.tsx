import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Columns3, Download, Eye, FileSpreadsheet, FileText, FilterX, MoreHorizontal, Pencil, Plus, Printer, RefreshCw, RotateCcw, Trash2, Upload, X, type LucideIcon } from 'lucide-react';
import {
  Button, Card, Combobox, DataTable, Dropdown, EmptyState, IconButton, Input, Pagination, SearchInput, Select, useConfirm, useToast,
  type Column, type DropdownItem, type SortState, Alert,
} from '@/components/ui';
import { api, ApiError, apiUrl, downloadFile, type Query } from '@/lib/api';
import { useCrudList, useCrudMeta } from '@/lib/queries';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { labelize } from '@/lib/format';
import type { Row } from '@/lib/types';
import { renderCell, fillTemplate } from './cells';
import { CrudFormModal } from './CrudForm';
import { CrudViewModal } from './CrudViewModal';
import { ImportDialog } from './ImportDialog';
import type { CrudColumn, CrudFilter, CrudMeta } from './types';

export interface CrudBulkAction {
  label: string;
  icon?: LucideIcon;
  danger?: boolean;
  onClick: (ids: number[], clear: () => void) => void | Promise<void>;
}

export interface CrudTableProps {
  module: string;
  /** Card title (defaults to module title). Set to false to hide the header row. */
  title?: string | false;
  description?: string;
  /** Fixed hidden filters (module must declare them in 'scopes'), e.g. { student_id: 5 }. Changing it resets to page 1. */
  scope?: Record<string, string | number>;
  /** Initial filter values */
  defaultFilters?: Record<string, unknown>;
  /**
   * Filter values controlled by the page (e.g. an academic picker outside the table). Whenever their content changes
   * they are merged into the table's filters (no remount needed); `onFiltersChange` reports every change made in the
   * table's own filter bar.
   */
  filters?: Record<string, unknown>;
  onFiltersChange?: (filters: Record<string, unknown>) => void;
  /** Initial sort for this table instance (wins over the module's default_sort). */
  defaultSort?: SortState;
  hideColumns?: string[];
  hideFilters?: string[];
  /** Override cell rendering per column key */
  renderers?: Record<string, (row: Row) => ReactNode>;
  /** Extra items for each row's "more" menu */
  rowMenu?: (row: Row) => (DropdownItem | false | null | undefined)[];
  /** Extra toolbar content (left of the Add button) */
  toolbar?: ReactNode;
  /** Navigate to a route instead of opening the view modal, e.g. (r) => `/students/${r.id}` */
  viewTo?: (row: Row) => string;
  onView?: (row: Row) => void;
  /** Override "Add" (e.g. navigate to a full page form) */
  onCreate?: () => void;
  createTo?: string;
  /** false hides "Add" even when the role may create (e.g. records are created on another screen). */
  canCreate?: boolean;
  onEdit?: (row: Row) => void;
  editTo?: (row: Row) => string;
  /** Per-row switches for the Edit / Delete icons (e.g. hide Delete on records the server will refuse). */
  canEdit?: (row: Row) => boolean;
  canDelete?: (row: Row) => boolean;
  formDefaults?: Record<string, unknown>;
  bulkActions?: CrudBulkAction[];
  /** Sync search/filters/page into the URL query string (and follow links that change them). */
  urlState?: boolean;
  addLabel?: string;
  emptyTitle?: string;
  emptyText?: string;
  onSaved?: (row: Row | null, id: number) => void;
  /** Called after records were deleted from this table (single delete or bulk delete). */
  onDeleted?: (ids: number[]) => void;
  /** Render inside a Card (default) or bare */
  bare?: boolean;
  /** Hide row action buttons */
  noRowActions?: boolean;
  className?: string;
  /** Content above the table inside the card (e.g. summary chips) */
  header?: (data: { total: number; summary?: Record<string, unknown> | null }) => ReactNode;
}

function cleanFilters(f: Record<string, unknown>) {
  const out: Record<string, unknown> = {};
  Object.entries(f).forEach(([k, v]) => {
    if (v === '' || v === null || v === undefined) return;
    if (typeof v === 'object' && !Array.isArray(v) && !Object.values(v as object).some((x) => x)) return;
    if (Array.isArray(v) && !v.length) return;
    out[k] = v;
  });
  return out;
}

/* ---------------------------------------------------------------- URL state
 * ?q=rahul&page=2&f.status=active&f.result_status=pass,backlog&f.created_at.from=2026-07-01&f.created_at.to=2026-07-31
 */
const isTableParam = (k: string) => k === 'q' || k === 'page' || k.startsWith('f.');

/** Canonical form of the table's own URL params — tells the table's writes apart from outside navigation. */
function urlStateKey(p: URLSearchParams): string {
  return [...p.entries()]
    .filter(([k, v]) => isTableParam(k) && v !== '')
    .map(([k, v]) => `${k}=${v}`)
    .sort()
    .join('&');
}

function filtersFromUrl(p: URLSearchParams, defs: CrudFilter[]): Record<string, unknown> {
  const f: Record<string, unknown> = {};
  defs.forEach((def) => {
    if (def.type === 'daterange') {
      const from = p.get(`f.${def.key}.from`) ?? '';
      const to = p.get(`f.${def.key}.to`) ?? '';
      if (from || to) f[def.key] = { from, to };
      return;
    }
    const v = p.get(`f.${def.key}`);
    if (v === null || v === '') return;
    f[def.key] = def.type === 'multiselect' ? v.split(',').filter(Boolean) : v;
  });
  return f;
}

function writeFiltersToUrl(next: URLSearchParams, filters: Record<string, unknown>) {
  Object.entries(cleanFilters(filters)).forEach(([k, v]) => {
    if (Array.isArray(v)) next.set(`f.${k}`, v.join(','));
    else if (v && typeof v === 'object') {
      const r = v as { from?: string; to?: string };
      if (r.from) next.set(`f.${k}.from`, r.from);
      if (r.to) next.set(`f.${k}.to`, r.to);
    } else next.set(`f.${k}`, String(v));
  });
}

/**
 * Full-featured server-side table for a CRUD module: search, filters, sorting, pagination, column control,
 * bulk selection & actions, export (CSV/Excel/PDF), import, add/edit/view modals and delete confirmation.
 */
export function CrudTable(props: CrudTableProps) {
  const { module, scope, hideColumns = [], hideFilters = [], urlState = false } = props;
  const { data: meta, error: metaError } = useCrudMeta(module);
  if (metaError) {
    return (
      <Card className="p-6">
        <Alert variant="error" title="Unable to load this table">
          {(metaError as ApiError).message}
        </Alert>
      </Card>
    );
  }
  if (!meta) {
    return (
      <Card className="p-5">
        <div className="mb-4 flex gap-3">
          <div className="skeleton h-10 w-72" />
          <div className="skeleton ml-auto h-10 w-28" />
        </div>
        <DataTable columns={[{ key: 'a', header: '' }, { key: 'b', header: '' }, { key: 'c', header: '' }, { key: 'd', header: '' }]} rows={[]} loading />
      </Card>
    );
  }
  return <CrudTableInner {...props} meta={meta} scope={scope} hideColumns={hideColumns} hideFilters={hideFilters} urlState={urlState} />;
}

function CrudTableInner({
  module, meta, title, description, scope, defaultFilters, filters: controlledFilters, onFiltersChange, defaultSort, hideColumns = [], hideFilters = [], renderers = {}, rowMenu,
  toolbar, viewTo, onView, onCreate, createTo, canCreate, onEdit, editTo, canEdit, canDelete, formDefaults, bulkActions = [], urlState, addLabel, emptyTitle, emptyText,
  onSaved, onDeleted, bare, noRowActions, className, header,
}: CrudTableProps & { meta: CrudMeta }) {
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();

  /** Filters the table starts from without anything in the URL: page defaults, module defaults, page-controlled values. */
  const baseFilters = (): Record<string, unknown> => {
    const f: Record<string, unknown> = { ...(defaultFilters ?? {}) };
    meta.filters.forEach((flt) => {
      if (flt.default !== undefined && f[flt.key] === undefined) f[flt.key] = flt.default;
    });
    return controlledFilters ? { ...f, ...controlledFilters } : f;
  };

  const initial = useMemo(() => {
    const f = baseFilters();
    if (urlState) Object.assign(f, filtersFromUrl(params, meta.filters));
    return { filters: f, q: urlState ? params.get('q') ?? '' : '', page: urlState ? Math.max(1, Number(params.get('page') ?? 1) || 1) : 1 };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [meta]);

  const [q, setQ] = useState(initial.q);
  // the search actually applied (debounced typing, or set at once by a link)
  const [appliedQ, setAppliedQ] = useState(initial.q);
  const [filters, setFilters] = useState<Record<string, unknown>>(initial.filters);
  const [page, setPage] = useState(initial.page);
  const [perPage, setPerPage] = useLocalStorage<number>(`gimt.perPage.${module}`, meta.per_page || 25);
  const [sort, setSort] = useState<SortState | null>(defaultSort ?? meta.default_sort ?? null);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const defaultHidden = useMemo(() => meta.columns.filter((c) => c.hidden).map((c) => c.key), [meta]);
  const [hiddenCols, setHiddenCols] = useLocalStorage<string[]>(`gimt.cols.${module}`, defaultHidden);
  const [formOpen, setFormOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [viewId, setViewId] = useState<number | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const debouncedQ = useDebounce(q, 350);
  useEffect(() => setAppliedQ(debouncedQ), [debouncedQ]);

  const filtersKey = JSON.stringify(cleanFilters(filters));
  const scopeKey = JSON.stringify(scope ?? {});

  // Back to page 1 whenever the search, filters, page size or scope change (not on mount, so ?page= links work).
  const resetSig = `${appliedQ}\n${filtersKey}\n${perPage}\n${scopeKey}`;
  const lastResetSig = useRef(resetSig);
  const lastScopeKey = useRef(scopeKey);
  useEffect(() => {
    if (lastResetSig.current === resetSig) return;
    lastResetSig.current = resetSig;
    setPage(1);
    if (lastScopeKey.current !== scopeKey) {
      lastScopeKey.current = scopeKey;
      setSelected(new Set()); // another parent record: the old selection no longer applies
    }
  }, [resetSig, scopeKey]);

  // Page-controlled filters: merge their new values in when their content changes.
  const controlledKey = controlledFilters ? JSON.stringify(controlledFilters) : null;
  const lastControlledKey = useRef(controlledKey);
  useEffect(() => {
    if (controlledKey === null || controlledKey === lastControlledKey.current) return;
    lastControlledKey.current = controlledKey;
    setFilters((f) => ({ ...f, ...(controlledFilters ?? {}) }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [controlledKey]);

  // Report filter-bar changes to the page.
  const lastReportedKey = useRef(filtersKey);
  useEffect(() => {
    if (!onFiltersChange || lastReportedKey.current === filtersKey) return;
    lastReportedKey.current = filtersKey;
    onFiltersChange(cleanFilters(filters));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtersKey]);

  // URL -> table: a link, the sidebar or back/forward changed ?q / ?page / ?f.* — adopt them (no remount needed).
  const lastUrlKey = useRef(urlStateKey(params));
  useEffect(() => {
    if (!urlState) return;
    const key = urlStateKey(params);
    if (key === lastUrlKey.current) return;
    lastUrlKey.current = key;
    const nextFilters = { ...baseFilters(), ...filtersFromUrl(params, meta.filters) };
    const nextQ = params.get('q') ?? '';
    const nextPage = Math.max(1, Number(params.get('page') ?? 1) || 1);
    // the URL also names the page: do not let the filter change reset it
    lastResetSig.current = `${nextQ}\n${JSON.stringify(cleanFilters(nextFilters))}\n${perPage}\n${scopeKey}`;
    setQ(nextQ);
    setAppliedQ(nextQ);
    setFilters(nextFilters);
    setPage(nextPage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params, urlState]);

  // Table -> URL
  useEffect(() => {
    if (!urlState) return;
    const next = new URLSearchParams(params);
    [...next.keys()].filter(isTableParam).forEach((k) => next.delete(k));
    if (appliedQ) next.set('q', appliedQ);
    if (page > 1) next.set('page', String(page));
    writeFiltersToUrl(next, filters);
    const key = urlStateKey(next);
    lastUrlKey.current = key;
    if (key !== urlStateKey(params)) setParams(next, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appliedQ, page, filtersKey, urlState]);

  const query: Query = {
    page,
    per_page: perPage,
    q: appliedQ || undefined,
    sort: sort?.key,
    dir: sort?.dir,
    f: cleanFilters(filters) as Record<string, unknown>,
    scope: scope as Record<string, unknown> | undefined,
  };
  const { data, isFetching, isLoading, refetch, error } = useCrudList(module, query);
  const rows = data?.rows ?? [];

  const canAdd = meta.can.create && canCreate !== false;

  const openView = (row: Row) => {
    if (onView) return onView(row);
    if (viewTo) return navigate(viewTo(row));
    if (meta.view.type === 'page' && meta.view.url) return navigate(fillTemplate(meta.view.url, row));
    setViewId(Number(row.id));
  };
  const openEdit = (row: Row) => {
    if (onEdit) return onEdit(row);
    if (editTo) return navigate(editTo(row));
    setEditId(Number(row.id));
    setFormOpen(true);
  };
  const openCreate = () => {
    if (onCreate) return onCreate();
    if (createTo) return navigate(createTo);
    setEditId(null);
    setFormOpen(true);
  };

  // Deep link from the top bar "Quick add" menu: /faculty?add=1 opens the create form once. It also reacts when the
  // param arrives while the page is already open (Quick add used on the same page), then removes it from the URL.
  const addParam = params.get('add') === '1';
  useEffect(() => {
    if (scope || !addParam || !canAdd) return;
    const next = new URLSearchParams(params);
    next.delete('add');
    setParams(next, { replace: true });
    openCreate();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [addParam]);

  const remove = async (row: Row) => {
    const name = String(row.name ?? row.title ?? row.full_name ?? row.code ?? `#${row.id}`);
    const ok = await confirm({ title: `Delete ${meta.singular.toLowerCase()}?`, message: <>Are you sure you want to delete <strong>{name}</strong>? This action cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/${module}/${row.id}`);
      toast.success(res.message || `${meta.singular} deleted.`);
      setSelected((s) => {
        if (!s.has(row.id as number)) return s;
        const n = new Set(s);
        n.delete(row.id as number);
        return n;
      });
      await qc.invalidateQueries({ queryKey: ['crud', module] });
      onDeleted?.([Number(row.id)]);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const ids = [...selected].map(Number);
  const clearSel = () => setSelected(new Set());
  const bulkDelete = async () => {
    const ok = await confirm({ title: `Delete ${ids.length} ${ids.length === 1 ? meta.singular.toLowerCase() : meta.title.toLowerCase()}?`, message: 'Selected records will be permanently deleted. Records linked to other data will be skipped.', confirmText: 'Delete selected', danger: true });
    if (!ok) return;
    try {
      const res = await api.post<{ deleted: number; errors: Record<string, string> }>(`crud/${module}/bulk`, { action: 'delete', ids });
      const failed = Object.keys(res.data.errors ?? {}).map(Number);
      if (failed.length) toast.warning(res.message);
      else toast.success(res.message);
      clearSel();
      await qc.invalidateQueries({ queryKey: ['crud', module] });
      const deleted = ids.filter((id) => !failed.includes(id));
      if (deleted.length) onDeleted?.(deleted);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulkStatus = async (value: string) => {
    try {
      const res = await api.post(`crud/${module}/bulk`, { action: 'status', ids, field: meta.bulk.status_field, value });
      toast.success(res.message);
      clearSel();
      await qc.invalidateQueries({ queryKey: ['crud', module] });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const exportQuery = (format: string, onlySelected = false): Query => ({
    format,
    q: appliedQ || undefined,
    sort: sort?.key,
    dir: sort?.dir,
    f: cleanFilters(filters) as Record<string, unknown>,
    scope: scope as Record<string, unknown> | undefined,
    ids: onlySelected ? ids.join(',') : undefined,
  });
  const doExport = (format: 'csv' | 'xlsx' | 'print', onlySelected = false) => {
    if (format === 'print') window.open(apiUrl(`crud/${module}/export`, exportQuery('print', onlySelected)), '_blank', 'noopener');
    else downloadFile(`crud/${module}/export`, exportQuery(format, onlySelected));
  };

  const filterDefs = meta.filters.filter((f) => !hideFilters.includes(f.key) && !(scope && f.key in scope));
  // only filters the user can see count as "active" and are cleared by "Clear": hidden ones belong to the page
  const visibleFilterKeys = new Set(filterDefs.map((f) => f.key));
  const activeFilterCount = Object.keys(cleanFilters(filters)).filter((k) => visibleFilterKeys.has(k)).length;
  const clearFilters = () => setFilters((f) => Object.fromEntries(Object.entries(f).filter(([k]) => !visibleFilterKeys.has(k))));
  const columns: Column<Row>[] = meta.columns
    .filter((c) => !hideColumns.includes(c.key))
    .map((c) => ({
      key: c.key,
      header: c.label,
      sortable: c.sortable,
      align: c.align ?? (c.format === 'money' || c.format === 'number' || c.format === 'percent' ? 'right' : c.format === 'boolean' ? 'center' : undefined),
      hidden: hiddenCols.includes(c.key),
      width: c.width,
      render: (row: Row) => (renderers[c.key] ? renderers[c.key](row) : renderCell(c, row, () => openView(row))),
    }));

  const cardTitle = title === false ? null : title ?? meta.title;
  const clearAll = () => {
    setQ('');
    setAppliedQ('');
    clearFilters();
  };
  const empty = (
    <EmptyState
      icon={appliedQ || activeFilterCount ? FilterX : FileText}
      title={appliedQ || activeFilterCount ? 'No matching records' : emptyTitle ?? `No ${meta.title.toLowerCase()} yet`}
      description={appliedQ || activeFilterCount ? 'Try a different search term or clear the filters.' : emptyText ?? (canAdd ? `Get started by adding the first ${meta.singular.toLowerCase()}.` : `${meta.title} will appear here once they are recorded.`)}
      action={
        appliedQ || activeFilterCount ? (
          <Button variant="secondary" icon={X} onClick={clearAll}>
            Clear filters
          </Button>
        ) : canAdd ? (
          <Button icon={Plus} onClick={openCreate}>
            {addLabel ?? `Add ${meta.singular}`}
          </Button>
        ) : undefined
      }
    />
  );

  const body = (
    <>
      {/* Toolbar */}
      <div className={clsx('flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800', bare && 'px-0 pt-0')}>
        {cardTitle !== null && (
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h2 className="card-title">{cardTitle}</h2>
              <p className="card-subtitle">{description ?? (data ? `${data.total.toLocaleString('en-IN')} record${data.total === 1 ? '' : 's'}` : meta.description)}</p>
            </div>
          </div>
        )}
        <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
          <SearchInput value={q} onChange={setQ} placeholder={meta.search_placeholder} className="w-full lg:max-w-xs" />
          <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
            {toolbar}
            <IconButton icon={RefreshCw} label="Refresh" onClick={() => refetch()} className={clsx(isFetching && '[&_svg]:animate-spin')} />
            <ColumnsMenu
              columns={meta.columns.filter((c) => !hideColumns.includes(c.key))}
              hidden={hiddenCols}
              onToggle={(key) => setHiddenCols((h) => (h.includes(key) ? h.filter((x) => x !== key) : [...h, key]))}
              onReset={() => setHiddenCols(defaultHidden)}
            />
            {meta.export && (
              <Dropdown
                label="Export"
                trigger={<><Download className="h-4 w-4" aria-hidden /><span className="hidden sm:inline">Export</span></>}
                items={[
                  { label: 'Export as CSV', icon: FileText, onClick: () => doExport('csv') },
                  { label: 'Export as Excel', icon: FileSpreadsheet, onClick: () => doExport('xlsx') },
                  { label: 'Print / Save as PDF', icon: Printer, onClick: () => doExport('print') },
                ]}
              />
            )}
            {meta.import && (
              <Button variant="secondary" size="sm" icon={Upload} onClick={() => setImportOpen(true)} aria-label={`Import ${meta.title.toLowerCase()}`}>
                <span className="hidden sm:inline">Import</span>
              </Button>
            )}
            {canAdd && (
              <Button size="sm" icon={Plus} onClick={openCreate}>
                {addLabel ?? `Add ${meta.singular}`}
              </Button>
            )}
          </div>
        </div>
        {filterDefs.length > 0 && (
          <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Filters">
            {filterDefs.map((f) => (
              <FilterControl key={f.key} def={f} value={filters[f.key]} filters={filters} onChange={(v) => setFilters((s) => ({ ...s, [f.key]: v }))} />
            ))}
            {activeFilterCount > 0 && (
              <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
                <FilterX className="h-3.5 w-3.5" aria-hidden /> Clear ({activeFilterCount})
              </button>
            )}
          </div>
        )}
      </div>

      {header && data && header({ total: data.total, summary: data.summary })}

      {/* Bulk bar */}
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 border-b border-brand-100 bg-brand-50/70 px-4 py-2.5 text-sm dark:border-brand-500/20 dark:bg-brand-500/10" role="region" aria-label="Bulk actions">
          <span className="font-semibold text-brand-900 dark:text-brand-100" aria-live="polite">{selected.size} selected</span>
          <button type="button" className="text-xs text-brand-700 underline dark:text-brand-300" onClick={clearSel}>
            Clear
          </button>
          <div className="ml-auto flex flex-wrap gap-2">
            {meta.bulk.status && meta.bulk.status.length > 0 && (
              <Dropdown trigger={<>Set {labelize(meta.bulk.status_field)}</>} items={meta.bulk.status.map((s) => ({ label: labelize(s), onClick: () => bulkStatus(s) }))} />
            )}
            {meta.export && (
              <Dropdown
                trigger={<><Download className="h-4 w-4" aria-hidden />Export selected</>}
                items={[
                  { label: 'CSV', onClick: () => doExport('csv', true) },
                  { label: 'Excel', onClick: () => doExport('xlsx', true) },
                  { label: 'Print / PDF', onClick: () => doExport('print', true) },
                ]}
              />
            )}
            {bulkActions.map((a) => (
              <Button key={a.label} size="sm" variant={a.danger ? 'danger' : 'secondary'} icon={a.icon} onClick={() => a.onClick(ids, clearSel)}>
                {a.label}
              </Button>
            ))}
            {meta.bulk.delete && (
              <Button size="sm" variant="danger" icon={Trash2} onClick={bulkDelete}>
                Delete
              </Button>
            )}
          </div>
        </div>
      )}

      {error ? (
        <div className="p-6">
          <Alert variant="error" title="Unable to load records" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
            {(error as ApiError).message}
          </Alert>
        </div>
      ) : (
        <DataTable<Row>
          columns={columns}
          rows={rows}
          loading={isLoading || isFetching}
          sort={sort}
          onSort={setSort}
          selectable={meta.bulk.delete || !!meta.bulk.status || bulkActions.length > 0 || meta.export}
          selected={selected}
          onSelectedChange={setSelected}
          empty={empty}
          caption={meta.title}
          actions={
            noRowActions
              ? undefined
              : (row) => {
                  const extra = rowMenu?.(row)?.filter(Boolean) ?? [];
                  const editable = meta.can.edit && (canEdit?.(row) ?? true);
                  const deletable = meta.can.delete && (canDelete?.(row) ?? true);
                  return (
                    <div className="flex items-center justify-end gap-0.5">
                      <IconButton size="sm" icon={Eye} label="View" tone="primary" onClick={() => openView(row)} />
                      {editable && <IconButton size="sm" icon={Pencil} label="Edit" tone="primary" onClick={() => openEdit(row)} />}
                      {deletable && <IconButton size="sm" icon={Trash2} label="Delete" tone="danger" onClick={() => remove(row)} />}
                      {extra.length > 0 && (
                        <Dropdown label="More actions" triggerClassName="btn-icon !h-8 !w-8 !rounded-lg" trigger={<MoreHorizontal className="h-4 w-4" aria-hidden />} items={extra} />
                      )}
                    </div>
                  );
                }
          }
        />
      )}

      {data && data.total > 0 && (
        <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />
      )}

      <CrudFormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        meta={meta}
        module={module}
        id={editId}
        defaults={formDefaults}
        scope={scope}
        onSaved={(row, id) => {
          void qc.invalidateQueries({ queryKey: ['crud-record', module] });
          onSaved?.(row as Row | null, id);
        }}
      />
      <CrudViewModal
        open={viewId !== null}
        onClose={() => setViewId(null)}
        meta={meta}
        module={module}
        id={viewId}
        onEdit={() => {
          const id = viewId;
          setViewId(null);
          if (id) openEdit({ id });
        }}
      />
      {meta.import && (
        <ImportDialog
          open={importOpen}
          onClose={() => setImportOpen(false)}
          module={module}
          title={meta.title}
          scope={Object.entries(scope ?? {}).flatMap(([name, value]) => {
            const f = meta.fields.find((x) => x.name === name);
            return f ? [{ name, label: f.label, value }] : [];
          })}
        />
      )}
    </>
  );

  return bare ? <div className={className}>{body}</div> : <Card className={clsx('overflow-hidden', className)}>{body}</Card>;
}

/** Column chooser: a popover of checkboxes (stays open while toggling) with a reset to the module's default layout. */
function ColumnsMenu({ columns, hidden, onToggle, onReset }: { columns: CrudColumn[]; hidden: string[]; onToggle: (key: string) => void; onReset: () => void }) {
  const shown = columns.filter((c) => !hidden.includes(c.key)).length;
  return (
    <Popover className="relative">
      <PopoverButton className="btn btn-secondary btn-sm" aria-label={`Choose columns (${shown} of ${columns.length} shown)`}>
        <Columns3 className="h-4 w-4" aria-hidden />
        <span className="hidden sm:inline">Columns</span>
      </PopoverButton>
      <PopoverPanel anchor="bottom end" className="menu-panel z-[60] w-64 p-1.5 [--anchor-gap:6px]">
        <p className="px-2 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
          Columns · {shown}/{columns.length}
        </p>
        <div className="max-h-72 overflow-y-auto">
          {columns.map((c) => {
            const on = !hidden.includes(c.key);
            // keep at least one column visible
            const locked = on && shown === 1;
            return (
              <label key={c.key} className={clsx('flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800', locked && 'cursor-not-allowed opacity-60')}>
                <input type="checkbox" className="form-checkbox" checked={on} disabled={locked} onChange={() => onToggle(c.key)} />
                <span className="min-w-0 flex-1 truncate">{c.label}</span>
              </label>
            );
          })}
        </div>
        <div className="mt-1 border-t border-slate-100 pt-1 dark:border-slate-800">
          <button type="button" onClick={onReset} className="menu-item w-full hover:bg-slate-100 dark:hover:bg-slate-800">
            <RotateCcw className="h-4 w-4 shrink-0 opacity-70" aria-hidden />
            <span className="flex-1 text-left">Reset to default columns</span>
          </button>
        </div>
      </PopoverPanel>
    </Popover>
  );
}

function FilterControl({ def, value, onChange, filters }: { def: CrudFilter; value: unknown; onChange: (v: unknown) => void; filters: Record<string, unknown> }) {
  const params: Record<string, string> = {};
  Object.entries(def.depends ?? {}).forEach(([param, key]) => {
    if (filters[key]) params[param] = String(filters[key]);
  });
  // native selects size to their longest option: cap them so one long program name cannot stretch a filter across the bar
  const cls = 'w-full sm:w-auto sm:min-w-[10rem] sm:max-w-[16rem]';
  switch (def.type) {
    case 'daterange': {
      const v = (value as { from?: string; to?: string }) ?? {};
      return (
        <div className="flex items-center gap-1.5" role="group" aria-label={def.label}>
          <span className="hidden text-xs font-medium text-slate-500 dark:text-slate-400 sm:inline">{def.label}</span>
          <Input inputSize="sm" type="date" value={v.from ?? ''} max={v.to || undefined} onChange={(e) => onChange({ ...v, from: e.target.value })} aria-label={`${def.label} from`} className="!w-36" />
          <span className="text-xs text-slate-400">to</span>
          <Input inputSize="sm" type="date" value={v.to ?? ''} min={v.from || undefined} onChange={(e) => onChange({ ...v, to: e.target.value })} aria-label={`${def.label} to`} className="!w-36" />
        </div>
      );
    }
    case 'date':
      return <Input inputSize="sm" type="date" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} aria-label={def.label} className="!w-40" />;
    case 'text':
      return <Input inputSize="sm" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} placeholder={def.placeholder ?? def.label} aria-label={def.label} className="!w-44" />;
    case 'boolean':
      return <Select inputSize="sm" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} options={[{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }]} placeholder={`${def.label}: Any`} aria-label={def.label} className={cls} />;
    case 'multiselect': {
      const list = Array.isArray(value) ? (value as (string | number)[]) : value ? [String(value)] : [];
      return (
        <div className={clsx(cls, 'sm:w-64')} aria-label={def.label}>
          <Combobox multiple size="sm" value={list} onChange={(v) => onChange(v)} options={def.options_url ? undefined : def.options} optionsUrl={def.options_url} params={params} placeholder={`${def.label}: All`} />
        </div>
      );
    }
    default:
      if (def.options_url || (def.options && def.options.length > 12)) {
        return (
          <div className={clsx(cls, 'sm:w-56')}>
            <Combobox size="sm" value={(value as string) ?? null} onChange={(v) => onChange(v ?? '')} options={def.options_url ? undefined : def.options} optionsUrl={def.options_url} params={params} placeholder={`${def.label}: All`} />
          </div>
        );
      }
      return <Select inputSize="sm" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} options={def.options ?? []} placeholder={`${def.label}: All`} aria-label={def.label} className={cls} />;
  }
}
