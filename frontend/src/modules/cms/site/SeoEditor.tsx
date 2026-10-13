import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ExternalLink, Globe, RotateCcw, Save, Search, Share2 } from 'lucide-react';
import { Alert, Badge, Button, Drawer, Field, Input, PageLoader, Select, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { ImageField } from './ImagePicker';

export interface SeoTarget {
  type: string;
  id?: number | null;
  route?: string | null;
  /** label shown while loading */
  title?: string;
}

interface SeoRow {
  id: number;
  meta_title: string | null;
  meta_description: string | null;
  meta_keywords: string | null;
  canonical_url: string | null;
  og_title: string | null;
  og_description: string | null;
  og_image: string | null;
  robots: string;
  schema_json: string | null;
  include_in_sitemap: boolean;
  sitemap_priority: number;
  sitemap_changefreq: string;
  updated_at: string | null;
}

interface SeoEntry {
  entity: { type: string; id: number | null; route: string | null; title: string; url: string; absolute_url: string; status: string };
  ref: { type: string; id: number | null; route: string | null };
  seo: SeoRow | null;
  defaults: { meta_title: string; meta_description: string; og_image: string; site_name: string };
  can?: { edit: boolean };
}

type Form = {
  meta_title: string; meta_description: string; meta_keywords: string; canonical_url: string; og_title: string; og_description: string; og_image: string;
  robots: string; schema_json: string; include_in_sitemap: boolean; sitemap_priority: string; sitemap_changefreq: string;
};

const empty: Form = { meta_title: '', meta_description: '', meta_keywords: '', canonical_url: '', og_title: '', og_description: '', og_image: '', robots: 'index,follow', schema_json: '', include_in_sitemap: true, sitemap_priority: '0.7', sitemap_changefreq: 'weekly' };

export const ROBOTS_OPTIONS = [
  { value: 'index,follow', label: 'index, follow (default)' }, { value: 'noindex,follow', label: 'noindex, follow' },
  { value: 'index,nofollow', label: 'index, nofollow' }, { value: 'noindex,nofollow', label: 'noindex, nofollow' },
];
const FREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'].map((v) => ({ value: v, label: v[0].toUpperCase() + v.slice(1) }));
const PRIORITY = Array.from({ length: 11 }, (_, i) => ({ value: (i / 10).toFixed(1), label: (i / 10).toFixed(1) }));

/** Length guidance bar: green inside the ideal range, amber when short, red when too long. */
export function LengthMeter({ length, min, max, hardMax }: { length: number; min: number; max: number; hardMax: number }) {
  const pct = Math.min(100, (length / hardMax) * 100);
  const tone = length === 0 ? 'bg-slate-300' : length > max ? 'bg-red-500' : length < min ? 'bg-amber-500' : 'bg-emerald-500';
  const text = length === 0 ? 'Using the automatic default' : length > max ? `${length - max} characters too long — search engines will cut it off` : length < min ? `A little short — aim for ${min}–${max} characters` : 'Good length';
  return (
    <div className="mt-1.5">
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={length} aria-valuemin={0} aria-valuemax={hardMax} aria-label="Length">
        <div className={clsx('h-full rounded-full transition-all', tone)} style={{ width: `${pct}%` }} />
      </div>
      <p className={clsx('mt-1 flex justify-between text-[11px]', length > max ? 'text-red-600 dark:text-red-400' : 'text-slate-500')}>
        <span>{text}</span>
        <span className="tabular-nums">{length} / {max}</span>
      </p>
    </div>
  );
}

/** Google-style result snippet. */
export function SearchSnippet({ title, url, description }: { title: string; url: string; description: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
      <p className="truncate text-xs text-slate-600 dark:text-slate-400">{url}</p>
      <p className="mt-0.5 line-clamp-1 text-lg leading-snug text-[#1a0dab] dark:text-[#8ab4f8]">{title.length > 62 ? `${title.slice(0, 60)}…` : title}</p>
      <p className="mt-0.5 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{description || 'No description — search engines will pick text from the page.'}</p>
    </div>
  );
}

/** Drawer to edit the SEO record of any page / program / post / event / notice / route. */
export function SeoEditor({ target, onClose, onSaved }: { target: SeoTarget | null; onClose: () => void; onSaved?: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [form, setForm] = useState<Form>(empty);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const key = target ? { type: target.type, id: target.id ?? undefined, route: target.route ?? undefined } : null;
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['seo', 'entry', key],
    queryFn: () => api.get<SeoEntry>('seo/entry', key ?? {}),
    enabled: !!target,
  });

  useEffect(() => {
    if (!data) return;
    const s = data.seo;
    setForm(s ? {
      meta_title: s.meta_title ?? '', meta_description: s.meta_description ?? '', meta_keywords: s.meta_keywords ?? '', canonical_url: s.canonical_url ?? '',
      og_title: s.og_title ?? '', og_description: s.og_description ?? '', og_image: s.og_image ?? '', robots: s.robots || 'index,follow', schema_json: s.schema_json ?? '',
      include_in_sitemap: !!s.include_in_sitemap, sitemap_priority: Number(s.sitemap_priority ?? 0.7).toFixed(1), sitemap_changefreq: s.sitemap_changefreq || 'weekly',
    } : empty);
    setErrors({});
    setFormError(null);
  }, [data]);

  const schemaError = useMemo(() => {
    if (!form.schema_json.trim()) return null;
    try {
      const v = JSON.parse(form.schema_json);
      return v && typeof v === 'object' ? null : 'Schema must be a JSON object or array.';
    } catch (e) {
      return `Invalid JSON: ${(e as Error).message}`;
    }
  }, [form.schema_json]);

  const set = <K extends keyof Form>(k: K, v: Form[K]) => {
    setForm((f) => ({ ...f, [k]: v }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const canEdit = data?.can?.edit ?? false;
  const effTitle = form.meta_title || data?.defaults.meta_title || '';
  const effDesc = form.meta_description || data?.defaults.meta_description || '';
  const ogImage = form.og_image || data?.defaults.og_image || '';

  const save = async () => {
    if (!target || schemaError) {
      if (schemaError) setErrors((e) => ({ ...e, schema_json: schemaError }));
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.put<SeoEntry>('seo/entry', { type: target.type, id: target.id ?? undefined, route: target.route ?? undefined, ...form, sitemap_priority: Number(form.sitemap_priority) });
      toast.success(res.message || 'SEO settings saved.');
      qc.setQueryData(['seo', 'entry', key], { ...res.data, can: data?.can });
      void qc.invalidateQueries({ queryKey: ['seo'] });
      onSaved?.();
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
    if (!target || !data?.seo) return;
    const ok = await confirm({ title: 'Reset SEO to defaults?', message: 'The custom title, description, social card and schema for this URL will be removed and the automatic defaults used instead.', confirmText: 'Reset', danger: true });
    if (!ok) return;
    try {
      const res = await api.del('seo/entry', { type: target.type, id: target.id ?? undefined, route: target.route ?? undefined });
      toast.success(res.message || 'SEO reset.');
      void qc.invalidateQueries({ queryKey: ['seo'] });
      await refetch();
      onSaved?.();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <Drawer
      open={!!target}
      onClose={onClose}
      width="max-w-2xl"
      title={data ? `SEO — ${data.entity.title}` : `SEO — ${target?.title ?? ''}`}
      description={data ? <span className="inline-flex items-center gap-2">{data.entity.url}{data.ref.type === 'route' && data.entity.type === 'page' && <Badge color="blue">Saved on route {data.ref.route}</Badge>}</span> : 'Search and social settings'}
      footer={
        <>
          {data?.seo && canEdit && <Button variant="ghost" icon={RotateCcw} onClick={reset} className="mr-auto !text-red-600">Reset to defaults</Button>}
          <Button variant="secondary" onClick={onClose}>{canEdit ? 'Cancel' : 'Close'}</Button>
          {canEdit && <Button icon={Save} onClick={save} loading={saving} disabled={isLoading || !!error}>Save SEO</Button>}
        </>
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load SEO settings">{(error as ApiError).message}</Alert>
      ) : isLoading || !data ? (
        <PageLoader label="Loading SEO settings…" />
      ) : (
        <div className="space-y-6">
          {formError && <Alert variant="error">{formError}</Alert>}
          {!canEdit && <Alert variant="info">You can view these settings but your role cannot change them.</Alert>}
          <section>
            <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300"><Search className="h-3.5 w-3.5" />Search result preview</h3>
            <SearchSnippet title={effTitle} url={data.entity.absolute_url} description={effDesc} />
            <a href={appUrl(data.entity.url)} target="_blank" rel="noreferrer" className="link mt-2 inline-flex items-center gap-1 text-xs"><ExternalLink className="h-3.5 w-3.5" />Open the page</a>
          </section>
          <section className="space-y-4">
            <Field label="SEO title" htmlFor="seo-title" error={errors.meta_title}>
              <Input id="seo-title" value={form.meta_title} maxLength={190} placeholder={data.defaults.meta_title} disabled={!canEdit} invalid={!!errors.meta_title} onChange={(e) => set('meta_title', e.target.value)} />
              <LengthMeter length={form.meta_title.length} min={30} max={60} hardMax={80} />
            </Field>
            <Field label="Meta description" htmlFor="seo-desc" error={errors.meta_description}>
              <Textarea id="seo-desc" rows={3} value={form.meta_description} maxLength={320} placeholder={data.defaults.meta_description || 'Describe this page in one or two sentences.'} disabled={!canEdit} invalid={!!errors.meta_description} onChange={(e) => set('meta_description', e.target.value)} />
              <LengthMeter length={form.meta_description.length} min={70} max={160} hardMax={200} />
            </Field>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Keywords" htmlFor="seo-kw" error={errors.meta_keywords} hint="Comma separated (optional).">
                <Input id="seo-kw" value={form.meta_keywords} maxLength={255} disabled={!canEdit} onChange={(e) => set('meta_keywords', e.target.value)} />
              </Field>
              <Field label="Robots" htmlFor="seo-robots" error={errors.robots}>
                <Select id="seo-robots" value={form.robots} options={ROBOTS_OPTIONS} disabled={!canEdit} onChange={(e) => set('robots', e.target.value)} />
              </Field>
            </div>
            {form.robots.includes('noindex') && <Alert variant="warning">This URL will be hidden from search engines and left out of the sitemap.</Alert>}
            <Field label="Canonical URL" htmlFor="seo-canonical" error={errors.canonical_url} hint="Only when this content is also published at another address.">
              <Input id="seo-canonical" value={form.canonical_url} maxLength={255} placeholder={data.entity.absolute_url} disabled={!canEdit} invalid={!!errors.canonical_url} onChange={(e) => set('canonical_url', e.target.value)} />
            </Field>
          </section>
          <section className="space-y-4">
            <h3 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300"><Share2 className="h-3.5 w-3.5" />Social sharing (Open Graph)</h3>
            <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
              {ogImage ? <img src={appUrl(ogImage)} alt="" className="aspect-[1.91/1] w-full object-cover" /> : <div className="flex aspect-[1.91/1] items-center justify-center bg-slate-100 text-sm text-slate-400 dark:bg-slate-800">No share image</div>}
              <div className="bg-slate-50 p-3 dark:bg-slate-800/60">
                <p className="text-[11px] uppercase text-slate-500">{new URL(data.entity.absolute_url).host}</p>
                <p className="line-clamp-1 font-semibold text-slate-900 dark:text-white">{form.og_title || effTitle}</p>
                <p className="line-clamp-2 text-xs text-slate-500">{form.og_description || effDesc}</p>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Social title" htmlFor="seo-ogt" error={errors.og_title}>
                <Input id="seo-ogt" value={form.og_title} maxLength={190} placeholder="Same as SEO title" disabled={!canEdit} onChange={(e) => set('og_title', e.target.value)} />
              </Field>
              <Field label="Share image" error={errors.og_image} hint="1200×630 px works best.">
                {canEdit ? <ImageField value={form.og_image} onChange={(v) => set('og_image', v)} invalid={!!errors.og_image} compact /> : <p className="text-sm text-slate-500">{form.og_image || 'Default image'}</p>}
              </Field>
            </div>
            <Field label="Social description" htmlFor="seo-ogd" error={errors.og_description}>
              <Textarea id="seo-ogd" rows={2} value={form.og_description} maxLength={320} placeholder="Same as meta description" disabled={!canEdit} onChange={(e) => set('og_description', e.target.value)} />
            </Field>
          </section>
          <section className="space-y-4">
            <h3 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300"><Globe className="h-3.5 w-3.5" />Structured data & sitemap</h3>
            <Field label="Schema (JSON-LD)" htmlFor="seo-schema" error={errors.schema_json ?? schemaError} hint="Valid JSON object, e.g. {&quot;@context&quot;: &quot;https://schema.org&quot;, &quot;@type&quot;: &quot;Course&quot;, …}">
              <Textarea id="seo-schema" rows={7} value={form.schema_json} disabled={!canEdit} invalid={!!(errors.schema_json ?? schemaError)} className="font-mono text-xs" onChange={(e) => set('schema_json', e.target.value)}
                placeholder={'{\n  "@context": "https://schema.org",\n  "@type": "WebPage"\n}'} />
            </Field>
            <div className="grid grid-cols-1 items-end gap-4 sm:grid-cols-3">
              <Toggle checked={form.include_in_sitemap} onChange={(v) => set('include_in_sitemap', v)} label="Include in sitemap" disabled={!canEdit || form.robots.includes('noindex')} />
              <Field label="Priority" htmlFor="seo-prio" error={errors.sitemap_priority}>
                <Select id="seo-prio" value={form.sitemap_priority} options={PRIORITY} disabled={!canEdit} onChange={(e) => set('sitemap_priority', e.target.value)} />
              </Field>
              <Field label="Change frequency" htmlFor="seo-freq" error={errors.sitemap_changefreq}>
                <Select id="seo-freq" value={form.sitemap_changefreq} options={FREQ} disabled={!canEdit} onChange={(e) => set('sitemap_changefreq', e.target.value)} />
              </Field>
            </div>
          </section>
          <p className="text-xs text-slate-400">{data.seo ? `Custom SEO saved · last updated ${data.seo.updated_at ?? '—'}` : 'This URL currently uses the automatic defaults.'}</p>
        </div>
      )}
    </Drawer>
  );
}
