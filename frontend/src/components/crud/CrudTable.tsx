import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Columns3, Download, Eye, FileSpreadsheet, FileText, FilterX, MoreHorizontal, Pencil, Plus, Printer, RefreshCw, Trash2, Upload, X, type LucideIcon } from 'lucide-react';
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
import type { CrudFilter, CrudMeta } from './types';

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
  /** Fixed hidden filters (module must declare them in 'scopes'), e.g. { student_id: 5 } */
  scope?: Record<string, string | number>;
  /** Initial filter values */
  defaultFilters?: Record<string, unknown>;
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
  onEdit?: (row: Row) => void;
  editTo?: (row: Row) => string;
  formDefaults?: Record<string, unknown>;
  bulkActions?: CrudBulkAction[];
  /** Sync search/filters/page into the URL query string */
  urlState?: boolean;
  addLabel?: string;
  emptyTitle?: string;
  emptyText?: string;
  onSaved?: (row: Row | null, id: number) => void;
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
  module, meta, title, description, scope, defaultFilters, hideColumns = [], hideFilters = [], renderers = {}, rowMenu, toolbar, viewTo, onView,
  onCreate, createTo, onEdit, editTo, formDefaults, bulkActions = [], urlState, addLabel, emptyTitle, emptyText, onSaved, bare, noRowActions, className, header,
}: CrudTableProps & { meta: CrudMeta }) {
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();

  const initialFilters = useMemo(() => {
    const f: Record<string, unknown> = { ...(defaultFilters ?? {}) };
    meta.filters.forEach((flt) => {
      if (flt.default !== undefined && f[flt.key] === undefined) f[flt.key] = flt.default;
      if (urlState && params.get(`f.${flt.key}`)) f[flt.key] = params.get(`f.${flt.key}`);
    });
    return f;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [meta]);

  const [q, setQ] = useState(urlState ? params.get('q') ?? '' : '');
  const [filters, setFilters] = useState<Record<string, unknown>>(initialFilters);
  const [page, setPage] = useState(urlState ? Number(params.get('page') ?? 1) : 1);
  const [perPage, setPerPage] = useLocalStorage<number>(`gimt.perPage.${module}`, meta.per_page || 25);
  const [sort, setSort] = useState<SortState | null>(meta.default_sort ?? null);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [hiddenCols, setHiddenCols] = useLocalStorage<string[]>(`gimt.cols.${module}`, meta.columns.filter((c) => c.hidden).map((c) => c.key));
  const [formOpen, setFormOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [viewId, setViewId] = useState<number | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const debouncedQ = useDebounce(q, 350);

  useEffect(() => setPage(1), [debouncedQ, filters, perPage]);
  useEffect(() => {
    if (!urlState) return;
    const next = new URLSearchParams(params);
    ['q', 'page'].forEach((k) => next.delete(k));
    [...next.keys()].filter((k) => k.startsWith('f.')).forEach((k) => next.delete(k));
    if (debouncedQ) next.set('q', debouncedQ);
    if (page > 1) next.set('page', String(page));
    Object.entries(cleanFilters(filters)).forEach(([k, v]) => typeof v !== 'object' && next.set(`f.${k}`, String(v)));
    if (next.toString() !== params.toString()) setParams(next, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedQ, page, filters, urlState]);

  const query: Query = {
    page,
    per_page: perPage,
    q: debouncedQ || undefined,
    sort: sort?.key,
    dir: sort?.dir,
    f: cleanFilters(filters) as Record<string, unknown>,
    scope: scope as Record<string, unknown> | undefined,
  };
  const { data, isFetching, isLoading, refetch, error } = useCrudList(module, query);
  const rows = data?.rows ?? [];

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

  const remove = async (row: Row) => {
    const name = String(row.name ?? row.title ?? row.full_name ?? row.code ?? `#${row.id}`);
    const ok = await confirm({ title: `Delete ${meta.singular.toLowerCase()}?`, message: <>Are you sure you want to delete <strong>{name}</strong>? This action cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/${module}/${row.id}`);
      toast.success(res.message || `${meta.singular} deleted.`);
      await qc.invalidateQueries({ queryKey: ['crud', module] });
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
      if (Object.keys(res.data.errors ?? {}).length) toast.warning(res.message);
      else toast.success(res.message);
      clearSel();
      await qc.invalidateQueries({ queryKey: ['crud', module] });
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
    q: debouncedQ || undefined,
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
  const activeFilterCount = Object.keys(cleanFilters(filters)).length;
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
  const canAdd = meta.can.create;
  const empty = (
    <EmptyState
      icon={debouncedQ || activeFilterCount ? FilterX : FileText}
      title={debouncedQ || activeFilterCount ? 'No matching records' : emptyTitle ?? `No ${meta.title.toLowerCase()} yet`}
      description={debouncedQ || activeFilterCount ? 'Try a different search term or clear the filters.' : emptyText ?? `Get started by adding the first ${meta.singular.toLowerCase()}.`}
      action={
        debouncedQ || activeFilterCount ? (
          <Button variant="secondary" icon={X} onClick={() => { setQ(''); setFilters({}); }}>
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
            <Dropdown
              label="Choose columns"
              trigger={<><Columns3 className="h-4 w-4" /><span className="hidden sm:inline">Columns</span></>}
              items={meta.columns
                .filter((c) => !hideColumns.includes(c.key))
                .map((c) => ({
                  label: `${hiddenCols.includes(c.key) ? '☐' : '☑'}  ${c.label}`,
                  onClick: () => setHiddenCols((h) => (h.includes(c.key) ? h.filter((x) => x !== c.key) : [...h, c.key])),
                }))}
            />
            {meta.export && (
              <Dropdown
                label="Export"
                trigger={<><Download className="h-4 w-4" /><span className="hidden sm:inline">Export</span></>}
                items={[
                  { label: 'Export as CSV', icon: FileText, onClick: () => doExport('csv') },
                  { label: 'Export as Excel', icon: FileSpreadsheet, onClick: () => doExport('xlsx') },
                  { label: 'Print / Save as PDF', icon: Printer, onClick: () => doExport('print') },
                ]}
              />
            )}
            {meta.import && (
              <Button variant="secondary" size="sm" icon={Upload} onClick={() => setImportOpen(true)}>
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
          <div className="flex flex-wrap items-center gap-2">
            {filterDefs.map((f) => (
              <FilterControl key={f.key} def={f} value={filters[f.key]} filters={filters} onChange={(v) => setFilters((s) => ({ ...s, [f.key]: v }))} />
            ))}
            {activeFilterCount > 0 && (
              <button type="button" onClick={() => setFilters({})} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800">
                <FilterX className="h-3.5 w-3.5" /> Clear ({activeFilterCount})
              </button>
            )}
          </div>
        )}
      </div>

      {header && data && header({ total: data.total, summary: data.summary })}

      {/* Bulk bar */}
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 border-b border-brand-100 bg-brand-50/70 px-4 py-2.5 text-sm dark:border-brand-500/20 dark:bg-brand-500/10">
          <span className="font-semibold text-brand-900 dark:text-brand-100">{selected.size} selected</span>
          <button type="button" className="text-xs text-brand-700 underline dark:text-brand-300" onClick={clearSel}>
            Clear
          </button>
          <div className="ml-auto flex flex-wrap gap-2">
            {meta.bulk.status && meta.bulk.status.length > 0 && (
              <Dropdown trigger={<>Set {labelize(meta.bulk.status_field)}</>} items={meta.bulk.status.map((s) => ({ label: labelize(s), onClick: () => bulkStatus(s) }))} />
            )}
            {meta.export && (
              <Dropdown
                trigger={<><Download className="h-4 w-4" />Export selected</>}
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
          <Alert variant="error" title="Unable to load records">
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
                  return (
                    <div className="flex items-center justify-end gap-0.5">
                      <IconButton size="sm" icon={Eye} label="View" tone="primary" onClick={() => openView(row)} />
                      {meta.can.edit && <IconButton size="sm" icon={Pencil} label="Edit" tone="primary" onClick={() => openEdit(row)} />}
                      {meta.can.delete && <IconButton size="sm" icon={Trash2} label="Delete" tone="danger" onClick={() => remove(row)} />}
                      {extra.length > 0 && (
                        <Dropdown label="More actions" triggerClassName="btn-icon !h-8 !w-8 !rounded-lg" trigger={<MoreHorizontal className="h-4 w-4" />} items={extra} />
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
      {meta.import && <ImportDialog open={importOpen} onClose={() => setImportOpen(false)} module={module} title={meta.title} />}
    </>
  );

  return bare ? <div className={className}>{body}</div> : <Card className={clsx('overflow-hidden', className)}>{body}</Card>;
}

function FilterControl({ def, value, onChange, filters }: { def: CrudFilter; value: unknown; onChange: (v: unknown) => void; filters: Record<string, unknown> }) {
  const params: Record<string, string> = {};
  Object.entries(def.depends ?? {}).forEach(([param, key]) => {
    if (filters[key]) params[param] = String(filters[key]);
  });
  const cls = 'w-full sm:w-auto sm:min-w-[10rem]';
  switch (def.type) {
    case 'daterange': {
      const v = (value as { from?: string; to?: string }) ?? {};
      return (
        <div className="flex items-center gap-1.5" aria-label={def.label}>
          <Input inputSize="sm" type="date" value={v.from ?? ''} onChange={(e) => onChange({ ...v, from: e.target.value })} aria-label={`${def.label} from`} className="!w-36" />
          <span className="text-xs text-slate-400">to</span>
          <Input inputSize="sm" type="date" value={v.to ?? ''} onChange={(e) => onChange({ ...v, to: e.target.value })} aria-label={`${def.label} to`} className="!w-36" />
        </div>
      );
    }
    case 'date':
      return <Input inputSize="sm" type="date" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} aria-label={def.label} className="!w-40" />;
    case 'text':
      return <Input inputSize="sm" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} placeholder={def.placeholder ?? def.label} aria-label={def.label} className="!w-44" />;
    case 'boolean':
      return <Select inputSize="sm" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} options={[{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }]} placeholder={`${def.label}: Any`} aria-label={def.label} className={cls} />;
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
