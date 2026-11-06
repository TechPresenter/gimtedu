import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  Ban,
  CheckCircle2,
  Copy,
  Download,
  FileSpreadsheet,
  Filter,
  Layers,
  PhoneOff,
  ShieldCheck,
  ShieldOff,
  Trash2,
  Upload,
  UserX,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Combobox, CountUp, Field, FileUpload, Select, Skeleton, useConfirm, useToast } from '@/components/ui';
import { ApiError, api } from '@/lib/api';
import { formatNumber, labelize } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { PhoneText } from '../shared';
import { audienceFilterCount, durationText, EMPTY_AUDIENCE, type Draft } from './lib';
import type { AudiencePreview, CampaignAudience, CampaignDetail, CampaignMeta, FacetOption, UploadSummary } from './types';
import { CategoryBadge, downloadReport } from './ui';

const REASON_ICONS: Record<string, LucideIcon> = {
  no_opt_in: UserX,
  no_consent: UserX,
  opted_out: ShieldOff,
  blocked: Ban,
  invalid_number: PhoneOff,
  duplicate: Copy,
  missing_variables: AlertTriangle,
};

const SOURCES: { key: Draft['audience_source']; label: string; hint: string; icon: LucideIcon }[] = [
  { key: 'filters', label: 'CRM contacts', hint: 'Filter students, applicants, leads, parents', icon: Filter },
  { key: 'csv', label: 'Upload a list', hint: 'CSV or Excel with phone numbers', icon: FileSpreadsheet },
  { key: 'mixed', label: 'Both', hint: 'CRM filters plus an uploaded list', icon: Layers },
];

function Chips({ options, value, onChange, label }: { options: FacetOption[]; value: (string | number)[]; onChange: (v: (string | number)[]) => void; label: string }) {
  if (!options.length) return <p className="text-xs text-slate-400">No values in the CRM yet.</p>;
  return (
    <div className="flex flex-wrap gap-1.5" role="group" aria-label={label}>
      {options.map((o) => {
        const on = value.map(String).includes(String(o.value));
        return (
          <button
            key={String(o.value)}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(on ? value.filter((v) => String(v) !== String(o.value)) : [...value, o.value])}
            className={clsx(
              'rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
              on ? 'bg-brand-900 text-white ring-brand-900 dark:bg-brand-500 dark:ring-brand-400' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700',
            )}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}

const opts = (list: FacetOption[]) => list.map((o) => ({ value: o.value, label: o.label, sub: o.sub ?? undefined }));

export function StepAudience({ draft, patch, errors, meta, campaign }: { draft: Draft; patch: (p: Partial<Draft>) => void; errors: Record<string, string>; meta: CampaignMeta; campaign: CampaignDetail }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const a = draft.audience;
  const setA = (p: Partial<CampaignAudience>) => patch({ audience: { ...a, ...p } });
  const tpl = meta.templates.find((t) => t.id === draft.template_id) ?? null;
  const category = tpl?.category ?? meta.types.find((t) => t.value === draft.type)?.category ?? 'MARKETING';
  const [upload, setUpload] = useState<UploadSummary | null>(campaign.upload);
  const [uploadVersion, setUploadVersion] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const showFilters = draft.audience_source !== 'csv';
  const showCsv = draft.audience_source !== 'filters';

  const payload = useMemo(
    () => JSON.stringify({ campaign_id: campaign.id, audience_source: draft.audience_source, audience: { ...a, opt_in_only: true }, template_id: draft.template_id, type: draft.type, v: uploadVersion }),
    [campaign.id, draft.audience_source, a, draft.template_id, draft.type, uploadVersion],
  );
  const debounced = useDebounce(payload, 450);
  const preview = useQuery({
    queryKey: ['wa', 'campaigns', 'preview', debounced],
    queryFn: ({ signal }) => api.post<AudiencePreview>('whatsapp/campaigns/audience/preview', JSON.parse(debounced), { signal }).then((r) => r.data),
    staleTime: 15_000,
  });
  const p = preview.data;
  const stale = payload !== debounced || preview.isFetching;

  const doUpload = async (f: File | null) => {
    setFile(f);
    setUploadError(null);
    if (!f) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', f);
      const res = await api.post<UploadSummary>(`whatsapp/campaigns/${campaign.id}/upload`, fd);
      setUpload(res.data);
      if (draft.audience_source === 'filters') patch({ audience_source: 'csv' });
      setUploadVersion((v) => v + 1);
      qc.invalidateQueries({ queryKey: ['wa', 'campaigns', 'detail', campaign.id] });
      toast.success(res.message);
    } catch (e) {
      const err = e as ApiError;
      setUploadError(err.errors?.file ?? err.message);
    } finally {
      setUploading(false);
      setFile(null);
    }
  };

  const remap = async (k: 'phone_column' | 'name_column' | 'consent_column', v: string) => {
    if (!upload) return;
    const body = { phone_column: upload.phone_column, name_column: upload.name_column, consent_column: upload.consent_column, [k]: v === '' ? null : Number(v) };
    try {
      const res = await api.post<UploadSummary>(`whatsapp/campaigns/${campaign.id}/upload/map`, body);
      setUpload(res.data);
      setUploadVersion((x) => x + 1);
      qc.invalidateQueries({ queryKey: ['wa', 'campaigns', 'detail', campaign.id] });
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const removeUpload = async () => {
    if (!(await confirm({ title: 'Remove the uploaded list?', message: 'The campaign goes back to CRM filters. You can upload a file again any time.', confirmText: 'Remove', danger: true }))) return;
    try {
      const res = await api.del(`whatsapp/campaigns/${campaign.id}/upload`);
      setUpload(null);
      patch({ audience_source: 'filters' });
      setUploadVersion((x) => x + 1);
      qc.invalidateQueries({ queryKey: ['wa', 'campaigns', 'detail', campaign.id] });
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const fetchFile = async (path: string, name: string) => {
    try {
      await downloadReport(path, undefined, name);
    } catch (e) {
      toast.error((e as Error).message);
    }
  };

  const colOptions = (upload?.headers ?? []).map((h, i) => ({ value: String(i), label: h }));
  const filterCount = audienceFilterCount(a);
  const segment = meta.segments.find((s) => s.id === a.segment_id);

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="min-w-0 space-y-5">
        <Card>
          <CardHeader title="Who should receive this campaign?" subtitle="Pick CRM contacts with filters, upload your own list, or combine both. Numbers are checked and de-duplicated." />
          <div role="radiogroup" aria-label="Audience source" className="grid grid-cols-1 gap-2.5 px-5 pb-5 sm:grid-cols-3">
            {SOURCES.map((s) => {
              const on = draft.audience_source === s.key;
              return (
                <button
                  key={s.key}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  onClick={() => patch({ audience_source: s.key })}
                  className={clsx(
                    'flex items-start gap-3 rounded-xl border p-3 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                    on ? 'border-accent-500 bg-accent-50/60 ring-1 ring-accent-500 dark:bg-accent-500/10' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900',
                  )}
                >
                  <s.icon className={clsx('mt-0.5 h-5 w-5 shrink-0', on ? 'text-accent-600 dark:text-accent-400' : 'text-slate-400')} aria-hidden />
                  <span>
                    <span className="block text-sm font-semibold text-slate-900 dark:text-white">{s.label}</span>
                    <span className="block text-xs text-slate-500 dark:text-slate-400">{s.hint}</span>
                  </span>
                </button>
              );
            })}
          </div>
          {errors.audience_source && <p className="form-error px-5 pb-4">{errors.audience_source}</p>}
        </Card>

        {showFilters && (
          <Card>
            <CardHeader
              title="CRM filters"
              subtitle={filterCount ? `${filterCount} filter value${filterCount > 1 ? 's' : ''} applied — contacts must match all filter groups.` : 'No filters: every active WhatsApp contact (archived excluded).'}
              icon={Filter}
              actions={
                filterCount > 0 ? (
                  <Button size="xs" variant="ghost" onClick={() => setA({ ...EMPTY_AUDIENCE })}>
                    Clear filters
                  </Button>
                ) : undefined
              }
            />
            <div className="grid grid-cols-1 gap-5 p-5 pt-0 md:grid-cols-2">
              <Field label="Saved segment" htmlFor="aud-seg" error={errors.segment_id} hint={segment ? `${formatNumber(segment.contacts_count)} contacts · ${formatNumber(segment.opted_in_count)} opted in` : 'Segments are created on the Contacts page.'} className="md:col-span-2">
                <Select
                  id="aud-seg"
                  value={a.segment_id ? String(a.segment_id) : ''}
                  onChange={(e) => setA({ segment_id: e.target.value ? Number(e.target.value) : null })}
                  placeholder="No segment — use the filters below"
                  options={meta.segments.map((s) => ({ value: String(s.id), label: `${s.name} (${formatNumber(s.contacts_count)})` }))}
                />
              </Field>
              <div className="md:col-span-2">
                <p className="form-label">Contact type</p>
                <Chips label="Contact type" options={meta.facets.types} value={a.contact_types} onChange={(v) => setA({ contact_types: v.map(String) })} />
              </div>
              <Field label="Programmes / courses" htmlFor="aud-prog">
                <Combobox id="aud-prog" multiple options={opts(meta.facets.programs)} value={a.programs} onChange={(v) => setA({ programs: v.map(Number) })} placeholder="Any programme" />
              </Field>
              <Field label="Admission status" htmlFor="aud-adm">
                <Combobox id="aud-adm" multiple options={opts(meta.facets.admission_statuses)} value={a.admission_status} onChange={(v) => setA({ admission_status: v.map(String) })} placeholder="Any status" />
              </Field>
              <div className="md:col-span-2">
                <p className="form-label">Lead stage</p>
                <Chips label="Lead stage" options={meta.facets.stages} value={a.stages} onChange={(v) => setA({ stages: v.map(String) })} />
              </div>
              <Field label="City" htmlFor="aud-city">
                <Combobox id="aud-city" multiple options={opts(meta.facets.cities)} value={a.cities} onChange={(v) => setA({ cities: v.map(String) })} placeholder="Any city" />
              </Field>
              <Field label="State" htmlFor="aud-state">
                <Combobox id="aud-state" multiple options={opts(meta.facets.states)} value={a.states} onChange={(v) => setA({ states: v.map(String) })} placeholder="Any state" />
              </Field>
              <Field label="Batch / intake" htmlFor="aud-batch">
                <Combobox id="aud-batch" multiple options={opts(meta.facets.batches)} value={a.batches} onChange={(v) => setA({ batches: v.map(String) })} placeholder="Any batch" />
              </Field>
              <Field label="Fee payment status" htmlFor="aud-pay">
                <Combobox id="aud-pay" multiple options={opts(meta.facets.payment_statuses)} value={a.payment_status} onChange={(v) => setA({ payment_status: v.map(String) })} placeholder="Any" />
              </Field>
              <div className="md:col-span-2">
                <p className="form-label">Tags</p>
                <Chips label="Tags" options={meta.facets.tags.map((t) => ({ value: t.id, label: t.name }))} value={a.tags} onChange={(v) => setA({ tags: v.map(Number) })} />
              </div>
            </div>
          </Card>
        )}

        {showCsv && (
          <Card>
            <CardHeader
              title="Uploaded list"
              subtitle={`${meta.xlsx ? 'CSV or Excel (.xlsx)' : 'CSV (this server cannot read .xlsx — save Excel sheets as CSV)'}, first row = column names, up to ${formatNumber(meta.max_upload_rows)} rows. Extra columns can personalise the message.`}
              icon={FileSpreadsheet}
              actions={
                <Button size="xs" variant="ghost" icon={Download} onClick={() => fetchFile('whatsapp/campaigns/upload-template', 'whatsapp-campaign-recipients.csv')}>
                  Sample file
                </Button>
              }
            />
            <div className="space-y-4 p-5 pt-0">
              {upload ? (
                <>
                  <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-700 dark:bg-slate-800/40">
                    <FileSpreadsheet className="h-8 w-8 shrink-0 text-emerald-600" aria-hidden />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{upload.file_name}</p>
                      <p className="text-xs text-slate-500 dark:text-slate-400">
                        {formatNumber(upload.total_rows)} rows · {upload.headers.length} columns
                      </p>
                    </div>
                    <Button size="sm" variant="ghost" icon={Trash2} onClick={removeUpload}>
                      Remove
                    </Button>
                  </div>
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {[
                      { l: 'Valid numbers', v: upload.valid_rows, c: 'text-emerald-700 dark:text-emerald-300' },
                      { l: 'With consent', v: upload.consent_rows, c: 'text-brand-800 dark:text-brand-200' },
                      { l: 'Invalid', v: upload.invalid_rows, c: upload.invalid_rows ? 'text-red-600 dark:text-red-300' : 'text-slate-900 dark:text-white' },
                      { l: 'Duplicates', v: upload.duplicate_rows, c: upload.duplicate_rows ? 'text-amber-700 dark:text-amber-300' : 'text-slate-900 dark:text-white' },
                    ].map((x) => (
                      <div key={x.l} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <p className={clsx('font-display text-xl font-bold tabular-nums', x.c)}>{formatNumber(x.v)}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{x.l}</p>
                      </div>
                    ))}
                  </div>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <Field label="Phone column" required htmlFor="up-phone">
                      <Select id="up-phone" value={upload.phone_column === null ? '' : String(upload.phone_column)} onChange={(e) => remap('phone_column', e.target.value)} placeholder="Choose…" options={colOptions} />
                    </Field>
                    <Field label="Name column" htmlFor="up-name">
                      <Select id="up-name" value={upload.name_column === null ? '' : String(upload.name_column)} onChange={(e) => remap('name_column', e.target.value)} placeholder="None" options={colOptions} />
                    </Field>
                    <Field label="Consent column" htmlFor="up-consent" hint="Yes / 1 / Agree = opted in">
                      <Select id="up-consent" value={upload.consent_column === null ? '' : String(upload.consent_column)} onChange={(e) => remap('consent_column', e.target.value)} placeholder="None" options={colOptions} />
                    </Field>
                  </div>
                  {upload.sample.length > 0 && (
                    <div className="table-wrap rounded-xl border border-slate-200 dark:border-slate-700">
                      <table className="data-table text-xs">
                        <caption className="sr-only">First rows of the uploaded file</caption>
                        <thead>
                          <tr>
                            {upload.headers.map((h, i) => (
                              <th key={i} className="whitespace-nowrap">
                                {h}
                                {i === upload.phone_column && <Badge color="green" className="ml-1">phone</Badge>}
                                {i === upload.name_column && <Badge color="blue" className="ml-1">name</Badge>}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody>
                          {upload.sample.map((r, i) => (
                            <tr key={i}>
                              {upload.headers.map((_, j) => (
                                <td key={j} className="whitespace-nowrap">
                                  {r[j] || <span className="text-slate-400">—</span>}
                                </td>
                              ))}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {(upload.invalid_rows > 0 || upload.duplicate_rows > 0) && (
                    <div className="rounded-xl border border-red-200 bg-red-50/60 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm font-semibold text-red-800 dark:text-red-200">
                          {formatNumber(upload.invalid_rows + upload.duplicate_rows)} row(s) will not be messaged
                        </p>
                        <Button size="xs" variant="secondary" icon={Download} onClick={() => fetchFile(`whatsapp/campaigns/${campaign.id}/upload/invalid`, `campaign-${campaign.id}-invalid-rows.csv`)}>
                          Download report
                        </Button>
                      </div>
                      <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs text-red-900 dark:text-red-100">
                        {upload.invalid_sample.map((r) => (
                          <li key={`i${r.row}`}>
                            Row {r.row}: {r.name ? `${r.name} — ` : ''}
                            <span className="font-mono">{r.value || '(empty)'}</span> · {r.reason}
                          </li>
                        ))}
                        {upload.duplicate_sample.map((r) => (
                          <li key={`d${r.row}`}>
                            Row {r.row}: {r.name ? `${r.name} — ` : ''}
                            <span className="font-mono">{r.phone_display ?? `+${r.phone}`}</span> · duplicate of row {r.first_row}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  <div>
                    <p className="form-label">Replace with another file</p>
                    <FileUpload file={file} onFile={doUpload} accept={meta.xlsx ? '.csv,.xlsx' : '.csv'} maxSize={5 * 1024 * 1024} onError={setUploadError} compact />
                  </div>
                </>
              ) : (
                <FileUpload file={file} onFile={doUpload} accept={meta.xlsx ? '.csv,.xlsx' : '.csv'} maxSize={5 * 1024 * 1024} onError={setUploadError} invalid={!!uploadError} />
              )}
              {uploading && (
                <p className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                  <Upload className="h-4 w-4 animate-pulse" aria-hidden /> Reading the file and checking numbers…
                </p>
              )}
              {uploadError && <Alert variant="error">{uploadError}</Alert>}
            </div>
          </Card>
        )}

        <Card className="flex gap-3 p-5">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
            <ShieldCheck className="h-5 w-5" aria-hidden />
          </span>
          <div className="min-w-0 text-sm">
            <p className="font-semibold text-slate-900 dark:text-white">Opted-in contacts only</p>
            <p className="mt-0.5 text-slate-600 dark:text-slate-300">
              Every campaign — marketing or utility template — goes only to contacts with a recorded WhatsApp opt-in{showCsv ? ' (or “yes” in the consent column of the uploaded list)' : ''}. Everyone else is skipped as “No WhatsApp opt-in” and listed in the report; opted-out (STOP) and blocked numbers are never messaged.
            </p>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              Individual updates a student needs (fee receipts, admit cards, results) are sent by the Event Manager, not by campaigns.
            </p>
          </div>
        </Card>
      </div>

      {/* live count */}
      <div className="space-y-4 lg:sticky lg:top-20 lg:self-start">
        <Card className="overflow-hidden">
          <div className="bg-gradient-to-br from-brand-900 to-brand-800 p-5 text-white dark:from-brand-950 dark:to-brand-900">
            <p className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-white/70">
              <Users className="h-4 w-4" aria-hidden /> Will receive the campaign
              {stale && <span className="ml-auto h-2 w-2 animate-pulse rounded-full bg-accent-400" aria-label="Updating" />}
            </p>
            {p ? (
              <p className="mt-1 font-display text-4xl font-extrabold tabular-nums">
                <CountUp value={p.eligible} />
              </p>
            ) : (
              <Skeleton className="mt-2 h-10 w-28 !bg-white/20" />
            )}
            <p className="mt-1 text-sm text-white/80">
              {p ? (
                <>
                  of {formatNumber(p.total)} in the audience · <CategoryBadge category={category} />
                </>
              ) : (
                'Counting…'
              )}
            </p>
            {p && p.eligible > 0 && <p className="mt-2 text-xs text-white/70">Sends in {durationText(p.estimated_minutes)} at {formatNumber(p.rate_per_minute)} / min</p>}
          </div>
          <div className="space-y-4 p-5">
            {preview.error && <Alert variant="error">{(preview.error as Error).message}</Alert>}
            {p?.segment_missing && <Alert variant="error">The saved segment was deleted, so the CRM filters match nobody. Choose another segment or clear it.</Alert>}
            {p?.capped && <Alert variant="warning">Only the first {formatNumber(p.max)} contacts are used. Narrow the filters or split the campaign.</Alert>}
            {errors.audience && <Alert variant="error">{errors.audience}</Alert>}
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Skipped and why</p>
              {!p ? (
                <Skeleton className="h-16 w-full" />
              ) : p.breakdown.length === 0 ? (
                <p className="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300">
                  <CheckCircle2 className="h-4 w-4" aria-hidden /> Nobody is skipped.
                </p>
              ) : (
                <ul className="space-y-1.5">
                  {p.breakdown.map((b) => {
                    const Icon = REASON_ICONS[b.reason] ?? AlertTriangle;
                    return (
                      <li key={b.reason} className="flex items-center gap-2 text-sm">
                        <Icon className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
                        <span className="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-200">{b.label}</span>
                        <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(b.count)}</span>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
            {p && p.by_type.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Recipients by type</p>
                <div className="flex flex-wrap gap-1.5">
                  {p.by_type.map((t) => (
                    <Badge key={t.type} color="navy">
                      {t.label} · {formatNumber(t.count)}
                    </Badge>
                  ))}
                </div>
                {draft.audience_source === 'mixed' && (
                  <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    {formatNumber(p.by_source.filter)} from CRM filters · {formatNumber(p.by_source.csv)} from the list
                  </p>
                )}
              </div>
            )}
            {p && p.sample.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">First recipients</p>
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {p.sample.slice(0, 6).map((r, i) => (
                    <li key={`${r.phone_display}-${i}`} className="flex items-center justify-between gap-2 py-1.5 text-sm">
                      <span className="min-w-0 truncate text-slate-800 dark:text-slate-100">{r.name || 'Unnamed'}</span>
                      <span className="shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400">
                        {r.phone ? <PhoneText phone={r.phone} /> : r.phone_display} {r.contact_type ? `· ${labelize(r.contact_type)}` : '· new'}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </Card>
      </div>
    </div>
  );
}
