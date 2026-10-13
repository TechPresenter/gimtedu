import { useCallback, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { BadgeCheck, CalendarDays, Eye, GraduationCap, HandCoins, Handshake, UserMinus, UserPlus, UsersRound } from 'lucide-react';
import { Button, PageHeader, Tabs, useToast } from '@/components/ui';
import type { CrudBulkAction } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { DirectoryOverview, type AlumniDashboard } from './alumni/DirectoryOverview';
import { ImportStudentsModal } from './alumni/ImportStudentsModal';
import { MentorshipsTab } from './alumni/MentorshipsTab';
import { BoundedPerson, FixedCrudFormModal, LinkedCrudTable, TwoLine, statsKey, useRefreshModules } from './alumni/shared';

const TABS = ['directory', 'mentors'] as const;
type Tab = (typeof TABS)[number];

export default function AlumniPage() {
  const { can } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const refresh = useRefreshModules();
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'directory';
  const [importOpen, setImportOpen] = useState(false);
  const [donateFor, setDonateFor] = useState<Row | null>(null);
  const [menteeFor, setMenteeFor] = useState<Row | null>(null);
  const dash = useApi<AlumniDashboard>(statsKey('alumni', 'dashboard'), 'alumni/dashboard');
  const k = dash.data?.kpis;
  const openImport = useCallback(() => setImportOpen(true), []);

  const setTab = (t: string) => {
    const next = new URLSearchParams();
    if (t !== 'directory') next.set('tab', t);
    setParams(next, { replace: true });
  };

  const bulk = async (action: 'mentor_on' | 'mentor_off' | 'verify', ids: number[], clear: () => void) => {
    try {
      const res = await api.post('crud/alumni/bulk', { action, ids });
      toast.success(res.message);
      clear();
      await refresh('alumni');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulkActions: CrudBulkAction[] = can('alumni', 'edit')
    ? [
        { label: 'Add to mentor pool', icon: Handshake, onClick: (ids, clear) => bulk('mentor_on', ids, clear) },
        { label: 'Remove from mentor pool', icon: UserMinus, onClick: (ids, clear) => bulk('mentor_off', ids, clear) },
        { label: 'Mark verified', icon: BadgeCheck, onClick: (ids, clear) => bulk('verify', ids, clear) },
      ]
    : [];

  return (
    <>
      <PageHeader
        title="Alumni Directory"
        description="GIMT graduates by batch, program, career and location — plus the alumni mentorship programme."
        breadcrumbs={[{ label: 'Alumni' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2 lg:flex-nowrap">
            <Button variant="secondary" icon={CalendarDays} to="/alumni/events">Events</Button>
            {can('alumni', 'import') && (
              <Button variant="secondary" icon={GraduationCap} onClick={openImport}>
                Import passed-out students
                {k && k.pending_import > 0 && <span className="ml-1 rounded-full bg-accent-600 px-1.5 text-[10px] font-bold text-white">{k.pending_import}</span>}
              </Button>
            )}
          </div>
        }
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'directory', label: 'Directory', icon: UsersRound, count: k ? formatNumber(k.total) : undefined },
            { key: 'mentors', label: 'Mentorships', icon: Handshake, count: k ? formatNumber(k.mentorships_active) : undefined },
          ]}
        />
      </PageHeader>

      {tab === 'directory' ? (
        <>
          <DirectoryOverview data={dash.data} isLoading={dash.isLoading} error={dash.error} canImport={can('alumni', 'import')} canEdit={can('alumni', 'edit')} onImport={openImport} />
          <LinkedCrudTable
            module="alumni"
            title="Alumni directory"
            viewTo={(r) => `/alumni/${r.id}`}
            addLabel="Add alumni"
            emptyTitle="No alumni profiles yet"
            emptyText="Import passed-out students or add alumni profiles manually to build the directory."
            bulkActions={bulkActions}
            renderers={{
              name: (r) => (
                <BoundedPerson name={String(r.name)} photo={r.photo} sub={r.email ?? r.phone} to={`/alumni/${r.id}`} className="min-w-[10.5rem] max-w-[13rem]"
                  badge={Number(r.is_mentor) ? <Handshake className="h-3.5 w-3.5 shrink-0 text-violet-600 dark:text-violet-300" aria-label="Mentor" /> : undefined} />
              ),
              batch_year: (r) => <TwoLine title={String(r.batch_year)} sub={r.program_short} className="min-w-[4.5rem] max-w-[6rem] text-center" />,
              program_short: (r) => <span className="whitespace-nowrap">{r.program_short ?? '—'}</span>,
              company: (r) => <TwoLine title={r.company} sub={r.designation} className="min-w-[9rem] max-w-[12rem]" />,
              city: (r) => <TwoLine title={<span className="font-normal text-slate-700 dark:text-slate-200">{r.city ?? '—'}</span>} sub={r.country !== 'India' ? r.country : undefined} className="min-w-[6rem] max-w-[9rem]" />,
              industry: (r) => (r.industry ? <span className="block max-w-[9rem] truncate" title={String(r.industry)}>{String(r.industry)}</span> : <span className="text-slate-400">—</span>),
            }}
            rowMenu={(r) => [
              { label: 'View profile', icon: Eye, onClick: () => navigate(`/alumni/${r.id}`) },
              can('alumni', 'create') && r.status === 'active' && { label: 'Record donation', icon: HandCoins, onClick: () => setDonateFor(r) },
              can('alumni', 'create') && r.status === 'active' && { label: 'Assign a mentee', icon: UserPlus, onClick: () => setMenteeFor(r) },
              can('alumni', 'edit') && r.status === 'active' && {
                label: Number(r.is_mentor) ? 'Remove from mentor pool' : 'Add to mentor pool',
                icon: Number(r.is_mentor) ? UserMinus : Handshake,
                onClick: () => bulk(Number(r.is_mentor) ? 'mentor_off' : 'mentor_on', [Number(r.id)], () => undefined),
              },
            ]}
            header={({ summary }) =>
              summary ? (
                <div className="flex flex-wrap gap-x-5 gap-y-1 border-b border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                  <span><strong className="text-slate-800 dark:text-slate-100">{formatNumber(summary.working)}</strong> working</span>
                  <span><strong className="text-slate-800 dark:text-slate-100">{formatNumber(summary.companies)}</strong> companies</span>
                  <span><strong className="text-slate-800 dark:text-slate-100">{formatNumber(summary.mentors)}</strong> mentors</span>
                  <span><strong className="text-slate-800 dark:text-slate-100">{formatNumber(summary.verified)}</strong> verified</span>
                </div>
              ) : null
            }
          />
        </>
      ) : (
        <MentorshipsTab />
      )}

      <ImportStudentsModal open={importOpen} onClose={() => setImportOpen(false)} />
      <FixedCrudFormModal
        open={!!donateFor}
        onClose={() => setDonateFor(null)}
        module="alumni_donations"
        fixed={{ alumni_id: donateFor?.id }}
        title="Record donation"
        context={donateFor && <DonorChip row={donateFor} label="Donor" />}
        onSaved={() => void refresh('alumni', 'alumni_donations')}
      />
      <FixedCrudFormModal
        open={!!menteeFor}
        onClose={() => setMenteeFor(null)}
        module="alumni_mentorships"
        fixed={{ alumni_id: menteeFor?.id }}
        title="Assign a mentee"
        context={menteeFor && <DonorChip row={menteeFor} label="Mentor" />}
        onSaved={() => void refresh('alumni', 'alumni_mentorships')}
      />
    </>
  );
}

function DonorChip({ row, label }: { row: Row; label: string }) {
  return (
    <div className="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm dark:bg-slate-800/60">
      <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      <span className="font-semibold text-slate-900 dark:text-white">{String(row.name)}</span>
      <span className="truncate text-slate-500">Batch {String(row.batch_year)}{row.company ? ` · ${row.company}` : ''}</span>
    </div>
  );
}
