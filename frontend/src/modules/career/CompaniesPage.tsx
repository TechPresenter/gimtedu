import { useSearchParams } from 'react-router-dom';
import { Building2, CalendarPlus, ExternalLink, Handshake, History, Sparkles, TrendingUp } from 'lucide-react';
import { PageHeader, Stagger, StatTile, StatusBadge } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import type { Row } from '@/lib/types';
import { CompanyDrawer } from './placement/CompanyDrawer';
import { COMPANY_TYPES, CompanyLogo, TIE_UP, TIE_UP_COLORS, lpa, usePlacementRefresh } from './placement/shared';

interface CompanyStats {
  session: string;
  active: number;
  mou: number;
  prospects: number;
  on_website: number;
  visited: number;
  hiring: number;
}

export default function CompaniesPage() {
  const { can } = useAuth();
  const refresh = usePlacementRefresh();
  const [params, setParams] = useSearchParams();
  const openId = Number(params.get('company') ?? 0) || null;
  const { data: st, isLoading } = useApi<CompanyStats>(['placement', 'stats', 'companies'], 'placement/stats/companies');

  const open = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('company', String(id));
    else next.delete('company');
    setParams(next, { replace: !id });
  };

  return (
    <>
      <PageHeader
        title="Companies"
        description="Recruiters, HR contacts, tie-up status and hiring history."
        breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Companies' }]}
      />
      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="a" label="Active companies" value={st?.active ?? 0} icon={Building2} tone="navy" loading={isLoading} sub={st ? `${st.on_website} shown on the website` : undefined} />,
          <StatTile key="m" label="MoU partners" value={st?.mou ?? 0} icon={Handshake} tone="purple" loading={isLoading} sub={st ? `${st.prospects} prospects in pipeline` : undefined} />,
          <StatTile key="v" label={`Visited in ${st?.session ?? 'this session'}`} value={st?.visited ?? 0} icon={CalendarPlus} tone="blue" loading={isLoading} sub="Companies with drives" />,
          <StatTile key="h" label="Hiring this session" value={st?.hiring ?? 0} icon={TrendingUp} tone="green" loading={isLoading} sub="Companies that made offers" />,
        ]}
      </Stagger>

      <CrudTable
        module="companies"
        urlState
        title="Recruiters"
        addLabel="Add company"
        emptyTitle="No companies yet"
        emptyText="Add recruiters with their HR contacts to start scheduling placement drives."
        onView={(r: Row) => open(Number(r.id))}
        rowMenu={(r: Row) => [
          { label: 'Hiring history', icon: History, onClick: () => open(Number(r.id)) },
          can('placement', 'create') && { label: 'Schedule a drive', icon: CalendarPlus, to: `/placement/drives?add=1&company=${r.id}` },
          r.website && { label: 'Visit website', icon: ExternalLink, href: String(r.website), target: '_blank' },
          Number(r.offers_count) > 0 && { label: 'View hires', icon: Sparkles, to: `/placement/offers?f.company_id=${r.id}` },
        ]}
        renderers={{
          name: (r: Row) => (
            <button type="button" onClick={() => open(Number(r.id))} className="group flex w-[17rem] max-w-[17rem] items-center gap-3 text-left">
              <CompanyLogo name={String(r.name)} src={r.logo as string | null} size="sm" wide />
              <span className="min-w-0 leading-tight">
                <span className="block truncate font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300" title={String(r.name)}>{String(r.name)}</span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                  {[r.industry, r.company_type ? COMPANY_TYPES[String(r.company_type)] ?? String(r.company_type) : null, r.city].filter(Boolean).join(' · ') || '—'}
                </span>
              </span>
            </button>
          ),
          company_type: (r: Row) => (r.company_type ? <span className="whitespace-nowrap text-sm text-slate-700 dark:text-slate-200">{COMPANY_TYPES[String(r.company_type)] ?? String(r.company_type)}</span> : <span className="text-slate-400">—</span>),
          tie_up_status: (r: Row) => (
            <div className="flex flex-col items-start gap-1 whitespace-nowrap">
              <StatusBadge status={String(r.tie_up_status)} label={TIE_UP[String(r.tie_up_status)]} colors={TIE_UP_COLORS} />
              {r.status !== 'active' && <StatusBadge status={String(r.status)} dot={false} />}
            </div>
          ),
          offers_count: (r: Row) => (
            <div className="text-center leading-tight">
              <div className="font-medium tabular-nums">{Number(r.offers_count)}</div>
              {r.highest_lpa !== null && r.highest_lpa !== undefined && <div className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">up to {lpa(r.highest_lpa)}</div>}
            </div>
          ),
          contact_person: (r: Row) =>
            r.contact_person ? (
              <div className="max-w-[13rem] leading-tight">
                <div className="truncate font-medium text-slate-800 dark:text-slate-100">{String(r.contact_person)}</div>
                <div className="truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.contact_designation ?? '')}>{String(r.contact_designation ?? '')}</div>
              </div>
            ) : (
              <span className="text-slate-400">—</span>
            ),
        }}
        onSaved={() => void refresh('companies')}
      />

      <CompanyDrawer id={openId} onClose={() => open(null)} />
    </>
  );
}
