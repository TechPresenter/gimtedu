import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Copy, ExternalLink, FileText, Globe, LayoutTemplate, Lock, PenLine, Plus, Rocket, SearchCheck, Undo2 } from 'lucide-react';
import { Alert, Button, Modal, PageHeader, PageLoader, Stagger, StatCard, useConfirm, useToast } from '@/components/ui';
import { CrudTable, useCrudForm, type CrudField } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { useAuth } from '@/lib/auth';
import { useApi, useCrudMeta } from '@/lib/queries';
import type { Row } from '@/lib/types';
import { PickerFormFields } from './site/PickerFormFields';
import { SeoEditor, type SeoTarget } from './site/SeoEditor';

interface Overview {
  pages: { total: number; published: number; draft: number; system_pages: number; with_blocks: number; scheduled: number };
}

/** Pages list + create/edit form (system pages keep their URL) + per-page SEO + builder/preview shortcuts. */
export default function PagesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const { data: overview, isLoading } = useApi<Overview>(['cms', 'overview'], 'cms/overview');
  const [form, setForm] = useState<{ id: number | null; system: boolean } | null>(null);
  const [seo, setSeo] = useState<SeoTarget | null>(null);
  const p = overview?.pages;

  // deep links: /cms/pages?edit=5 (from the builder) and ?add=1 (quick add)
  useEffect(() => {
    const edit = Number(params.get('edit'));
    if (edit > 0) {
      setForm({ id: edit, system: false });
      const next = new URLSearchParams(params);
      next.delete('edit');
      setParams(next, { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['crud', 'pages'] });
    void qc.invalidateQueries({ queryKey: ['cms', 'overview'] });
  };
  const action = async (row: Row, act: 'publish' | 'unpublish' | 'duplicate') => {
    if (act === 'unpublish') {
      const ok = await confirm({ title: 'Unpublish this page?', message: <>“{row.title}” will no longer be visible on the website until it is published again.</>, confirmText: 'Unpublish' });
      if (!ok) return;
    }
    try {
      const res = await api.post<{ id?: number }>(`cms/pages/${row.id}/${act}`);
      toast.success(res.message);
      refresh();
      if (act === 'duplicate' && res.data?.id) navigate(`/cms/pages/${res.data.id}/builder`);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulk = async (ids: number[], clear: () => void, act: 'publish' | 'unpublish') => {
    try {
      const res = await api.post<{ message: string }>('crud/pages/bulk', { action: act, ids });
      toast.success(res.message || res.data?.message || 'Done.');
      clear();
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Pages"
        description="Website pages, their content and page-builder layouts. System pages keep their URL and cannot be deleted."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Pages' }]}
        actions={
          <>
            <Button variant="secondary" icon={Globe} href={appUrl('/')} target="_blank">View website</Button>
            {can('cms', 'create') && <Button icon={Plus} onClick={() => setForm({ id: null, system: false })}>New page</Button>}
          </>
        }
      />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="t" label="Total pages" value={p?.total ?? 0} icon={FileText} tone="navy" loading={isLoading} hint={p ? `${p.system_pages} system pages` : undefined} />,
          <StatCard key="p" label="Published" value={p?.published ?? 0} icon={Rocket} tone="green" loading={isLoading} hint={p?.scheduled ? `${p.scheduled} scheduled` : 'Live on the website'} />,
          <StatCard key="d" label="Drafts" value={p?.draft ?? 0} icon={PenLine} tone="amber" loading={isLoading} hint="Not visible to visitors" />,
          <StatCard key="b" label="Using the builder" value={p?.with_blocks ?? 0} icon={LayoutTemplate} tone="orange" loading={isLoading} hint="Pages with builder blocks" />,
        ]}
      </Stagger>

      <CrudTable
        module="pages"
        urlState
        addLabel="New page"
        onCreate={() => setForm({ id: null, system: false })}
        onEdit={(row) => setForm({ id: Number(row.id), system: Number(row.is_system) === 1 })}
        emptyTitle="No pages yet"
        emptyText="Create your first page, then open the builder to add sections."
        renderers={{
          title: (row) => (
            <div className="min-w-0 leading-tight">
              <button type="button" onClick={() => navigate(`/cms/pages/${row.id}/builder`)} className="inline-flex items-center gap-1.5 text-left font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                {Number(row.is_system) === 1 && <Lock className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-label="System page" />}
                {String(row.title)}
              </button>
              <a href={appUrl(String(row.url))} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-xs text-slate-500 hover:text-brand-700 dark:text-slate-400">
                {String(row.url)}<ExternalLink className="h-3 w-3" />
              </a>
            </div>
          ),
          blocks_count: (row) => (
            <span className={clsx('inline-flex min-w-[2rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold', Number(row.blocks_count) ? 'bg-orange-50 text-[#C2410C] dark:bg-orange-500/15 dark:text-orange-300' : 'text-slate-400')}>
              {Number(row.blocks_count) || '—'}
            </span>
          ),
        }}
        rowMenu={(row) => [
          { label: 'Open builder', icon: LayoutTemplate, to: `/cms/pages/${row.id}/builder` },
          { label: 'Preview on website', icon: ExternalLink, href: appUrl(String(row.url)), target: '_blank' },
          can('seo', 'view') && { label: 'SEO settings', icon: SearchCheck, onClick: () => setSeo({ type: row.seo_ref?.type ?? 'page', id: row.seo_ref?.id ?? Number(row.id), route: row.seo_ref?.route ?? null, title: String(row.title) }) },
          can('cms', 'create') && { label: 'Duplicate as draft', icon: Copy, onClick: () => action(row, 'duplicate') },
          can('cms', 'publish') && (row.status === 'published'
            ? { label: 'Unpublish', icon: Undo2, onClick: () => action(row, 'unpublish') }
            : { label: 'Publish now', icon: Rocket, onClick: () => action(row, 'publish') }),
        ]}
        bulkActions={can('cms', 'publish') ? [
          { label: 'Publish', icon: Rocket, onClick: (ids, clear) => bulk(ids, clear, 'publish') },
          { label: 'Move to draft', icon: Undo2, onClick: (ids, clear) => bulk(ids, clear, 'unpublish') },
        ] : []}
      />

      <PageFormModal state={form} onClose={() => setForm(null)} onSaved={(id, created) => {
        refresh();
        if (created) {
          toast.info('Add sections to your new page with the builder.');
          navigate(`/cms/pages/${id}/builder`);
        }
      }} />
      <SeoEditor target={seo} onClose={() => setSeo(null)} />
    </>
  );
}

/** Create / edit page details. The slug of a system page is locked. */
function PageFormModal({ state, onClose, onSaved }: { state: { id: number | null; system: boolean } | null; onClose: () => void; onSaved: (id: number, created: boolean) => void }) {
  const { data: meta } = useCrudMeta('pages');
  const open = !!state;
  const id = state?.id ?? null;
  const defaults = useMemo(() => ({}), []);
  const form = useCrudForm({ meta: open ? meta : undefined, module: 'pages', id, defaults, onSaved: (_row, newId) => { onSaved(newId, !id); onClose(); } });
  const isSystem = !!(id && (state?.system || Number((form.values as Row).is_system) === 1));
  const fields = useMemo<CrudField[]>(
    () => form.visibleFields.map((f) => (f.name === 'slug' && isSystem ? { ...f, readonly_on_edit: true, help: 'System page — the URL is fixed.' } : f)),
    [form.visibleFields, isSystem],
  );
  const formId = 'page-form';
  return (
    <Modal open={open} onClose={onClose} static={form.saving} size="xl" title={id ? 'Edit page details' : 'New page'}
      description={id ? 'Title, URL, hero text, banner and the rich-text content of the page.' : 'Create the page, then open the builder to add sections.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form={formId} loading={form.saving} disabled={form.loading}>{id ? 'Save changes' : 'Create page'}</Button>
        </>
      }>
      {form.loading || !meta ? (
        <PageLoader label="Loading page…" />
      ) : (
        <form id={formId} onSubmit={form.submit} noValidate className="space-y-4">
          {form.formError && <Alert variant="error">{form.formError}</Alert>}
          {isSystem && <Alert variant="info" title="System page">This page is part of the website structure. You can change its content but not its URL, and it cannot be deleted.</Alert>}
          <PickerFormFields state={form} fields={fields} id={id} images={['banner_image']} />
        </form>
      )}
    </Modal>
  );
}
