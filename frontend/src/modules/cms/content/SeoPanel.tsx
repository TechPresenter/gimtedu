import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { CheckCircle2, Circle, Globe, Search, Share2, Sparkles, Wand2 } from 'lucide-react';
import { Button, Card, CardBody, CardHeader, Field, Input, Select, Tabs, Textarea, Toggle } from '@/components/ui';
import { formatDate } from '@/lib/format';
import { useApi } from '@/lib/queries';
import { MediaField } from './MediaPicker';
import { mediaSrc } from './media';
import type { SeoValues } from './types';

export const emptySeo: SeoValues = {
  meta_title: '', meta_description: '', meta_keywords: '', canonical_url: '', og_title: '', og_description: '', og_image: '',
  robots: 'index,follow', schema_json: '', include_in_sitemap: true, sitemap_priority: '0.6', sitemap_changefreq: 'monthly',
};

interface SeoOptions {
  robots: { value: string; label: string }[];
  changefreq: { value: string; label: string }[];
  site_url: string;
  site_name: string;
}

interface SeoPanelProps {
  value: SeoValues;
  onChange: (v: SeoValues) => void;
  errors: Record<string, string>;
  /** The post being edited (used for previews, defaults and generated schema) */
  post: { title: string; excerpt: string; slug: string; thumbnail: string | null; published_at: string | null; author?: string | null; tags?: string };
  pathPrefix?: string;
  /** Read-only (roles without edit rights) */
  disabled?: boolean;
}

const strip = (s: string) => s.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

function Counter({ len, min, max }: { len: number; min: number; max: number }) {
  const tone = len === 0 ? 'text-slate-400' : len < min ? 'text-amber-600' : len > max ? 'text-red-600' : 'text-emerald-600';
  return <span className={clsx('tabular-nums', tone)}>{len} / {max}</span>;
}

/**
 * SEO panel for a blog post (seo_settings entity "post"): meta tags with length guidance, Google + social previews,
 * robots / canonical / sitemap options and JSON-LD schema with a one-click Article generator.
 */
export function SeoPanel({ value, onChange, errors, post, pathPrefix = '/blog/', disabled }: SeoPanelProps) {
  const [tab, setTab] = useState<'search' | 'social' | 'advanced'>('search');
  const { data: opts } = useApi<SeoOptions>(['blog-seo-options'], 'blog/seo-options', undefined, { staleTime: 10 * 60 * 1000 });
  const set = (patch: Partial<SeoValues>) => onChange({ ...value, ...patch });
  const err = (k: string) => errors[`seo.${k}`];
  // Jump to the tab that holds the first server-side error.
  useEffect(() => {
    const keys = Object.keys(errors).filter((k) => k.startsWith('seo.') && errors[k]);
    if (!keys.length) return;
    const k = keys[0].slice(4);
    setTab(['meta_title', 'meta_description', 'meta_keywords'].includes(k) ? 'search' : k.startsWith('og_') ? 'social' : 'advanced');
  }, [errors]);

  const siteUrl = opts?.site_url ?? window.location.origin;
  const url = `${siteUrl}${pathPrefix}${post.slug || 'your-post-url'}`;
  const effTitle = value.meta_title || post.title || 'Post title';
  const effDesc = value.meta_description || strip(post.excerpt) || 'Add a meta description so search engines show a helpful summary of this post.';
  const ogTitle = value.og_title || effTitle;
  const ogDesc = value.og_description || effDesc;
  const ogImage = value.og_image || post.thumbnail;

  const checks = [
    { ok: value.meta_title.length >= 30 && value.meta_title.length <= 60, label: 'Meta title is 30–60 characters' },
    { ok: value.meta_description.length >= 120 && value.meta_description.length <= 160, label: 'Meta description is 120–160 characters' },
    { ok: !!post.slug && post.slug.length <= 75, label: 'Short, readable URL slug' },
    { ok: !!ogImage, label: 'Social sharing image set' },
    { ok: !!value.schema_json, label: 'Structured data (JSON-LD) added' },
    { ok: value.robots.startsWith('index'), label: 'Visible to search engines' },
  ];
  const score = checks.filter((c) => c.ok).length;

  const fillFromPost = () =>
    set({
      meta_title: value.meta_title || `${post.title}`.slice(0, 60),
      meta_description: value.meta_description || strip(post.excerpt).slice(0, 158),
      meta_keywords: value.meta_keywords || post.tags || '',
      og_title: value.og_title || post.title,
      og_description: value.og_description || strip(post.excerpt).slice(0, 200),
      og_image: value.og_image || post.thumbnail || '',
    });
  const generateSchema = () => {
    const schema = {
      '@context': 'https://schema.org',
      '@type': 'NewsArticle',
      headline: (value.meta_title || post.title).slice(0, 110),
      description: effDesc,
      image: ogImage ? [`${siteUrl}/${ogImage.replace(/^\/+/, '')}`] : undefined,
      datePublished: post.published_at ? new Date(post.published_at.replace(' ', 'T')).toISOString() : undefined,
      author: post.author ? { '@type': 'Person', name: post.author } : { '@type': 'Organization', name: opts?.site_name ?? 'GIMT' },
      publisher: { '@type': 'Organization', name: opts?.site_name ?? 'GIMT' },
      mainEntityOfPage: url,
    };
    set({ schema_json: JSON.stringify(schema, null, 2) });
  };

  return (
    <Card id="seo">
      <CardHeader
        title="SEO & social sharing"
        subtitle="How this post appears in Google and when shared on social media."
        icon={Search}
        actions={
          <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold', score >= 5 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : score >= 3 ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300')}>
            <Sparkles className="h-3.5 w-3.5" /> SEO score {score}/6
          </span>
        }
      />
      <CardBody>
        <fieldset disabled={disabled} className="min-w-0 space-y-5">
        <Tabs
          value={tab}
          onChange={(k) => setTab(k as typeof tab)}
          tabs={[{ key: 'search', label: 'Search', icon: Search }, { key: 'social', label: 'Social', icon: Share2 }, { key: 'advanced', label: 'Advanced', icon: Globe }]}
        />

        {tab === 'search' && (
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <div className="min-w-0 space-y-4 lg:col-span-3">
              <Field label="Meta title" htmlFor="seo-title" error={err('meta_title')} aside={<Counter len={value.meta_title.length} min={30} max={60} />} hint="Defaults to the post title. Keep it under 60 characters.">
                <Input id="seo-title" value={value.meta_title} onChange={(e) => set({ meta_title: e.target.value })} maxLength={190} placeholder={post.title || 'Meta title'} invalid={!!err('meta_title')} />
              </Field>
              <Field label="Meta description" htmlFor="seo-desc" error={err('meta_description')} aside={<Counter len={value.meta_description.length} min={120} max={160} />} hint="A compelling summary — 120 to 160 characters works best.">
                <Textarea id="seo-desc" rows={3} value={value.meta_description} onChange={(e) => set({ meta_description: e.target.value })} maxLength={320} placeholder={strip(post.excerpt) || 'Describe this post for search results'} invalid={!!err('meta_description')} />
              </Field>
              <Field label="Keywords" htmlFor="seo-kw" error={err('meta_keywords')} hint="Comma separated. Used by some search engines and internal search.">
                <Input id="seo-kw" value={value.meta_keywords} onChange={(e) => set({ meta_keywords: e.target.value })} maxLength={255} placeholder="admissions, mba, greater noida" invalid={!!err('meta_keywords')} />
              </Field>
              <Button size="sm" variant="soft" icon={Wand2} onClick={fillFromPost}>
                Fill empty fields from the post
              </Button>
            </div>
            <div className="min-w-0 space-y-4 lg:col-span-2">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Google preview</p>
                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950">
                  <div className="flex items-center gap-2">
                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-brand-800 dark:bg-slate-800 dark:text-brand-200">G</span>
                    <div className="min-w-0 leading-tight">
                      <p className="truncate text-xs text-slate-700 dark:text-slate-200">{opts?.site_name ?? 'GIMT'}</p>
                      <p className="truncate text-[11px] text-slate-500">{url}</p>
                    </div>
                  </div>
                  <p className="mt-2 line-clamp-2 text-[17px] leading-snug text-[#1a0dab] dark:text-[#8ab4f8]">{effTitle.length > 62 ? `${effTitle.slice(0, 60)}…` : effTitle}</p>
                  <p className="mt-1 line-clamp-2 text-[13px] leading-snug text-slate-600 dark:text-slate-400">
                    {post.published_at && <span className="text-slate-500">{formatDate(post.published_at)} — </span>}
                    {effDesc.length > 162 ? `${effDesc.slice(0, 160)}…` : effDesc}
                  </p>
                </div>
              </div>
              <ul className="space-y-1.5" aria-label="SEO checklist">
                {checks.map((c) => (
                  <li key={c.label} className="flex items-center gap-2 text-sm">
                    {c.ok ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" aria-hidden /> : <Circle className="h-4 w-4 shrink-0 text-slate-300 dark:text-slate-600" aria-hidden />}
                    <span className={c.ok ? 'text-slate-700 dark:text-slate-200' : 'text-slate-500'}>{c.label}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        )}

        {tab === 'social' && (
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <div className="min-w-0 space-y-4 lg:col-span-3">
              <Field label="Social title (og:title)" htmlFor="seo-ogt" error={err('og_title')} aside={<Counter len={value.og_title.length} min={20} max={70} />}>
                <Input id="seo-ogt" value={value.og_title} onChange={(e) => set({ og_title: e.target.value })} maxLength={190} placeholder={effTitle} invalid={!!err('og_title')} />
              </Field>
              <Field label="Social description (og:description)" htmlFor="seo-ogd" error={err('og_description')} aside={<Counter len={value.og_description.length} min={60} max={200} />}>
                <Textarea id="seo-ogd" rows={3} value={value.og_description} onChange={(e) => set({ og_description: e.target.value })} maxLength={320} placeholder={effDesc} invalid={!!err('og_description')} />
              </Field>
              <Field label="Social image (og:image)" error={err('og_image')} hint="1200 × 630 px works best. Defaults to the featured image.">
                <MediaField value={value.og_image || null} onChange={(p) => set({ og_image: p ?? '' })} folder="blog" invalid={!!err('og_image')} placeholder="Choose a social image" />
              </Field>
            </div>
            <div className="min-w-0 lg:col-span-2">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Share preview</p>
              <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950">
                <div className="aspect-[1.91/1] bg-slate-100 dark:bg-slate-800">
                  {ogImage ? <img src={mediaSrc(ogImage)} alt="" className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-xs text-slate-400">No image</div>}
                </div>
                <div className="border-t border-slate-100 px-3 py-2.5 dark:border-slate-800">
                  <p className="truncate text-[11px] uppercase text-slate-500">{siteUrl.replace(/^https?:\/\//, '')}</p>
                  <p className="line-clamp-2 text-sm font-semibold text-slate-900 dark:text-white">{ogTitle}</p>
                  <p className="line-clamp-2 text-xs text-slate-500">{ogDesc}</p>
                </div>
              </div>
            </div>
          </div>
        )}

        {tab === 'advanced' && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Canonical URL" htmlFor="seo-canon" error={err('canonical_url')} hint="Only when this content also lives at another URL." className="sm:col-span-2">
              <Input id="seo-canon" value={value.canonical_url} onChange={(e) => set({ canonical_url: e.target.value })} maxLength={255} placeholder={url} invalid={!!err('canonical_url')} />
            </Field>
            <Field label="Robots" htmlFor="seo-robots" error={err('robots')}>
              <Select id="seo-robots" value={value.robots} onChange={(e) => set({ robots: e.target.value })} options={opts?.robots ?? [{ value: 'index,follow', label: 'Index, follow (default)' }]} />
            </Field>
            <Field label="Sitemap" htmlFor="seo-sitemap">
              <div className="flex h-10 items-center">
                <Toggle id="seo-sitemap" checked={value.include_in_sitemap} onChange={(v) => set({ include_in_sitemap: v })} label="Include in sitemap.xml" />
              </div>
            </Field>
            <Field label="Sitemap priority" htmlFor="seo-prio" error={err('sitemap_priority')}>
              <Input id="seo-prio" type="number" min={0} max={1} step={0.1} value={value.sitemap_priority} onChange={(e) => set({ sitemap_priority: e.target.value })} disabled={!value.include_in_sitemap} invalid={!!err('sitemap_priority')} />
            </Field>
            <Field label="Change frequency" htmlFor="seo-freq" error={err('sitemap_changefreq')}>
              <Select id="seo-freq" value={value.sitemap_changefreq} onChange={(e) => set({ sitemap_changefreq: e.target.value })} options={opts?.changefreq ?? [{ value: 'monthly', label: 'Monthly' }]} disabled={!value.include_in_sitemap} />
            </Field>
            <Field
              label="Schema markup (JSON-LD)"
              htmlFor="seo-schema"
              error={err('schema_json')}
              className="sm:col-span-2"
              aside={
                <button type="button" onClick={generateSchema} className="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline dark:text-brand-300">
                  <Wand2 className="h-3.5 w-3.5" /> Generate Article schema
                </button>
              }
            >
              <Textarea id="seo-schema" rows={8} value={value.schema_json} onChange={(e) => set({ schema_json: e.target.value })} className="font-mono text-xs" placeholder='{ "@context": "https://schema.org", "@type": "NewsArticle", … }' invalid={!!err('schema_json')} />
            </Field>
          </div>
        )}
        </fieldset>
      </CardBody>
    </Card>
  );
}
