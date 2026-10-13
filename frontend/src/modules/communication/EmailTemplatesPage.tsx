import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Copy, Eye, FileText, FlaskConical, Layers, Lock, Mail, MessageSquareText, Plus, Power, PowerOff, ShieldCheck } from 'lucide-react';
import { Alert, Badge, Button, PageHeader, StatCard, Stagger, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ChannelBadge, useCommRefresh } from './shared';
import { TemplateEditor } from './templates/TemplateEditor';
import { TemplatePreviewModal, TemplateTestDialog } from './templates/TemplatePreview';

interface TemplateSummary {
  total: number;
  active: number;
  email: number;
  sms: number;
  whatsapp: number;
  system: number;
  used_in_campaigns: number;
}

export default function EmailTemplatesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCommRefresh();
  const [params, setParams] = useSearchParams();
  const tab = ['email', 'sms', 'whatsapp'].includes(String(params.get('channel'))) ? String(params.get('channel')) : 'all';
  const { data: s, isLoading, error } = useApi<TemplateSummary>(['comm', 'templates-summary'], 'communication/templates/summary');
  const [editor, setEditor] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });
  const [previewId, setPreviewId] = useState<number | null>(null);
  const [testing, setTesting] = useState<{ id: number; name: string; channel: string } | null>(null);

  const setTab = (k: string) => {
    const next = new URLSearchParams(params);
    if (k === 'all') next.delete('channel');
    else next.set('channel', k);
    next.delete('page');
    setParams(next, { replace: true });
  };

  const quick = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh('crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const setStatus = async (r: Row, status: 'active' | 'inactive') => {
    if (status === 'inactive' && r.is_system) {
      const ok = await confirm({
        title: 'Deactivate a system template?',
        message: (
          <>
            <strong>{String(r.name)}</strong> ({String(r.code)}) is sent automatically by the ERP. While it is inactive those e-mails go out as a bare fallback message without this wording.
          </>
        ),
        confirmText: 'Deactivate',
        danger: true,
      });
      if (!ok) return;
    }
    await quick(() => api.post(`crud/email_templates/${r.id}`, { status }));
  };

  const duplicate = async (r: Row) => {
    try {
      const res = await api.post<{ id: number }>(`communication/templates/${r.id}/duplicate`);
      toast.success(res.message);
      await refresh('crud');
      setEditor({ open: true, id: res.data.id });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tabs = [
    { key: 'all', label: 'All templates', count: s?.total },
    { key: 'email', label: 'E-mail', count: s?.email },
    { key: 'sms', label: 'SMS', count: s?.sms },
    { key: 'whatsapp', label: 'WhatsApp', count: s?.whatsapp },
  ];

  return (
    <>
      <PageHeader
        title="Message Templates"
        description="E-mail, SMS and WhatsApp templates used for admissions, fees, exams, notices, events and newsletters. Use {{placeholders}} for personal details."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Newsletter / Email', to: '/newsletter' }, { label: 'Email Templates' }]}
        actions={
          can('communication', 'create') && (
            <Button icon={Plus} onClick={() => setEditor({ open: true, id: null })}>
              New template
            </Button>
          )
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load template statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
        {[
          <StatCard key="total" label="Templates" icon={FileText} tone="navy" loading={isLoading} value={s?.total ?? 0} hint={s ? `${s.active} active` : undefined} />,
          <StatCard key="sys" label="System templates" icon={ShieldCheck} tone="blue" loading={isLoading} value={s?.system ?? 0} hint="Sent automatically by the ERP" />,
          <StatCard key="email" label="E-mail" icon={Mail} tone="green" loading={isLoading} value={s?.email ?? 0} hint="HTML templates" />,
          <StatCard key="sms" label="SMS & WhatsApp" icon={MessageSquareText} tone="purple" loading={isLoading} value={(s?.sms ?? 0) + (s?.whatsapp ?? 0)} hint="Plain-text templates" />,
          <StatCard key="used" label="Used in campaigns" icon={Layers} tone="orange" loading={isLoading} value={s?.used_in_campaigns ?? 0} hint="Templates behind campaigns" />,
        ]}
      </Stagger>

      <Tabs tabs={tabs} value={tab} onChange={setTab} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="email_templates"
        urlState
        scope={tab === 'all' ? undefined : { channel: tab }}
        title={tab === 'all' ? 'All templates' : `${tabs.find((t) => t.key === tab)?.label} templates`}
        addLabel="New template"
        emptyTitle="No templates here"
        emptyText="Create a template once and reuse it for campaigns, notices and automatic messages."
        onCreate={() => setEditor({ open: true, id: null })}
        onEdit={(r) => setEditor({ open: true, id: Number(r.id) })}
        onView={(r) => setPreviewId(Number(r.id))}
        renderers={{
          name: (r) => (
            <button type="button" onClick={() => setPreviewId(Number(r.id))} className="block max-w-[15rem] text-left leading-tight">
              <span className="flex items-center gap-1.5">
                <span className="truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.name)}</span>
                {r.is_system ? <Lock className="h-3 w-3 shrink-0 text-slate-400" aria-label="System template" /> : null}
              </span>
              <span className="flex items-center gap-1.5">
                <code className="text-[11px] text-slate-500 dark:text-slate-400">{String(r.code)}</code>
                {r.is_system ? <span className="rounded bg-brand-50 px-1 text-[10px] font-semibold uppercase tracking-wide text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">System</span> : null}
              </span>
            </button>
          ),
          channel: (r) => <ChannelBadge channel={String(r.channel)} />,
          variables: (r) => {
            const vars = String(r.variables ?? '').split(',').filter(Boolean);
            if (!vars.length) return <span className="text-xs text-slate-400">None</span>;
            return (
              <div className="flex max-w-[12rem] flex-wrap gap-1">
                {vars.slice(0, 3).map((v) => <Badge key={v} color="slate" className="font-mono !text-[10px]">{v}</Badge>)}
                {vars.length > 3 && <span className="text-[11px] text-slate-500">+{vars.length - 3}</span>}
              </div>
            );
          },
          updated_at: (r) => <span className="whitespace-nowrap text-sm">{timeAgo(r.updated_at ?? r.created_at)}</span>,
          subject: (r) => <span className="block max-w-[13rem] truncate text-sm" title={String(r.subject ?? '')}>{String(r.subject ?? '—')}</span>,
          status: (r) => <StatusBadge status={String(r.status)} />,
        }}
        rowMenu={(r) => [
          { label: 'Preview', icon: Eye, onClick: () => setPreviewId(Number(r.id)) },
          can('communication', 'edit') && { label: 'Send a test', icon: FlaskConical, onClick: () => setTesting({ id: Number(r.id), name: String(r.name), channel: String(r.channel) }) },
          can('communication', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => void duplicate(r) },
          can('communication', 'edit') && r.status === 'active' && { label: 'Deactivate', icon: PowerOff, onClick: () => void setStatus(r, 'inactive') },
          can('communication', 'edit') && r.status !== 'active' && { label: 'Activate', icon: Power, onClick: () => void setStatus(r, 'active') },
        ]}
      />

      <TemplateEditor
        open={editor.open}
        id={editor.id}
        onClose={() => setEditor({ open: false, id: null })}
        onSaved={() => {
          setEditor({ open: false, id: null });
          void refresh('crud');
        }}
      />
      <TemplatePreviewModal
        id={previewId}
        onClose={() => setPreviewId(null)}
        onEdit={(id) => {
          setPreviewId(null);
          setEditor({ open: true, id });
        }}
        onTest={(t) => setTesting(t)}
      />
      <TemplateTestDialog template={testing} onClose={() => setTesting(null)} />
    </>
  );
}
