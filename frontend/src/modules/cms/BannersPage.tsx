import { useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, CalendarClock, ExternalLink, GripVertical, ImageIcon, Images, Monitor, Pencil, Plus, Radio, Save, Smartphone, TimerOff, Undo2 } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, IconButton, Modal, PageHeader, PageLoader, Skeleton, Stagger, StatCard, StatusBadge, useToast } from '@/components/ui';
import { CrudTable, useCrudForm } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { useAuth } from '@/lib/auth';
import { useApi, useCrudList, useCrudMeta } from '@/lib/queries';
import { formatDateTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { usePreviewData } from './site/api';
import { PickerFormFields } from './site/PickerFormFields';
import { moveItem, useSortable } from './site/sortable';
import { BrowserChrome, PreviewCtx, ScaledFrame, spotlightSkipReason } from './site/preview/kit';
import { SectionPreview } from './site/preview/SectionPreview';
import type { HomeSection, PreviewData } from './site/types';

const STATE_COLORS = { live: 'green', scheduled: 'amber', expired: 'slate', inactive: 'red' } as const;
const IMAGE_FIELDS = ['desktop_image', 'mobile_image'];

type Banner = PreviewData['banners'][number];
const asBanner = (r: Row): Banner => ({
  id: Number(r.id), title: String(r.title ?? ''), highlight: r.highlight ?? null, eyebrow: r.eyebrow ?? null, subheading: r.subheading ?? null,
  desktop_image: r.desktop_image ?? null, mobile_image: r.mobile_image ?? null, image_alt: r.image_alt ?? null,
  cta_text: r.cta_text ?? null, cta_url: r.cta_url ?? null, cta2_text: r.cta2_text ?? null, cta2_url: r.cta2_url ?? null,
});

export default function BannersPage() {
  const { can } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const all = useCrudList('banners', { per_page: 100, sort: 'sort_order', dir: 'asc' });
  const home = useApi<{ sections: HomeSection[] }>(['cms', 'homepage'], 'cms/homepage');
  const preview = usePreviewData();
  const rows = useMemo(() => all.data?.rows ?? [], [all.data]);
  const hero = useMemo(() => rows.filter((r) => r.placement === 'home_hero'), [rows]);
  const heroSection = home.data?.sections.find((s) => s.key === 'hero');
  const [order, setOrder] = useState<number[] | null>(null);
  const [selected, setSelected] = useState<number | null>(null);
  const [mobile, setMobile] = useState(false);
  const [form, setForm] = useState<{ id: number | null } | null>(null);
  const [savingOrder, setSavingOrder] = useState(false);

  const slides = useMemo(() => (order ? (order.map((id) => hero.find((h) => Number(h.id) === id)).filter(Boolean) as Row[]) : hero), [order, hero]);
  const dirty = !!order && order.join() !== hero.map((h) => Number(h.id)).join();
  useEffect(() => {
    if (selected === null && slides.length) setSelected(Number((slides.find((s) => s.live_state === 'live') ?? slides[0]).id));
  }, [slides, selected]);
  const current = slides.find((s) => Number(s.id) === selected) ?? slides[0];
  const move = (from: number, to: number) => setOrder(moveItem(slides.map((s) => Number(s.id)), from, to));
  const sortable = useSortable(move, 'slides');
  const heroTitle = heroSection?.title ?? null;
  const skipReason = (r: Row) => spotlightSkipReason(asBanner(r), heroTitle);
  const k = useMemo(() => ({
    live: rows.filter((r) => r.live_state === 'live').length,
    scheduled: rows.filter((r) => r.live_state === 'scheduled').length,
    expired: rows.filter((r) => r.live_state === 'expired').length,
    heroLive: hero.filter((r) => r.live_state === 'live').length,
  }), [rows, hero]);
  const shownOnSite = heroSection?.content?.use_banners ? hero.filter((r) => r.live_state === 'live' && !spotlightSkipReason(asBanner(r), heroTitle)).slice(0, 4).length : 0;

  const saveOrder = async () => {
    setSavingOrder(true);
    try {
      const res = await api.post('cms/banners/reorder', { ids: slides.map((s) => Number(s.id)) });
      toast.success(res.message || 'Slide order saved.');
      setOrder(null);
      await qc.invalidateQueries({ queryKey: ['crud', 'banners'] });
      void qc.invalidateQueries({ queryKey: ['cms', 'preview-data'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSavingOrder(false);
    }
  };
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['crud', 'banners'] });
    void qc.invalidateQueries({ queryKey: ['cms', 'preview-data'] });
    void qc.invalidateQueries({ queryKey: ['cms', 'overview'] });
  };

  // Preview the selected slide the way the website shows it: in the spotlight card of the home page hero.
  const previewData: PreviewData | undefined = preview.data && current ? { ...preview.data, banners: [asBanner(current)] } : preview.data;
  const heroPreview = heroSection ? { ...heroSection, content: { ...heroSection.content, use_banners: true } } : null;
  const currentReason = current ? skipReason(current) : null;

  return (
    <>
      <PageHeader
        title="Banners & Sliders"
        description="Home hero slides, inner-page promotions, the home popup and promo strips — with schedules and display order."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Banners / Sliders' }]}
        actions={
          <>
            <Button variant="secondary" icon={ExternalLink} href={appUrl('/')} target="_blank">View website</Button>
            {can('cms', 'create') && <Button icon={Plus} onClick={() => setForm({ id: null })}>Add banner</Button>}
          </>
        }
      />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="l" label="Live now" value={k.live} icon={Radio} tone="green" loading={all.isLoading} hint="Active and inside their schedule" />,
          <StatCard key="h" label="In the home spotlight" value={shownOnSite} icon={Images} tone="orange" loading={all.isLoading || home.isLoading} hint={`${k.heroLive} hero slides live · ${hero.length} in total`} />,
          <StatCard key="s" label="Scheduled" value={k.scheduled} icon={CalendarClock} tone="amber" loading={all.isLoading} hint="Start in the future" />,
          <StatCard key="e" label="Expired" value={k.expired} icon={TimerOff} tone="slate" loading={all.isLoading} hint="End date has passed" />,
        ]}
      </Stagger>

      {/* Hero slider manager */}
      <Card className="mb-6 overflow-hidden">
        <CardHeader
          icon={Images}
          title="Home hero slides"
          subtitle="Live slides rotate in the spotlight card of the home page hero (up to 4, in this order). Drag to reorder."
          actions={
            <>
              {dirty && <Button size="sm" variant="ghost" icon={Undo2} onClick={() => setOrder(null)}>Undo</Button>}
              {can('cms', 'edit') && <Button size="sm" icon={Save} onClick={saveOrder} loading={savingOrder} disabled={!dirty}>Save order</Button>}
            </>
          }
        />
        {heroSection && !heroSection.content?.use_banners && (
          <div className="px-5 pt-4"><Alert variant="warning">Hero slides are switched off — turn on “Use Banners / Sliders as hero slides” in Home Page Sections › Hero to show them.</Alert></div>
        )}
        {all.error ? (
          <div className="p-5"><Alert variant="error">{(all.error as ApiError).message}</Alert></div>
        ) : all.isLoading ? (
          <div className="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]"><Skeleton className="h-80 w-full rounded-xl" /><Skeleton className="h-80 w-full rounded-xl" /></div>
        ) : !slides.length ? (
          <EmptyState icon={Images} title="No hero slides yet" description="Add a banner with the “Home hero slider” placement to feature programs, events or campaigns in the home page hero."
            action={can('cms', 'create') && <Button icon={Plus} onClick={() => setForm({ id: null })}>Add a hero slide</Button>} />
        ) : (
          <div className="grid grid-cols-1 gap-5 p-5 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
            <ol className="space-y-2">
              {slides.map((s, i) => {
                const reason = s.live_state === 'live' ? skipReason(s) : null;
                return (
                  <li key={s.id} {...sortable.dropProps(i)}
                    className={clsx('flex items-center gap-3 rounded-xl border p-2 transition', Number(s.id) === Number(current?.id) ? 'border-brand-300 bg-brand-50/60 dark:border-brand-500/50 dark:bg-brand-500/10' : 'border-slate-200 dark:border-slate-700',
                      sortable.overIndex === i && sortable.dragIndex !== i && 'ring-2 ring-brand-400', sortable.dragIndex === i && 'opacity-50')}>
                    {can('cms', 'edit') && <span {...sortable.dragProps(i)} className="cursor-grab text-slate-300 active:cursor-grabbing dark:text-slate-600" title="Drag to reorder"><GripVertical className="h-4 w-4" /></span>}
                    <button type="button" onClick={() => setSelected(Number(s.id))} className="flex min-w-0 flex-1 items-center gap-3 text-left">
                      {s.desktop_image ? <img src={appUrl(String(s.desktop_image))} alt="" className="h-12 w-20 shrink-0 rounded-lg object-cover" /> : <span className="flex h-12 w-20 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800"><ImageIcon className="h-4 w-4 text-slate-400" /></span>}
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{s.title}</span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                          <StatusBadge status={String(s.live_state)} colors={STATE_COLORS} />
                          {reason && <span title={reason}><Badge color="amber">Not shown</Badge></span>}
                          {s.ends_at && <span className="truncate text-[11px] text-slate-500">until {formatDateTime(s.ends_at)}</span>}
                        </span>
                      </span>
                    </button>
                    <div className="flex shrink-0 items-center">
                      {can('cms', 'edit') && <IconButton size="sm" icon={ArrowUp} label="Move up" disabled={i === 0} onClick={() => move(i, i - 1)} />}
                      {can('cms', 'edit') && <IconButton size="sm" icon={ArrowDown} label="Move down" disabled={i === slides.length - 1} onClick={() => move(i, i + 1)} />}
                      {can('cms', 'edit') && <IconButton size="sm" icon={Pencil} label="Edit slide" tone="primary" onClick={() => setForm({ id: Number(s.id) })} />}
                    </div>
                  </li>
                );
              })}
            </ol>
            <div className="min-w-0">
              <div className="mb-2 flex items-center justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Slide {Math.max(1, slides.findIndex((s) => Number(s.id) === Number(current?.id)) + 1)} of {slides.length}
                  {current?.live_state !== 'live' && <Badge color="amber" className="ml-2">Not live</Badge>}
                </p>
                <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="tablist" aria-label="Preview device">
                  {([[false, Monitor, 'Desktop'], [true, Smartphone, 'Mobile']] as const).map(([m, Icon, label]) => (
                    <button key={label} type="button" role="tab" aria-selected={mobile === m} onClick={() => setMobile(m)} className={clsx('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold', mobile === m ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500')}>
                      <Icon className="h-3.5 w-3.5" />{label}
                    </button>
                  ))}
                </div>
              </div>
              {currentReason && <Alert variant="warning" className="mb-3">{currentReason}</Alert>}
              {current && heroPreview && previewData ? (
                <BrowserChrome url={appUrl('/')}>
                  <PreviewCtx.Provider value={{ mobile, data: previewData }}>
                    <ScaledFrame width={mobile ? 390 : 1280}>
                      <SectionPreview section={heroPreview} />
                    </ScaledFrame>
                  </PreviewCtx.Provider>
                </BrowserChrome>
              ) : home.error || preview.error ? (
                <Alert variant="error">{((home.error ?? preview.error) as ApiError).message}</Alert>
              ) : (
                <Skeleton className="h-72 w-full rounded-2xl" />
              )}
              <p className="mt-2 text-center text-xs text-slate-400">The hero copy comes from Home Page Sections › Hero; this slide appears in its spotlight card.</p>
            </div>
          </div>
        )}
      </Card>

      <CrudTable
        module="banners"
        urlState
        title="All banners"
        addLabel="Add banner"
        onCreate={() => setForm({ id: null })}
        onEdit={(r) => setForm({ id: Number(r.id) })}
        emptyTitle="No banners yet"
        emptyText="Add hero slides, page promotions, a popup or a promo strip."
        renderers={{
          desktop_image: (r) => r.desktop_image ? <img src={appUrl(String(r.desktop_image))} alt="" loading="lazy" className="h-11 w-[72px] rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" /> : <span className="flex h-11 w-[72px] items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800"><ImageIcon className="h-4 w-4 text-slate-400" /></span>,
        }}
      />

      <BannerFormModal state={form} onClose={() => setForm(null)} onSaved={refresh} />
    </>
  );
}

/** Create / edit a banner; images are picked from the campus photos or the media library, or uploaded. */
function BannerFormModal({ state, onClose, onSaved }: { state: { id: number | null } | null; onClose: () => void; onSaved: () => void }) {
  const { data: meta } = useCrudMeta('banners');
  const open = !!state;
  const id = state?.id ?? null;
  const defaults = useMemo(() => ({ placement: 'home_hero', status: 'active' }), []);
  const form = useCrudForm({ meta: open ? meta : undefined, module: 'banners', id, defaults, onSaved: () => { onSaved(); onClose(); } });
  const v = form.values as Row;
  return (
    <Modal open={open} onClose={onClose} static={form.saving} size="xl" title={id ? 'Edit banner' : 'Add banner'}
      description="Heading, images, buttons, placement and schedule. Live = active and inside the schedule."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="banner-form" loading={form.saving} disabled={form.loading}>{id ? 'Save changes' : 'Add banner'}</Button>
        </>
      }>
      {form.loading || !meta ? (
        <PageLoader label="Loading banner…" />
      ) : (
        <form id="banner-form" onSubmit={form.submit} noValidate className="space-y-4">
          {form.formError && <Alert variant="error">{form.formError}</Alert>}
          {v.placement === 'home_hero' && !(v.cta_text && v.cta_url) && !(v.cta2_text && v.cta2_url) && (
            <Alert variant="info">Hero slides appear in the home page spotlight only when they have a button (text + link).</Alert>
          )}
          <PickerFormFields state={form} fields={form.visibleFields} id={id} images={IMAGE_FIELDS} />
        </form>
      )}
    </Modal>
  );
}
