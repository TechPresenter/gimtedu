import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, ChevronLeft, ChevronRight, Database, FileSpreadsheet, LayoutTemplate, Type, Wand2 } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, Input, Skeleton } from '@/components/ui';
import { api } from '@/lib/api';
import { labelize } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { WaBubble, WaPhoneFrame, formatWaPhone } from '../shared';
import { templateTitle, type Draft } from './lib';
import type { CampaignDetail, CampaignMeta, MapSource, PersonalizePreview, TemplateSlot, VarMapEntry } from './types';
import type { StepKey } from './CampaignEditorPage';

const SOURCE_OPTS: { key: MapSource; label: string; icon: typeof Type }[] = [
  { key: 'contact', label: 'Contact field', icon: Database },
  { key: 'csv', label: 'List column', icon: FileSpreadsheet },
  { key: 'literal', label: 'Fixed text', icon: Type },
];

export function StepPersonalise({ draft, patch, meta, campaign, onGoto }: { draft: Draft; patch: (p: Partial<Draft>) => void; errors: Record<string, string>; meta: CampaignMeta; campaign: CampaignDetail; onGoto: (k: StepKey) => void }) {
  const tpl = meta.templates.find((t) => t.id === draft.template_id) ?? null;
  const headers = campaign.upload?.headers ?? [];
  const slots = tpl?.slots ?? [];
  const map = draft.variable_map;
  const [sampleIdx, setSampleIdx] = useState(0);

  // make sure every slot has an entry (old drafts / template switched without saving)
  useEffect(() => {
    if (!tpl) return;
    const missing = slots.filter((s) => !map[s.key]);
    if (!missing.length) return;
    const next = { ...map };
    for (const s of missing) {
      next[s.key] = s.kind === 'media' ? { source: 'literal', value: tpl.header_media_url ?? '', fallback: '' } : meta.contact_keys.includes(s.var_key) ? { source: 'contact', value: s.var_key, fallback: '' } : { source: 'literal', value: '', fallback: '' };
    }
    patch({ variable_map: next });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tpl?.id]);

  const setSlot = (key: string, p: Partial<VarMapEntry>) => patch({ variable_map: { ...map, [key]: { ...(map[key] ?? { source: 'literal', value: '', fallback: '' }), ...p } } });

  const payload = useMemo(() => JSON.stringify({ variable_map: map, template_id: draft.template_id, audience: draft.audience, audience_source: draft.audience_source }), [map, draft.template_id, draft.audience, draft.audience_source]);
  const debounced = useDebounce(payload, 500);
  const preview = useQuery({
    queryKey: ['wa', 'campaigns', 'personalize', campaign.id, debounced],
    queryFn: ({ signal }) => api.post<PersonalizePreview>(`whatsapp/campaigns/${campaign.id}/personalize/preview`, JSON.parse(debounced), { signal }).then((r) => r.data),
    enabled: !!tpl,
    staleTime: 15_000,
  });
  const pv = preview.data;
  const samples = pv?.samples ?? [];
  const sample = samples[Math.min(sampleIdx, Math.max(0, samples.length - 1))];
  const errs = pv?.errors ?? {};

  const catalogGroups = useMemo(() => {
    const g = new Map<string, typeof meta.catalog>();
    for (const v of meta.catalog) {
      if (!meta.contact_keys.includes(v.key)) continue;
      if (!g.has(v.group)) g.set(v.group, []);
      g.get(v.group)!.push(v);
    }
    return [...g.entries()];
  }, [meta.catalog, meta.contact_keys]);

  if (!tpl) {
    return (
      <Card>
        <EmptyState icon={LayoutTemplate} title="Choose a template first" description="Personalisation maps the template's variables to each recipient's details." action={<Button onClick={() => onGoto('template')}>Choose template</Button>} />
      </Card>
    );
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
      <Card className="min-w-0">
        <CardHeader
          title="Personalise every message"
          subtitle={`${templateTitle(tpl)} has ${slots.length} variable${slots.length === 1 ? '' : 's'}. Map each one to a contact field, a column of your list or a fixed text. The fallback is used when a recipient has no value.`}
          icon={Wand2}
        />
        {slots.length === 0 ? (
          <div className="p-5 pt-0">
            <Alert variant="success">This template has no variables — every recipient gets the same text.</Alert>
          </div>
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {slots.map((s) => (
              <SlotRow
                key={s.key}
                slot={s}
                entry={map[s.key]}
                error={errs[s.key]}
                headers={headers}
                catalogGroups={catalogGroups}
                value={sample?.values[s.key]}
                onChange={(p) => setSlot(s.key, p)}
                onUploadHint={() => onGoto('audience')}
              />
            ))}
          </ul>
        )}
      </Card>

      <div className="space-y-3 lg:sticky lg:top-20 lg:self-start">
        <div className="flex items-center gap-2">
          <Button size="sm" variant="ghost" icon={ChevronLeft} aria-label="Previous recipient" disabled={sampleIdx <= 0} onClick={() => setSampleIdx((i) => Math.max(0, i - 1))} />
          <div className="min-w-0 flex-1 text-center">
            {sample ? (
              <>
                <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{sample.recipient.name || 'Unnamed recipient'}</p>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                  {sample.recipient.phone ? formatWaPhone(sample.recipient.phone) : sample.recipient.phone_display || 'Sample values'}
                  {sample.recipient.contact_type ? ` · ${labelize(sample.recipient.contact_type)}` : ''} · {samples.length > 1 ? `${Math.min(sampleIdx, samples.length - 1) + 1} of ${samples.length}` : 'preview'}
                </p>
              </>
            ) : (
              <Skeleton className="mx-auto h-8 w-40" />
            )}
          </div>
          <Button size="sm" variant="ghost" icon={ChevronRight} aria-label="Next recipient" disabled={sampleIdx >= samples.length - 1} onClick={() => setSampleIdx((i) => Math.min(samples.length - 1, i + 1))} />
        </div>
        <WaPhoneFrame height={470} subtitle={preview.isFetching ? 'Updating preview…' : 'Live preview'}>
          {sample ? <WaBubble direction="out" type="template" template={sample.preview} time={null} showStatus={false} /> : <Skeleton className="ml-auto h-40 w-56 rounded-xl" />}
        </WaPhoneFrame>
        {pv?.is_sample && <p className="text-center text-xs text-slate-500 dark:text-slate-400">The audience is empty, so template samples are shown.</p>}
        {sample && sample.missing.length > 0 && (
          <Alert variant="warning">
            This recipient has no value for {sample.missing.map((k) => slots.find((s) => s.key === k)?.label ?? k).join(', ')} — add a fallback, or they will be skipped.
          </Alert>
        )}
        {preview.error && <Alert variant="error">{(preview.error as Error).message}</Alert>}
      </div>
    </div>
  );
}

function SlotRow({
  slot,
  entry,
  error,
  headers,
  catalogGroups,
  value,
  onChange,
  onUploadHint,
}: {
  slot: TemplateSlot;
  entry: VarMapEntry | undefined;
  error?: string;
  headers: string[];
  catalogGroups: [string, { key: string; label: string; sample: string }[]][];
  value?: string;
  onChange: (p: Partial<VarMapEntry>) => void;
  onUploadHint: () => void;
}) {
  const e = entry ?? { source: 'literal' as MapSource, value: '', fallback: '' };
  const media = slot.kind === 'media';
  const id = `slot-${slot.key.replace(/\W/g, '-')}`;
  const sources = media ? SOURCE_OPTS.filter((o) => o.key !== 'contact') : SOURCE_OPTS;
  return (
    <li className="grid gap-3 px-5 py-4 md:grid-cols-[11rem_minmax(0,1fr)]">
      <div>
        <p className="text-sm font-semibold text-slate-900 dark:text-white">{slot.label}</p>
        {slot.var_key && <p className="font-mono text-[11px] text-slate-500 dark:text-slate-400">{`{{${slot.var_key}}}`}</p>}
        {slot.sample && <p className="mt-0.5 truncate text-xs text-slate-400" title={slot.sample}>e.g. {slot.sample}</p>}
      </div>
      <div className="min-w-0 space-y-2">
        <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="radiogroup" aria-label={`Value source for ${slot.label}`}>
          {sources.map((o) => (
            <button
              key={o.key}
              type="button"
              role="radio"
              aria-checked={e.source === o.key}
              onClick={() => onChange({ source: o.key, value: o.key === e.source ? e.value : o.key === 'csv' ? headers[0] ?? '' : '' })}
              className={clsx(
                'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                e.source === o.key ? 'bg-white text-brand-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white',
              )}
            >
              <o.icon className="h-3.5 w-3.5" aria-hidden />
              {o.label}
            </button>
          ))}
        </div>
        <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_12rem]">
          {e.source === 'contact' ? (
            <select id={id} aria-label={`Contact field for ${slot.label}`} className={clsx('form-input', error && 'form-input-error')} value={e.value} onChange={(ev) => onChange({ value: ev.target.value })}>
              <option value="">Choose a field…</option>
              {catalogGroups.map(([g, vars]) => (
                <optgroup key={g} label={g}>
                  {vars.map((v) => (
                    <option key={v.key} value={v.key}>
                      {v.label} — e.g. {v.sample}
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>
          ) : e.source === 'csv' ? (
            headers.length ? (
              <select id={id} aria-label={`List column for ${slot.label}`} className={clsx('form-input', error && 'form-input-error')} value={e.value} onChange={(ev) => onChange({ value: ev.target.value })}>
                <option value="">Choose a column…</option>
                {headers.map((h) => (
                  <option key={h} value={h}>
                    {h}
                  </option>
                ))}
              </select>
            ) : (
              <p className="flex items-center gap-2 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs text-slate-500 dark:border-slate-600 dark:text-slate-400">
                <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden /> No list uploaded.
                <button type="button" className="font-semibold text-brand-700 hover:underline dark:text-brand-300" onClick={onUploadHint}>
                  Upload one
                </button>
              </p>
            )
          ) : (
            <Input
              id={id}
              aria-label={`Fixed text for ${slot.label}`}
              value={e.value}
              maxLength={media ? 500 : 1000}
              invalid={!!error}
              placeholder={media ? 'https://… public file URL' : slot.sample ? `e.g. ${slot.sample}` : 'Same text for everyone'}
              onChange={(ev) => onChange({ value: ev.target.value })}
            />
          )}
          {!media && <Input aria-label={`Fallback for ${slot.label}`} value={e.fallback} maxLength={500} placeholder="Fallback (optional)" onChange={(ev) => onChange({ fallback: ev.target.value })} />}
        </div>
        {error ? (
          <p className="form-error" role="alert">
            {error}
            {e.source === 'literal' && slot.sample && !e.value && (
              <button type="button" className="ml-2 font-semibold text-brand-700 hover:underline dark:text-brand-300" onClick={() => onChange({ value: slot.sample })}>
                Use “{slot.sample}”
              </button>
            )}
          </p>
        ) : value !== undefined ? (
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
            Preview recipient: <span className={clsx('font-medium', value ? 'text-slate-800 dark:text-slate-100' : 'text-amber-700 dark:text-amber-300')}>{value || 'no value'}</span>
          </p>
        ) : null}
      </div>
    </li>
  );
}
