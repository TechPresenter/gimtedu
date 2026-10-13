import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  Award, CopyPlus, Eye, FileStack, LayoutGrid, LayoutTemplate, MoreHorizontal, Pencil, Plus, Power, Rows3, Star, Trash2, X,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, Dropdown, EmptyState, PageHeader, Reveal, SearchInput, Select, Skeleton, Stagger, StatCard, StatusBadge, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useCrudList, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { printUrl } from '@/lib/config';
import { formatDate, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { TypeIcon, useCertConfig } from './certificates/shared';
import { TemplateThumb } from './certificates/TemplateThumb';
import type { TemplateRow } from './certificates/types';

export default function CertificateTemplatesPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const [params, setParams] = useSearchParams();
  const view = params.get('view') === 'table' ? 'table' : 'gallery';
  const [type, setType] = useState(params.get('type') ?? '');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const config = useCertConfig();
  const all = useCrudList<TemplateRow>('certificate_templates', { per_page: 100 });
  const list = useCrudList<TemplateRow>('certificate_templates', { per_page: 100, q: dq || undefined, f: { type: type || undefined, status: status || undefined } });
  const rows = list.data?.rows ?? [];
  const allRows = all.data?.rows ?? [];
  const types = (config.data?.types ?? []).filter((t) => t.key !== 'custom' || allRows.some((r) => r.type === 'custom'));
  const covered = new Set(allRows.filter((r) => r.status === 'active').map((r) => r.type));
  const missingTypes = (config.data?.types ?? []).filter((t) => t.key !== 'custom' && !covered.has(t.key));

  const refresh = () => invalidate('crud', 'cert-config');
  const preview = (r: { id: number }) => window.open(printUrl('certificate.php', { template_id: r.id }), '_blank', 'noopener');
  const act = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const duplicate = (r: { id: number }) =>
    act(async () => {
      const res = await api.post<{ id: number }>(`certificates/templates/${r.id}/duplicate`);
      navigate(`/certificates/templates/${res.data.id}`);
      return res;
    });
  const makeDefault = (r: { id: number }) => act(() => api.post(`certificates/templates/${r.id}/default`));
  const toggleStatus = (r: TemplateRow | Row) =>
    act(() => api.post(`crud/certificate_templates/${r.id}`, { status: r.status === 'active' ? 'inactive' : 'active', ...(r.status === 'active' ? { is_default: false } : {}) }));
  const remove = async (r: TemplateRow | Row) => {
    const ok = await confirm({ title: 'Delete template?', message: <>Delete <strong>{String(r.name)}</strong>? Templates already used for certificates cannot be deleted — mark them inactive instead.</>, confirmText: 'Delete', danger: true });
    if (ok) await act(() => api.del(`crud/certificate_templates/${r.id}`));
  };
  const setView = (v: string) => {
    const next = new URLSearchParams(params);
    if (v === 'table') next.set('view', 'table');
    else next.delete('view');
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="Certificate Templates"
        description="Wording and design of each certificate — placeholders are filled from the student's record on issue."
        breadcrumbs={[{ label: 'Certificates', to: '/certificates' }, { label: 'Templates' }]}
        actions={
          <>
            <Button variant="secondary" icon={Award} to="/certificates">Issued certificates</Button>
            {can('certificates', 'create') && <Button icon={Plus} to="/certificates/templates/new">New template</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="t" label="Templates" icon={LayoutTemplate} tone="navy" loading={all.isLoading} value={allRows.length} hint={`${allRows.filter((r) => r.status === 'active').length} active`} />,
          <StatCard key="c" label="Types covered" icon={FileStack} tone="green" loading={all.isLoading || config.isLoading} value={`${(config.data?.types ?? []).filter((t) => t.key !== 'custom' && covered.has(t.key)).length} / 7`} hint={missingTypes.length ? `Missing: ${missingTypes.map((t) => t.short).join(', ')}` : 'Every type is covered'} />,
          <StatCard key="u" label="Certificates generated" icon={Award} tone="blue" loading={all.isLoading} value={allRows.reduce((s, r) => s + (r.issued_count ?? 0), 0)} hint="From all templates" />,
          <StatCard key="l" label="Landscape designs" icon={LayoutGrid} tone="purple" loading={all.isLoading} value={allRows.filter((r) => r.orientation === 'landscape').length} hint="Award-style layouts" />,
        ]}
      </Stagger>

      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <Tabs variant="pills" value={view} onChange={setView} tabs={[{ key: 'gallery', label: 'Gallery', icon: LayoutGrid }, { key: 'table', label: 'Table', icon: Rows3 }]} />
        {view === 'gallery' && (
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search templates…" className="sm:w-64" />
            <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} options={{ active: 'Active', inactive: 'Inactive' }} placeholder="Status: All" aria-label="Status" className="sm:!w-40" />
          </div>
        )}
      </div>

      {view === 'table' ? (
        <CrudTable
          module="certificate_templates"
          urlState
          addLabel="New template"
          onCreate={() => navigate('/certificates/templates/new')}
          onEdit={(r) => navigate(`/certificates/templates/${r.id}`)}
          onView={(r) => navigate(`/certificates/templates/${r.id}`)}
          emptyTitle="No templates yet"
          emptyText="Create a template for each certificate type — bonafide, transfer, character, course completion, internship, migration and provisional."
          renderers={{
            name: (r) => (
              <div className="min-w-[13rem] max-w-[18rem] leading-tight">
                <button type="button" onClick={() => navigate(`/certificates/templates/${r.id}`)} className="text-left font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{String(r.name)}</button>
                {r.description ? <p className="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{String(r.description)}</p> : null}
              </div>
            ),
            type: (r) => (
              <span className="inline-flex min-w-[7.5rem] max-w-[9.5rem] items-center gap-2 leading-tight" title={`${String(r.type_label)} · ${String(r.orientation)}`}>
                <TypeIcon type={String(r.type)} size="sm" />
                <span>{config.data?.types.find((t) => t.key === r.type)?.short ?? String(r.type_label)}<span className="block text-xs capitalize text-slate-500">{String(r.orientation)}</span></span>
              </span>
            ),
            signatory_name: (r) => (r.signatory_name
              ? <div className="whitespace-nowrap leading-tight"><p>{String(r.signatory_name)}</p><p className="text-xs text-slate-500">{String(r.signatory_designation ?? '')}</p></div>
              : <span className="text-slate-400">—</span>),
            orientation: (r) => <span className="capitalize">{String(r.orientation)}</span>,
            is_default: (r) => (r.is_default ? <Star className="mx-auto h-4 w-4 fill-amber-400 text-amber-500" aria-label="Default" /> : <span className="text-slate-300">—</span>),
          }}
          rowMenu={(r) => [
            { label: 'Preview specimen', icon: Eye, onClick: () => preview({ id: Number(r.id) }) },
            can('certificates', 'create') && { label: 'Duplicate', icon: CopyPlus, onClick: () => void duplicate({ id: Number(r.id) }) },
            can('certificates', 'edit') && !r.is_default && r.status === 'active' && { label: 'Make default', icon: Star, onClick: () => void makeDefault({ id: Number(r.id) }) },
            can('certificates', 'edit') && { label: r.status === 'active' ? 'Deactivate' : 'Activate', icon: Power, onClick: () => void toggleStatus(r) },
            can('certificates', 'create') && r.status === 'active' && { label: 'Issue with this template', icon: Award, to: `/certificates?type=${String(r.type)}&template_id=${String(r.id)}` },
          ]}
        />
      ) : (
        <>
          <div className="mb-5 flex gap-2 overflow-x-auto pb-1 scrollbar-none">
            <button type="button" onClick={() => setType('')} className={clsx('pill-tab whitespace-nowrap rounded-full border px-3 py-1.5 text-sm', !type ? 'border-brand-800 bg-brand-800 text-white' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300')}>
              All types
            </button>
            {types.map((t) => (
              <button key={t.key} type="button" onClick={() => setType(t.key === type ? '' : t.key)}
                className={clsx('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm transition', t.key === type ? 'border-brand-800 bg-brand-800 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300')}>
                {t.short}
                <span className={clsx('rounded-full px-1.5 text-[10px] font-semibold', t.key === type ? 'bg-white/20' : 'bg-slate-100 dark:bg-slate-800')}>{allRows.filter((r) => r.type === t.key).length}</span>
              </button>
            ))}
          </div>

          {missingTypes.length > 0 && !all.isLoading && (
            <Alert variant="warning" className="mb-5" title="Some certificate types have no active template">
              {missingTypes.map((t) => t.label).join(', ')} cannot be issued until a template exists.{' '}
              {can('certificates', 'create') && <button type="button" className="font-semibold underline" onClick={() => navigate(`/certificates/templates/new?type=${missingTypes[0].key}`)}>Create one now</button>}
            </Alert>
          )}

          {list.error ? (
            <Alert variant="error" title="Unable to load templates">{(list.error as ApiError).message}</Alert>
          ) : list.isLoading ? (
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
              {Array.from({ length: 6 }).map((_, i) => <Card key={i} className="p-4"><Skeleton className="h-56 w-full" /><Skeleton className="mt-4 h-5 w-2/3" /><Skeleton className="mt-2 h-4 w-1/2" /></Card>)}
            </div>
          ) : rows.length === 0 ? (
            <Card>
              <EmptyState
                icon={dq || type || status ? X : LayoutTemplate}
                title={dq || type || status ? 'No templates match' : 'No templates yet'}
                description={dq || type || status ? 'Try another search or clear the filters.' : 'Start with a template for the most requested certificate — the bonafide certificate.'}
                action={dq || type || status
                  ? <Button variant="secondary" icon={X} onClick={() => { setQ(''); setType(''); setStatus(''); }}>Clear filters</Button>
                  : can('certificates', 'create') ? <Button icon={Plus} to="/certificates/templates/new">New template</Button> : undefined}
              />
            </Card>
          ) : (
            <Stagger className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" step={60}>
              {rows.map((r) => (
                <Card key={r.id} className={clsx('group flex h-full flex-col overflow-hidden transition duration-200 hover:-translate-y-0.5 hover:shadow-soft', r.status !== 'active' && 'opacity-75')}>
                  <button type="button" onClick={() => navigate(`/certificates/templates/${r.id}`)} className="relative flex h-56 items-center justify-center bg-gradient-to-br from-slate-100 to-slate-50 p-5 dark:from-slate-800/70 dark:to-slate-900" aria-label={`Edit ${r.name}`}>
                    <TemplateThumb title={r.title} orientation={r.orientation} borderStyle={r.border_style} accent={r.accent_color} showSeal={r.show_seal} showQr={r.show_qr}
                      className={clsx('max-w-full transition duration-300 group-hover:scale-[1.03]', r.orientation === 'landscape' ? '!w-auto h-[72%]' : '!w-auto h-[90%]')} />
                    {r.is_default && (
                      <span className="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/30">
                        <Star className="h-3 w-3 fill-current" /> Default
                      </span>
                    )}
                  </button>
                  <div className="flex flex-1 flex-col p-4">
                    <div className="flex items-start gap-3">
                      <TypeIcon type={r.type} />
                      <div className="min-w-0 flex-1">
                        <p className="line-clamp-2 font-semibold leading-snug text-slate-900 dark:text-white" title={r.name}>{r.name}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{r.type_label} · <span className="capitalize">{r.orientation}</span> · {config.data?.border_styles[r.border_style]?.split(' (')[0] ?? r.border_style}</p>
                      </div>
                      <StatusBadge status={r.status} />
                    </div>
                    {r.description && <p className="mt-3 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{r.description}</p>}
                    <div className="mt-3 flex flex-wrap gap-1.5 text-xs">
                      <Badge color="slate">{formatNumber(r.issued_count)} issued</Badge>
                      {r.pending_count > 0 && <Badge color="amber">{r.pending_count} pending</Badge>}
                      <Badge color="slate">{r.validity_days ? `Valid ${r.validity_days} days` : 'No expiry'}</Badge>
                      {r.last_used && <Badge color="slate">Used {formatDate(r.last_used)}</Badge>}
                    </div>
                    <p className="mb-3 mt-3 truncate text-xs text-slate-500 dark:text-slate-400">
                      Signed by {[r.signatory_name && `${r.signatory_name} (${r.signatory_designation ?? 'Signatory'})`, r.signatory2_name && `${r.signatory2_name} (${r.signatory2_designation ?? ''})`].filter(Boolean).join(' & ') || 'Authorised signatory'}
                    </p>
                    <div className="mt-auto flex items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                      {can('certificates', 'edit') ? (
                        <Button size="sm" variant="secondary" icon={Pencil} to={`/certificates/templates/${r.id}`}>Edit</Button>
                      ) : (
                        <Button size="sm" variant="secondary" icon={Eye} to={`/certificates/templates/${r.id}`}>Open</Button>
                      )}
                      <Button size="sm" variant="ghost" icon={Eye} onClick={() => preview(r)}>Specimen</Button>
                      <div className="ml-auto">
                        <Dropdown
                          label={`More actions for ${r.name}`}
                          triggerClassName="btn-icon !h-8 !w-8 !rounded-lg"
                          trigger={<MoreHorizontal className="h-4 w-4" />}
                          items={[
                            can('certificates', 'create') && { label: 'Duplicate', icon: CopyPlus, onClick: () => void duplicate(r) },
                            can('certificates', 'edit') && !r.is_default && r.status === 'active' && { label: 'Make default for type', icon: Star, onClick: () => void makeDefault(r) },
                            can('certificates', 'edit') && { label: r.status === 'active' ? 'Deactivate' : 'Activate', icon: Power, onClick: () => void toggleStatus(r) },
                            can('certificates', 'create') && r.status === 'active' && { label: 'Issue with this template', icon: Award, to: `/certificates?type=${r.type}&template_id=${r.id}` },
                            can('certificates', 'delete') && { divider: true, label: 'd' },
                            can('certificates', 'delete') && { label: 'Delete', icon: Trash2, danger: true, disabled: r.issued_count > 0, hint: r.issued_count > 0 ? 'In use' : undefined, onClick: () => void remove(r) },
                          ]}
                        />
                      </div>
                    </div>
                  </div>
                </Card>
              ))}
            </Stagger>
          )}
          <Reveal className="mt-8">
            <Card className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center">
              <span className="kpi-icon shrink-0 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><LayoutTemplate className="h-5 w-5" /></span>
              <div className="min-w-0 flex-1 text-sm text-slate-600 dark:text-slate-300">
                <p className="font-semibold text-slate-900 dark:text-white">How templates work</p>
                Each type uses its <strong>default</strong> template unless another is chosen while issuing. Issued certificates keep a snapshot of the wording and design,
                so editing a template never changes certificates that were already printed.
              </div>
            </Card>
          </Reveal>
        </>
      )}
    </>
  );
}
