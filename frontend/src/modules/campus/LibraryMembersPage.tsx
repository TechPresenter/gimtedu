import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Ban, BookUp, CheckCircle2, Eye, GraduationCap, IdCard, Plus, Printer, UserCheck, UserRoundX, Users, UsersRound, Briefcase } from 'lucide-react';
import { Avatar, Button, PageHeader, StatTile, Stagger, StatusBadge, ProgressBar } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatNumber } from '@/lib/format';
import type { Paginated, Row } from '@/lib/types';
import { EnrollDialog, MemberDrawer, MemberFormDialog, MemberStatusDialog, type StatusTarget } from './library/memberDialogs';
import { MEMBERSHIP_COLORS, MemberTypeBadge, libCrumbs, openPrint } from './library/shared';
import type { RulesPayload } from './library/types';

/** Counts for the KPI strip come from the CRUD list endpoint (per_page=1, filtered) so they always match the table filters. */
function useMemberCount(f: Record<string, string>) {
  return useApi<Paginated<Row>>(['library', 'member-count', f], 'crud/library_members', { per_page: 1, f });
}

export default function LibraryMembersPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const drawerId = Number(params.get('member')) || null;
  /** When the table is filtered to one section, its whole set of library cards can be printed in one go. */
  const sectionFilter = params.get('f.section') ?? '';
  const [formOpen, setFormOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [enrollOpen, setEnrollOpen] = useState(false);
  const [statusTarget, setStatusTarget] = useState<StatusTarget | null>(null);
  const all = useMemberCount({});
  const students = useMemberCount({ member_type: 'student' });
  const faculty = useMemberCount({ member_type: 'faculty' });
  const staff = useMemberCount({ member_type: 'staff' });
  const suspended = useMemberCount({ status: 'suspended' });
  const expired = useMemberCount({ status: 'expired' });
  const borrowing = useMemberCount({ loans_state: 'borrowing' });
  const rules = useApi<RulesPayload>(['library', 'rules'], 'library/rules');
  const sd = rules.data?.rules.types.student;

  const openDrawer = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('member', String(id));
    else next.delete('member');
    setParams(next, { replace: !id });
  };
  const openForm = (id: number | null) => {
    setEditId(id);
    setFormOpen(true);
  };
  const nameOf = (r: Row) => `${r.member_name} (${r.membership_no})`;

  const rowMenu = (r: Row) => {
    const state = String(r.membership_state);
    return [
      { label: 'Member card', icon: Eye, onClick: () => openDrawer(Number(r.id)) },
      { label: 'Print library card', icon: IdCard, onClick: () => openPrint('cards', { ids: r.id }) },
      can('library', 'create') && state === 'active' && { label: 'Issue a book', icon: BookUp, to: `/library/circulation?tab=issue&member=${r.id}` },
      can('library', 'edit') && { label: '', divider: true },
      can('library', 'edit') && state === 'active' && { label: 'Suspend…', icon: Ban, danger: true, onClick: () => setStatusTarget({ ids: [Number(r.id)], mode: 'suspended', label: nameOf(r) }) },
      can('library', 'edit') && state !== 'active' && { label: 'Activate…', icon: CheckCircle2, onClick: () => setStatusTarget({ ids: [Number(r.id)], mode: 'active', label: nameOf(r) }) },
    ];
  };

  const loading = all.isLoading;
  return (
    <>
      <PageHeader
        title="Library Members"
        description="Students, faculty and staff who can borrow books — limits, validity, loans and dues."
        breadcrumbs={libCrumbs('Members')}
        actions={
          <>
            {sectionFilter && (
              <Button variant="secondary" icon={Printer} onClick={() => openPrint('cards', { section_id: sectionFilter })}>
                Print section cards
              </Button>
            )}
            {can('library', 'create') && (
              <Button variant="secondary" icon={UsersRound} onClick={() => setEnrollOpen(true)}>
                Bulk enroll
              </Button>
            )}
            {can('library', 'create') && (
              <Button icon={Plus} onClick={() => openForm(null)}>
                Add Member
              </Button>
            )}
          </>
        }
      />

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="a" label="Total members" value={all.data?.total ?? 0} icon={Users} tone="navy" loading={loading} sub={`${formatNumber(borrowing.data?.total ?? 0)} borrowing now`} />,
          <StatTile key="s" label="Students" value={students.data?.total ?? 0} icon={GraduationCap} tone="blue" loading={students.isLoading} sub={sd ? `${sd.max_books} books · ${sd.loan_days} days by default` : 'Default limits from rules'} />,
          <StatTile key="f" label="Faculty & staff" value={(faculty.data?.total ?? 0) + (staff.data?.total ?? 0)} icon={Briefcase} tone="purple" loading={faculty.isLoading || staff.isLoading} sub={`${formatNumber(faculty.data?.total ?? 0)} faculty · ${formatNumber(staff.data?.total ?? 0)} staff`} />,
          <StatTile key="x" label="Suspended / expired" value={(suspended.data?.total ?? 0) + (expired.data?.total ?? 0)} icon={UserRoundX} tone="red" loading={suspended.isLoading || expired.isLoading} sub={`${formatNumber(suspended.data?.total ?? 0)} suspended · ${formatNumber(expired.data?.total ?? 0)} expired`} />,
        ]}
      </Stagger>

      <CrudTable
        module="library_members"
        urlState
        title="Member directory"
        addLabel="Add Member"
        onCreate={() => openForm(null)}
        onEdit={(r) => openForm(Number(r.id))}
        onView={(r) => openDrawer(Number(r.id))}
        rowMenu={rowMenu}
        emptyText="Enroll students section-wise with Bulk enroll, or add faculty and staff one by one."
        bulkActions={[
          { label: 'Print cards', icon: Printer, onClick: (ids) => openPrint('cards', { ids: ids.join(',') }) },
          ...(can('library', 'edit')
            ? [
                { label: 'Activate', icon: UserCheck, onClick: (ids: number[], clear: () => void) => setStatusTarget({ ids, mode: 'active', label: `${ids.length} selected member${ids.length === 1 ? '' : 's'}`, clear }) },
                { label: 'Suspend', icon: Ban, danger: true, onClick: (ids: number[], clear: () => void) => setStatusTarget({ ids, mode: 'suspended', label: `${ids.length} selected member${ids.length === 1 ? '' : 's'}`, clear }) },
              ]
            : []),
        ]}
        renderers={{
          member_name: (r) => (
            <button type="button" onClick={() => openDrawer(Number(r.id))} className="flex min-w-[14rem] items-center gap-3 text-left">
              <Avatar name={String(r.member_name)} src={r.member_photo} />
              <span className="min-w-0 leading-tight">
                <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.member_name}</span>
                <span className="block max-w-[15rem] truncate text-xs text-slate-500 dark:text-slate-400">
                  <span className="font-mono">{r.membership_no}</span> · {r.member_info || r.member_code}
                </span>
              </span>
            </button>
          ),
          member_type: (r) => <MemberTypeBadge type={String(r.member_type)} />,
          loans: (r) => {
            const used = Number(r.loans);
            const max = Number(r.max_books) || 1;
            const overdue = Number(r.overdue_count);
            return (
              <div className="mx-auto w-20" title={`${used} of ${max} books${overdue ? ` · ${overdue} overdue` : ''}`}>
                <p className="text-center text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">
                  {used} / {max}
                </p>
                <ProgressBar value={(used / max) * 100} tone={overdue ? 'red' : used >= max ? 'amber' : 'green'} className="mt-1 h-1.5" label="Borrowing limit used" />
                {overdue > 0 && <p className="mt-0.5 text-center text-[11px] font-semibold text-red-600 dark:text-red-400">{overdue} overdue</p>}
              </div>
            );
          },
          overdue_count: (r) => (Number(r.overdue_count) > 0 ? <span className="font-semibold text-red-600 dark:text-red-400">{r.overdue_count}</span> : <span className="text-slate-400">—</span>),
          unpaid_fines: (r) => (Number(r.unpaid_fines) > 0 ? <span className="whitespace-nowrap font-medium text-red-600 dark:text-red-400">{formatMoney(r.unpaid_fines)}</span> : <span className="text-slate-400">—</span>),
          membership_state: (r) => <StatusBadge status={String(r.membership_state)} colors={MEMBERSHIP_COLORS} />,
        }}
      />

      <MemberFormDialog open={formOpen} id={editId} onClose={() => setFormOpen(false)} onSaved={(id) => !editId && openDrawer(id)} />
      <EnrollDialog open={enrollOpen} onClose={() => setEnrollOpen(false)} />
      <MemberStatusDialog target={statusTarget} onClose={() => setStatusTarget(null)} />
      <MemberDrawer
        memberId={drawerId}
        onClose={() => openDrawer(null)}
        onEdit={(id) => openForm(id)}
        onStatus={(t) => setStatusTarget(t)}
      />
    </>
  );
}
