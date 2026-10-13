import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useBlocker, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowDown, ArrowLeft, ArrowUp, ChevronDown, CircleAlert, Copy, ExternalLink, Eye, EyeOff, FileText, GripVertical, LayoutTemplate, Monitor, PanelsTopLeft, Pencil, Plus, Rocket,
  Save, SearchCheck, Smartphone, Trash2, Undo2,
} from 'lucide-react';
import { Alert, Badge, Button, Card, EmptyState, Field, IconButton, Input, Modal, PageHeader, Select, Skeleton, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useHotkey } from '@/lib/hooks';
import { formatDateTime, timeAgo } from '@/lib/format';
import { blockSummary, deepClone, newBlock, useCmsSchema, usePreviewData } from './site/api';
import { CmsIcon } from './site/icons';
import { ShapeForm } from './site/ShapeForm';
import { SeoEditor, type SeoTarget } from './site/SeoEditor';
import { moveItem, useSortable } from './site/sortable';
import { BlockPreview } from './site/preview/BlockPreview';
import { BrowserChrome, Img, PreviewCtx, ScaledFrame } from './site/preview/kit';
import type { Block, BlockDef, CmsPage, CmsSchema } from './site/types';

interface BuilderPayload {
  page: CmsPage;
  blocks: Block[];
  can: { edit: boolean; publish: boolean; seo: boolean };
}

type BlockErrors = Record<string, Record<string, string>>;

const BG_OPTIONS = [
  { value: 'white', label: 'White' }, { value: 'light', label: 'Warm grey' }, { value: 'cream', label: 'Soft warm' }, { value: 'sand', label: 'Sand (warm grey 2)' }, { value: 'dark', label: 'Dark (navy)' },
];
const PAD_OPTIONS = [{ value: 'normal', label: 'Normal spacing' }, { value: 'tight', label: 'Tight' }, { value: 'none', label: 'No spacing' }];

export default function PageBuilderPage() {
  const { id } = useParams();
  const pageId = Number(id);
  const { data, isLoading, error } = useApi<BuilderPayload>(['cms', 'builder', pageId], `cms/pages/${pageId}/builder`, undefined, { enabled: pageId > 0, refetchOnWindowFocus: false });
  const schema = useCmsSchema();

  if (error) {
    const e = error as ApiError;
    return (
      <>
        <PageHeader title="Page builder" breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Pages', to: '/cms/pages' }, { label: 'Builder' }]} />
        <Card>
          <EmptyState icon={FileText} title={e.status === 404 ? 'Page not found' : 'Unable to open the page builder'} description={e.message}
            action={<Button icon={ArrowLeft} to="/cms/pages">Back to pages</Button>} />
        </Card>
      </>
    );
  }
  if (isLoading || !data || !schema.data) {
    return (
      <>
        <PageHeader title="Page builder" breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Pages', to: '/cms/pages' }, { label: 'Builder' }]} />
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,500px)_minmax(0,1fr)]">
          <div className="space-y-3">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-16 w-full rounded-2xl" />)}</div>
          <Skeleton className="h-[560px] w-full rounded-2xl" />
        </div>
        {schema.error && <Alert variant="error" className="mt-4">{(schema.error as ApiError).message}</Alert>}
      </>
    );
  }
  return <Builder key={pageId} initial={data} schema={schema.data} />;
}

function Builder({ initial, schema }: { initial: BuilderPayload; schema: CmsSchema }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const preview = usePreviewData();
  const [page, setPage] = useState<CmsPage>(initial.page);
  const [blocks, setBlocks] = useState<Block[]>(initial.blocks);
  const [snapshot, setSnapshot] = useState(() => JSON.stringify(initial.blocks));
  const [selected, setSelected] = useState<string | null>(initial.blocks[0]?.id ?? null);
  const [tab, setTab] = useState<'content' | 'settings'>('content');
  const [errors, setErrors] = useState<BlockErrors>({});
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [pane, setPane] = useState<'editor' | 'preview'>('editor');
  const [saving, setSaving] = useState<null | 'save' | 'publish'>(null);
  const [seo, setSeo] = useState<SeoTarget | null>(null);
  const can = initial.can;
  const readOnly = !can.edit;
  const defs = useMemo(() => Object.fromEntries(schema.blocks.map((b) => [b.type, b])) as Record<string, BlockDef>, [schema]);
  const dirty = JSON.stringify(blocks) !== snapshot;
  const previewRefs = useRef<Record<string, HTMLDivElement | null>>({});

  // ----- unsaved-changes guard (router navigation + tab close)
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    void confirm({ title: 'Leave without saving?', message: 'You have unsaved changes to this page. They will be lost if you leave now.', confirmText: 'Discard changes', danger: true })
      .then((ok) => (ok ? blocker.proceed() : blocker.reset()));
  }, [blocker, confirm]);
  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  // ----- block operations
  const update = (bid: string, patch: Partial<Block>) => setBlocks((list) => list.map((b) => (b.id === bid ? { ...b, ...patch } : b)));
  const clearError = (bid: string) => errors[bid] && setErrors((e) => ({ ...e, [bid]: {} }));
  const move = useCallback((from: number, to: number) => setBlocks((list) => moveItem(list, from, to)), []);
  const sortable = useSortable(move, 'blocks');
  const insert = (def: BlockDef) => {
    const b = newBlock(def);
    setBlocks((list) => {
      const at = selected ? list.findIndex((x) => x.id === selected) + 1 : list.length;
      const next = list.slice();
      next.splice(at > 0 ? at : list.length, 0, b);
      return next;
    });
    setSelected(b.id);
    setTab('content');
    setPaletteOpen(false);
    setPane('editor');
    setTimeout(() => previewRefs.current[b.id]?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
  };
  const duplicate = (b: Block) => {
    const copy: Block = { ...deepClone(b), id: newBlock(defs[b.type]).id };
    setBlocks((list) => {
      const i = list.findIndex((x) => x.id === b.id);
      const next = list.slice();
      next.splice(i + 1, 0, copy);
      return next;
    });
    setSelected(copy.id);
  };
  const remove = async (b: Block) => {
    const ok = await confirm({ title: 'Delete this block?', message: <>The <strong>{defs[b.type]?.label ?? b.type}</strong> block “{blockSummary(b)}” will be removed from the page. You can undo this until you save.</>, confirmText: 'Delete block', danger: true });
    if (!ok) return;
    setBlocks((list) => list.filter((x) => x.id !== b.id));
    if (selected === b.id) setSelected(null);
  };
  const revert = async () => {
    const ok = await confirm({ title: 'Discard all changes?', message: 'The page will return to the last saved version.', confirmText: 'Discard', danger: true });
    if (!ok) return;
    setBlocks(JSON.parse(snapshot) as Block[]);
    setErrors({});
  };

  // ----- save
  const save = async (publish: boolean, force = false): Promise<void> => {
    if (readOnly || saving) return;
    setSaving(publish ? 'publish' : 'save');
    const sent = blocks;
    try {
      const res = await api.put<{ page: CmsPage; blocks: Block[] }>(`cms/pages/${page.id}/builder`, { blocks: sent, publish, updated_at: page.updated_at, force });
      setPage(res.data.page);
      setBlocks(res.data.blocks);
      setSnapshot(JSON.stringify(res.data.blocks));
      setErrors({});
      toast.success(res.message || 'Page saved.');
      void qc.invalidateQueries({ queryKey: ['crud', 'pages'] });
      void qc.invalidateQueries({ queryKey: ['cms', 'overview'] });
    } catch (e) {
      const err = e as ApiError;
      if (err.status === 409) {
        setSaving(null);
        const ok = await confirm({ title: 'This page was changed elsewhere', message: err.message, confirmText: 'Overwrite their changes', danger: true });
        if (ok) return save(publish, true);
        return;
      }
      const mapped: BlockErrors = {};
      Object.entries(err.errors ?? {}).forEach(([k, msg]) => {
        const m = /^blocks\.(\d+)\.(.+)$/.exec(k);
        if (m && sent[Number(m[1])]) {
          const bid = sent[Number(m[1])].id;
          (mapped[bid] ??= {})[m[2]] = msg;
        }
      });
      setErrors(mapped);
      const first = Object.keys(mapped)[0];
      if (first) {
        setSelected(first);
        setTab(Object.keys(mapped[first]).some((k) => k.startsWith('settings')) && !Object.keys(mapped[first]).some((k) => k.startsWith('data')) ? 'settings' : 'content');
      }
      toast.error(err.message || 'Unable to save the page. Please try again.');
    } finally {
      setSaving(null);
    }
  };
  useHotkey('s', (e) => {
    e.preventDefault();
    void save(false);
  }, { meta: true });

  // page.php: when the first visible block is a hero it replaces the page hero and is shown above the rich text
  const firstVisible = blocks.find((b) => !b.hidden);
  const leadHero = firstVisible?.type === 'hero' ? firstVisible : null;
  const previewBlock = (b: Block) => (
    <div key={b.id} ref={(el) => { previewRefs.current[b.id] = el; }} onClick={() => { setSelected(b.id); setPane('editor'); }}
      className={clsx('relative cursor-pointer outline-offset-[-3px]', selected === b.id ? 'outline outline-[3px] outline-[#D03E16]' : 'hover:outline hover:outline-2 hover:outline-[#D03E16]/40', b.hidden && 'opacity-40')}>
      {b.hidden && <span className="absolute right-3 top-3 z-10 rounded-full bg-[#0B2A5B] px-2.5 py-1 text-[11px] font-semibold text-white">Hidden on website</span>}
      {b === leadHero && <span className="absolute left-3 top-3 z-10 rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-[#0B2A5B] shadow">Page hero</span>}
      <BlockPreview block={b} />
    </div>
  );
  const selectedIndex = blocks.findIndex((b) => b.id === selected);
  const errorCount = Object.values(errors).reduce((n, e) => n + Object.keys(e).length, 0);
  const grouped = useMemo(() => {
    const g: Record<string, BlockDef[]> = {};
    schema.blocks.forEach((b) => (g[b.group] ??= []).push(b));
    return g;
  }, [schema]);

  return (
    <>
      <PageHeader
        title={page.title}
        description={<span className="inline-flex flex-wrap items-center gap-2">Page builder · <a href={page.absolute_url} target="_blank" rel="noreferrer" className="link inline-flex items-center gap-1">{page.url}<ExternalLink className="h-3 w-3" /></a></span>}
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Pages', to: '/cms/pages' }, { label: page.title }]}
        actions={
          <>
            <Button variant="secondary" icon={Eye} href={page.absolute_url} target="_blank">Preview</Button>
            {can.seo && <Button variant="secondary" icon={SearchCheck} onClick={() => setSeo({ type: page.seo_ref.type, id: page.seo_ref.id, route: page.seo_ref.route, title: page.title })}>SEO</Button>}
            {!readOnly && <Button variant="secondary" icon={Save} onClick={() => save(false)} loading={saving === 'save'} disabled={!!saving}>{page.status === 'published' ? 'Save' : 'Save draft'}</Button>}
            {!readOnly && can.publish && (
              <Button icon={Rocket} onClick={() => save(true)} loading={saving === 'publish'} disabled={!!saving || (page.status === 'published' && !dirty)}>
                {page.status === 'published' ? 'Update live page' : 'Save & publish'}
              </Button>
            )}
          </>
        }
      />

      {/* Status bar */}
      <Card className="mb-5 flex flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3 text-sm">
        <StatusBadge status={page.status} />
        <span className="text-slate-500 dark:text-slate-400">Template: <span className="font-medium text-slate-700 dark:text-slate-200">{schema.page_templates.find((t) => t.value === page.template)?.label ?? page.template}</span></span>
        <span className="text-slate-500 dark:text-slate-400" title={formatDateTime(page.updated_at)}>Saved {timeAgo(page.updated_at)}{page.updated_by_name ? ` by ${page.updated_by_name}` : ''}</span>
        {dirty ? (
          <span className="inline-flex items-center gap-1.5 font-medium text-amber-600 dark:text-amber-400"><span className="h-2 w-2 animate-pulse rounded-full bg-amber-500" />Unsaved changes</span>
        ) : (
          <span className="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400"><span className="h-2 w-2 rounded-full bg-emerald-500" />All changes saved</span>
        )}
        {errorCount > 0 && <span className="inline-flex items-center gap-1.5 font-medium text-red-600"><CircleAlert className="h-4 w-4" />{errorCount === 1 ? '1 field needs attention' : `${errorCount} fields need attention`}</span>}
        <div className="ml-auto flex items-center gap-2">
          {dirty && !readOnly && <Button size="sm" variant="ghost" icon={Undo2} onClick={revert}>Discard</Button>}
          <Button size="sm" variant="ghost" icon={Pencil} to={`/cms/pages?edit=${page.id}`}>Page details</Button>
          <div className="inline-flex rounded-lg bg-slate-100 p-0.5 xl:hidden dark:bg-slate-800" role="tablist" aria-label="Builder view">
            {(['editor', 'preview'] as const).map((p) => (
              <button key={p} type="button" role="tab" aria-selected={pane === p} onClick={() => setPane(p)} className={clsx('rounded-md px-3 py-1 text-xs font-semibold capitalize', pane === p ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500')}>{p}</button>
            ))}
          </div>
        </div>
      </Card>

      {readOnly && <Alert variant="info" className="mb-5">You can view this page but your role cannot edit it.</Alert>}
      {page.seo_ref.type === 'route' && (
        <Alert variant="info" className="mb-5" title="This page has its own website template">
          {page.url} is laid out by its dedicated template, which uses the title, subtitle, excerpt, banner and the blocks it supports (for example statistics,
          cards and people) in its own design. The preview shows the blocks in the standard page layout.
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,500px)_minmax(0,1fr)]">
        {/* ------------------------------------------------ Editor column */}
        <div className={clsx('min-w-0 space-y-3', pane === 'preview' && 'hidden xl:block')}>
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Blocks <span className="font-normal text-slate-500">({blocks.length})</span></h2>
            {!readOnly && <Button size="sm" icon={Plus} onClick={() => setPaletteOpen(true)}>Add block</Button>}
          </div>

          {page.has_content && (
            <div className="flex items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50/70 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800/40">
              <span className="kpi-icon !h-9 !w-9 bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300"><FileText className="h-4 w-4" /></span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-slate-800 dark:text-slate-100">Page content (rich text)</p>
                <p className="text-xs text-slate-500">Shown above the blocks. Edit it in the page details.</p>
              </div>
              <Button size="xs" variant="secondary" to={`/cms/pages?edit=${page.id}`}>Edit</Button>
            </div>
          )}

          {blocks.length === 0 ? (
            <Card>
              <EmptyState icon={LayoutTemplate} title="This page has no blocks yet" description="Build the page from ready-made sections: hero, text, cards, statistics, galleries, program listings and more."
                action={!readOnly && <Button icon={Plus} onClick={() => setPaletteOpen(true)}>Add your first block</Button>} />
              {!readOnly && (
                <div className="grid grid-cols-2 gap-2 border-t border-slate-100 p-4 sm:grid-cols-4 dark:border-slate-800">
                  {['hero', 'text', 'cards', 'cta'].map((t) => defs[t] && (
                    <button key={t} type="button" onClick={() => insert(defs[t])} className="flex flex-col items-center gap-1.5 rounded-xl border border-slate-200 p-3 text-xs font-medium text-slate-600 transition hover:-translate-y-0.5 hover:border-brand-300 hover:text-brand-700 dark:border-slate-700 dark:text-slate-300">
                      <CmsIcon name={defs[t].icon} className="h-5 w-5" />{defs[t].label}
                    </button>
                  ))}
                </div>
              )}
            </Card>
          ) : (
            <ol className="space-y-2.5">
              {blocks.map((b, i) => {
                const def = defs[b.type];
                const isSel = selected === b.id;
                const bErr = errors[b.id] ?? {};
                const hasErr = Object.keys(bErr).length > 0;
                return (
                  <li key={b.id} {...sortable.dropProps(i)}
                    className={clsx('rounded-2xl border bg-white transition dark:bg-slate-900',
                      isSel ? 'border-brand-400 shadow-soft dark:border-brand-500/60' : hasErr ? 'border-red-300 dark:border-red-500/50' : 'border-slate-200 dark:border-slate-800',
                      sortable.overIndex === i && sortable.dragIndex !== i && 'ring-2 ring-[#D03E16]/60', sortable.dragIndex === i && 'opacity-50', b.hidden && !isSel && 'opacity-70')}>
                    <div className="flex items-center gap-2 px-3 py-2.5" {...(readOnly ? {} : sortable.dragProps(i))}>
                      {!readOnly && <span className="cursor-grab text-slate-300 active:cursor-grabbing dark:text-slate-600" title="Drag to reorder"><GripVertical className="h-4 w-4" /></span>}
                      <button type="button" onClick={() => setSelected(isSel ? null : b.id)} className="flex min-w-0 flex-1 items-center gap-3 text-left" aria-expanded={isSel}>
                        <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', hasErr ? 'bg-red-50 text-red-600 dark:bg-red-500/15' : 'bg-orange-50 text-[#D03E16] dark:bg-orange-500/15')}>
                          {hasErr ? <CircleAlert className="h-4 w-4" /> : <CmsIcon name={def?.icon} className="h-4 w-4" />}
                        </span>
                        <span className="min-w-0">
                          <span className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                            {def?.label ?? b.type}
                            {b.hidden && <Badge color="slate">Hidden</Badge>}
                            {b.settings.anchor && <code className="text-[10px] font-normal text-slate-400">#{b.settings.anchor}</code>}
                          </span>
                          <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{blockSummary(b)}</span>
                        </span>
                        <ChevronDown className={clsx('ml-auto h-4 w-4 shrink-0 text-slate-400 transition', isSel && 'rotate-180')} />
                      </button>
                      {!readOnly && (
                        <div className="flex shrink-0 items-center">
                          <IconButton size="sm" icon={ArrowUp} label="Move up" disabled={i === 0} onClick={() => move(i, i - 1)} />
                          <IconButton size="sm" icon={ArrowDown} label="Move down" disabled={i === blocks.length - 1} onClick={() => move(i, i + 1)} />
                          <IconButton size="sm" icon={Copy} label="Duplicate block" onClick={() => duplicate(b)} />
                          <IconButton size="sm" icon={b.hidden ? Eye : EyeOff} label={b.hidden ? 'Show block' : 'Hide block'} onClick={() => update(b.id, { hidden: !b.hidden })} />
                          <IconButton size="sm" icon={Trash2} label="Delete block" tone="danger" onClick={() => remove(b)} />
                        </div>
                      )}
                    </div>
                    {isSel && def && (
                      <div className="border-t border-slate-100 dark:border-slate-800">
                        <Tabs className="px-3" value={tab} onChange={(k) => setTab(k as typeof tab)} tabs={[{ key: 'content', label: 'Content' }, { key: 'settings', label: 'Section settings' }]} />
                        <div className="p-4">
                          {tab === 'content' ? (
                            <>
                              <p className="mb-4 text-xs text-slate-500">{def.description}</p>
                              <ShapeForm fields={def.fields} value={b.data} schema={schema} preview={preview.data} disabled={readOnly} errors={bErr} path="data" idPrefix={b.id}
                                onChange={(d) => {
                                  update(b.id, { data: d });
                                  clearError(b.id);
                                }} />
                            </>
                          ) : (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                              <Field label="Anchor id" htmlFor={`${b.id}-anchor`} error={bErr['settings.anchor']} hint="For links like /page#anchor">
                                <Input id={`${b.id}-anchor`} value={b.settings.anchor} maxLength={60} disabled={readOnly} placeholder="e.g. values"
                                  onChange={(e) => update(b.id, { settings: { ...b.settings, anchor: e.target.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-') } })} />
                              </Field>
                              <Field label="Background" htmlFor={`${b.id}-bg`}>
                                <Select id={`${b.id}-bg`} value={b.settings.background} options={BG_OPTIONS} disabled={readOnly} onChange={(e) => update(b.id, { settings: { ...b.settings, background: e.target.value } })} />
                              </Field>
                              <Field label="Spacing" htmlFor={`${b.id}-pad`}>
                                <Select id={`${b.id}-pad`} value={b.settings.padding} options={PAD_OPTIONS} disabled={readOnly} onChange={(e) => update(b.id, { settings: { ...b.settings, padding: e.target.value } })} />
                              </Field>
                            </div>
                          )}
                        </div>
                      </div>
                    )}
                  </li>
                );
              })}
            </ol>
          )}
          {!readOnly && blocks.length > 0 && (
            <button type="button" onClick={() => setPaletteOpen(true)} className="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-200 py-3.5 text-sm font-medium text-slate-500 transition hover:border-[#D03E16]/50 hover:text-[#D03E16] dark:border-slate-700">
              <Plus className="h-4 w-4" />Add block{selectedIndex >= 0 ? ' below the selected one' : ''}
            </button>
          )}
        </div>

        {/* ------------------------------------------------ Live preview column */}
        <div className={clsx('min-w-0', pane === 'editor' && 'hidden xl:block')}>
          <div className="xl:sticky xl:top-20">
            <div className="mb-2 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Live preview</h2>
              <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="tablist" aria-label="Preview device">
                {([['desktop', Monitor], ['mobile', Smartphone]] as const).map(([d, Icon]) => (
                  <button key={d} type="button" role="tab" aria-selected={device === d} onClick={() => setDevice(d)}
                    className={clsx('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold capitalize', device === d ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500')}>
                    <Icon className="h-3.5 w-3.5" />{d}
                  </button>
                ))}
              </div>
            </div>
            <BrowserChrome url={page.absolute_url}>
              <PreviewCtx.Provider value={{ mobile: device === 'mobile', data: preview.data }}>
                <ScaledFrame width={device === 'mobile' ? 390 : 1280} maxHeight={typeof window !== 'undefined' ? Math.max(420, window.innerHeight - 230) : 700}>
                  {page.template !== 'landing' && !leadHero && (
                    <section className="relative overflow-hidden bg-[#FAF8F5] bg-[linear-gradient(#0B2A5B0a_1px,transparent_1px),linear-gradient(90deg,#0B2A5B0a_1px,transparent_1px)] [background-size:32px_32px]">
                      <div className={clsx('relative grid items-center gap-10', device === 'mobile' ? 'grid-cols-1 px-5 py-10' : 'mx-auto max-w-[1160px] grid-cols-[1.2fr_1fr] px-10 py-14')}>
                        <div>
                          <p className="text-xs font-medium text-[#64748B]">Home › {page.title}</p>
                          <h1 className="mt-4 font-display text-[52px] font-extrabold leading-[1.04] tracking-[-0.035em] text-[#0B2A5B]">{page.title}</h1>
                          {page.subtitle && <p className="mt-3 font-display text-xl font-bold text-[#C93C14]">{page.subtitle}</p>}
                          {page.excerpt && <p className="mt-3 max-w-2xl text-[15px] leading-relaxed text-[#4A5568]">{page.excerpt}</p>}
                        </div>
                        {page.banner_image && device !== 'mobile' && <Img src={page.banner_image} className="aspect-[4/3] w-full rounded-[28px] shadow-[0_30px_60px_-30px_rgba(11,42,91,.55)]" />}
                      </div>
                    </section>
                  )}
                  {leadHero && previewBlock(leadHero)}
                  {page.has_content && page.content && (
                    <section className="bg-white py-12">
                      <div className={clsx('mx-auto space-y-3 text-[15px] leading-relaxed text-[#4A5568] [&_a]:text-[#C93C14] [&_a]:underline [&_h2]:mt-6 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:text-[#0B2A5B] [&_h3]:mt-4 [&_h3]:font-display [&_h3]:text-lg [&_h3]:font-bold [&_h3]:text-[#0B2A5B] [&_li]:ml-5 [&_ol]:list-decimal [&_strong]:text-[#0B2A5B] [&_table]:w-full [&_td]:border [&_td]:border-[#EDE8E2] [&_td]:p-2 [&_th]:border [&_th]:border-[#EDE8E2] [&_th]:bg-[#FAF8F5] [&_th]:p-2 [&_th]:text-left [&_ul]:list-disc', device === 'mobile' ? 'px-5' : 'max-w-3xl px-10')}
                        dangerouslySetInnerHTML={{ __html: page.content }} />
                    </section>
                  )}
                  {blocks.filter((b) => b !== leadHero).map(previewBlock)}

                  {!blocks.length && !page.has_content && (
                    <div className="flex flex-col items-center justify-center gap-2 py-24 text-center text-slate-400">
                      <PanelsTopLeft className="h-10 w-10" />
                      <p className="text-sm">Add blocks to see the page take shape.</p>
                    </div>
                  )}
                  <footer className="bg-[#F5F2EE] px-10 py-8 text-center text-xs text-[#4A5568]">Website footer</footer>
                </ScaledFrame>
              </PreviewCtx.Provider>
            </BrowserChrome>
            <p className="mt-2 text-center text-xs text-slate-400">Approximate preview · click a section to edit it · <Link className="link" to="/cms/pages">All pages</Link></p>
          </div>
        </div>
      </div>

      {/* Palette */}
      <Modal open={paletteOpen} onClose={() => setPaletteOpen(false)} size="xl" title="Add a block" description={selectedIndex >= 0 ? 'The block is inserted below the selected block.' : 'The block is added at the end of the page.'}>
        <div className="space-y-5">
          {Object.entries(grouped).map(([group, list]) => (
            <div key={group}>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{group}</p>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {list.map((def) => (
                  <button key={def.type} type="button" onClick={() => insert(def)}
                    className="group flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-left transition hover:-translate-y-0.5 hover:border-[#D03E16]/50 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-700">
                    <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-[#D03E16] transition group-hover:bg-[#D03E16] group-hover:text-white dark:bg-orange-500/15">
                      <CmsIcon name={def.icon} className="h-5 w-5" />
                    </span>
                    <span className="min-w-0">
                      <span className="block text-sm font-semibold text-slate-900 dark:text-white">{def.label}</span>
                      <span className="block text-xs text-slate-500 dark:text-slate-400">{def.description}</span>
                    </span>
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>
      </Modal>

      <SeoEditor target={seo} onClose={() => setSeo(null)} />
    </>
  );
}
