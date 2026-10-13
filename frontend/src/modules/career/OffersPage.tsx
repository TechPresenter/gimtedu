import { useState } from 'react';
import { BadgeCheck, Download, ExternalLink, FileWarning, GraduationCap, Hourglass, Printer, Trophy, UserRound } from 'lucide-react';
import { Badge, Button, PageHeader, Stagger, StatTile, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { CrudTable, type CrudBulkAction } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import type { Row } from '@/lib/types';
import { OFFER_ACTIONS, OFFER_TRANSITIONS, OfferStatusModal, type OfferTarget } from './placement/offers';
import { CompanyLogo, JOB_TYPES, OFFER_STATUS, OFFER_STATUS_COLORS, OFFER_STATUS_SHORT, StudentCell, lpa, privateFileUrl, usePlacementRefresh } from './placement/shared';

interface OfferStats {
  session: string;
  offers: number;
  placed: number;
  confirmed: number;
  pending: number;
  declined: number;
  highest: number | null;
  average: number | null;
  letters_missing: number;
}

export default function OffersPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const [change, setChange] = useState<{ target: OfferTarget; status: string } | null>(null);
  const { data: st, isLoading } = useApi<OfferStats>(['placement', 'stats', 'offers'], 'placement/stats/offers');

  const target = (r: Row): OfferTarget => ({ id: Number(r.id), status: String(r.status), student_name: String(r.student_name), company_name: String(r.company_name), package_lpa: r.package_lpa });

  const bulk = (status: string) => async (ids: number[], clear: () => void) => {
    const def = OFFER_ACTIONS[status];
    const ok = await confirm({ title: `${def.label} — ${ids.length} offer${ids.length === 1 ? '' : 's'}?`, message: def.help, confirmText: def.label, danger: def.danger });
    if (!ok) return;
    try {
      const res = await api.post<{ updated: number; skipped: number }>('placement/offers/bulk-status', { ids, status });
      if (res.data.updated) toast.success(res.message);
      else toast.warning(res.message);
      clear();
      await refresh('placement_offers', 'placement_applications');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulkActions: CrudBulkAction[] = can('placement', 'edit')
    ? (['accepted', 'joined', 'declined', 'revoked'] as const).map((s) => ({ label: OFFER_ACTIONS[s].label, icon: OFFER_ACTIONS[s].icon, danger: OFFER_ACTIONS[s].danger, onClick: bulk(s) }))
    : [];

  return (
    <>
      <PageHeader
        title="Offers"
        description="Job offers, CTC, joining dates and offer letters — mark students placed as they accept."
        breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Offers' }]}
        actions={
          <Button variant="secondary" icon={Printer} onClick={() => window.open(printUrl('placement-report.php'), '_blank', 'noopener')}>
            Placement report
          </Button>
        }
      />
      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="p" label={`Placed students · ${st?.session ?? ''}`} value={st?.placed ?? 0} icon={GraduationCap} tone="green" loading={isLoading} sub={st ? `${st.confirmed} confirmed · ${st.offers} offers` : undefined} />,
          <StatTile key="w" label="Awaiting acceptance" value={st?.pending ?? 0} icon={Hourglass} tone="amber" loading={isLoading} sub={st ? `${st.declined} declined this session` : undefined} />,
          <StatTile key="h" label="Highest package" value={st ? lpa(st.highest) : '—'} icon={Trophy} tone="purple" loading={isLoading} sub={st ? `Average ${lpa(st.average)}` : undefined} />,
          <StatTile key="l" label="Offer letters missing" value={st?.letters_missing ?? 0} icon={FileWarning} tone="red" loading={isLoading} sub="Valid offers without a letter" />,
        ]}
      </Stagger>

      <CrudTable
        module="placement_offers"
        urlState
        title="Offers"
        addLabel="Record offer"
        emptyTitle="No offers recorded yet"
        emptyText="Record an offer when a company selects a student — it marks the student placed."
        bulkActions={bulkActions}
        rowMenu={(r: Row) => [
          ...(can('placement', 'edit')
            ? (OFFER_TRANSITIONS[String(r.status)] ?? []).map((s) => ({ label: OFFER_ACTIONS[s].label, icon: OFFER_ACTIONS[s].icon, danger: OFFER_ACTIONS[s].danger, onClick: () => setChange({ target: target(r), status: s }) }))
            : []),
          r.offer_letter && { label: 'Download offer letter', icon: Download, href: privateFileUrl(String(r.offer_letter), `Offer-${r.student_uid}.pdf`), target: '_blank' },
          r.drive_id && { label: 'Open drive', icon: ExternalLink, to: `/placement/drives/${r.drive_id}` },
          { label: 'Student profile', icon: UserRound, to: `/students/${r.student_id}` },
        ]}
        renderers={{
          student_name: (r: Row) => <StudentCell name={String(r.student_name)} sub={String(r.student_sub ?? r.student_uid ?? '')} src={r.student_photo as string | null} />,
          company_name: (r: Row) => (
            <div className="flex w-[12rem] max-w-[12rem] items-center gap-2.5">
              <CompanyLogo name={String(r.company_name)} src={r.company_logo as string | null} size="sm" wide />
              <div className="min-w-0 leading-tight">
                <div className="truncate font-semibold text-slate-900 dark:text-white">{String(r.company_name)}</div>
                <div className="truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.job_role)}>{String(r.job_role)}</div>
              </div>
            </div>
          ),
          status: (r: Row) => (
            <div className="flex flex-col items-start gap-1">
              <StatusBadge status={String(r.status)} label={OFFER_STATUS_SHORT[String(r.status)] ?? OFFER_STATUS[String(r.status)]} colors={OFFER_STATUS_COLORS} />
              {r.status === 'offered' && can('placement', 'edit') && (
                <button type="button" onClick={() => setChange({ target: target(r), status: 'accepted' })} className="inline-flex items-center gap-1 whitespace-nowrap text-xs font-semibold text-accent-700 hover:underline dark:text-accent-400">
                  <BadgeCheck className="h-3.5 w-3.5" /> Mark placed
                </button>
              )}
            </div>
          ),
          package_lpa: (r: Row) => (
            <div className="text-right leading-tight">
              <div className="whitespace-nowrap font-semibold tabular-nums text-slate-900 dark:text-white">{lpa(r.package_lpa)}</div>
              {r.job_type && <div className="text-xs text-slate-500 dark:text-slate-400">{r.job_type === 'internship_ppo' ? 'PPO' : JOB_TYPES[String(r.job_type)] ?? ''}</div>}
            </div>
          ),
          offer_letter: (r: Row) =>
            r.offer_letter ? (
              <a href={privateFileUrl(String(r.offer_letter), `Offer-${r.student_uid}.pdf`)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                <Download className="h-3.5 w-3.5" /> PDF
              </a>
            ) : ['offered', 'accepted', 'joined'].includes(String(r.status)) ? (
              <Badge color="amber">Missing</Badge>
            ) : (
              <span className="text-slate-400">—</span>
            ),
        }}
        onSaved={() => void refresh('placement_offers', 'placement_applications')}
      />

      <OfferStatusModal target={change?.target ?? null} status={change?.status ?? null} onClose={() => setChange(null)} />
    </>
  );
}
