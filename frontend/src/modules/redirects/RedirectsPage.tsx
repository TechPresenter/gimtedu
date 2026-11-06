import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowLeftRight, ArrowRight, Ban, ExternalLink, FlaskConical, Link2Off, MousePointerClick, Plus, Power, PowerOff, RotateCcw, SearchX, TriangleAlert,
} from 'lucide-react';
import { Alert, Badge, Button, PageHeader, Stagger, StatCard, Tabs, Toggle, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { useApi } from '@/lib/queries';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { NotFoundReport } from './NotFoundReport';
import { RedirectFormModal } from './RedirectFormModal';
import { UrlTester } from './UrlTester';
import { codeColor, type RedirectDraft, type RedirectSummary } from './types';

const TABS = ['rules', '404', 'test'] as const;
type Tab = (typeof TABS)[number];

/**
 * Website CMS → Redirects: 301/302/307/308 and 410 rules (CRUD with live validation, CSV import/export, bulk
 * activate/deactivate), the 404 report with suggested targets, and the URL tester. Rules are applied by the website
 * (app/services/seo.php) before a 404 is answered and on live pages.
 */
export default function RedirectsPage() {
  const { can } = useAuth();
  const canEdit = can('seo', 'edit');
  const toast = useToast();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'rules';
  const testUrl = params.get('url') ?? '';
  const summary = useApi<RedirectSummary>(['redirects', 'summary'], 'redirects/summary');
  const s = summary.data;
  const [form, setForm] = useState<{ open: boolean; id: number | null; initial?: Partial<RedirectDraft> }>({ open: false, id: null });

  const goTab = (t: string, extra: Record<string, string> = {}) => setParams(t === 'rules' ? extra : { tab: t, ...extra }, { replace: false });
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['crud', 'redirects'] });
    void qc.invalidateQueries({ queryKey: ['redirects'] });
  };
  const test = (url: string) => goTab('test', { url });

  const patch = async (r: Row, data: Record<string, unknown>, msg: string) => {
    try {
      await api.post(`crud/redirects/${r.id}`, data);
      toast.success(msg);
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulk = async (ids: number[], action: 'activate' | 'deactivate' | 'reset_hits', clear: () => void) => {
    try {
      const res = await api.post('redirects/bulk', { ids, action });
      toast.success(res.message);
      clear();
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const warnings = useMemo(() => {
    if (!s) return [];
    const out: { key: string; text: string; items: string[] }[] = [];
    if (s.warnings.missing_count) {
      out.push({ key: 'missing', text: `${s.warnings.missing_count} active redirect${s.warnings.missing_count === 1 ? ' points' : 's point'} to a page that does not exist on the website.`,
        items: s.warnings.missing_targets.slice(0, 4).map((m) => `${m.from} → ${m.to}`) });
    }
    if (s.warnings.chains.length) {
      out.push({ key: 'chains', text: `${s.warnings.chains.length} redirect chain${s.warnings.chains.length === 1 ? '' : 's'}: the target is redirected again (slower and weaker for SEO). Edit and save the rule to point it straight at the final URL.`,
        items: s.warnings.chains.slice(0, 4).map((c) => `${c.from} → ${c.to} → ${c.final ?? '410'}`) });
    }
    return out;
  }, [s]);

  return (
    <>
      <PageHeader
        title="Redirects"
        description="Send visitors and search engines from old or moved URLs to the right page."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'SEO Management', to: '/cms/seo' }, { label: 'Redirects' }]}
        actions={
          <>
            <Button variant="secondary" icon={FlaskConical} onClick={() => goTab('test')}>Test a URL</Button>
            {canEdit && <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>Add redirect</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="a" label="Active redirects" value={s?.rules.active ?? 0} icon={ArrowLeftRight} tone="navy" loading={summary.isLoading}
            hint={s ? `${formatNumber(s.rules.permanent)} permanent · ${formatNumber(s.rules.temporary)} temporary` : undefined} />,
          <StatCard key="h" label="Redirect hits" value={s?.rules.hits ?? 0} icon={MousePointerClick} tone="blue" loading={summary.isLoading}
            hint={s ? (s.rules.last_hit ? `${s.rules.used_30d} used in 30 days · last ${timeAgo(s.rules.last_hit)}` : 'No hits yet') : undefined} />,
          <StatCard key="n" label="404 URLs recorded" value={s?.not_found.paths ?? 0} icon={SearchX} tone={s && s.not_found.recent ? 'orange' : 'green'} loading={summary.isLoading}
            to="/redirects?tab=404" hint={s ? `${formatNumber(s.not_found.recent)} seen this week` : undefined} />,
          <StatCard key="g" label="Gone (410)" value={s?.rules.gone ?? 0} icon={Ban} tone="red" loading={summary.isLoading} hint="Removed pages" />,
        ]}
      </Stagger>

      {summary.error && <Alert variant="error" className="mb-4">{(summary.error as ApiError).message}</Alert>}
      {warnings.map((w) => (
        <Alert key={w.key} variant="warning" className="mb-4" title={w.text}>
          {w.items.length > 0 && <ul className="mt-1 space-y-0.5 font-mono text-xs">{w.items.map((i) => <li key={i} className="break-all">{i}</li>)}</ul>}
        </Alert>
      ))}

      <Tabs className="mb-5" value={tab} onChange={(k) => goTab(k)} tabs={[
        { key: 'rules', label: 'Redirect rules', icon: ArrowLeftRight, count: s?.rules.total },
        { key: '404', label: '404 report', icon: SearchX, count: s?.not_found.paths },
        { key: 'test', label: 'Test a URL', icon: FlaskConical },
      ]} />

      {tab === 'rules' && (
        <CrudTable
          module="redirects"
          urlState
          title={false}
          addLabel="Add redirect"
          onCreate={() => setForm({ open: true, id: null })}
          onEdit={(r) => setForm({ open: true, id: Number(r.id) })}
          onView={canEdit ? (r) => setForm({ open: true, id: Number(r.id) }) : undefined}
          emptyTitle="No redirects yet"
          emptyText="Add a redirect when a page moves, or create one straight from the 404 report."
          renderers={{
            from_path: (r) => (
              <div className="max-w-[300px]">
                <a href={appUrl(String(r.from_path))} target="_blank" rel="noreferrer" className="group flex items-center gap-1 font-mono text-xs font-semibold text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">
                  <span className="truncate" title={String(r.from_path)}>{String(r.from_path)}</span><ExternalLink className="h-3 w-3 shrink-0 opacity-0 transition group-hover:opacity-100" aria-hidden />
                </a>
                {(r.warnings as string[] | undefined)?.includes('from_live') && <p className="mt-0.5 text-[11px] text-amber-700 dark:text-amber-300">Live page — redirected away</p>}
                {(r.warnings as string[] | undefined)?.includes('gone_but_live') && <p className="mt-0.5 text-[11px] text-amber-700 dark:text-amber-300">Page is live — 410 not applied</p>}
              </div>
            ),
            to_url: (r) => Number(r.status_code) === 410 ? <span className="text-xs font-semibold text-red-600 dark:text-red-400">Gone — no target</span> : (
              <div className="flex max-w-[320px] items-center gap-1.5">
                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />
                <span className={clsx('truncate font-mono text-xs', (r.warnings as string[] | undefined)?.includes('target_missing') ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200')} title={String(r.to_url)}>{String(r.to_url)}</span>
                {(r.warnings as string[] | undefined)?.includes('target_missing') && <span title="This page does not exist"><Link2Off className="h-3.5 w-3.5 shrink-0 text-red-500" aria-label="Target does not exist" /></span>}
              </div>
            ),
            status_code: (r) => <Badge color={codeColor(String(r.status_code))}>{String(r.status_code)}</Badge>,
            hits: (r) => <span className="font-semibold tabular-nums">{formatNumber(r.hits)}</span>,
            last_hit_at: (r) => r.last_hit_at ? <span className="whitespace-nowrap text-sm" title={formatDateTime(r.last_hit_at)}>{timeAgo(r.last_hit_at)}</span> : <span className="text-xs text-slate-400">Never</span>,
            is_active: (r) => canEdit ? (
              <Toggle checked={!!Number(r.is_active)} onChange={(v) => void patch(r, { is_active: v }, v ? 'Redirect activated.' : 'Redirect deactivated.')} label={<span className="sr-only">Active</span>} />
            ) : <Badge color={Number(r.is_active) ? 'green' : 'slate'}>{Number(r.is_active) ? 'Active' : 'Inactive'}</Badge>,
          }}
          rowMenu={(r) => [
            { label: 'Test this URL', icon: FlaskConical, onClick: () => test(String(r.from_path)) },
            { label: 'Open old URL', icon: ExternalLink, href: appUrl(String(r.from_path)), target: '_blank' },
            canEdit && Number(r.hits) > 0 && { label: 'Reset hit counter', icon: RotateCcw, onClick: () => void bulk([Number(r.id)], 'reset_hits', () => undefined) },
          ]}
          bulkActions={canEdit ? [
            { label: 'Activate', icon: Power, onClick: (ids, clear) => void bulk(ids, 'activate', clear) },
            { label: 'Deactivate', icon: PowerOff, onClick: (ids, clear) => void bulk(ids, 'deactivate', clear) },
            { label: 'Reset hits', icon: RotateCcw, onClick: (ids, clear) => void bulk(ids, 'reset_hits', clear) },
          ] : []}
          onSaved={() => refresh()}
        />
      )}
      {tab === '404' && <NotFoundReport canEdit={canEdit} onCreate={(initial) => setForm({ open: true, id: null, initial })} onTest={test} />}
      {tab === 'test' && <UrlTester initial={testUrl} autoRun={!!testUrl} />}

      {tab !== 'test' && (
        <p className="mt-4 flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
          <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          Rules match case-insensitively and ignore a trailing slash. Mixed-case and trailing-slash URLs are also fixed automatically with a 301. Loops are refused and chains are flattened when you save.
        </p>
      )}

      <RedirectFormModal open={form.open} id={form.id} initial={form.initial} onClose={() => setForm({ open: false, id: null })} onSaved={() => refresh()} />
    </>
  );
}
