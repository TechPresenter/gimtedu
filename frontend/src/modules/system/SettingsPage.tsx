import { useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  ArrowRight, Building2, CalendarCheck, ChartLine, CheckCircle2, CreditCard, Database, Globe, GraduationCap, Mail, MessageSquare, RotateCcw, Save, Send, Share2,
  ShieldCheck, Smartphone, type LucideIcon,
} from 'lucide-react';
import { Alert, Badge, Button, Card, Field, Input, PageHeader, Reveal, Select, Skeleton, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useApi } from '@/lib/queries';
import { formatDate, formatDateTime, timeAgo } from '@/lib/format';
import { SettingInput, colSpan, type SettingField } from './settings/SettingInput';

interface SettingSection {
  key: string;
  title: string;
  description?: string;
  type?: 'session' | 'backup';
  test?: 'email' | 'sms' | 'whatsapp';
  fields: SettingField[];
}
interface SettingGroup {
  key: string;
  title: string;
  icon: string;
  description: string;
  sections: SettingSection[];
  values: Record<string, string>;
  secrets: Record<string, boolean>;
}
interface Session {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  is_current: boolean;
  admissions_open: boolean;
  status: string;
}
interface Payload {
  groups: SettingGroup[];
  sessions: Session[];
  current_session_id: number | null;
  mail: { driver: string; effective_driver: string; dev: boolean; sent_30d: number; failed_30d: number };
  backup: { last: { created_at: string; status: string; size_bytes: number } | null; next_due: string | null };
  can_edit: boolean;
}

const icons: Record<string, LucideIcon> = {
  'building-2': Building2, globe: Globe, 'graduation-cap': GraduationCap, mail: Mail, 'credit-card': CreditCard, 'shield-check': ShieldCheck, database: Database,
  'chart-line': ChartLine, 'share-2': Share2,
};
const groupTone: Record<string, string> = {
  general: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300', website: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300',
  academic: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300', email: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
  payment: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300', security: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300',
  backup: 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200', analytics: 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300',
  social: 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300',
};

export default function SettingsPage() {
  const { refresh: refreshSession } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const { data, isLoading, error, refetch } = useApi<Payload>(['settings'], 'settings');
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [files, setFiles] = useState<Record<string, File | null>>({});
  const [removed, setRemoved] = useState<Record<string, boolean>>({});
  const [secretEdit, setSecretEdit] = useState<Record<string, boolean>>({});
  const [secretClear, setSecretClear] = useState<Record<string, boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);

  const tab = params.get('tab') ?? 'general';
  const group = data?.groups.find((g) => g.key === tab) ?? data?.groups[0];
  const readOnly = !data?.can_edit;
  const val = (g: SettingGroup, k: string) => drafts[k] ?? g.values[k] ?? '';
  const sectionDirty = (g: SettingGroup, s: SettingSection) =>
    s.fields.some((f) => (drafts[f.key] !== undefined && drafts[f.key] !== (g.values[f.key] ?? '')) || !!files[f.key] || !!removed[f.key] || !!secretClear[f.key]);
  const groupDirty = (g?: SettingGroup) => !!g && g.sections.some((s) => sectionDirty(g, s));

  const resetKeys = (keys: string[]) => {
    const drop = <T,>(o: Record<string, T>) => Object.fromEntries(Object.entries(o).filter(([k]) => !keys.includes(k)));
    setDrafts(drop);
    setFiles(drop);
    setRemoved(drop);
    setSecretEdit(drop);
    setSecretClear(drop);
    setErrors(drop);
  };

  const goTab = async (key: string) => {
    if (key === group?.key) return;
    if (groupDirty(group) && !(await confirm({ title: 'Discard unsaved changes?', message: `You have unsaved changes in ${group?.title} settings.`, confirmText: 'Discard', danger: true }))) return;
    if (group) resetKeys(group.sections.flatMap((s) => s.fields.map((f) => f.key)));
    setParams({ tab: key }, { replace: true });
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  };

  const save = async (g: SettingGroup, s: SettingSection) => {
    const payload: Record<string, unknown> = {};
    const clear: string[] = [];
    let hasFile = false;
    s.fields.forEach((f) => {
      if (f.type === 'image') {
        if (files[f.key]) {
          payload[f.key] = files[f.key];
          hasFile = true;
        } else if (removed[f.key]) payload[`${f.key}__remove`] = 1;
        return;
      }
      if (f.secret) {
        if (secretClear[f.key]) clear.push(f.key);
        else if (drafts[f.key]) payload[f.key] = drafts[f.key];
        return;
      }
      payload[f.key] = val(g, f.key);
    });
    if (clear.length) payload.__clear = clear;
    setSaving(s.key);
    try {
      const res = await api.post<{ group: SettingGroup; saved: string[] }>(`settings/${g.key}`, hasFile ? toFormData(payload) : payload);
      qc.setQueryData<Payload>(['settings'], (old) => (old ? { ...old, groups: old.groups.map((x) => (x.key === g.key ? res.data.group : x)) } : old));
      resetKeys(s.fields.map((f) => f.key));
      toast.success(res.message);
      if (['general', 'website', 'security'].includes(g.key)) void refreshSession();
      if (g.key === 'backup') void qc.invalidateQueries({ queryKey: ['backup-overview'] });
    } catch (e) {
      const ex = e as ApiError;
      setErrors((x) => ({ ...x, ...(ex.errors ?? {}) }));
      toast.error(ex.message || 'Unable to save settings. Please try again.');
    } finally {
      setSaving(null);
    }
  };

  return (
    <>
      <PageHeader
        title="System Settings"
        description="Configure the institute profile, website branding, academics, messaging, payments, security, backups and integrations."
        breadcrumbs={[{ label: 'System' }, { label: 'Settings' }, ...(group ? [{ label: group.title }] : [])]}
      />
      {error ? (
        <Alert variant="error" title="Unable to load settings" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
          {/* Section navigation */}
          <nav aria-label="Settings sections" className="lg:sticky lg:top-20 lg:h-fit">
            <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:px-0 lg:hidden">
              {(data?.groups ?? []).map((g) => {
                const Icon = icons[g.icon] ?? Building2;
                return (
                  <button key={g.key} type="button" onClick={() => void goTab(g.key)} aria-current={g.key === group?.key ? 'page' : undefined}
                    className={clsx('inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition', g.key === group?.key ? 'border-brand-800 bg-brand-800 text-white' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300')}>
                    <Icon className="h-3.5 w-3.5" /> {g.title}
                  </button>
                );
              })}
            </div>
            <Card className="hidden p-2 lg:block">
              {isLoading && Array.from({ length: 9 }).map((_, i) => <Skeleton key={i} className="mb-1.5 h-14 w-full rounded-xl" />)}
              {data?.groups.map((g) => {
                const Icon = icons[g.icon] ?? Building2;
                const active = g.key === group?.key;
                return (
                  <button
                    key={g.key}
                    type="button"
                    onClick={() => void goTab(g.key)}
                    aria-current={active ? 'page' : undefined}
                    className={clsx('group relative mb-0.5 flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition duration-150', active ? 'bg-brand-50 dark:bg-brand-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50')}
                  >
                    <span className={clsx('absolute left-0 top-1/2 h-7 w-1 -translate-y-1/2 rounded-r-full bg-brand-700 transition-all duration-200 dark:bg-brand-300', active ? 'opacity-100' : 'scale-y-0 opacity-0')} />
                    <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition group-hover:scale-105', groupTone[g.key])}>
                      <Icon className="h-4 w-4" />
                    </span>
                    <span className="min-w-0">
                      <span className={clsx('flex items-center gap-1.5 text-sm font-semibold', active ? 'text-brand-900 dark:text-white' : 'text-slate-700 dark:text-slate-200')}>
                        {g.title}
                        {groupDirty(g) && <span className="h-1.5 w-1.5 rounded-full bg-amber-500" title="Unsaved changes" />}
                      </span>
                      <span className="line-clamp-2 text-[11px] leading-snug text-slate-500 dark:text-slate-400">{g.description}</span>
                    </span>
                  </button>
                );
              })}
            </Card>
          </nav>

          {/* Content */}
          <div className="min-w-0 space-y-6">
            {isLoading || !data || !group ? (
              Array.from({ length: 3 }).map((_, i) => (
                <Card key={i} className="space-y-4 p-5">
                  <Skeleton className="h-5 w-1/3" />
                  <div className="grid grid-cols-2 gap-4">{Array.from({ length: 6 }).map((__, j) => <Skeleton key={j} className="h-10 w-full" />)}</div>
                </Card>
              ))
            ) : (
              <div key={group.key} className="space-y-6 motion-safe:animate-slide-up">
                <div className="flex items-center gap-3">
                  <span className={clsx('kpi-icon', groupTone[group.key])}>{(() => { const I = icons[group.icon] ?? Building2; return <I className="h-5 w-5" />; })()}</span>
                  <div>
                    <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{group.title} settings</h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">{group.description}</p>
                  </div>
                </div>
                {readOnly && <Alert variant="info">You can view these settings but your role cannot change them.</Alert>}
                {group.key === 'email' && data.mail.dev && (
                  <Alert variant="warning" title="Development mode">
                    Outgoing email is written to <code className="font-mono text-xs">storage/logs/mail.log</code> instead of being delivered, and SMS / WhatsApp messages are only recorded in message logs.
                  </Alert>
                )}
                {group.sections.map((s, i) => (
                  <Reveal key={s.key} delay={Math.min(i, 4) * 60}>
                    <SectionCard
                      group={group}
                      section={s}
                      data={data}
                      readOnly={readOnly}
                      dirty={sectionDirty(group, s)}
                      saving={saving === s.key}
                      onSave={() => save(group, s)}
                      onReset={() => resetKeys(s.fields.map((f) => f.key))}
                      onSessionChanged={() => {
                        void refetch();
                        void refreshSession();
                      }}
                    >
                      {s.fields.length > 0 && (
                        <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">
                          {s.fields.map((f) => (
                            <Field
                              key={f.key}
                              className={clsx('col-span-1', colSpan[f.col ?? 6] ?? 'sm:col-span-6')}
                              label={f.type === 'toggle' ? undefined : f.label}
                              required={f.required && f.type !== 'checkboxes' ? true : undefined}
                              htmlFor={`s-${f.key}`}
                              error={errors[f.key]}
                              hint={f.type === 'toggle' ? undefined : f.help}
                            >
                              <SettingInput
                                field={f}
                                value={val(group, f.key)}
                                onChange={(v) => {
                                  setDrafts((d) => ({ ...d, [f.key]: v }));
                                  if (errors[f.key]) setErrors((x) => ({ ...x, [f.key]: '' }));
                                }}
                                invalid={!!errors[f.key]}
                                disabled={readOnly}
                                secretStored={group.secrets[f.key]}
                                secretEditing={secretEdit[f.key]}
                                secretCleared={secretClear[f.key]}
                                onSecretEdit={(on) => {
                                  setSecretEdit((x) => ({ ...x, [f.key]: on }));
                                  setSecretClear((x) => ({ ...x, [f.key]: false }));
                                  if (!on) setDrafts((d) => Object.fromEntries(Object.entries(d).filter(([k]) => k !== f.key)));
                                }}
                                onSecretClear={() => setSecretClear((x) => ({ ...x, [f.key]: true }))}
                                file={files[f.key]}
                                onFile={(fl) => {
                                  setFiles((x) => ({ ...x, [f.key]: fl }));
                                  if (fl) setRemoved((x) => ({ ...x, [f.key]: false }));
                                }}
                                removed={removed[f.key]}
                                onRemove={() => setRemoved((x) => ({ ...x, [f.key]: true }))}
                                onError={(m) => setErrors((x) => ({ ...x, [f.key]: m }))}
                              />
                            </Field>
                          ))}
                        </div>
                      )}
                      {s.key === 'colors' && <BrandPreview primary={val(group, 'primary_color')} accent={val(group, 'accent_color')} secondary={val(group, 'secondary_color')} heading={val(group, 'heading_font')} />}
                    </SectionCard>
                  </Reveal>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}

interface SectionCardProps {
  group: SettingGroup;
  section: SettingSection;
  data: Payload;
  readOnly: boolean;
  dirty: boolean;
  saving: boolean;
  onSave: () => void;
  onReset: () => void;
  onSessionChanged: () => void;
  children: ReactNode;
}

function SectionCard({ group, section: s, data, readOnly, dirty, saving, onSave, onReset, onSessionChanged, children }: SectionCardProps) {
  return (
    <Card className={clsx('overflow-hidden transition-shadow duration-300', dirty && 'ring-2 ring-amber-300/60 dark:ring-amber-500/30')}>
      <div className="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
        <h3 className="card-title">{s.title}</h3>
        {s.description && <p className="card-subtitle">{s.description}</p>}
      </div>
      <div className="space-y-5 p-5">
        {s.type === 'session' && <SessionBlock data={data} readOnly={readOnly} onChanged={onSessionChanged} />}
        {children}
        {s.type === 'backup' && <BackupInfo data={data} />}
        {s.test === 'email' && <TestEmailPanel data={data} disabled={readOnly} />}
        {(s.test === 'sms' || s.test === 'whatsapp') && <TestSmsPanel channel={s.test} enabled={group.values[`${s.test}_enabled`] === '1'} disabled={readOnly} />}
      </div>
      {s.fields.length > 0 && !readOnly && (
        <div className="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-800/20">
          <p className={clsx('text-xs transition-opacity', dirty ? 'text-amber-700 opacity-100 dark:text-amber-300' : 'text-slate-400')}>{dirty ? 'You have unsaved changes in this section.' : 'All changes saved.'}</p>
          <div className="flex gap-2">
            <Button size="sm" variant="ghost" icon={RotateCcw} onClick={onReset} disabled={!dirty || saving}>
              Reset
            </Button>
            <Button size="sm" icon={Save} onClick={onSave} loading={saving} disabled={!dirty}>
              Save {s.title.toLowerCase()}
            </Button>
          </div>
        </div>
      )}
    </Card>
  );
}

function SessionBlock({ data, readOnly, onChanged }: { data: Payload; readOnly: boolean; onChanged: () => void }) {
  const toast = useToast();
  const [sel, setSel] = useState<string>(String(data.current_session_id ?? ''));
  const [busy, setBusy] = useState(false);
  const current = data.sessions.find((s) => s.id === data.current_session_id) ?? null;
  const chosen = data.sessions.find((s) => String(s.id) === sel) ?? null;
  const apply = async () => {
    setBusy(true);
    try {
      const res = await api.post('settings/academic-session', { id: Number(sel) });
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-[1fr_auto] md:items-end">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Current academic session" htmlFor="s-session">
          <Select id="s-session" value={sel} onChange={(e) => setSel(e.target.value)} options={data.sessions.map((s) => ({ value: String(s.id), label: `${s.name}${s.is_current ? ' (current)' : ''}` }))} disabled={readOnly} />
        </Field>
        <div className="rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm dark:border-slate-700">
          {chosen ? (
            <>
              <p className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                <CalendarCheck className="h-4 w-4 text-brand-700 dark:text-brand-300" /> {chosen.name}
                <StatusBadge status={chosen.status} />
                {chosen.admissions_open && <Badge color="green">Admissions open</Badge>}
              </p>
              <p className="mt-0.5 text-xs text-slate-500">
                {formatDate(chosen.start_date)} – {formatDate(chosen.end_date)}
              </p>
            </>
          ) : (
            <p className="text-slate-500">No sessions defined yet.</p>
          )}
        </div>
      </div>
      <div className="flex flex-wrap gap-2">
        {!readOnly && (
          <Button variant="success" icon={CheckCircle2} onClick={apply} loading={busy} disabled={!sel || String(current?.id ?? '') === sel}>
            Make current
          </Button>
        )}
        <Button variant="secondary" iconRight={ArrowRight} to="/academics?tab=sessions">
          Manage sessions
        </Button>
      </div>
    </div>
  );
}

function BackupInfo({ data }: { data: Payload }) {
  const last = data.backup.last;
  return (
    <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700 dark:bg-slate-800/30">
      <div className="text-sm">
        <p className="font-medium text-slate-800 dark:text-slate-100">
          Last database backup: {last ? <>{formatDateTime(last.created_at)} <span className="text-slate-500">({timeAgo(last.created_at)})</span></> : 'never'}
          {last && <span className="ml-2 align-middle"><StatusBadge status={last.status} /></span>}
        </p>
        <p className="text-xs text-slate-500">{data.backup.next_due ? `Next scheduled backup: ${formatDateTime(data.backup.next_due)}` : 'Automatic backups are off.'}</p>
      </div>
      <Button size="sm" variant="secondary" iconRight={ArrowRight} to="/backup">
        Open Backup & Restore
      </Button>
    </div>
  );
}

function TestEmailPanel({ data, disabled }: { data: Payload; disabled: boolean }) {
  const { session } = useAuth();
  const toast = useToast();
  const [to, setTo] = useState(session?.user?.email ?? '');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const send = async () => {
    setBusy(true);
    setResult(null);
    try {
      const res = await api.post('settings/test-email', { to });
      setResult({ ok: true, message: res.message });
      toast.success('Test email processed.');
    } catch (e) {
      const ex = e as ApiError;
      setResult({ ok: false, message: ex.errors?.to ?? ex.message });
    } finally {
      setBusy(false);
    }
  };
  const driverLabel: Record<string, string> = { smtp: 'SMTP server', mail: 'PHP mail()', log: 'Log file (no delivery)' };
  return (
    <div className="rounded-xl border border-dashed border-brand-200 bg-brand-50/40 p-4 dark:border-brand-500/30 dark:bg-brand-500/5">
      <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <Send className="h-4 w-4 text-brand-700 dark:text-brand-300" />
        <span className="font-semibold text-slate-900 dark:text-white">Send a test email</span>
        <Badge color="navy">Delivery: {driverLabel[data.mail.effective_driver] ?? data.mail.effective_driver}</Badge>
        <span className="text-xs text-slate-500">
          Last 30 days: {data.mail.sent_30d} sent · {data.mail.failed_30d} failed
        </span>
      </div>
      <p className="mb-3 text-xs text-slate-500">Save your changes first — the test uses the stored configuration.</p>
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input type="email" value={to} onChange={(e) => setTo(e.target.value)} placeholder="recipient@example.com" aria-label="Test email recipient" disabled={disabled} className="sm:max-w-xs" />
        <Button variant="secondary" icon={Mail} onClick={send} loading={busy} disabled={disabled || !to}>
          Send test email
        </Button>
      </div>
      {result && (
        <Alert variant={result.ok ? 'success' : 'error'} className="mt-3 motion-safe:animate-slide-up">
          {result.message}
        </Alert>
      )}
    </div>
  );
}

function TestSmsPanel({ channel, enabled, disabled }: { channel: 'sms' | 'whatsapp'; enabled: boolean; disabled: boolean }) {
  const toast = useToast();
  const label = channel === 'sms' ? 'SMS' : 'WhatsApp';
  const [phone, setPhone] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const send = async () => {
    setBusy(true);
    setResult(null);
    try {
      const res = await api.post('settings/test-sms', { channel, phone });
      setResult({ ok: true, message: res.message });
      toast.success(`Test ${label} processed.`);
    } catch (e) {
      const ex = e as ApiError;
      setResult({ ok: false, message: ex.errors?.phone ?? ex.message });
    } finally {
      setBusy(false);
    }
  };
  const Icon = channel === 'sms' ? Smartphone : MessageSquare;
  return (
    <div className="rounded-xl border border-dashed border-slate-200 p-4 dark:border-slate-700">
      <div className="mb-3 flex items-center gap-2 text-sm">
        <Icon className="h-4 w-4 text-slate-500" />
        <span className="font-semibold text-slate-900 dark:text-white">Send a test {label}</span>
        {!enabled && <span className="text-xs text-slate-500">— enable and save the gateway first</span>}
      </div>
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+91 98xxxxxxxx" aria-label={`Test ${label} number`} disabled={disabled || !enabled} className="sm:max-w-xs" />
        <Button variant="secondary" icon={Send} onClick={send} loading={busy} disabled={disabled || !enabled || !phone}>
          Send test {label}
        </Button>
      </div>
      {result && (
        <Alert variant={result.ok ? 'success' : 'error'} className="mt-3 motion-safe:animate-slide-up">
          {result.message}
        </Alert>
      )}
    </div>
  );
}

/** Live preview of the website brand colours. */
function BrandPreview({ primary, accent, secondary, heading }: { primary: string; accent: string; secondary: string; heading: string }) {
  const ok = (c: string, f: string) => (/^#[0-9a-f]{6}$/i.test(c) ? c : f);
  const p = ok(primary, '#0B2A5B');
  const a = ok(accent, '#22943F');
  const s = ok(secondary, '#1D4ED8');
  const font = useMemo(() => `"${heading || 'Plus Jakarta Sans'}", Inter, sans-serif`, [heading]);
  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700" aria-label="Brand preview">
      <div className="flex items-center justify-between px-4 py-2.5 text-white" style={{ background: p }}>
        <span className="text-sm font-bold" style={{ fontFamily: font }}>GLOBAL IMT</span>
        <span className="rounded-lg px-3 py-1 text-xs font-semibold text-white shadow-sm" style={{ background: a }}>Apply Now →</span>
      </div>
      <div className="bg-white px-4 py-4 dark:bg-slate-900">
        <p className="text-base font-extrabold" style={{ color: p, fontFamily: font }}>Shaping Future Leaders</p>
        <p className="mt-1 text-xs text-slate-500">Preview of headings, links and buttons with the selected colours.</p>
        <div className="mt-3 flex flex-wrap items-center gap-3">
          <span className="rounded-lg px-3 py-1.5 text-xs font-semibold text-white transition hover:-translate-y-0.5" style={{ background: a }}>Apply for Admission</span>
          <span className="rounded-lg border px-3 py-1.5 text-xs font-semibold" style={{ borderColor: p, color: p }}>Explore Programs</span>
          <span className="text-xs font-semibold underline" style={{ color: s }}>View placement report</span>
        </div>
      </div>
    </div>
  );
}
