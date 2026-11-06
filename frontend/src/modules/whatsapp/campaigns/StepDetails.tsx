import clsx from 'clsx';
import { CheckCircle2 } from 'lucide-react';
import { Card, CardHeader, Field, Input, Textarea } from '@/components/ui';
import type { Draft } from './lib';
import type { CampaignMeta } from './types';
import { CategoryBadge, TypeTile } from './ui';

export function StepDetails({ draft, patch, errors, meta }: { draft: Draft; patch: (p: Partial<Draft>) => void; errors: Record<string, string>; meta: CampaignMeta }) {
  return (
    <div className="grid gap-5 lg:grid-cols-3">
      <Card className="lg:col-span-2">
        <CardHeader title="Campaign details" subtitle="Only your team sees the name and description." />
        <div className="space-y-5 p-5">
          <Field label="Campaign name" required htmlFor="cmp-name" error={errors.name} aside={`${draft.name.length}/150`}>
            <Input id="cmp-name" value={draft.name} maxLength={150} autoFocus invalid={!!errors.name} placeholder="e.g. Admissions Open 2026-27 — MBA & BBA" onChange={(e) => patch({ name: e.target.value })} />
          </Field>
          <div>
            <p className="form-label">
              Campaign type<span className="ml-0.5 text-red-500" aria-hidden>*</span>
            </p>
            <div role="radiogroup" aria-label="Campaign type" className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
              {meta.types.map((t) => {
                const active = draft.type === t.value;
                return (
                  <button
                    key={t.value}
                    type="button"
                    role="radio"
                    aria-checked={active}
                    onClick={() => patch({ type: t.value })}
                    className={clsx(
                      'relative flex items-start gap-3 rounded-xl border p-3 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                      active ? 'border-accent-500 bg-accent-50/60 ring-1 ring-accent-500 dark:border-accent-500 dark:bg-accent-500/10' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900',
                    )}
                  >
                    <TypeTile type={t.value} size="sm" />
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-2">
                        <span className="text-sm font-semibold text-slate-900 dark:text-white">{t.label}</span>
                      </span>
                      <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{t.description}</span>
                      <span className="mt-1.5 inline-flex items-center gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                        Usually <CategoryBadge category={t.category} />
                      </span>
                    </span>
                    {active && <CheckCircle2 className="absolute right-2.5 top-2.5 h-4 w-4 text-accent-600 dark:text-accent-400" aria-hidden />}
                  </button>
                );
              })}
            </div>
            {errors.type && (
              <p className="form-error" role="alert">
                {errors.type}
              </p>
            )}
          </div>
          <Field label="Description" htmlFor="cmp-desc" error={errors.description} hint="Purpose, owner or approval note (optional)." aside={`${draft.description.length}/500`}>
            <Textarea id="cmp-desc" rows={3} maxLength={500} value={draft.description} onChange={(e) => patch({ description: e.target.value })} placeholder="e.g. Launch announcement for the 2026-27 intake, approved by the Director." />
          </Field>
        </div>
      </Card>
      <Card className="h-fit">
        <CardHeader title="How campaigns work" />
        <ol className="space-y-3 p-5 text-sm text-slate-600 dark:text-slate-300">
          {[
            ['Approved templates only', 'WhatsApp allows business-initiated messages only through templates approved by Meta.'],
            ['Consent first', 'Every campaign, marketing or utility, goes only to contacts who opted in to WhatsApp. People who replied STOP are never messaged. Individual updates such as fee receipts or admit cards are sent by the Event Manager.'],
            ['Personal, not generic', 'Each message is filled with the recipient’s own name, course, dues or any column of your uploaded sheet.'],
            ['Safe sending', `Messages are queued and sent at up to ${meta.rate_per_minute} per minute; you can pause, resume or cancel at any time.`],
          ].map(([t, d], i) => (
            <li key={t} className="flex gap-3">
              <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{i + 1}</span>
              <span>
                <b className="block text-slate-900 dark:text-white">{t}</b>
                {d}
              </span>
            </li>
          ))}
        </ol>
      </Card>
    </div>
  );
}
