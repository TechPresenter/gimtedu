import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { CircleAlert, CircleCheck, ExternalLink, Globe, Info, KeyRound, ListChecks, RotateCcw, Save, Search, Share2, TriangleAlert } from 'lucide-react';
import { Alert, Badge, Button, Drawer, Field, Input, PageLoader, Select, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { formatDateTime } from '@/lib/format';
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
  focus_keyword: string | null;
  secondary_keywords: string | null;
  canonical_url: string | null;
  og_title: string | null;
  og_description: string | null;
  og_image: string | null;
  twitter_title: string | null;
  twitter_description: string | null;
  image_alt: string | null;
  robots: string;
  schema_json: string | null;
  include_in_sitemap: boolean;
  sitemap_priority: number;
  sitemap_changefreq: string;
  index_status: string | null;
  index_checked_at: string | null;
  index_note: string | null;
  updated_at: string | null;
}

interface SeoContext {
  audit: { title: string | null; description: string | null; h1: string | null; h1_count: number | null; images_no_alt: number; schema_types: string[]; issues: string[]; status_code: number | null; crawled_at: string | null } | null;
  other_titles: Record<string, string>;
  other_descriptions: Record<string, string>;
  in_sitemap: boolean;
  og_image_missing?: boolean;
  index_statuses: Record<string, string>;
}

interface SeoEntry {
  entity: { type: string; id: number | null; route: string | null; title: string; url: string; absolute_url: string; status: string };
  ref: { type: string; id: number | null; route: string | null };
  seo: SeoRow | null;
  defaults: { meta_title: string; meta_description: string; og_image: string; site_name: string };
  context?: SeoContext;
  can?: { edit: boolean };
}

type Form = {
  meta_title: string; meta_description: string; meta_keywords: string; focus_keyword: string; secondary_keywords: string; canonical_url: string;
  og_title: string; og_description: string; og_image: string; twitter_title: string; twitter_description: string; image_alt: string;
  robots: string; schema_json: string; include_in_sitemap: boolean; sitemap_priority: string; sitemap_changefreq: string;
  index_status: string; index_checked_at: string; index_note: string;
};

const empty: Form = {
  meta_title: '', meta_description: '', meta_keywords: '', focus_keyword: '', secondary_keywords: '', canonical_url: '', og_title: '', og_description: '', og_image: '',
  twitter_title: '', twitter_description: '', image_alt: '', robots: 'index,follow', schema_json: '', include_in_sitemap: true, sitemap_priority: '0.7', sitemap_changefreq: 'weekly',
  index_status: 'unknown', index_checked_at: '', index_note: '',
};

export const ROBOTS_OPTIONS = [
  { value: 'index,follow', label: 'index, follow (default)' }, { value: 'noindex,follow', label: 'noindex, follow' },
  { value: 'index,nofollow', label: 'index, nofollow' }, { value: 'noindex,nofollow', label: 'noindex, nofollow' },
];
const FREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'].map((v) => ({ value: v, label: v[0].toUpperCase() + v.slice(1) }));
const PRIORITY = Array.from({ length: 11 }, (_, i) => ({ value: (i / 10).toFixed(1), label: (i / 10).toFixed(1) }));
const INDEX_STATUSES: Record<string, string> = { unknown: 'Not checked', submitted: 'Submitted', indexed: 'Indexed', excluded: 'Excluded', error: 'Error' };

/** Same rule as the server (cms_seo_has_markup): SEO text is printed into meta tags and must stay plain text. */
const MARKUP = /<\s*[a-z!/?]|&lt;\s*\/?\s*[a-z]|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|\bon[a-z]{3,}\s*=\s*["']?/i;
const TEXT_FIELDS: (keyof Form)[] = ['meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'secondary_keywords', 'canonical_url', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'image_alt', 'index_note'];

/** Lower-case words without punctuation — mirrors cms_seo_fold() for keyword checks. */
function fold(s: string | null | undefined) {
  return (s ?? '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim();
}
function contains(text: string | null | undefined, keyword: string) {
  const k = fold(keyword);
  return k !== '' && ` ${fold(text)} `.includes(` ${k} `);
}
/** Path of a canonical URL on this website, the absolute URL when it points elsewhere. */
function canonicalPath(c: string, ownUrl: string): string {
  const v = c.trim();
  if (!v) return '';
  if (/^https?:\/\//i.test(v)) {
    try {
      const u = new URL(v);
      const own = new URL(ownUrl);
      if (u.host.toLowerCase() !== own.host.toLowerCase()) return v;
      const p = u.pathname + u.search;
      return p === '/' ? '/' : p.replace(/\/+$/, '');
    } catch {
      return v;
    }
  }
  const p = `/${v.replace(/^\/+/, '')}`;
  return p === '/' ? '/' : p.replace(/\/+$/, '');
}

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

type Check = { tone: 'ok' | 'warn' | 'error' | 'info'; text: string };
const CHECK_ICON = { ok: CircleCheck, warn: TriangleAlert, error: CircleAlert, info: Info };
const CHECK_TONE = {
  ok: 'text-emerald-600 dark:text-emerald-400', warn: 'text-amber-600 dark:text-amber-400', error: 'text-red-600 dark:text-red-400', info: 'text-slate-400 dark:text-slate-500',
};

function SeoChecks({ checks }: { checks: Check[] }) {
  const counts = checks.reduce((acc, c) => ({ ...acc, [c.tone]: (acc[c.tone] ?? 0) + 1 }), {} as Record<string, number>);
  return (
    <section aria-labelledby="seo-checks-title">
      <h3 id="seo-checks-title" className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">
        <ListChecks className="h-3.5 w-3.5" />Live checks
        <span className="ml-auto flex gap-1 normal-case tracking-normal">
          {counts.error ? <Badge color="red">{counts.error} to fix</Badge> : null}
          {counts.warn ? <Badge color="amber">{counts.warn} to improve</Badge> : null}
          {counts.ok ? <Badge color="green">{counts.ok} good</Badge> : null}
        </span>
      </h3>
      <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700" aria-live="polite">
        {checks.map((c, i) => {
          const Icon = CHECK_ICON[c.tone];
          return (
            <li key={i} className="flex items-start gap-2.5 px-3 py-2 text-sm">
              <Icon className={clsx('mt-0.5 h-4 w-4 shrink-0', CHECK_TONE[c.tone])} aria-hidden />
              <span className="sr-only">{c.tone === 'ok' ? 'Passed:' : c.tone === 'info' ? 'Note:' : c.tone === 'warn' ? 'Warning:' : 'Problem:'}</span>
              <span className="min-w-0 break-words text-slate-700 dark:text-slate-200">{c.text}</span>
            </li>
          );
        })}
      </ul>
    </section>
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
      meta_title: s.meta_title ?? '', meta_description: s.meta_description ?? '', meta_keywords: s.meta_keywords ?? '', focus_keyword: s.focus_keyword ?? '',
      secondary_keywords: s.secondary_keywords ?? '', canonical_url: s.canonical_url ?? '', og_title: s.og_title ?? '', og_description: s.og_description ?? '', og_image: s.og_image ?? '',
      twitter_title: s.twitter_title ?? '', twitter_description: s.twitter_description ?? '', image_alt: s.image_alt ?? '',
      robots: s.robots || 'index,follow', schema_json: s.schema_json ?? '', include_in_sitemap: !!s.include_in_sitemap, sitemap_priority: Number(s.sitemap_priority ?? 0.7).toFixed(1),
      sitemap_changefreq: s.sitemap_changefreq || 'weekly', index_status: s.index_status || 'unknown', index_checked_at: s.index_checked_at ?? '', index_note: s.index_note ?? '',
    } : empty);
    setErrors({});
    setFormError(null);
  }, [data]);

  const schemaError = useMemo(() => {
    if (!form.schema_json.trim()) return null;
    try {
      const v = JSON.parse(form.schema_json);
      if (!v || typeof v !== 'object') return 'Schema must be a JSON object or array.';
      return /<\s*\/?\s*script|<!--|javascript\s*:/i.test(form.schema_json) ? 'Schema may not contain <script> tags, HTML comments or javascript: links.' : null;
    } catch (e) {
      return `Invalid JSON: ${(e as Error).message}`;
    }
  }, [form.schema_json]);

  /** Client-side mirror of the server's plain-text rule (the server rejects it too). */
  const markupErrors = useMemo(() => {
    const out: Record<string, string> = {};
    TEXT_FIELDS.forEach((k) => {
      const v = form[k];
      if (typeof v === 'string' && MARKUP.test(v)) out[k] = 'Plain text only — HTML tags and scripts are not allowed.';
    });
    return out;
  }, [form]);

  const set = <K extends keyof Form>(k: K, v: Form[K]) => {
    setForm((f) => ({ ...f, [k]: v }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const canEdit = data?.can?.edit ?? false;
  const ctx = data?.context;
  const audit = ctx?.audit ?? null;
  // What the page prints when a field is empty: the crawled value (latest audit) or the template default
  const effTitle = form.meta_title || audit?.title || data?.defaults.meta_title || '';
  const effDesc = form.meta_description || audit?.description || data?.defaults.meta_description || '';
  const ogImage = form.og_image || data?.defaults.og_image || '';
  // the stored / default share image whose file is missing on the server (a newly picked image always exists)
  const ogMissing = !!ctx?.og_image_missing && (form.og_image || '') === (data?.seo?.og_image ?? '');

  const checks = useMemo<Check[]>(() => {
    if (!data) return [];
    const out: Check[] = [];
    const kw = form.focus_keyword.trim();
    const tl = effTitle.length;
    const dl = effDesc.length;
    out.push(tl > 60 ? { tone: 'warn', text: `Title is ${tl} characters — Google shows about 60.` } : tl < 30 ? { tone: 'warn', text: `Title is only ${tl} characters — aim for 30–60.` } : { tone: 'ok', text: `Title length ${tl} characters.` });
    out.push(dl === 0 ? { tone: 'error', text: 'No meta description — search engines will pick text from the page.' }
      : dl > 160 ? { tone: 'warn', text: `Description is ${dl} characters — it will be cut after about 160.` }
        : dl < 70 ? { tone: 'warn', text: `Description is only ${dl} characters — aim for 70–160.` } : { tone: 'ok', text: `Description length ${dl} characters.` });
    if (!kw) {
      out.push({ tone: 'info', text: 'No focus keyword — add the main search phrase this page should rank for to get keyword checks.' });
    } else {
      out.push(contains(effTitle, kw) ? { tone: 'ok', text: `Focus keyword “${kw}” appears in the title.` } : { tone: 'warn', text: `Focus keyword “${kw}” is not in the title.` });
      out.push(contains(effDesc, kw) ? { tone: 'ok', text: 'Focus keyword appears in the description.' } : { tone: 'warn', text: 'Focus keyword is not in the meta description.' });
      if (audit?.h1) out.push(contains(audit.h1, kw) ? { tone: 'ok', text: `Focus keyword appears in the H1 (“${audit.h1}”).` } : { tone: 'warn', text: `Focus keyword is not in the H1 (“${audit.h1}”).` });
      else out.push({ tone: 'info', text: audit ? 'The last audit found no H1 on this page.' : 'H1 not known yet — run a site audit (SEO › Audit) to check the H1.' });
      if (contains(data.entity.url.replace(/[-_/]+/g, ' '), kw)) out.push({ tone: 'ok', text: 'Focus keyword appears in the URL.' });
    }
    const dupTitle = ctx?.other_titles?.[effTitle.toLowerCase()];
    if (effTitle && dupTitle) out.push({ tone: 'warn', text: `Duplicate title — ${dupTitle} uses the same title.` });
    const dupDesc = ctx?.other_descriptions?.[effDesc.toLowerCase()];
    if (effDesc && dupDesc) out.push({ tone: 'warn', text: `Duplicate description — ${dupDesc} uses the same description.` });
    const canon = canonicalPath(form.canonical_url, data.entity.absolute_url);
    const own = data.entity.url === '/' ? '/' : data.entity.url.replace(/\/+$/, '');
    if (canon && canon.toLowerCase() !== own.toLowerCase()) out.push({ tone: 'warn', text: `Canonical points elsewhere (${canon}) — search engines will index that address instead, and this URL is left out of the sitemap.` });
    const noindex = form.robots.includes('noindex');
    if (noindex && form.include_in_sitemap) out.push({ tone: 'info', text: 'noindex + “Include in sitemap”: noindex pages are left out of the sitemap automatically.' });
    if (noindex && ctx?.in_sitemap) out.push({ tone: 'error', text: 'This URL is noindex but currently listed in the sitemap — save to remove it.' });
    if (!noindex && !form.include_in_sitemap) out.push({ tone: 'info', text: 'Indexable but left out of the sitemap — search engines find it only through links.' });
    if (ogMissing) out.push({ tone: 'error', text: `The share image file (${ogImage}) does not exist on this server — choose another image.` });
    if (ogImage && !form.image_alt.trim()) out.push({ tone: 'info', text: 'Add alt text for the share image (describes it to screen readers and some platforms).' });
    if (schemaError) out.push({ tone: 'error', text: schemaError });
    if (audit) {
      if (audit.images_no_alt > 0) out.push({ tone: 'warn', text: `${audit.images_no_alt} image(s) on the page have no alt text (last audit).` });
      if (audit.issues.includes('broken_links')) out.push({ tone: 'error', text: 'The page links to broken internal URLs (last audit).' });
      if (audit.issues.some((i) => i.startsWith('schema_') && i !== 'schema_none')) out.push({ tone: 'warn', text: 'Structured data on the page has errors (last audit).' });
    }
    if (Object.keys(markupErrors).length) out.push({ tone: 'error', text: 'Some fields contain HTML or script code — SEO fields accept plain text only.' });
    return out;
  }, [data, ctx, audit, form, effTitle, effDesc, ogImage, ogMissing, schemaError, markupErrors]);

  const save = async () => {
    if (!target) return;
    if (schemaError || Object.keys(markupErrors).length) {
      setErrors((e) => ({ ...e, ...markupErrors, ...(schemaError ? { schema_json: schemaError } : {}) }));
      setFormError('Please fix the highlighted fields and try again.');
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
    const ok = await confirm({ title: 'Reset SEO to defaults?', message: 'The custom title, description, keywords, social card, schema and indexing notes for this URL will be removed and the automatic defaults used instead.', confirmText: 'Reset', danger: true });
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

  const err = (k: keyof Form) => errors[k] || markupErrors[k];
  const statuses = ctx?.index_statuses ?? INDEX_STATUSES;
  const host = data ? (() => { try { return new URL(data.entity.absolute_url).host; } catch { return ''; } })() : '';

  return (
    <Drawer
      open={!!target}
      onClose={onClose}
      width="max-w-2xl"
      title={data ? `SEO — ${data.entity.title}` : `SEO — ${target?.title ?? ''}`}
      description={data ? <span className="inline-flex flex-wrap items-center gap-2">{data.entity.url}{data.ref.type === 'route' && data.entity.type === 'page' && <Badge color="blue">Saved on route {data.ref.route}</Badge>}</span> : 'Search and social settings'}
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
          <SeoChecks checks={checks} />
          <section className="space-y-4">
            <Field label="SEO title" htmlFor="seo-title" error={err('meta_title')} hint={!form.meta_title && audit?.title ? 'Empty = the title the page prints now (from the last audit).' : undefined}>
              <Input id="seo-title" value={form.meta_title} maxLength={190} placeholder={audit?.title || data.defaults.meta_title} disabled={!canEdit} invalid={!!err('meta_title')} onChange={(e) => set('meta_title', e.target.value)} />
              <LengthMeter length={form.meta_title.length} min={30} max={60} hardMax={80} />
            </Field>
            <Field label="Meta description" htmlFor="seo-desc" error={err('meta_description')}>
              <Textarea id="seo-desc" rows={3} value={form.meta_description} maxLength={320} placeholder={audit?.description || data.defaults.meta_description || 'Describe this page in one or two sentences.'} disabled={!canEdit} invalid={!!err('meta_description')} onChange={(e) => set('meta_description', e.target.value)} />
              <LengthMeter length={form.meta_description.length} min={70} max={160} hardMax={200} />
            </Field>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Focus keyword" htmlFor="seo-focus" error={err('focus_keyword')} hint="The main phrase this page should rank for.">
                <Input id="seo-focus" value={form.focus_keyword} maxLength={120} disabled={!canEdit} invalid={!!err('focus_keyword')} prefix={<KeyRound className="h-4 w-4" />} onChange={(e) => set('focus_keyword', e.target.value)} />
              </Field>
              <Field label="Secondary keywords" htmlFor="seo-secondary" error={err('secondary_keywords')} hint="Comma separated.">
                <Input id="seo-secondary" value={form.secondary_keywords} maxLength={500} disabled={!canEdit} invalid={!!err('secondary_keywords')} onChange={(e) => set('secondary_keywords', e.target.value)} />
              </Field>
              <Field label="Meta keywords tag" htmlFor="seo-kw" error={err('meta_keywords')} hint="Optional; search engines ignore it.">
                <Input id="seo-kw" value={form.meta_keywords} maxLength={255} disabled={!canEdit} invalid={!!err('meta_keywords')} onChange={(e) => set('meta_keywords', e.target.value)} />
              </Field>
              <Field label="Robots" htmlFor="seo-robots" error={errors.robots}>
                <Select id="seo-robots" value={form.robots} options={ROBOTS_OPTIONS} disabled={!canEdit} onChange={(e) => set('robots', e.target.value)} />
              </Field>
            </div>
            {form.robots.includes('noindex') && <Alert variant="warning">This URL will be hidden from search engines and left out of the sitemap.</Alert>}
            <Field label="Canonical URL" htmlFor="seo-canonical" error={err('canonical_url')} hint="Only when this content is also published at another address.">
              <Input id="seo-canonical" value={form.canonical_url} maxLength={255} placeholder={data.entity.absolute_url} disabled={!canEdit} invalid={!!err('canonical_url')} onChange={(e) => set('canonical_url', e.target.value)} />
            </Field>
          </section>
          <section className="space-y-4">
            <h3 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300"><Share2 className="h-3.5 w-3.5" />Social sharing (Open Graph and X)</h3>
            <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
              {ogImage && !ogMissing ? <img src={appUrl(ogImage)} alt={form.image_alt} className="aspect-[1.91/1] w-full object-cover" /> : <div className={clsx('flex aspect-[1.91/1] items-center justify-center bg-slate-100 text-sm dark:bg-slate-800', ogMissing ? 'text-red-600 dark:text-red-400' : 'text-slate-400')}>{ogMissing ? 'Share image file not found' : 'No share image'}</div>}
              <div className="bg-slate-50 p-3 dark:bg-slate-800/60">
                <p className="text-[11px] uppercase text-slate-500">{host}</p>
                <p className="line-clamp-1 font-semibold text-slate-900 dark:text-white">{form.og_title || effTitle}</p>
                <p className="line-clamp-2 text-xs text-slate-500">{form.og_description || effDesc}</p>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Social title" htmlFor="seo-ogt" error={err('og_title')}>
                <Input id="seo-ogt" value={form.og_title} maxLength={190} placeholder="Same as SEO title" disabled={!canEdit} invalid={!!err('og_title')} onChange={(e) => set('og_title', e.target.value)} />
              </Field>
              <Field label="Share image" error={errors.og_image} hint="1200×630 px works best.">
                {canEdit ? <ImageField value={form.og_image} onChange={(v) => set('og_image', v)} invalid={!!errors.og_image} compact /> : <p className="text-sm text-slate-500">{form.og_image || 'Default image'}</p>}
              </Field>
            </div>
            <Field label="Social description" htmlFor="seo-ogd" error={err('og_description')}>
              <Textarea id="seo-ogd" rows={2} value={form.og_description} maxLength={320} placeholder="Same as meta description" disabled={!canEdit} invalid={!!err('og_description')} onChange={(e) => set('og_description', e.target.value)} />
            </Field>
            <Field label="Share image alt text" htmlFor="seo-alt" error={err('image_alt')} hint="Describe the image in a few words (about 125 characters at most).">
              <Input id="seo-alt" value={form.image_alt} maxLength={190} disabled={!canEdit} invalid={!!err('image_alt')} onChange={(e) => set('image_alt', e.target.value)} />
            </Field>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="X (Twitter) title" htmlFor="seo-twt" error={err('twitter_title')} hint="Empty = social title.">
                <Input id="seo-twt" value={form.twitter_title} maxLength={190} placeholder={form.og_title || effTitle} disabled={!canEdit} invalid={!!err('twitter_title')} onChange={(e) => set('twitter_title', e.target.value)} />
              </Field>
              <Field label="X (Twitter) description" htmlFor="seo-twd" error={err('twitter_description')} hint="Empty = social description.">
                <Input id="seo-twd" value={form.twitter_description} maxLength={320} placeholder={form.og_description || effDesc} disabled={!canEdit} invalid={!!err('twitter_description')} onChange={(e) => set('twitter_description', e.target.value)} />
              </Field>
            </div>
          </section>
          <section className="space-y-4">
            <h3 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300"><Globe className="h-3.5 w-3.5" />Structured data & sitemap</h3>
            <Field label="Schema (JSON-LD)" htmlFor="seo-schema" error={errors.schema_json ?? schemaError} hint="Valid JSON object, e.g. {&quot;@context&quot;: &quot;https://schema.org&quot;, &quot;@type&quot;: &quot;Course&quot;, …}">
              <Textarea id="seo-schema" rows={7} value={form.schema_json} disabled={!canEdit} invalid={!!(errors.schema_json ?? schemaError)} className="font-mono text-xs" onChange={(e) => set('schema_json', e.target.value)}
                placeholder={'{\n  "@context": "https://schema.org",\n  "@type": "WebPage"\n}'} />
            </Field>
            {audit && audit.schema_types.length > 0 && <p className="-mt-2 text-xs text-slate-500">Structured data on the live page (last audit): {audit.schema_types.join(', ')}</p>}
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
          <section className="space-y-4">
            <h3 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300"><Search className="h-3.5 w-3.5" />Indexing status</h3>
            <p className="-mt-2 text-xs text-slate-500">Record what Google Search Console (URL Inspection) or Bing Webmaster Tools reports for this URL.</p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Field label="Status" htmlFor="seo-index" error={errors.index_status}>
                <Select id="seo-index" value={form.index_status} options={Object.entries(statuses).map(([value, label]) => ({ value, label }))} disabled={!canEdit} onChange={(e) => set('index_status', e.target.value)} />
              </Field>
              <Field label="Checked on" htmlFor="seo-index-date" error={errors.index_checked_at}>
                <Input id="seo-index-date" type="date" value={form.index_checked_at} max={new Date().toISOString().slice(0, 10)} disabled={!canEdit} invalid={!!errors.index_checked_at} onChange={(e) => set('index_checked_at', e.target.value)} />
              </Field>
              <Field label="Note" htmlFor="seo-index-note" error={err('index_note')}>
                <Input id="seo-index-note" value={form.index_note} maxLength={255} placeholder="e.g. Crawled – currently not indexed" disabled={!canEdit} invalid={!!err('index_note')} onChange={(e) => set('index_note', e.target.value)} />
              </Field>
            </div>
          </section>
          <p className="text-xs text-slate-400">
            {data.seo ? `Custom SEO saved · last updated ${data.seo.updated_at ? formatDateTime(data.seo.updated_at) : '—'}` : 'This URL currently uses the automatic defaults.'}
            {audit?.crawled_at && ` · Last audited ${formatDateTime(audit.crawled_at)}`}
          </p>
        </div>
      )}
    </Drawer>
  );
}
