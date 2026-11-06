import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { CheckCircle2, FileText, Image as ImageIcon, LayoutTemplate, Plus, ShieldCheck, Users } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, SearchInput, Tabs } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { WaBubble, WaPhoneFrame } from '../shared';
import { samplePreview, templateTitle, type Draft } from './lib';
import type { CampaignMeta, CampaignTemplate } from './types';
import { CategoryBadge } from './ui';

export function StepTemplate({ draft, patch, errors, meta }: { draft: Draft; patch: (p: Partial<Draft>) => void; errors: Record<string, string>; meta: CampaignMeta }) {
  const { can } = useAuth();
  const suggested = meta.types.find((t) => t.value === draft.type)?.category ?? 'MARKETING';
  const [tab, setTab] = useState<'suggested' | 'all' | 'MARKETING' | 'UTILITY'>('suggested');
  const [q, setQ] = useState('');
  const selected = meta.templates.find((t) => t.id === draft.template_id) ?? null;

  const list = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return meta.templates.filter((t) => {
      if (tab === 'suggested' && t.category !== suggested) return false;
      if ((tab === 'MARKETING' || tab === 'UTILITY') && t.category !== tab) return false;
      if (needle && !`${t.name} ${t.display_name ?? ''} ${t.body}`.toLowerCase().includes(needle)) return false;
      return true;
    });
  }, [meta.templates, tab, q, suggested]);

  const count = (cat?: string) => meta.templates.filter((t) => !cat || t.category === cat).length;

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
      <Card className="min-w-0">
        <CardHeader
          title="Choose an approved template"
          subtitle="Only templates approved by WhatsApp can start a conversation. Edit or create templates in the Template Library."
          actions={
            can('whatsapp_templates', 'create') ? (
              <Button size="sm" variant="secondary" icon={Plus} to="/whatsapp/templates/designer">
                New template
              </Button>
            ) : undefined
          }
        />
        <div className="space-y-3 border-b border-slate-100 px-5 pb-4 dark:border-slate-800">
          <Tabs
            variant="pills"
            value={tab}
            onChange={(k) => setTab(k as typeof tab)}
            tabs={[
              { key: 'suggested', label: `Suggested (${suggested === 'MARKETING' ? 'Marketing' : 'Utility'})`, count: count(suggested) },
              { key: 'all', label: 'All', count: count() },
              { key: 'MARKETING', label: 'Marketing', count: count('MARKETING') },
              { key: 'UTILITY', label: 'Utility', count: count('UTILITY') },
            ]}
          />
          <SearchInput value={q} onChange={setQ} placeholder="Search templates by name or text…" />
          {errors.template_id && <Alert variant="error">{errors.template_id}</Alert>}
        </div>
        {list.length === 0 ? (
          <EmptyState
            icon={LayoutTemplate}
            title={meta.templates.length ? 'No template matches' : 'No approved templates yet'}
            description={meta.templates.length ? 'Try another tab or search.' : 'Design a template and submit it to WhatsApp for approval — approval usually takes a few minutes to a day.'}
            action={
              can('whatsapp_templates', 'create') ? (
                <Button icon={Plus} to="/whatsapp/templates/designer">
                  Design a template
                </Button>
              ) : undefined
            }
          />
        ) : (
          <div role="radiogroup" aria-label="Templates" className="grid max-h-[40rem] grid-cols-1 gap-3 overflow-y-auto p-5 md:grid-cols-2">
            {list.map((t) => (
              <TemplateCard key={t.id} t={t} active={t.id === draft.template_id} onPick={() => patch({ template_id: t.id })} />
            ))}
          </div>
        )}
      </Card>

      <div className="space-y-4 lg:sticky lg:top-20 lg:self-start">
        {selected ? (
          <>
            <WaPhoneFrame height={460} subtitle="Preview with sample values">
              <WaBubble direction="out" type="template" template={samplePreview(selected)} status="read" time={null} showStatus={false} />
            </WaPhoneFrame>
            <Card className="p-4">
              <p className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                {templateTitle(selected)} <CategoryBadge category={selected.category} />
              </p>
              <p className="mt-0.5 font-mono text-xs text-slate-500 dark:text-slate-400">
                {selected.name} · {selected.language} · {selected.slots.length} variable{selected.slots.length === 1 ? '' : 's'}
              </p>
              {selected.category === 'MARKETING' ? (
                <p className="mt-3 flex gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                  <Users className="h-4 w-4 shrink-0" aria-hidden />
                  Marketing template: only contacts who opted in to WhatsApp messages will receive it. Everyone else is skipped and listed in the report.
                </p>
              ) : (
                <p className="mt-3 flex gap-2 rounded-lg bg-brand-50 p-2.5 text-xs text-brand-900 dark:bg-brand-500/10 dark:text-brand-100">
                  <ShieldCheck className="h-4 w-4 shrink-0" aria-hidden />
                  Utility template: like every campaign, it goes only to contacts who opted in to WhatsApp. Use it only for genuine updates — WhatsApp re-categorises promotional utility templates.
                </p>
              )}
              {selected.category !== suggested && (
                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                  {meta.types.find((x) => x.value === draft.type)?.label} campaigns usually use a {suggested.toLowerCase()} template.
                </p>
              )}
            </Card>
          </>
        ) : (
          <Card className="p-6 text-center">
            <LayoutTemplate className="mx-auto h-8 w-8 text-slate-400" aria-hidden />
            <p className="mt-2 text-sm font-medium text-slate-700 dark:text-slate-200">Pick a template to preview it</p>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">The preview shows how the message looks on a phone.</p>
          </Card>
        )}
      </div>
    </div>
  );
}

function TemplateCard({ t, active, onPick }: { t: CampaignTemplate; active: boolean; onPick: () => void }) {
  const HeaderIcon = t.header_type === 'image' || t.header_type === 'video' ? ImageIcon : t.header_type === 'document' ? FileText : null;
  return (
    <button
      type="button"
      role="radio"
      aria-checked={active}
      onClick={onPick}
      className={clsx(
        'relative flex flex-col rounded-xl border p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
        active ? 'border-accent-500 bg-accent-50/50 ring-1 ring-accent-500 dark:bg-accent-500/10' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900',
      )}
    >
      <span className="flex items-start gap-2 pr-6">
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{templateTitle(t)}</span>
          <span className="block truncate font-mono text-[11px] text-slate-500 dark:text-slate-400">
            {t.name} · {t.language}
          </span>
        </span>
      </span>
      <span className="mt-2 flex flex-wrap items-center gap-1.5">
        <CategoryBadge category={t.category} />
        {HeaderIcon && (
          <span className="inline-flex items-center gap-1 text-[11px] text-slate-500 dark:text-slate-400">
            <HeaderIcon className="h-3 w-3" aria-hidden /> {t.header_type}
          </span>
        )}
        {t.buttons.length > 0 && <span className="text-[11px] text-slate-500 dark:text-slate-400">{t.buttons.length} button{t.buttons.length > 1 ? 's' : ''}</span>}
      </span>
      <span className="mt-2 line-clamp-3 whitespace-pre-line text-xs text-slate-600 dark:text-slate-300">{t.body}</span>
      <span className="mt-auto pt-2 text-[11px] text-slate-400">{t.usage_count ? `Used ${formatNumber(t.usage_count)} times${t.last_used_at ? ` · last ${timeAgo(t.last_used_at)}` : ''}` : 'Not used yet'}</span>
      {active && <CheckCircle2 className="absolute right-3 top-3 h-5 w-5 text-accent-600 dark:text-accent-400" aria-hidden />}
    </button>
  );
}
