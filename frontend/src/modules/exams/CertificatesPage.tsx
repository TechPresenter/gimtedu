import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Award, Ban, BadgeCheck, CalendarCheck, CheckCircle2, ExternalLink, Eye, FileClock, FileX, LayoutTemplate, MoreHorizontal, Printer, RotateCcw, ScanLine, Settings2, Trash2,
  TrendingUp, Users, X,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, Dropdown, EmptyState, IconButton, PageHeader, PersonCell, ProgressBar, Reveal, Skeleton, Stagger, StatCard, StatusBadge, Tabs,
  useConfirm, useToast, type DropdownItem,
} from '@/components/ui';
import { BarChart } from '@/components/charts';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useTheme } from '@/lib/theme';
import { printUrl } from '@/lib/config';
import { formatDate, formatNumber, formatPercent, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CertStatusBadge, TypeIcon, useCertConfig } from './certificates/shared';
import { IssueCertificateModal } from './certificates/IssueCertificateModal';
import { BulkIssueModal } from './certificates/BulkIssueModal';
import { CertificateDrawer } from './certificates/CertificateDrawer';
import { ReasonModal, ReissueModal, SettingsModal, useRefreshCertificates, type CertRef } from './certificates/ActionModals';
import type { CertSummary, StudentCard } from './certificates/types';

const TABS = ['all', 'pending', 'issued', 'expired', 'revoked', 'rejected'] as const;
type TabKey = (typeof TABS)[number];
const RESULT_COLORS = { valid: 'green', expired: 'purple', revoked: 'red', not_found: 'slate' } as const;
const RESULT_LABEL = { valid: 'Valid', expired: 'Expired', revoked: 'Revoked', not_found: 'Not found' } as const;
const SOURCE_LABEL: Record<string, string> = { qr: 'QR scan', web: 'Website', api: 'API' };

const trend = (v: number | null | undefined, label: string) =>
  v === null || v === undefined ? undefined : { value: formatPercent(Math.abs(v), Math.abs(v) < 10 ? 1 : 0), dir: v > 0 ? ('up' as const) : v < 0 ? ('down' as const) : ('flat' as const), label };

export default function CertificatesPage() {
  const { can } = useAuth();
  const { resolved: theme } = useTheme();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useRefreshCertificates();
  const [params, setParams] = useSearchParams();
  const studentId = Number(params.get('student_id')) || null;
  const viewId = Number(params.get('view')) || null;
  const tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as TabKey) : 'all';

  const summary = useApi<CertSummary>(['cert-summary'], 'certificates/summary');
  const config = useCertConfig();
  const student = useApi<StudentCard>(['cert-student', studentId], `certificates/students/${studentId}`, undefined, { enabled: !!studentId });
  const k = summary.data?.kpis;

  const [issueOpen, setIssueOpen] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [reason, setReason] = useState<{ mode: 'revoke' | 'reject'; cert: CertRef } | null>(null);
  const [reissue, setReissue] = useState<CertRef | null>(null);

  // /certificates?student_id=… (from a student profile) opens the issue dialog with the student preselected, once.
  // /certificates?type=…&template_id=… ("Issue with this template" in the template gallery) opens it with the type
  // and template preselected.
  const presetType = params.get('type');
  const presetTemplate = Number(params.get('template_id')) || null;
  const autoOpened = useRef<string | null>(null);
  useEffect(() => {
    const key = `${studentId ?? ''}|${presetType ?? ''}|${presetTemplate ?? ''}`;
    if ((studentId || presetType) && can('certificates', 'create') && autoOpened.current !== key && params.get('issue') !== '0') {
      autoOpened.current = key;
      setIssueOpen(true);
    }
  }, [studentId, presetType, presetTemplate, can, params]);
  const closeIssue = () => {
    setIssueOpen(false);
    // Drop the one-off presets so a later "Issue certificate" starts clean (the student filter stays).
    if (presetType || presetTemplate) {
      const next = new URLSearchParams(params);
      next.delete('type');
      next.delete('template_id');
      setParams(next, { replace: true });
    }
  };

  const setParam = (key: string, value: string | null) => {
    const next = new URLSearchParams(params);
    if (value === null || value === '') next.delete(key);
    else next.set(key, value);
    if (key === 'tab') next.delete('page');
    setParams(next, { replace: key !== 'view' });
  };
  const openView = (id: number) => setParam('view', String(id));
  const refOf = (r: Row): CertRef => ({ id: Number(r.id), certificate_no: String(r.certificate_no), type: String(r.type), type_label: String(r.type_label ?? ''), student_name: String(r.student_name ?? '') });
  const print = (ids: number[]) => void window.open(printUrl('certificate.php', ids.length === 1 ? { id: ids[0] } : { ids: ids.join(',') }), '_blank', 'noopener');
  const approve = async (r: Row) => {
    try {
      const res = await api.post(`certificates/${r.id}/approve`);
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const removeRequest = async (r: Row) => {
    const ok = await confirm({
      title: 'Delete request?',
      message: <>Delete the {String(r.status)} request <strong>{String(r.certificate_no)}</strong> for {String(r.student_name)}? This cannot be undone.</>,
      confirmText: 'Delete', danger: true,
    });
    if (!ok) return;
    try {
      const res = await api.del(`crud/certificates/${r.id}`);
      toast.success(res.message || 'Certificate request deleted.');
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  /** Workflow actions for a row (shown in the row's "More" menu). */
  const menuFor = (r: Row): (DropdownItem | false)[] => {
    const st = String(r.status);
    return [
      st === 'pending' && can('certificates', 'approve') && { label: 'Approve & issue', icon: CheckCircle2, onClick: () => void approve(r) },
      st === 'pending' && can('certificates', 'approve') && { label: 'Reject request', icon: FileX, onClick: () => setReason({ mode: 'reject', cert: refOf(r) }) },
      (st === 'issued' || st === 'revoked') && can('certificates', 'create') && { label: 'Reissue', icon: RotateCcw, onClick: () => setReissue(refOf(r)) },
      (st === 'issued' || st === 'revoked') && { label: 'Verification page', icon: ExternalLink, href: `${config.data?.verify_base ?? '/verify-certificate'}/${encodeURIComponent(String(r.certificate_no))}`, target: '_blank' },
      st === 'issued' && can('certificates', 'edit') && { divider: true, label: 'd' },
      st === 'issued' && can('certificates', 'edit') && { label: 'Revoke', icon: Ban, danger: true, onClick: () => setReason({ mode: 'revoke', cert: refOf(r) }) },
      (st === 'pending' || st === 'rejected') && can('certificates', 'delete') && { divider: true, label: 'd2' },
      (st === 'pending' || st === 'rejected') && can('certificates', 'delete') && { label: 'Delete request', icon: Trash2, danger: true, onClick: () => void removeRequest(r) },
    ];
  };
  const approveMany = async (ids: number[], clear: () => void) => {
    const ok = await confirm({ title: `Approve ${ids.length} request${ids.length === 1 ? '' : 's'}?`, message: 'Pending requests among the selected certificates will be issued with today\'s date. Others are skipped.', confirmText: 'Approve' });
    if (!ok) return;
    let done = 0;
    const failed: string[] = [];
    for (const id of ids) {
      try {
        await api.post(`certificates/${id}/approve`);
        done++;
      } catch (e) {
        failed.push((e as ApiError).message);
      }
    }
    if (done) toast.success(`${done} certificate${done === 1 ? '' : 's'} approved and issued.`);
    if (failed.length) toast.warning(`${failed.length} skipped: ${failed[0]}`);
    clear();
    await refresh();
  };

  const tabCounts: Record<TabKey, number | undefined> = {
    all: k?.all_count, pending: k?.pending_count, issued: k?.valid_count, expired: k?.expired_count, revoked: k?.revoked_count, rejected: k?.rejected_count,
  };
  const tabLabels: Record<TabKey, string> = { all: 'All', pending: 'Pending approval', issued: 'Valid', expired: 'Expired', revoked: 'Revoked', rejected: 'Rejected' };
  const scope: Record<string, string | number> = {};
  if (studentId) scope.student_id = studentId;
  if (tab !== 'all') scope.state = tab;

  const byType = summary.data?.by_type ?? [];
  const maxType = Math.max(1, ...byType.map((t) => t.total));
  const monthly = summary.data?.monthly ?? [];

  return (
    <>
      <PageHeader
        title="Certificates"
        description="Issue, approve, print and revoke official certificates with unique numbers and QR verification."
        breadcrumbs={[{ label: 'Examination', to: '/examination' }, { label: 'Certificates' }]}
        actions={
          <>
            {can('certificates', 'manage') && <IconButton icon={Settings2} label="Certificate settings" onClick={() => setSettingsOpen(true)} />}
            <Button variant="secondary" icon={LayoutTemplate} to="/certificates/templates">Templates</Button>
            {can('certificates', 'create') && <Button variant="secondary" icon={Users} onClick={() => setBulkOpen(true)}>Bulk issue</Button>}
          </>
        }
      />

      {studentId && (
        <Reveal>
          <Card className="mb-6 flex flex-col gap-4 border-brand-200 bg-brand-50/60 p-4 sm:flex-row sm:items-center dark:border-brand-500/30 dark:bg-brand-500/10">
            {student.error ? (
              <Alert variant="error" className="flex-1">{(student.error as ApiError).message}</Alert>
            ) : !student.data ? (
              <div className="flex flex-1 items-center gap-3"><Skeleton className="h-12 w-12 rounded-full" /><Skeleton className="h-5 w-56" /></div>
            ) : (
              <>
                <div className="flex min-w-0 flex-1 items-center gap-3">
                  <Avatar name={student.data.name} src={student.data.photo} size="lg" />
                  <div className="min-w-0">
                    <p className="text-xs font-medium uppercase tracking-wide text-brand-700 dark:text-brand-300">Certificates of</p>
                    <p className="truncate font-display text-lg font-bold text-slate-900 dark:text-white">{student.data.name}</p>
                    <p className="truncate text-xs text-slate-600 dark:text-slate-300">
                      {student.data.student_uid} · {student.data.program_short} · Sem {student.data.semester} · <StatusBadge status={student.data.status} />
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button variant="secondary" size="sm" to={`/students/${student.data.id}`}>Student profile</Button>
                  {can('certificates', 'create') && <Button size="sm" icon={Award} onClick={() => setIssueOpen(true)}>Issue certificate</Button>}
                  <IconButton icon={X} label="Show all students" onClick={() => setParam('student_id', null)} />
                </div>
              </>
            )}
          </Card>
        </Reveal>
      )}

      {summary.error ? (
        <Alert variant="error" className="mb-6" title="Unable to load certificate statistics">{(summary.error as ApiError).message}</Alert>
      ) : (
        <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
          {[
            <StatCard key="valid" label="Valid certificates" icon={BadgeCheck} tone="green" loading={summary.isLoading} value={k?.valid_count ?? 0} hint={k ? `${formatNumber(k.session_count)} issued this session` : undefined} />,
            <StatCard key="month" label="Issued this month" icon={CalendarCheck} tone="blue" loading={summary.isLoading} value={k?.month_count ?? 0} trend={trend(k?.month_trend, 'vs last month')} hint={k ? `${formatNumber(k.prev_month_count)} last month` : undefined} />,
            <StatCard key="pending" label="Pending approval" icon={FileClock} tone="amber" loading={summary.isLoading} value={k?.pending_count ?? 0} to="/certificates?tab=pending"
              hint={summary.data?.settings.approval_required ? 'Approval step is on' : 'Approval step is off'} />,
            <StatCard key="revoked" label="Revoked / expired" icon={Ban} tone="red" loading={summary.isLoading} value={(k?.revoked_count ?? 0) + (k?.expired_count ?? 0)} to="/certificates?tab=revoked"
              hint={k ? `${formatNumber(k.revoked_count)} revoked · ${formatNumber(k.expired_count)} expired` : undefined} />,
            <StatCard key="verify" label="Verifications (30 days)" icon={ScanLine} tone="cyan" loading={summary.isLoading} value={k?.verifications_30d ?? 0} trend={trend(k?.verifications_trend, 'vs previous 30 days')} />,
          ]}
        </Stagger>
      )}

      <Tabs
        variant="pills"
        className="mb-4"
        value={tab}
        onChange={(v) => setParam('tab', v === 'all' ? null : v)}
        tabs={TABS.map((t) => ({ key: t, label: tabLabels[t], count: tabCounts[t] !== undefined && !studentId ? formatNumber(tabCounts[t]) : undefined }))}
      />

      <CrudTable
        key={`${tab}-${studentId ?? 'all'}`}
        module="certificates"
        urlState
        scope={Object.keys(scope).length ? scope : undefined}
        hideFilters={tab !== 'all' ? ['status'] : []}
        title={studentId && student.data ? `Certificates of ${student.data.name}` : tab === 'all' ? 'Issued certificates' : tabLabels[tab]}
        addLabel="Issue certificate"
        emptyTitle={tab === 'pending' ? 'No requests waiting for approval' : studentId ? 'No certificates for this student yet' : 'No certificates yet'}
        emptyText={tab === 'pending' ? 'Requests from staff without approval rights appear here when the approval step is on.' : 'Issue a bonafide, character, transfer or any other certificate — it is numbered, QR-secured and ready to print.'}
        onCreate={() => setIssueOpen(true)}
        onView={(r) => openView(Number(r.id))}
        noRowActions
        renderers={{
          row_actions: (r) => {
            const st = String(r.status);
            const items = menuFor(r).filter(Boolean);
            return (
              <div className="flex items-center justify-end gap-0.5">
                <IconButton size="sm" icon={Eye} label={`View ${String(r.certificate_no)}`} tone="primary" onClick={() => openView(Number(r.id))} />
                {st !== 'pending' && st !== 'rejected' && <IconButton size="sm" icon={Printer} label={`Print ${String(r.certificate_no)}`} tone="primary" onClick={() => print([Number(r.id)])} />}
                {items.length > 0 && (
                  <Dropdown label={`More actions for ${String(r.certificate_no)}`} triggerClassName="btn-icon !h-8 !w-8 !rounded-lg" trigger={<MoreHorizontal className="h-4 w-4" />} items={items} />
                )}
              </div>
            );
          },
          certificate_no: (r) => (
            <button type="button" onClick={() => openView(Number(r.id))} className="whitespace-nowrap text-left font-mono text-[13px] font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
              {String(r.certificate_no)}
            </button>
          ),
          type: (r) => (
            <span className="inline-flex min-w-[7.5rem] max-w-[9rem] items-center gap-2 text-sm leading-tight text-slate-700 dark:text-slate-200">
              <TypeIcon type={String(r.type)} size="sm" />{String(r.type_label)}
            </span>
          ),
          student_name: (r) => (
            <div className="min-w-[11rem] max-w-[13.5rem]" title={`${String(r.student_name)} · ${String(r.program_short)} Sem ${String(r.current_semester)}`}>
              <PersonCell name={String(r.student_name)} sub={`${r.student_uid} · ${r.program_short} Sem ${r.current_semester}${r.student_status !== 'active' ? ` · ${String(r.student_status)}` : ''}`} src={r.student_photo as string | null} to={`/students/${r.student_id}`} size="sm" />
            </div>
          ),
          program_short: (r) => <span className="whitespace-nowrap">{String(r.program_short)}{r.current_semester ? <span className="text-xs text-slate-500"> · Sem {String(r.current_semester)}</span> : null}</span>,
          issue_date: (r) => (
            <div className="whitespace-nowrap leading-tight">
              <p>{formatDate(r.issue_date)}</p>
              <p className="text-xs text-slate-500">{r.valid_until ? `till ${formatDate(r.valid_until)}` : 'No expiry'}</p>
            </div>
          ),
          display_status: (r) => (
            <span title={String(r.display_status === 'pending' ? 'Pending approval' : r.revoked_reason ?? '')} className="whitespace-nowrap">
              <CertStatusBadge status={String(r.display_status)} short />
            </span>
          ),
          issued_by_name: (r) => (r.issued_by_name ? <span className="whitespace-nowrap text-sm">{String(r.issued_by_name)}</span> : <span className="text-slate-400">—</span>),
        }}
        bulkActions={[
          { label: 'Print selected', icon: Printer, onClick: (ids) => print(ids) },
          ...(can('certificates', 'approve') && (tab === 'pending' || tab === 'all') ? [{ label: 'Approve selected', icon: CheckCircle2, onClick: (ids: number[], clear: () => void) => void approveMany(ids, clear) }] : []),
        ]}
      />

      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Certificates & verifications" subtitle="Issued per month vs public verification lookups (last 6 months)" icon={TrendingUp} />
            <div className="card-body">
              {summary.isLoading ? (
                <Skeleton className="h-60 w-full" />
              ) : monthly.every((m) => !m.issued && !m.verifications) ? (
                <EmptyState icon={Award} title="No activity yet" description="Issued certificates and verification lookups will be charted here." />
              ) : (
                <BarChart labels={monthly.map((m) => m.label)} height={250}
                  series={[{ label: 'Issued', data: monthly.map((m) => m.issued), color: theme === 'dark' ? '#60A5FA' : '#0B2A5B' }, { label: 'Verified online', data: monthly.map((m) => m.verifications), color: '#22943F' }]} />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="By certificate type" subtitle="Issued, revoked and pending" icon={Award} />
            <div className="card-body space-y-3.5">
              {summary.isLoading
                ? Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-8 w-full" />)
                : byType.map((t) => (
                  <div key={t.type} className="flex items-center gap-3">
                    <TypeIcon type={t.type} size="sm" />
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 flex items-center justify-between gap-2 text-sm">
                        <span className="truncate font-medium text-slate-700 dark:text-slate-200">{t.label}</span>
                        <span className="tabular-nums text-slate-500">{formatNumber(t.total)}{t.pending ? <span className="ml-1 text-xs text-amber-600">({t.pending} pending)</span> : null}</span>
                      </div>
                      <ProgressBar value={(t.total / maxType) * 100} tone={t.tone} label={`${t.label} certificates`} />
                    </div>
                  </div>
                ))}
            </div>
          </Card>
        </Reveal>
      </div>

      <Reveal className="mt-6">
        <Card>
          <CardHeader title="Recent public verifications" subtitle={k ? `${formatNumber(k.verifications_30d)} lookups in 30 days · ${formatNumber(k.verifications_flagged)} flagged (not found, revoked or expired)` : 'QR scans and website lookups'} icon={ScanLine}
            actions={<Button size="sm" variant="secondary" icon={ExternalLink} href={config.data?.verify_base ?? '/verify-certificate'} target="_blank">Open</Button>} />
          {summary.isLoading ? (
            <div className="card-body space-y-3">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}</div>
          ) : !summary.data?.recent_verifications.length ? (
            <EmptyState icon={ScanLine} title="No verifications yet" description="When employers or universities scan a certificate's QR code, the lookups appear here." />
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {summary.data.recent_verifications.map((v) => (
                <li key={v.id} className="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:gap-4">
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    {v.type ? <TypeIcon type={v.type} size="sm" /> : <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800"><X className="h-3.5 w-3.5" /></span>}
                    <div className="min-w-0 leading-tight">
                      {v.certificate_id ? (
                        <button type="button" onClick={() => openView(v.certificate_id!)} className="font-mono text-[13px] font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{v.certificate_no}</button>
                      ) : (
                        <span className="font-mono text-[13px] font-semibold text-slate-500 line-through decoration-slate-300">{v.certificate_no}</span>
                      )}
                      <p className="truncate text-xs text-slate-500">{v.student_name ? `${v.student_name} · ${v.type_label}` : 'No certificate with this number'}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3 pl-10 text-xs text-slate-500 sm:pl-0">
                    <span>{SOURCE_LABEL[v.source] ?? v.source}</span>
                    <span className="w-24 text-right">{timeAgo(v.created_at)}</span>
                    <Badge color={RESULT_COLORS[v.result]}>{RESULT_LABEL[v.result]}</Badge>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </Reveal>

      <IssueCertificateModal open={issueOpen} onClose={closeIssue} studentId={studentId} type={presetType} templateId={presetTemplate} onView={openView} />
      <BulkIssueModal open={bulkOpen} onClose={() => setBulkOpen(false)} />
      <SettingsModal open={settingsOpen} onClose={() => setSettingsOpen(false)} />
      <CertificateDrawer id={viewId} onClose={() => setParam('view', null)} onOpen={openView} />
      <ReasonModal mode={reason?.mode ?? 'revoke'} cert={reason?.cert ?? null} onClose={() => setReason(null)} />
      <ReissueModal cert={reissue} onClose={() => setReissue(null)} onDone={(id) => openView(id)} />
    </>
  );
}
