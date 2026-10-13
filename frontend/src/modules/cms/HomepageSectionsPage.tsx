import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, ExternalLink, Eye, EyeOff, GripVertical, LayoutTemplate, Monitor, Pencil, RotateCcw, Save, Smartphone, Undo2 } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Field, IconButton, Input, Modal, PageHeader, Skeleton, Stagger, StatCard, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { timeAgo } from '@/lib/format';
import { deepClone, useCmsSchema, usePreviewData } from './site/api';
import { CmsIcon } from './site/icons';
import { ShapeForm } from './site/ShapeForm';
import { moveItem, useSortable } from './site/sortable';
import { BrowserChrome, PreviewCtx, ScaledFrame, spotlightSlides } from './site/preview/kit';
import { SectionPreview } from './site/preview/SectionPreview';
import type { CmsSchema, HomeSection, PreviewData, SectionDef } from './site/types';

interface Payload {
  sections: HomeSection[];
  can: { edit: boolean };
  site_url: string;
}

export default function HomepageSectionsPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const { data, isLoading, error, refetch } = useApi<Payload>(['cms', 'homepage'], 'cms/homepage');
  const schema = useCmsSchema();
  const preview = usePreviewData();
  const [order, setOrder] = useState<string[] | null>(null);
  const [savingOrder, setSavingOrder] = useState(false);
  const [editing, setEditing] = useState<string | null>(null);
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [busy, setBusy] = useState<string | null>(null);
  const canEdit = data?.can.edit ?? false;

  useEffect(() => {
    const s = params.get('section');
    if (s && data) {
      setEditing(s);
      const next = new URLSearchParams(params);
      next.delete('section');
      setParams(next, { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  const sections = useMemo(() => {
    const list = data?.sections ?? [];
    if (!order) return list;
    return order.map((k) => list.find((s) => s.key === k)).filter(Boolean) as HomeSection[];
  }, [data, order]);
  const dirtyOrder = !!order && order.join() !== (data?.sections ?? []).map((s) => s.key).join();
  const move = (from: number, to: number) => setOrder(moveItem(sections.map((s) => s.key), from, to));
  const sortable = useSortable(move, 'sections');
  const enabled = sections.filter((s) => s.is_enabled);
  const lastUpdated = (data?.sections ?? []).map((s) => s.updated_at).filter(Boolean).sort().pop();
  const heroSec = (data?.sections ?? []).find((s) => s.key === 'hero');
  const spotlight = heroSec?.content?.use_banners ? spotlightSlides(preview.data?.banners ?? [], heroSec.title).length : 0;

  const saveOrder = async () => {
    setSavingOrder(true);
    try {
      const res = await api.post<{ sections: HomeSection[] }>('cms/homepage/reorder', { keys: sections.map((s) => s.key) });
      qc.setQueryData(['cms', 'homepage'], { ...data, sections: res.data.sections });
      setOrder(null);
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSavingOrder(false);
    }
  };
  const toggle = async (s: HomeSection) => {
    setBusy(s.key);
    try {
      const res = await api.post(`cms/homepage/${s.key}/toggle`, { enabled: !s.is_enabled });
      toast.success(res.message);
      await refetch();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <PageHeader
        title="Home Page Sections"
        description="Turn sections on or off, change their order and edit every heading, image and button of the website home page."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Home Page Sections' }]}
        actions={
          <>
            <Button variant="secondary" icon={ExternalLink} href={data?.site_url ?? '/'} target="_blank">View home page</Button>
            {dirtyOrder && <Button variant="ghost" icon={Undo2} onClick={() => setOrder(null)}>Undo order</Button>}
            {canEdit && <Button icon={Save} onClick={saveOrder} loading={savingOrder} disabled={!dirtyOrder}>Save order</Button>}
          </>
        }
      />
      {error && <Alert variant="error" className="mb-6" title="Unable to load home page sections">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="e" label="Sections shown" value={isLoading ? '—' : `${enabled.length} / ${sections.length}`} icon={LayoutTemplate} tone="navy" loading={isLoading} hint="Enabled on the home page" />,
          <StatCard key="h" label="Hero spotlight slides" value={spotlight} icon={Monitor} tone="orange" loading={preview.isLoading || isLoading} hint={`${preview.data?.banners.length ?? 0} live hero banners · Banners`} to="/cms/banners" />,
          <StatCard key="u" label="Last edited" value={lastUpdated ? timeAgo(lastUpdated) : '—'} icon={Pencil} tone="purple" loading={isLoading} hint="Most recent section change" />,
          <StatCard key="o" label="Unsaved order" value={dirtyOrder ? 'Yes' : 'No'} icon={GripVertical} tone={dirtyOrder ? 'amber' : 'green'} loading={isLoading} hint={dirtyOrder ? 'Click “Save order”' : 'Order is saved'} />,
        ]}
      </Stagger>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,560px)_minmax(0,1fr)]">
        <Card className="overflow-hidden">
          <CardHeader title="Sections" subtitle="Drag to reorder · the order is saved with “Save order”" icon={LayoutTemplate} />
          {isLoading ? (
            <div className="space-y-2 p-4">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-14 w-full rounded-xl" />)}</div>
          ) : (
            <ol className="divide-y divide-slate-100 dark:divide-slate-800">
              {sections.map((s, i) => (
                <li key={s.key} {...sortable.dropProps(i)}
                  className={clsx('flex items-center gap-3 px-4 py-3 transition', sortable.overIndex === i && sortable.dragIndex !== i && 'bg-orange-50/70 dark:bg-orange-500/10', sortable.dragIndex === i && 'opacity-50', !s.is_enabled && 'bg-slate-50/60 dark:bg-slate-800/30')}>
                  <span {...(canEdit ? sortable.dragProps(i) : {})} className={clsx('text-slate-300 dark:text-slate-600', canEdit && 'cursor-grab active:cursor-grabbing')} title="Drag to reorder"><GripVertical className="h-4 w-4" /></span>
                  <span className="hidden w-5 text-center text-xs font-semibold tabular-nums text-slate-400 sm:block">{i + 1}</span>
                  <span className={clsx('hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl sm:inline-flex', s.is_enabled ? 'bg-orange-50 text-[#D03E16] dark:bg-orange-500/15' : 'bg-slate-100 text-slate-400 dark:bg-slate-800')}>
                    <CmsIcon name={s.icon} className="h-5 w-5" />
                  </span>
                  <button type="button" onClick={() => setEditing(s.key)} className="min-w-0 flex-1 text-left">
                    <span className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                      {s.label}
                      {!s.exists && <Badge color="amber">Not set up</Badge>}
                    </span>
                    <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{s.title || s.description}</span>
                  </button>
                  <span className="hidden text-[11px] text-slate-400 sm:block">{s.updated_at ? timeAgo(s.updated_at) : ''}</span>
                  <div className="flex shrink-0 items-center gap-0.5">
                    {canEdit && <IconButton size="sm" icon={ArrowUp} label="Move up" disabled={i === 0} onClick={() => move(i, i - 1)} />}
                    {canEdit && <IconButton size="sm" icon={ArrowDown} label="Move down" disabled={i === sections.length - 1} onClick={() => move(i, i + 1)} />}
                    <IconButton size="sm" icon={Pencil} label={`Edit ${s.label}`} tone="primary" onClick={() => setEditing(s.key)} />
                  </div>
                  {canEdit ? (
                    <Toggle checked={s.is_enabled} onChange={() => toggle(s)} disabled={busy === s.key} />
                  ) : (
                    s.is_enabled ? <Eye className="h-4 w-4 text-emerald-500" aria-label="Shown" /> : <EyeOff className="h-4 w-4 text-slate-400" aria-label="Hidden" />
                  )}
                </li>
              ))}
            </ol>
          )}
        </Card>

        <div className="min-w-0">
          <div className="xl:sticky xl:top-20">
            <div className="mb-2 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Home page preview</h2>
              <DeviceToggle device={device} onChange={setDevice} />
            </div>
            {isLoading || !schema.data ? (
              <Skeleton className="h-[520px] w-full rounded-2xl" />
            ) : (
              <BrowserChrome url={data?.site_url ?? '/'}>
                <PreviewCtx.Provider value={{ mobile: device === 'mobile', data: preview.data }}>
                  <ScaledFrame width={device === 'mobile' ? 390 : 1280} maxHeight={typeof window !== 'undefined' ? Math.max(420, window.innerHeight - 220) : 700}>
                    {enabled.map((s) => (
                      <div key={s.key} onClick={() => setEditing(s.key)} className="cursor-pointer hover:outline hover:outline-2 hover:outline-offset-[-2px] hover:outline-[#D03E16]/50">
                        <SectionPreview section={s} />
                      </div>
                    ))}
                    {!enabled.length && <p className="py-24 text-center text-sm text-slate-400">All sections are switched off.</p>}
                  </ScaledFrame>
                </PreviewCtx.Provider>
              </BrowserChrome>
            )}
            <p className="mt-2 text-center text-xs text-slate-400">Approximate preview of the enabled sections in the current order · click a section to edit it</p>
          </div>
        </div>
      </div>

      {schema.data && data && (
        <SectionEditor
          section={data.sections.find((s) => s.key === editing) ?? null}
          def={schema.data.sections.find((d) => d.key === editing)}
          schema={schema.data}
          preview={preview.data}
          canEdit={canEdit}
          onClose={() => setEditing(null)}
          onSaved={(saved) => qc.setQueryData(['cms', 'homepage'], { ...data, sections: data.sections.map((s) => (s.key === saved.key ? saved : s)) })}
        />
      )}
    </>
  );
}

function DeviceToggle({ device, onChange }: { device: 'desktop' | 'mobile'; onChange: (d: 'desktop' | 'mobile') => void }) {
  return (
    <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="tablist" aria-label="Preview device">
      {([['desktop', Monitor], ['mobile', Smartphone]] as const).map(([d, Icon]) => (
        <button key={d} type="button" role="tab" aria-selected={device === d} onClick={() => onChange(d)}
          className={clsx('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold capitalize', device === d ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500')}>
          <Icon className="h-3.5 w-3.5" />{d}
        </button>
      ))}
    </div>
  );
}

/** Tailored editor for one section: form from the section definition + live preview. */
function SectionEditor({ section, def, schema, preview, canEdit, onClose, onSaved }: {
  section: HomeSection | null; def?: SectionDef; schema: CmsSchema; preview?: PreviewData; canEdit: boolean; onClose: () => void; onSaved: (s: HomeSection) => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [draft, setDraft] = useState<HomeSection | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  useEffect(() => {
    setDraft(section ? deepClone(section) : null);
    setErrors({});
    setFormError(null);
  }, [section]);
  const dirty = !!draft && !!section && JSON.stringify(draft) !== JSON.stringify(section);

  const close = async () => {
    if (dirty && !(await confirm({ title: 'Discard changes?', message: 'Your changes to this section have not been saved.', confirmText: 'Discard', danger: true }))) return;
    onClose();
  };
  const save = async () => {
    if (!draft) return;
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.put<{ section: HomeSection }>(`cms/homepage/${draft.key}`, { title: draft.title, subtitle: draft.subtitle, content: draft.content });
      toast.success(res.message);
      onSaved(res.data.section);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };
  const reset = async () => {
    if (!draft) return;
    const ok = await confirm({ title: `Reset “${draft.label}” to the default content?`, message: 'The heading, text, images and items of this section will be replaced with the original GIMT content.', confirmText: 'Reset section', danger: true });
    if (!ok) return;
    try {
      const res = await api.post<{ section: HomeSection }>(`cms/homepage/${draft.key}/reset`);
      toast.success(res.message);
      onSaved(res.data.section);
      setDraft(deepClone(res.data.section));
      setErrors({});
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <Modal open={!!section && !!def} onClose={close} static={saving} size="2xl" title={draft ? `Edit section — ${draft.label}` : 'Edit section'} description={def?.description}
      footer={
        <>
          {canEdit && <Button variant="ghost" icon={RotateCcw} className="mr-auto" onClick={reset}>Reset to default</Button>}
          <Button variant="secondary" onClick={close} disabled={saving}>{canEdit ? 'Cancel' : 'Close'}</Button>
          {canEdit && <Button icon={Save} onClick={save} loading={saving} disabled={!dirty}>Save section</Button>}
        </>
      }>
      {draft && def && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <div className="min-w-0 space-y-4">
            {formError && <Alert variant="error">{formError}</Alert>}
            {!draft.is_enabled && <Alert variant="warning">This section is switched off and not shown on the website.</Alert>}
            <Field label={def.title_label} required={def.title_required} htmlFor="sec-title" error={errors.title}>
              <Input id="sec-title" value={draft.title ?? ''} maxLength={190} disabled={!canEdit} invalid={!!errors.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} />
            </Field>
            <Field label={def.subtitle_label} htmlFor="sec-sub" error={errors.subtitle}>
              <Textarea id="sec-sub" rows={2} value={draft.subtitle ?? ''} maxLength={500} disabled={!canEdit} onChange={(e) => setDraft({ ...draft, subtitle: e.target.value })} />
            </Field>
            <ShapeForm fields={def.fields} value={draft.content} onChange={(content) => setDraft({ ...draft, content })} errors={errors} path="content" schema={schema} preview={preview} disabled={!canEdit} idPrefix={`sec-${draft.key}`} />
          </div>
          <div className="min-w-0">
            <div className="lg:sticky lg:top-0">
              <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Live preview</p>
                <DeviceToggle device={device} onChange={setDevice} />
              </div>
              <div className="overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
                <PreviewCtx.Provider value={{ mobile: device === 'mobile', data: preview }}>
                  <ScaledFrame width={device === 'mobile' ? 390 : 1280} maxHeight={560}>
                    <SectionPreview section={draft} />
                  </ScaledFrame>
                </PreviewCtx.Provider>
              </div>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
