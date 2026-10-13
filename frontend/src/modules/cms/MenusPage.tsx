import { useEffect, useState } from 'react';
import { useBlocker, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowDown, ArrowUp, ChevronDown, ChevronRight, CircleAlert, CornerDownRight, ExternalLink, GripVertical, IndentDecrease, IndentIncrease, LayoutGrid, ListTree, Menu as MenuIcon, Plus,
  RefreshCw, RotateCcw, Save, Trash2, Undo2,
} from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Combobox, Dropdown, EmptyState, Field, IconButton, Input, PageHeader, Select, Skeleton, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { useApi } from '@/lib/queries';
import { isValidLink, useCmsSchema, usePreviewData } from './site/api';
import { CmsIcon } from './site/icons';
import { DrawerPreview, FooterPreview, HeaderPreview, TopbarPreview } from './site/MenuPreview';
import { IconPicker } from './site/ShapeForm';
import { ScaledFrame } from './site/preview/kit';
import { moveItem, useSortable } from './site/sortable';
import type { MenuItemNode, MenuLocation } from './site/types';

interface LocationRow extends MenuLocation {
  location: string;
  menu_id: number | null;
  name: string;
  status: string;
  items: number;
  active_items: number;
}
interface ServerItem {
  id: number | null;
  title: string;
  url: string | null;
  page_id: number | null;
  page_title?: string | null;
  target: string;
  icon: string | null;
  description: string | null;
  is_mega: boolean | number;
  status: string;
  children: ServerItem[];
}
interface MenuPayload {
  location: MenuLocation & { location: string };
  menu: { id: number | null; name: string; status: string };
  items: ServerItem[];
}

let keySeq = 0;
const newKey = () => `n${Date.now().toString(36)}${(keySeq += 1)}`;
const toNodes = (items: ServerItem[]): MenuItemNode[] =>
  items.map((it) => ({
    id: it.id ?? null, key: it.id ? `i${it.id}` : newKey(), title: it.title ?? '', url: it.url ?? '', page_id: it.page_id ?? null, target: it.target === '_blank' ? '_blank' : '_self',
    icon: it.icon ?? null, description: it.description ?? null, is_mega: !!Number(it.is_mega), status: it.status === 'inactive' ? 'inactive' : 'active', children: toNodes(it.children ?? []),
  }));
const strip = (nodes: MenuItemNode[]): unknown[] => nodes.map(({ key: _k, ...n }) => ({ ...n, children: strip(n.children) })); // eslint-disable-line @typescript-eslint/no-unused-vars
const count = (nodes: MenuItemNode[]): number => nodes.reduce((n, x) => n + 1 + count(x.children), 0);

type Path = number[];
const getList = (tree: MenuItemNode[], parent: Path): MenuItemNode[] => parent.reduce<MenuItemNode[]>((list, i) => list[i].children, tree);
function mapList(tree: MenuItemNode[], parent: Path, fn: (list: MenuItemNode[]) => MenuItemNode[]): MenuItemNode[] {
  if (!parent.length) return fn(tree);
  const [h, ...rest] = parent;
  return tree.map((n, i) => (i === h ? { ...n, children: mapList(n.children, rest, fn) } : n));
}

export default function MenusPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const schema = useCmsSchema();
  const preview = usePreviewData();
  const { data: locData, isLoading: locLoading, error: locError } = useApi<{ locations: LocationRow[]; can: { edit: boolean } }>(['cms', 'menus'], 'menus');
  const location = params.get('location') ?? 'header';
  const { data, isLoading, error } = useApi<MenuPayload>(['cms', 'menu', location], `menus/${location}`, undefined, { refetchOnWindowFocus: false });
  const [tree, setTree] = useState<MenuItemNode[]>([]);
  const [meta, setMeta] = useState({ name: '', status: 'active' });
  const [snapshot, setSnapshot] = useState('');
  const [openKey, setOpenKey] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const canEdit = locData?.can.edit ?? false;
  const loc = locData?.locations.find((l) => l.location === location);
  const maxDepth = loc?.depth ?? 1;

  useEffect(() => {
    if (!data) return;
    const nodes = toNodes(data.items);
    setTree(nodes);
    setMeta({ name: data.menu.name, status: data.menu.status });
    setSnapshot(JSON.stringify({ t: strip(nodes), m: { name: data.menu.name, status: data.menu.status } }));
    setErrors({});
    setOpenKey(null);
    // keep long mega menus compact: columns start collapsed
    const c = new Set<string>();
    const walk = (list: MenuItemNode[], depth: number) => list.forEach((n) => {
      if (depth >= 1 && n.children.length) c.add(n.key);
      walk(n.children, depth + 1);
    });
    walk(nodes, 0);
    setCollapsed(c);
  }, [data]);
  const dirty = !!data && JSON.stringify({ t: strip(tree), m: meta }) !== snapshot;

  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    void confirm({ title: 'Leave without saving?', message: 'Your changes to this menu have not been saved.', confirmText: 'Discard changes', danger: true }).then((ok) => (ok ? blocker.proceed() : blocker.reset()));
  }, [blocker, confirm]);

  const switchLocation = async (next: string) => {
    if (next === location) return;
    if (dirty && !(await confirm({ title: 'Discard unsaved changes?', message: `Your changes to the ${loc?.short ?? ''} menu will be lost.`, confirmText: 'Discard', danger: true }))) return;
    setParams({ location: next });
  };

  // ----- tree operations
  const updateNode = (parent: Path, i: number, patch: Partial<MenuItemNode>) => {
    setTree((t) => mapList(t, parent, (list) => list.map((n, j) => (j === i ? { ...n, ...patch } : n))));
    setErrors({});
  };
  const removeNode = async (parent: Path, i: number) => {
    const n = getList(tree, parent)[i];
    if (n.children.length && !(await confirm({ title: `Remove “${n.title}”?`, message: `Its ${count(n.children)} sub-item(s) will be removed too.`, confirmText: 'Remove', danger: true }))) return;
    setTree((t) => mapList(t, parent, (list) => list.filter((_, j) => j !== i)));
  };
  const moveNode = (parent: Path, from: number, to: number) => setTree((t) => mapList(t, parent, (list) => moveItem(list, from, to)));
  const addNode = (parent: Path) => {
    const node: MenuItemNode = { id: null, key: newKey(), title: '', url: '', page_id: null, target: '_self', icon: null, description: null, is_mega: false, status: 'active', children: [] };
    setTree((t) => mapList(t, parent, (list) => [...list, node]));
    setOpenKey(node.key);
  };
  const indent = (parent: Path, i: number) => {
    if (i === 0) return;
    setTree((t) => {
      const list = getList(t, parent);
      const node = list[i];
      return mapList(t, parent, (l) => l.filter((_, j) => j !== i).map((n, j) => (j === i - 1 ? { ...n, children: [...n.children, { ...node, is_mega: false }] } : n)));
    });
  };
  const outdent = (parent: Path, i: number) => {
    if (!parent.length) return;
    const grand = parent.slice(0, -1);
    const pIndex = parent[parent.length - 1];
    setTree((t) => {
      const node = getList(t, parent)[i];
      const removed = mapList(t, parent, (l) => l.filter((_, j) => j !== i));
      return mapList(removed, grand, (l) => {
        const next = l.slice();
        next.splice(pIndex + 1, 0, node);
        return next;
      });
    });
  };
  const allowsChildren = (depth: number, parentMega: boolean) => depth + 1 < maxDepth && !(location === 'header' && depth === 1 && !parentMega);
  const toggleCollapse = (key: string) => setCollapsed((c) => {
    const next = new Set(c);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    return next;
  });

  // ----- actions
  const save = async () => {
    // client-side checks mirror the server
    const errs: Record<string, string> = {};
    const walk = (nodes: MenuItemNode[], path: string) => nodes.forEach((n, i) => {
      if (!n.title.trim()) errs[`${path}.${i}.title`] = 'Title is required.';
      if (!n.page_id && !n.url.trim()) errs[`${path}.${i}.url`] = 'Enter a URL or choose a page.';
      else if (!n.page_id && !isValidLink(n.url.trim())) errs[`${path}.${i}.url`] = 'Enter a valid link, e.g. /about or https://example.com.';
      walk(n.children, `${path}.${i}.children`);
    });
    walk(tree, 'items');
    if (Object.keys(errs).length) {
      setErrors(errs);
      toast.error('Please fix the highlighted menu items.');
      return;
    }
    setSaving(true);
    try {
      const res = await api.put<{ menu: { name: string; status: string }; items: ServerItem[] }>(`menus/${location}`, { name: meta.name, status: meta.status, items: strip(tree) });
      qc.setQueryData(['cms', 'menu', location], { ...data, menu: { ...data?.menu, ...res.data.menu }, items: res.data.items });
      void qc.invalidateQueries({ queryKey: ['cms', 'menus'] });
      void qc.invalidateQueries({ queryKey: ['cms', 'overview'] });
      toast.success(res.message);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  const syncPrograms = async (mode: 'auto' | 'columns') => {
    const what = mode === 'auto'
      ? 'The Programs mega menu will show every active program automatically, as cards with level and department filters (recommended).'
      : 'Editable Undergraduate, Postgraduate and Diploma & Certificate columns are generated from the active programs.';
    const ok = await confirm({ title: mode === 'auto' ? 'Use the automatic Programs mega menu?' : 'Generate Programs columns?', message: dirty ? `Unsaved changes to this menu will be lost. ${what}` : what, confirmText: mode === 'auto' ? 'Use automatic' : 'Generate columns' });
    if (!ok) return;
    setSyncing(true);
    try {
      const res = await api.post<{ items: ServerItem[] }>(`menus/${location}/sync-programs`, { mode });
      qc.setQueryData(['cms', 'menu', location], { ...data, items: res.data.items });
      void qc.invalidateQueries({ queryKey: ['cms', 'menus'] });
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSyncing(false);
    }
  };
  const restoreDefaults = async () => {
    const ok = await confirm({ title: 'Load the default links?', message: 'The editor is filled with the website’s built-in links for this location. Nothing is saved until you click “Save menu”.', confirmText: 'Load defaults' });
    if (!ok) return;
    try {
      const res = await api.get<{ items: ServerItem[] }>(`menus/${location}/fallback`);
      setTree(toNodes(res.items));
      toast.info('Default links loaded — review them and save the menu.');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Menus"
        description="Header navigation with dropdowns and the Programs mega menu, top bar links, footer columns and the legal bar."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Menus' }]}
        actions={
          <>
            <Button variant="secondary" icon={ExternalLink} href={appUrl('/')} target="_blank">View website</Button>
            {dirty && <Button variant="ghost" icon={Undo2} onClick={() => { if (!data) return; setTree(toNodes(data.items)); setMeta({ name: data.menu.name, status: data.menu.status }); setErrors({}); }}>Discard</Button>}
            {canEdit && <Button icon={Save} onClick={save} loading={saving} disabled={!dirty}>Save menu</Button>}
          </>
        }
      />
      {locError && <Alert variant="error" className="mb-6">{(locError as ApiError).message}</Alert>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
        {/* Locations: select on phones, list on larger screens */}
        <div className="lg:hidden">
          <Field label="Menu location" htmlFor="menu-location">
            <Select id="menu-location" value={location} onChange={(e) => switchLocation(e.target.value)}
              options={(locData?.locations ?? []).map((l) => ({ value: l.location, label: `${l.label} (${l.items})` }))} />
          </Field>
        </div>
        <Card className="hidden h-fit overflow-hidden lg:block">
          <CardHeader title="Locations" subtitle="Where each menu appears" icon={MenuIcon} />
          <nav className="p-2" aria-label="Menu locations">
            {locLoading
              ? Array.from({ length: 7 }).map((_, i) => <Skeleton key={i} className="mb-2 h-12 w-full rounded-xl" />)
              : locData?.locations.map((l) => (
                  <button key={l.location} type="button" onClick={() => switchLocation(l.location)} aria-current={l.location === location ? 'page' : undefined}
                    className={clsx('mb-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition', l.location === location ? 'bg-brand-50 text-brand-900 dark:bg-brand-500/15 dark:text-white' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60')}>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-semibold">{l.short}</span>
                      <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">{l.items ? `${l.active_items} of ${l.items} links active` : 'Empty — uses defaults'}</span>
                    </span>
                    {l.status === 'inactive' ? <Badge color="red">Off</Badge> : <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-slate-600 dark:bg-slate-800 dark:text-slate-300">{l.items}</span>}
                  </button>
                ))}
          </nav>
        </Card>

        <div className="min-w-0 space-y-6">
          {/* Preview */}
          <Card className="overflow-hidden">
            <CardHeader icon={LayoutGrid} title="Live preview" subtitle="How this menu looks on the website (Design v3 — approximate)" />
            <div className="border-t border-slate-100 dark:border-slate-800">
              {location === 'header' && <ScaledFrame width={880}><HeaderPreview items={tree} settings={preview.data?.settings} focusKey={openKey} programs={preview.data?.programs} /></ScaledFrame>}
              {location === 'topbar' && <TopbarPreview items={tree} settings={preview.data?.settings} />}
              {location === 'mobile' && <div className="bg-[#F5F2EE] p-6"><DrawerPreview items={tree} /></div>}
              {['footer_quick', 'footer_academics', 'footer_admissions', 'footer_support', 'legal'].includes(location) && <FooterPreview location={location} items={tree} />}
            </div>
          </Card>
          {/* Editor */}
          <Card>
            <CardHeader
              icon={ListTree}
              title={loc?.label ?? 'Menu'}
              subtitle={loc ? `${loc.description} · up to ${loc.depth} level${loc.depth > 1 ? 's' : ''}` : undefined}
            />
            <div className="card-body space-y-4">
              {canEdit && (
                <div className="flex flex-wrap items-center justify-end gap-2">
                  {location === 'header' && (
                    <Dropdown triggerClassName="btn btn-secondary btn-sm" label="Programs mega menu" trigger={<><RefreshCw className={clsx('h-4 w-4', syncing && 'animate-spin')} />Programs menu</>}
                      items={[
                        { label: 'Automatic program cards (recommended)', icon: LayoutGrid, onClick: () => syncPrograms('auto') },
                        { label: 'Generate editable columns', icon: ListTree, onClick: () => syncPrograms('columns') },
                      ]} />
                  )}
                  <Button size="sm" variant="ghost" icon={RotateCcw} onClick={restoreDefaults}>Defaults</Button>
                  <Button size="sm" icon={Plus} onClick={() => addNode([])}>Add item</Button>
                </div>
              )}

              {error ? <Alert variant="error">{(error as ApiError).message}</Alert> : isLoading || !schema.data ? (
                <div className="space-y-2">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-12 w-full rounded-xl" />)}</div>
              ) : (
                <>
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    <Field label="Menu name" htmlFor="menu-name" error={errors.name}>
                      <Input id="menu-name" value={meta.name} maxLength={100} disabled={!canEdit} onChange={(e) => setMeta((m) => ({ ...m, name: e.target.value }))} />
                    </Field>
                    <div className="pb-2">
                      <Toggle checked={meta.status === 'active'} onChange={(v) => setMeta((m) => ({ ...m, status: v ? 'active' : 'inactive' }))} label="Menu active" description={meta.status === 'active' ? 'Shown on the website' : 'Website uses its default links'} disabled={!canEdit} />
                    </div>
                  </div>
                  {errors.items && <Alert variant="error">{errors.items}</Alert>}
                  {tree.length ? (
                    <TreeList nodes={tree} parent={[]} depth={0} parentMega={false} canEdit={canEdit} openKey={openKey} setOpenKey={setOpenKey} errors={errors} icons={schema.data.icons}
                      ops={{ updateNode, removeNode, moveNode, addNode, indent, outdent, allowsChildren, toggleCollapse, collapsed, flat: maxDepth === 1 }} location={location} />
                  ) : (
                    <EmptyState icon={ListTree} title="This menu is empty" description="The website shows its built-in default links until you add items here."
                      action={canEdit && <><Button icon={Plus} onClick={() => addNode([])}>Add item</Button><Button variant="secondary" icon={RotateCcw} onClick={restoreDefaults}>Load default links</Button></>} />
                  )}
                </>
              )}
            </div>
          </Card>

        </div>
      </div>
    </>
  );
}

interface TreeOps {
  updateNode: (parent: Path, i: number, patch: Partial<MenuItemNode>) => void;
  removeNode: (parent: Path, i: number) => void;
  moveNode: (parent: Path, from: number, to: number) => void;
  addNode: (parent: Path) => void;
  indent: (parent: Path, i: number) => void;
  outdent: (parent: Path, i: number) => void;
  allowsChildren: (depth: number, parentMega: boolean) => boolean;
  toggleCollapse: (key: string) => void;
  collapsed: Set<string>;
  flat: boolean;
}

function TreeList({ nodes, parent, depth, parentMega, canEdit, openKey, setOpenKey, errors, icons, ops, location }: {
  nodes: MenuItemNode[]; parent: Path; depth: number; parentMega: boolean; canEdit: boolean; openKey: string | null; setOpenKey: (k: string | null) => void;
  errors: Record<string, string>; icons: string[]; ops: TreeOps; location: string;
}) {
  const sortable = useSortable((from, to) => ops.moveNode(parent, from, to), `menu-${parent.join('-') || 'root'}`);
  const pathKey = (i: number) => ['items', ...parent.flatMap((p) => [p, 'children']), i].join('.');
  return (
    <ol className={clsx('space-y-2', depth > 0 && 'mt-2 border-l-2 border-dashed border-slate-200 pl-4 dark:border-slate-700')}>
      {nodes.map((n, i) => {
        const key = pathKey(i);
        const nodeErrors = Object.keys(errors).filter((k) => k.startsWith(`${key}.`) && !k.startsWith(`${key}.children`));
        const isOpen = openKey === n.key || nodeErrors.length > 0;
        const canIndent = canEdit && i > 0 && ops.allowsChildren(depth, parentMega);
        const childAllowed = ops.allowsChildren(depth, parentMega);
        return (
          <li key={n.key} {...sortable.dropProps(i)} className={clsx(sortable.overIndex === i && sortable.dragIndex !== i && 'rounded-xl ring-2 ring-[#D03E16]/50', sortable.dragIndex === i && 'opacity-50')}>
            <div className={clsx('rounded-xl border bg-white dark:bg-slate-900', nodeErrors.length ? 'border-red-300 dark:border-red-500/50' : 'border-slate-200 dark:border-slate-700', n.status === 'inactive' && 'opacity-60')}>
              <div className="flex items-center gap-2 px-2.5 py-2" {...(canEdit ? sortable.dragProps(i) : {})}>
                {canEdit && <span className="cursor-grab text-slate-300 active:cursor-grabbing dark:text-slate-600"><GripVertical className="h-4 w-4" /></span>}
                {depth > 0 && <CornerDownRight className="h-3.5 w-3.5 shrink-0 text-slate-300" />}
                <button type="button" onClick={() => setOpenKey(isOpen && !nodeErrors.length ? null : n.key)} className="flex min-w-0 flex-1 items-center gap-2 text-left" aria-expanded={isOpen}>
                  {nodeErrors.length > 0 ? <CircleAlert className="h-4 w-4 shrink-0 text-red-500" /> : n.icon ? <CmsIcon name={n.icon} className="h-4 w-4 shrink-0 text-[#D03E16]" /> : null}
                  <span className="min-w-0">
                    <span className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-white">
                      {n.title || <span className="italic text-slate-400">Untitled item</span>}
                      {n.is_mega && <Badge color="purple">Mega menu</Badge>}
                      {n.target === '_blank' && <Badge color="slate">New tab</Badge>}
                      {n.status === 'inactive' && <Badge color="red">Hidden</Badge>}
                    </span>
                    <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{n.url || '—'}</span>
                  </span>
                  <ChevronDown className={clsx('ml-auto h-4 w-4 shrink-0 text-slate-400 transition', isOpen && 'rotate-180')} />
                </button>
                {n.children.length > 0 && (
                  <button type="button" onClick={() => ops.toggleCollapse(n.key)} aria-expanded={!ops.collapsed.has(n.key)} title={ops.collapsed.has(n.key) ? 'Show sub-items' : 'Hide sub-items'}
                    className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700">
                    {ops.collapsed.has(n.key) ? <ChevronRight className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                    {n.children.length}<span className="hidden sm:inline">&nbsp;sub-item{n.children.length === 1 ? '' : 's'}</span>
                  </button>
                )}
                {canEdit && (
                  <div className="flex shrink-0 items-center">
                    <IconButton size="sm" icon={ArrowUp} label="Move up" disabled={i === 0} onClick={() => ops.moveNode(parent, i, i - 1)} />
                    <IconButton size="sm" icon={ArrowDown} label="Move down" disabled={i === nodes.length - 1} onClick={() => ops.moveNode(parent, i, i + 1)} />
                    {!ops.flat && (
                      <span className="hidden sm:contents">
                        <IconButton size="sm" icon={IndentIncrease} label="Make sub-item of the item above" disabled={!canIndent} onClick={() => ops.indent(parent, i)} />
                        <IconButton size="sm" icon={IndentDecrease} label="Move one level up" disabled={depth === 0} onClick={() => ops.outdent(parent, i)} />
                      </span>
                    )}
                    <IconButton size="sm" icon={Trash2} label="Remove item" tone="danger" onClick={() => ops.removeNode(parent, i)} />
                  </div>
                )}
              </div>
              {isOpen && (
                <div className="grid grid-cols-1 gap-4 border-t border-slate-100 p-4 sm:grid-cols-2 dark:border-slate-800">
                  <Field label="Title" required htmlFor={`${n.key}-title`} error={errors[`${key}.title`]}>
                    <Input id={`${n.key}-title`} value={n.title} maxLength={100} disabled={!canEdit} invalid={!!errors[`${key}.title`]} onChange={(e) => ops.updateNode(parent, i, { title: e.target.value })} />
                  </Field>
                  <Field label="Link to a page" htmlFor={`${n.key}-page`} hint="Or type a URL below." error={errors[`${key}.page_id`]}>
                    <Combobox id={`${n.key}-page`} source="pages" value={n.page_id} disabled={!canEdit} placeholder="Choose a page…"
                      onChange={(v, opt) => ops.updateNode(parent, i, { page_id: v ? Number(v) : null, url: v && opt?.sub ? String(opt.sub) : n.url, title: n.title || (opt?.label ?? '') })} />
                  </Field>
                  <Field label="URL" htmlFor={`${n.key}-url`} error={errors[`${key}.url`]} hint={n.page_id ? 'Set automatically from the linked page when saved.' : '/about, /programs#mba, https://… or tel:'}>
                    <Input id={`${n.key}-url`} value={n.url} maxLength={255} disabled={!canEdit || !!n.page_id} invalid={!!errors[`${key}.url`]} onChange={(e) => ops.updateNode(parent, i, { url: e.target.value })} />
                  </Field>
                  <Field label="Open in" htmlFor={`${n.key}-target`}>
                    <Select id={`${n.key}-target`} value={n.target} disabled={!canEdit} options={[{ value: '_self', label: 'Same tab' }, { value: '_blank', label: 'New tab' }]} onChange={(e) => ops.updateNode(parent, i, { target: e.target.value as '_self' | '_blank' })} />
                  </Field>
                  <Field label="Icon" htmlFor={`${n.key}-icon`} hint="Shown in dropdown and mega menus." error={errors[`${key}.icon`]}>
                    <IconPicker id={`${n.key}-icon`} value={n.icon ?? ''} icons={icons} disabled={!canEdit} onChange={(v) => ops.updateNode(parent, i, { icon: v || null })} />
                  </Field>
                  <Field label="Description" htmlFor={`${n.key}-desc`} hint="Short line under the title in panels." error={errors[`${key}.description`]}>
                    <Input id={`${n.key}-desc`} value={n.description ?? ''} maxLength={190} disabled={!canEdit} onChange={(e) => ops.updateNode(parent, i, { description: e.target.value || null })} />
                  </Field>
                  <div className="flex flex-wrap items-center gap-6 sm:col-span-2">
                    <Toggle checked={n.status === 'active'} onChange={(v) => ops.updateNode(parent, i, { status: v ? 'active' : 'inactive' })} label="Visible" disabled={!canEdit} />
                    {location === 'header' && depth === 0 && (
                      <Toggle checked={n.is_mega} onChange={(v) => ops.updateNode(parent, i, { is_mega: v })} label="Mega menu" description="Children become columns; their sub-items are the links." disabled={!canEdit} />
                    )}
                    {canEdit && childAllowed && (
                      <Button size="sm" variant="secondary" icon={Plus} className="ml-auto" onClick={() => { ops.addNode([...parent, i]); }}>
                        {location === 'header' && n.is_mega && depth === 0 ? 'Add column' : 'Add sub-item'}
                      </Button>
                    )}
                  </div>
                  {errors[`${key}.children`] && <Alert variant="error" className="sm:col-span-2">{errors[`${key}.children`]}</Alert>}
                </div>
              )}
            </div>
            {n.children.length > 0 && !ops.collapsed.has(n.key) && (
              <TreeList nodes={n.children} parent={[...parent, i]} depth={depth + 1} parentMega={n.is_mega} canEdit={canEdit} openKey={openKey} setOpenKey={setOpenKey} errors={errors} icons={icons} ops={ops} location={location} />
            )}
          </li>
        );
      })}
    </ol>
  );
}
