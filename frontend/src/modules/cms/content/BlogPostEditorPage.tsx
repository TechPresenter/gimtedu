import { useEffect, useMemo, useRef, useState } from 'react';
import { useBlocker, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  AlertTriangle, CalendarClock, CheckCircle2, Copy, ExternalLink, Eye, FileEdit, ImageIcon, Info, Loader2, Save, Send, Star, Tags, Trash2, Undo2, XCircle,
} from 'lucide-react';
import {
  Alert, Button, Card, CardBody, CardHeader, Combobox, DescriptionList, EmptyState, Field, Input, PageHeader, RichText, Select, Skeleton, Textarea, Toggle,
  useConfirm, useToast,
} from '@/components/ui';
import type { CrudField, CrudRecordPayload } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { useCrudMeta } from '@/lib/queries';
import type { Option } from '@/lib/types';
import { MediaField } from './MediaPicker';
import { SeoPanel, emptySeo } from './SeoPanel';
import { TagInput } from './widgets';
import type { SeoValues } from './types';

interface PostValues {
  title: string;
  slug: string;
  excerpt: string;
  content: string;
  status: 'draft' | 'scheduled' | 'published';
  published_at: string;
  type: string;
  category_id: string | number;
  author_id: string | number | null;
  tags: string;
  is_featured: boolean;
  thumbnail: string;
}

const blank: PostValues = {
  title: '', slug: '', excerpt: '', content: '', status: 'draft', published_at: '', type: 'news', category_id: '', author_id: null, tags: '', is_featured: false, thumbnail: '',
};

const slugify = (s: string) => s.toLowerCase().normalize('NFKD').replace(/[^\w\s-]/g, '').trim().replace(/[\s_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 180);
const textLen = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim().length;
const words = (html: string) => (html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim().match(/\S+/g) ?? []).length;

const STATUSES = [
  { key: 'draft', label: 'Draft', icon: FileEdit, hint: 'Only visible in the admin panel' },
  { key: 'scheduled', label: 'Scheduled', icon: CalendarClock, hint: 'Goes live automatically at the publish time' },
  { key: 'published', label: 'Published', icon: CheckCircle2, hint: 'Visible on the website now' },
] as const;

function toLocalInput(v: unknown): string {
  if (!v) return '';
  const s = String(v).replace(' ', 'T');
  return s.length >= 16 ? s.slice(0, 16) : s;
}

/** Full-page editor for a blog / news post: content, publishing, featured image, organisation and the SEO panel. */
export default function BlogPostEditorPage() {
  const { id: idParam } = useParams();
  const id = idParam && /^\d+$/.test(idParam) ? Number(idParam) : null;
  const navigate = useNavigate();
  const location = useLocation();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can, session } = useAuth();
  const user = session?.user;
  const canPublish = can('blog', 'publish');
  const canSave = id ? can('blog', 'edit') : can('blog', 'create');

  const meta = useCrudMeta('blog_posts');
  const record = useQuery({ queryKey: ['crud-record', 'blog_posts', id], queryFn: () => api.get<CrudRecordPayload>(`crud/blog_posts/${id}`), enabled: !!id });
  const seoQ = useQuery({ queryKey: ['blog-seo', id], queryFn: () => api.get<SeoValues>(`blog/posts/${id}/seo`), enabled: !!id });

  const [values, setValues] = useState<PostValues>({ ...blank, author_id: user?.id ?? null });
  const [seo, setSeo] = useState<SeoValues>(emptySeo);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState<string | null>(null);
  const [slugTouched, setSlugTouched] = useState(false);
  const snapshot = useRef<string>('');
  const loadedFor = useRef<number | null | undefined>(undefined);

  // Load the record + SEO once per id
  useEffect(() => {
    if (!id) {
      if (loadedFor.current !== null) {
        const init = { ...blank, author_id: user?.id ?? null };
        setValues(init);
        setSeo(emptySeo);
        snapshot.current = JSON.stringify([init, emptySeo]);
        loadedFor.current = null;
        setSlugTouched(false);
      }
      return;
    }
    if (!record.data || !seoQ.data || loadedFor.current === id) return;
    const v = record.data.values;
    const next: PostValues = {
      title: String(v.title ?? ''), slug: String(v.slug ?? ''), excerpt: String(v.excerpt ?? ''), content: String(v.content ?? ''),
      status: (v.status as PostValues['status']) ?? 'draft', published_at: toLocalInput(v.published_at), type: String(v.type ?? 'news'),
      category_id: (v.category_id as number) ?? '', author_id: (v.author_id as number) ?? null, tags: String(v.tags ?? ''), is_featured: !!v.is_featured, thumbnail: String(v.thumbnail ?? ''),
    };
    const s: SeoValues = { ...emptySeo, ...Object.fromEntries(Object.entries(seoQ.data).map(([k, x]) => [k, x === null ? '' : x])) } as SeoValues;
    s.include_in_sitemap = !!seoQ.data.include_in_sitemap;
    s.sitemap_priority = String(seoQ.data.sitemap_priority ?? '0.6');
    setValues(next);
    setSeo(s);
    setSlugTouched(true);
    setErrors({});
    setFormError(null);
    snapshot.current = JSON.stringify([next, s]);
    loadedFor.current = id;
  }, [id, record.data, seoQ.data, user?.id]);

  // "Edit SEO" deep link (/cms/blog/5#seo): scroll to the panel once the form is rendered.
  const ready = !id || loadedFor.current === id;
  useEffect(() => {
    if (!ready || location.hash !== '#seo') return;
    const t = setTimeout(() => document.getElementById('seo')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
    return () => clearTimeout(t);
  }, [ready, location.hash]);

  const dirty = snapshot.current !== '' && JSON.stringify([values, seo]) !== snapshot.current;
  // In-app navigation guard (sidebar, breadcrumbs, back button) — skipped right after save/delete/discard.
  const dirtyRef = useRef(dirty);
  dirtyRef.current = dirty;
  const allowNav = useRef(false);
  useEffect(() => {
    allowNav.current = false;
  }, [location.pathname]);
  const blocker = useBlocker(({ currentLocation, nextLocation }) => !allowNav.current && dirtyRef.current && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    void confirm({ title: 'Leave without saving?', message: 'Your changes to this post have not been saved.', confirmText: 'Discard changes', danger: true }).then((ok) =>
      ok ? blocker.proceed() : blocker.reset(),
    );
  }, [blocker, confirm]);
  useEffect(() => {
    if (!dirty) return;
    const onBefore = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', onBefore);
    return () => window.removeEventListener('beforeunload', onBefore);
  }, [dirty]);

  const set = <K extends keyof PostValues>(k: K, v: PostValues[K]) => {
    setValues((p) => {
      const next = { ...p, [k]: v };
      if (k === 'title' && !slugTouched) next.slug = slugify(String(v));
      return next;
    });
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };

  // Live slug availability
  const dSlug = useDebounce(values.slug, 400);
  const slugCheck = useQuery({
    queryKey: ['blog-slug', dSlug, id],
    queryFn: () => api.get<{ slug: string; available: boolean; suggestion: string }>('blog/slug-check', { slug: dSlug, id: id ?? undefined }),
    enabled: dSlug.length > 0,
    staleTime: 10_000,
  });

  const fieldOpts = (name: string): Option[] => (meta.data?.fields.find((f: CrudField) => f.name === name)?.options ?? []) as Option[];
  const categories = fieldOpts('category_id');
  const types = fieldOpts('type');
  const authors = fieldOpts('author_id');
  const row = record.data?.row as Record<string, unknown> | undefined;
  const live = row?.status === 'published';

  const save = async (status?: PostValues['status']) => {
    if (!canSave) return;
    const target = status ?? values.status;
    const clientErr: Record<string, string> = {};
    if (!values.title.trim()) clientErr.title = 'Title is required.';
    if (target === 'scheduled' && !values.published_at) clientErr.published_at = 'Choose when this post should go live.';
    if (target !== 'draft' && textLen(values.content) < 40) clientErr.content = 'Write the post content (at least a few sentences) before publishing.';
    if (Object.keys(clientErr).length) {
      setErrors(clientErr);
      setFormError('Please fix the highlighted fields.');
      return;
    }
    setSaving(target);
    setFormError(null);
    try {
      const payload = {
        ...values,
        status: target,
        category_id: values.category_id || null,
        author_id: values.author_id || null,
        published_at: values.published_at || null,
        thumbnail: values.thumbnail || null,
        seo: { ...seo, include_in_sitemap: seo.include_in_sitemap },
      };
      const res = await api.post<{ id: number; row: Record<string, unknown> | null }>(id ? `crud/blog_posts/${id}` : 'crud/blog_posts', payload);
      const savedStatus = String(res.data.row?.status ?? target);
      toast.success(
        savedStatus === 'published' ? (id && live ? 'Post updated on the website.' : 'Post published.') : savedStatus === 'scheduled' ? `Post scheduled for ${formatDateTime(res.data.row?.published_at)}.` : 'Draft saved.',
      );
      setErrors({});
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['crud', 'blog_posts'] }),
        qc.invalidateQueries({ queryKey: ['blog-summary'] }),
        qc.invalidateQueries({ queryKey: ['crud', 'blog_categories'] }),
      ]);
      if (!id) {
        snapshot.current = '';
        allowNav.current = true;
        navigate(`/cms/blog/${res.data.id}`, { replace: true });
      } else {
        loadedFor.current = undefined;
        await Promise.all([record.refetch(), seoQ.refetch()]);
      }
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setFormError(err.message || 'Unable to save the post. Please try again.');
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message || 'Unable to save the post.');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setSaving(null);
    }
  };

  const duplicate = async () => {
    if (!id) return;
    try {
      const res = await api.post<{ id: number }>(`blog/posts/${id}/duplicate`);
      toast.success(res.message);
      await qc.invalidateQueries({ queryKey: ['crud', 'blog_posts'] });
      navigate(`/cms/blog/${res.data.id}`);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async () => {
    if (!id) return;
    const ok = await confirm({ title: 'Delete this post?', message: <>“{values.title || 'Untitled'}” and its SEO settings will be permanently deleted. This cannot be undone.</>, confirmText: 'Delete post', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/blog_posts/${id}`);
      toast.success(res.message || 'Post deleted.');
      snapshot.current = '';
      allowNav.current = true;
      await qc.invalidateQueries({ queryKey: ['crud', 'blog_posts'] });
      await qc.invalidateQueries({ queryKey: ['blog-summary'] });
      navigate('/cms/blog');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const discard = async () => {
    const ok = await confirm({ title: 'Discard changes?', message: 'Your unsaved changes will be lost.', confirmText: 'Discard' });
    if (!ok) return;
    if (id) {
      loadedFor.current = undefined;
      await Promise.all([record.refetch(), seoQ.refetch()]);
    } else {
      snapshot.current = '';
      allowNav.current = true;
      navigate('/cms/blog');
    }
  };

  const wordCount = useMemo(() => words(values.content), [values.content]);
  const minutes = wordCount ? Math.max(1, Math.round(wordCount / 200)) : 0;
  const primaryLabel = values.status === 'published' ? (id && live ? 'Update' : 'Publish') : values.status === 'scheduled' ? 'Schedule' : 'Save draft';
  const PrimaryIcon = values.status === 'draft' ? Save : Send;
  const title = id ? (values.title || 'Edit post') : 'New post';

  if (id && (record.error || seoQ.error)) {
    const err = (record.error ?? seoQ.error) as ApiError;
    return (
      <>
        <PageHeader title="Post not available" breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Blog / News', to: '/cms/blog' }, { label: 'Post' }]} />
        <Card>
          <EmptyState icon={XCircle} title={err.status === 404 ? 'This post no longer exists' : 'Unable to load this post'} description={err.message} action={<Button to="/cms/blog">Back to all posts</Button>} />
        </Card>
      </>
    );
  }

  const loading = !!id && (record.isLoading || seoQ.isLoading || loadedFor.current !== id);

  return (
    <>
      <PageHeader
        title={title}
        description={id ? <>Last saved {row?.updated_at ? timeAgo(row.updated_at) : row?.created_at ? timeAgo(row.created_at) : '—'}{dirty && <span className="ml-2 font-medium text-amber-600">· Unsaved changes</span>}</> : 'Write a news story, blog article, press release or achievement.'}
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Blog / News', to: '/cms/blog' }, { label: id ? 'Edit post' : 'New post' }]}
        actions={
          <>
            {id && values.slug && (
              <Button variant="secondary" icon={live ? ExternalLink : Eye} href={appUrl(`blog/${values.slug}`)} target="_blank">
                {live ? 'View live' : 'Preview'}
              </Button>
            )}
            {dirty && (
              <Button variant="ghost" icon={Undo2} onClick={discard}>
                Discard
              </Button>
            )}
            {canSave && (
              <Button icon={PrimaryIcon} loading={!!saving} disabled={loading} onClick={() => save()}>
                {primaryLabel}
              </Button>
            )}
          </>
        }
      />

      {!canSave && (
        <Alert variant="info" className="mb-4" title="Read-only">
          You can view this post but your role cannot {id ? 'edit' : 'create'} posts.
        </Alert>
      )}
      {formError && (
        <Alert variant="error" className="mb-4" title="Not saved">
          {formError}
        </Alert>
      )}

      {loading ? (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="space-y-4 lg:col-span-2">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-6 w-1/2" />
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-80 w-full" />
          </div>
          <div className="space-y-4">
            <Skeleton className="h-56 w-full" />
            <Skeleton className="h-48 w-full" />
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
          {/* ---------------------------------------------------------------- main column */}
          <div className="min-w-0 space-y-6 lg:col-span-2">
            <Card>
              <CardBody className="space-y-5">
                <Field label="Title" required htmlFor="post-title" error={errors.title} aside={`${values.title.length} / 255`}>
                  <Input
                    id="post-title"
                    value={values.title}
                    onChange={(e) => set('title', e.target.value)}
                    maxLength={255}
                    placeholder="e.g. GIMT students win the inter-college hackathon"
                    className="!h-12 font-display !text-lg font-semibold"
                    invalid={!!errors.title}
                    disabled={!canSave}
                  />
                </Field>
                <Field
                  label="URL slug"
                  htmlFor="post-slug"
                  error={errors.slug}
                  hint={
                    slugCheck.data && dSlug === values.slug ? (
                      slugCheck.data.available ? (
                        <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400"><CheckCircle2 className="h-3.5 w-3.5" /> Available</span>
                      ) : (
                        <span className="inline-flex flex-wrap items-center gap-1 text-amber-700 dark:text-amber-400">
                          <AlertTriangle className="h-3.5 w-3.5" /> Already used by another post.
                          <button type="button" className="font-semibold underline" onClick={() => { setSlugTouched(true); set('slug', slugCheck.data!.suggestion); }}>
                            Use “{slugCheck.data.suggestion}”
                          </button>
                        </span>
                      )
                    ) : (
                      'Generated from the title. Lowercase letters, numbers and dashes.'
                    )
                  }
                >
                  <Input
                    id="post-slug"
                    value={values.slug}
                    prefix={<span className="text-xs">/blog/</span>}
                    className="!pl-14 font-mono text-sm"
                    onChange={(e) => {
                      setSlugTouched(true);
                      set('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-'));
                    }}
                    maxLength={190}
                    invalid={!!errors.slug}
                    disabled={!canSave}
                  />
                </Field>
                <Field label="Excerpt" htmlFor="post-excerpt" error={errors.excerpt} aside={`${values.excerpt.length} / 500`} hint="Shown on listing cards. Generated from the content when left empty.">
                  <Textarea id="post-excerpt" rows={3} value={values.excerpt} onChange={(e) => set('excerpt', e.target.value)} maxLength={500} invalid={!!errors.excerpt} disabled={!canSave} placeholder="A one or two sentence summary of the post…" />
                </Field>
                <Field label="Content" htmlFor="post-content" error={errors.content} aside={wordCount ? `${formatNumber(wordCount)} words · ${minutes} min read` : '0 words'}>
                  {canSave ? (
                    <RichText id="post-content" value={values.content} onChange={(h) => set('content', h)} minHeight={360} invalid={!!errors.content} placeholder="Start writing your story…" />
                  ) : (
                    // Read-only roles: show the (server-sanitised) content instead of an editor.
                    <div id="post-content" className="max-w-none rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 py-3 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-100 [&_a]:text-brand-700 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-slate-200 [&_blockquote]:pl-3 [&_h2]:mb-2 [&_h2]:mt-3 [&_h2]:text-lg [&_h2]:font-bold [&_h3]:mb-1.5 [&_h3]:mt-2 [&_h3]:text-base [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1.5 [&_ul]:list-disc [&_ul]:pl-5" dangerouslySetInnerHTML={{ __html: values.content || '<p>No content yet.</p>' }} />
                  )}
                </Field>
              </CardBody>
            </Card>

            <SeoPanel
                value={seo}
                onChange={setSeo}
                errors={errors}
                disabled={!canSave}
                post={{ title: values.title, excerpt: values.excerpt || values.content, slug: values.slug, thumbnail: values.thumbnail || null, published_at: values.published_at || (row?.published_at as string) || null, author: authors.find((a) => String(a.value) === String(values.author_id))?.label, tags: values.tags }}
              />
          </div>

          {/* ---------------------------------------------------------------- sidebar */}
          <div className="min-w-0 space-y-6">
            <Card>
              <CardHeader title="Publishing" icon={Send} subtitle={id ? `Currently ${String(row?.status ?? values.status)}` : 'New posts start as drafts'} />
              <CardBody className="space-y-4">
                <div role="radiogroup" aria-label="Status" className="grid gap-2">
                  {STATUSES.map((s) => {
                    // Publishing, scheduling and taking a live post offline all need blog.publish.
                    const disabled = !canSave || (!canPublish && (row?.status ?? 'draft') !== s.key && (s.key !== 'draft' || (row?.status ?? 'draft') !== 'draft'));
                    const active = values.status === s.key;
                    return (
                      <button
                        key={s.key}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        disabled={disabled}
                        onClick={() => set('status', s.key)}
                        className={clsx(
                          'flex items-start gap-3 rounded-xl border px-3 py-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-50',
                          active ? 'border-brand-600 bg-brand-50/70 ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/10 dark:ring-brand-400' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600',
                        )}
                      >
                        <s.icon className={clsx('mt-0.5 h-4 w-4 shrink-0', active ? 'text-brand-700 dark:text-brand-300' : 'text-slate-400')} />
                        <span className="min-w-0">
                          <span className="block text-sm font-semibold text-slate-800 dark:text-slate-100">{s.label}</span>
                          <span className="block text-xs text-slate-500 dark:text-slate-400">{s.hint}</span>
                        </span>
                      </button>
                    );
                  })}
                </div>
                {!canPublish && <p className="text-xs text-slate-500">Your role can save drafts and edit content. A content manager publishes or unpublishes posts.</p>}
                {errors.status && <Alert variant="error">{errors.status}</Alert>}
                <Field
                  label={values.status === 'scheduled' ? 'Go live on' : 'Publish date'}
                  htmlFor="post-date"
                  required={values.status === 'scheduled'}
                  error={errors.published_at}
                  hint={values.status === 'published' && !values.published_at ? 'Leave empty to use the current time.' : values.status === 'scheduled' ? 'Must be in the future.' : undefined}
                >
                  <Input id="post-date" type="datetime-local" value={values.published_at} onChange={(e) => set('published_at', e.target.value)} invalid={!!errors.published_at} disabled={!canSave} />
                </Field>
                <div className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/60">
                  <Toggle id="post-featured" checked={values.is_featured} onChange={(v) => set('is_featured', v)} label="Featured post" description="Highlighted on the home page" disabled={!canSave} />
                  <Star className={clsx('h-4 w-4', values.is_featured ? 'fill-amber-400 text-amber-400' : 'text-slate-300')} aria-hidden />
                </div>
                {canSave && (
                  <div className="flex flex-col gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <Button icon={PrimaryIcon} loading={saving === values.status} disabled={!!saving} onClick={() => save()} className="w-full justify-center">
                      {primaryLabel}
                    </Button>
                    {values.status !== 'draft' && (canPublish || !live) && (
                      <Button variant="secondary" icon={Save} loading={saving === 'draft'} disabled={!!saving} onClick={() => save('draft')} className="w-full justify-center">
                        {live ? 'Unpublish & save as draft' : 'Save as draft'}
                      </Button>
                    )}
                  </div>
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader title="Featured image" icon={ImageIcon} subtitle="Shown on cards, the post header and social shares." />
              <CardBody>
                <MediaField value={values.thumbnail || null} onChange={(p) => set('thumbnail', p ?? '')} folder="blog" invalid={!!errors.thumbnail} disabled={!canSave} layout="stack" />
                {errors.thumbnail && <p className="form-error">{errors.thumbnail}</p>}
              </CardBody>
            </Card>

            <Card>
              <CardHeader title="Organise" icon={Tags} />
              <CardBody className="space-y-4">
                <Field label="Type" htmlFor="post-type" required error={errors.type}>
                  <Select id="post-type" value={values.type} onChange={(e) => set('type', e.target.value)} options={types} invalid={!!errors.type} disabled={!canSave} />
                </Field>
                <Field label="Category" htmlFor="post-cat" error={errors.category_id} hint={categories.length ? undefined : 'Create categories in the Categories tab.'}>
                  <Select id="post-cat" value={String(values.category_id ?? '')} onChange={(e) => set('category_id', e.target.value)} options={categories} placeholder="— Uncategorised —" invalid={!!errors.category_id} disabled={!canSave} />
                </Field>
                <Field label="Author" htmlFor="post-author" error={errors.author_id}>
                  <Combobox id="post-author" value={values.author_id ?? null} onChange={(v) => set('author_id', v)} options={authors} placeholder="Search users…" invalid={!!errors.author_id} disabled={!canSave} />
                </Field>
                <Field label="Tags" htmlFor="post-tags" error={errors.tags} hint="Press Enter or comma to add a tag.">
                  <TagInput id="post-tags" value={values.tags} onChange={(v) => set('tags', v)} invalid={!!errors.tags} disabled={!canSave} />
                </Field>
              </CardBody>
            </Card>

            {id && row && (
              <Card>
                <CardHeader title="Details" icon={Info} />
                <CardBody>
                  <DescriptionList
                    columns={2}
                    items={[
                      { label: 'Views', value: formatNumber(row.views) },
                      { label: 'Read time', value: minutes ? `${minutes} min` : '—' },
                      { label: 'Created', value: formatDateTime(row.created_at) },
                      { label: 'Updated', value: row.updated_at ? formatDateTime(row.updated_at) : '—' },
                    ]}
                  />
                  <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    {can('blog', 'create') && (
                      <Button size="sm" variant="secondary" icon={Copy} onClick={duplicate}>
                        Duplicate
                      </Button>
                    )}
                    {can('blog', 'delete') && (
                      <Button size="sm" variant="ghost" icon={Trash2} className="!text-red-600" onClick={remove}>
                        Delete
                      </Button>
                    )}
                  </div>
                </CardBody>
              </Card>
            )}
          </div>
        </div>
      )}
      {saving && <span className="sr-only" aria-live="polite"><Loader2 /> Saving…</span>}
    </>
  );
}
