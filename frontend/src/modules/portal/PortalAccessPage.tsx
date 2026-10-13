import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { ChevronDown, ExternalLink, GraduationCap, History, KeyRound, LockKeyhole, LogIn, Settings2, ShieldAlert, UserCheck, Users } from 'lucide-react';
import { Alert, Button, Dropdown, Modal, PageHeader, Stagger, StatTile, Tabs, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { useApi } from '@/lib/queries';
import { ActivityTab } from './ActivityTab';
import { FacultyTab } from './FacultyTab';
import { StudentsTab } from './StudentsTab';
import type { PortalSettings, PortalSummary } from './types';

const TABS = ['students', 'faculty', 'activity'] as const;
type Tab = (typeof TABS)[number];

function SettingsModal({ open, onClose, settings, onSaved }: { open: boolean; onClose: () => void; settings: PortalSettings | undefined; onSaved: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState<PortalSettings>({ portal_student_enabled: true, portal_faculty_enabled: true, portal_self_activation: true });
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (open && settings) setForm(settings);
  }, [open, settings]);
  const save = async () => {
    setBusy(true);
    try {
      const res = await api.post('portal-access/settings', form);
      toast.success(res.message);
      onSaved();
      onClose();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Unable to save the settings.');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      title="Portal settings"
      description="Switch the portals on or off for maintenance and control self-service activation."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={() => void save()} loading={busy}>
            Save settings
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <Toggle checked={form.portal_student_enabled} onChange={(v) => setForm((f) => ({ ...f, portal_student_enabled: v }))} label="Student portal" description="When off, students cannot sign in and signed-in students are signed out." />
        <Toggle checked={form.portal_self_activation} onChange={(v) => setForm((f) => ({ ...f, portal_self_activation: v }))} label="Self-service activation" description="Students activate their own account with Student ID + date of birth + registered email or mobile." />
        <Toggle checked={form.portal_faculty_enabled} onChange={(v) => setForm((f) => ({ ...f, portal_faculty_enabled: v }))} label="Faculty portal" description="When off, faculty cannot use the portal (their admin-panel access is not affected)." />
        <p className="text-xs text-slate-500 dark:text-slate-400">Password rules, lockout attempts and the session timeout are shared with the admin panel — see Settings › Security.</p>
      </div>
    </Modal>
  );
}

export default function PortalAccessPage() {
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'students';
  const qc = useQueryClient();
  const { data: summary, isLoading, error, refetch } = useApi<PortalSummary>(['portal-access', 'summary'], 'portal-access/summary');
  const [settingsOpen, setSettingsOpen] = useState(false);
  const refreshSummary = () => void qc.invalidateQueries({ queryKey: ['portal-access', 'summary'] });
  const s = summary?.students;
  const f = summary?.faculty;
  const off = summary && (!summary.settings.portal_student_enabled || !summary.settings.portal_faculty_enabled);

  return (
    <>
      <PageHeader
        title="Portal Access"
        description="Student and faculty portal logins — generate accounts, reset passwords, unlock and review sign-in activity."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'System' }, { label: 'Portal Access' }]}
        actions={
          <>
            <Button variant="secondary" icon={Settings2} onClick={() => setSettingsOpen(true)} disabled={!summary}>
              Settings
            </Button>
            <Dropdown
              label="Open a portal"
              triggerClassName="btn btn-secondary"
              trigger={
                <>
                  <ExternalLink className="h-4 w-4" aria-hidden /> Open portal <ChevronDown className="h-4 w-4 opacity-60" aria-hidden />
                </>
              }
              items={[
                { label: 'Student sign-in page', icon: GraduationCap, href: summary?.urls.student, target: '_blank', disabled: !summary },
                { label: 'Faculty sign-in page', icon: Users, href: summary?.urls.faculty, target: '_blank', disabled: !summary },
              ]}
            />
          </>
        }
      />

      {error ? (
        <Alert variant="error" className="mb-5" action={<Button size="xs" variant="secondary" onClick={() => void refetch()}>Retry</Button>}>
          {error instanceof ApiError ? error.message : 'Unable to load the portal summary.'}
        </Alert>
      ) : null}
      {summary && off && (
        <Alert variant="warning" className="mb-5" title="A portal is switched off">
          {!summary.settings.portal_student_enabled && 'The student portal is off. '}
          {!summary.settings.portal_faculty_enabled && 'The faculty portal is off. '}
          Users see a maintenance message on the sign-in page.
        </Alert>
      )}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6" step={50}>
        {[
          <StatTile key="acc" label="Student accounts" icon={GraduationCap} tone="navy" loading={isLoading} value={s ? `${formatNumber(s.accounts)} / ${formatNumber(s.total)}` : 0} sub={s ? `${formatNumber(s.without)} without an account` : undefined} />,
          <StatTile key="act" label="Activated" icon={UserCheck} tone="green" loading={isLoading} value={s?.activated ?? 0} sub={s ? `${formatNumber(s.self_activated)} self-activated` : undefined} />,
          <StatTile key="lock" label="Locked / disabled" icon={LockKeyhole} tone="red" loading={isLoading} value={s ? s.locked + s.disabled + (f?.locked ?? 0) : 0} sub={s ? `${s.must_change} on temporary password` : undefined} />,
          <StatTile key="fac" label="Faculty logins" icon={Users} tone="purple" loading={isLoading} value={f ? `${formatNumber(f.linked)} / ${formatNumber(f.total)}` : 0} sub={f ? `${formatNumber(f.without)} without a login` : undefined} />,
          <StatTile key="today" label="Sign-ins today" icon={LogIn} tone="cyan" loading={isLoading} value={summary ? summary.today.student_logins + summary.today.faculty_logins : 0} sub={summary ? `${summary.today.student_logins} students · ${summary.today.faculty_logins} faculty` : undefined} />,
          <StatTile key="fail" label="Failed today" icon={ShieldAlert} tone="orange" loading={isLoading} value={summary?.today.failed ?? 0} sub={s ? `${formatNumber(s.recent)} students active this week` : undefined} />,
        ]}
      </Stagger>

      <Tabs
        className="mb-4"
        value={tab}
        onChange={(t) => setParams({ tab: t }, { replace: true })}
        tabs={[
          { key: 'students', label: 'Students', icon: GraduationCap, count: s ? formatNumber(s.accounts) : undefined },
          { key: 'faculty', label: 'Faculty', icon: KeyRound, count: f ? formatNumber(f.linked) : undefined },
          { key: 'activity', label: 'Login activity', icon: History },
        ]}
      />
      {tab === 'students' && <StudentsTab portalUrl={summary?.urls.student ?? ''} onChanged={refreshSummary} />}
      {tab === 'faculty' && <FacultyTab portalUrl={summary?.urls.faculty ?? ''} onChanged={refreshSummary} />}
      {tab === 'activity' && <ActivityTab />}

      <SettingsModal open={settingsOpen} onClose={() => setSettingsOpen(false)} settings={summary?.settings} onSaved={refreshSummary} />
    </>
  );
}
