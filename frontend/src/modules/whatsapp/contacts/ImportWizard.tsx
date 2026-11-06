import { useEffect, useMemo, useState } from 'react';
import { CircleAlert, Download, FileSpreadsheet, RefreshCw, ShieldCheck, SkipForward, Upload, UserPlus } from 'lucide-react';
import { Alert, Badge, Button, Combobox, Field, FileUpload, Input, Modal, RadioGroup, Select, Stepper, StatTile, useToast } from '@/components/ui';
import { api, downloadFile, toFormData } from '@/lib/api';
import { formatNumber, formatPhone } from '@/lib/format';
import { TYPE_LABELS, useCrmRefresh, useFacets } from './crm';
import { ConsentHelp, fieldErrors } from './crmUi';

interface UploadResult {
  token: string;
  file: string;
  headers: string[];
  total_rows: number;
  sample: string[][];
  mapping: Record<string, number>;
  fields: { key: string; label: string; required: boolean }[];
}
interface PreviewRow {
  row: number;
  raw_phone: string;
  phone?: string;
  name: string;
  action: 'create' | 'update' | 'skip' | 'error';
  errors: string[];
  warnings: string[];
  consent: boolean | null;
  existing_name?: string | null;
}
interface Preview {
  counts: { create: number; update: number; skip: number; error: number; opt_in: number };
  total: number;
  rows: PreviewRow[];
  consent_mapped: boolean;
}
interface Report {
  created: number;
  updated: number;
  skipped: number;
  failed: number;
  opted_in: number;
  errors: { row: number; message: string }[];
  file: string;
}

const STEPS = [
  { key: 'upload', label: 'Upload' },
  { key: 'map', label: 'Map columns' },
  { key: 'preview', label: 'Preview' },
  { key: 'done', label: 'Result' },
];
const ACTION_COLORS = { create: 'green', update: 'blue', skip: 'slate', error: 'red' } as const;

/** CSV / XLSX contact import: upload → column mapping → preview with duplicate detection → import report. */
export function ImportWizard({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const { data: facets } = useFacets();
  const [step, setStep] = useState('upload');
  const [file, setFile] = useState<File | null>(null);
  const [upload, setUpload] = useState<UploadResult | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [strategy, setStrategy] = useState('update');
  const [contactType, setContactType] = useState('lead');
  const [tags, setTags] = useState<string[]>([]);
  const [optSource, setOptSource] = useState('');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [report, setReport] = useState<Report | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [srcError, setSrcError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setSrcError(null);
      setStep('upload');
      setFile(null);
      setUpload(null);
      setMapping({});
      setStrategy('update');
      setContactType('lead');
      setTags([]);
      setOptSource('');
      setPreview(null);
      setReport(null);
      setError(null);
    }
  }, [open]);

  const headerOptions = useMemo(() => (upload?.headers ?? []).map((h, i) => ({ value: String(i), label: h || `Column ${i + 1}` })), [upload]);
  const mappingPayload = () => Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)]));

  const doUpload = async () => {
    if (!file) {
      setError('Choose a CSV or Excel file.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const r = await api.post<UploadResult>('whatsapp/contacts/import/upload', toFormData({ file }));
      setUpload(r.data);
      setMapping(Object.fromEntries(Object.entries(r.data.mapping ?? {}).map(([k, v]) => [k, String(v)])));
      setStep('map');
    } catch (e) {
      setError(fieldErrors(e).file ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const doPreview = async () => {
    if (!mapping.phone) {
      setError('Choose the column that holds the WhatsApp number.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const r = await api.post<Preview>('whatsapp/contacts/import/preview', { token: upload!.token, mapping: mappingPayload(), strategy });
      setPreview(r.data);
      setStep('preview');
    } catch (e) {
      setError(fieldErrors(e)['mapping.phone'] ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const doRun = async () => {
    setError(null);
    setSrcError(null);
    if (preview && preview.counts.opt_in > 0 && !optSource.trim()) {
      setSrcError('Say where these contacts agreed to WhatsApp messages — it is stored with every opt-in.');
      return;
    }
    setBusy(true);
    try {
      const r = await api.post<Report>('whatsapp/contacts/import/run', { token: upload!.token, mapping: mappingPayload(), strategy, contact_type: contactType, tags, opt_in_source: optSource.trim() });
      setReport(r.data);
      setStep('done');
      toast.success(r.message);
      refresh();
    } catch (e) {
      const fe = fieldErrors(e);
      if (fe.opt_in_source) setSrcError(fe.opt_in_source);
      else setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const tagOptions = [...new Set([...(facets?.tags ?? []).map((t) => t.name), ...tags])].map((n) => ({ value: n, label: n }));
  const consentMapped = !!mapping.consent;

  const footer =
    step === 'upload' ? (
      <>
        <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
        <Button icon={Upload} onClick={doUpload} loading={busy} disabled={!file}>Upload &amp; continue</Button>
      </>
    ) : step === 'map' ? (
      <>
        <Button variant="secondary" onClick={() => setStep('upload')} disabled={busy}>Back</Button>
        <Button onClick={doPreview} loading={busy}>Preview import</Button>
      </>
    ) : step === 'preview' ? (
      <>
        <Button variant="secondary" onClick={() => setStep('map')} disabled={busy}>Back</Button>
        <Button onClick={doRun} loading={busy} disabled={!preview || preview.counts.create + preview.counts.update === 0}>
          Import {preview ? formatNumber(preview.counts.create + preview.counts.update) : ''} contacts
        </Button>
      </>
    ) : (
      <Button onClick={onClose}>Done</Button>
    );

  return (
    <Modal open={open} onClose={onClose} static={busy} size="xl" title="Import contacts" description="CSV or Excel (.xlsx), up to 10,000 rows. Contacts are matched on the WhatsApp number." footer={footer}>
      <div className="mb-5">
        <Stepper steps={STEPS} current={step} />
      </div>
      {error && <Alert variant="error" className="mb-4">{error}</Alert>}

      {step === 'upload' && (
        <div className="space-y-4">
          <FileUpload file={file} onFile={(f) => { setFile(f); setError(null); }} accept={facets?.xlsx === false ? '.csv,text/csv' : '.csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'} maxSize={5 * 1024 * 1024} onError={setError} />
          {facets?.xlsx === false && <p className="text-xs text-amber-700 dark:text-amber-300">This server cannot read Excel files (PHP zip extension missing) — save the sheet as CSV.</p>}
          <div className="flex flex-col gap-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300 sm:flex-row sm:items-center">
            <FileSpreadsheet className="h-5 w-5 shrink-0 text-emerald-600" aria-hidden />
            <span className="flex-1">The first row must hold column names. Include a <strong>WhatsApp consent</strong> column (Yes / No) — only rows with consent are opted in.</span>
            <Button size="sm" variant="secondary" icon={Download} onClick={() => downloadFile('whatsapp/contacts/import/template')}>Template</Button>
          </div>
          <ConsentHelp />
        </div>
      )}

      {step === 'map' && upload && (
        <div className="space-y-5">
          <p className="text-sm text-slate-600 dark:text-slate-300">
            <strong>{upload.file}</strong> — {formatNumber(upload.total_rows)} rows. Match each GIMT field to a column of your file (we guessed from the headers).
          </p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {upload.fields.map((f) => (
              <Field key={f.key} label={f.label} required={f.required} htmlFor={`wa-map-${f.key}`}>
                <Select inputSize="sm" id={`wa-map-${f.key}`} value={mapping[f.key] ?? ''} onChange={(e) => setMapping((m) => ({ ...m, [f.key]: e.target.value }))} options={headerOptions} placeholder="— Not in file —" />
              </Field>
            ))}
          </div>
          {!consentMapped && <Alert variant="warning" title="No consent column mapped">Contacts will be imported without WhatsApp opt-in; marketing templates will not be sent to them.</Alert>}
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="When the number already exists">
              <RadioGroup
                name="wa-import-strategy"
                inline={false}
                value={strategy}
                onChange={setStrategy}
                options={[
                  { value: 'skip', label: 'Skip — keep the existing contact unchanged' },
                  { value: 'update', label: 'Merge — fill empty fields and add tags' },
                  { value: 'overwrite', label: 'Overwrite — replace with the file’s values' },
                ]}
              />
            </Field>
            <div className="space-y-3">
              <Field label="Type for rows without a type" htmlFor="wa-imp-type">
                <Select inputSize="sm" id="wa-imp-type" value={contactType} onChange={(e) => setContactType(e.target.value)} options={TYPE_LABELS} />
              </Field>
              <Field label="Add tags to every imported contact">
                <Combobox multiple size="sm" options={tagOptions} value={tags} onChange={(v) => setTags(v.map(String))} placeholder="Optional" />
              </Field>
              {consentMapped && (
                <Field label="Where was the consent collected?" htmlFor="wa-imp-src" hint="Saved with each opt-in; required when the file has rows with consent.">
                  <Input inputSize="sm" id="wa-imp-src" value={optSource} onChange={(e) => setOptSource(e.target.value)} maxLength={80} placeholder="e.g. Education fair 2026 sign-up sheet" />
                </Field>
              )}
            </div>
          </div>
          {upload.sample.length > 0 && (
            <div className="table-wrap rounded-xl border border-slate-200 dark:border-slate-700">
              <table className="data-table text-xs">
                <thead>
                  <tr>{upload.headers.map((h, i) => <th key={i}>{h || `Column ${i + 1}`}</th>)}</tr>
                </thead>
                <tbody>
                  {upload.sample.map((r, i) => (
                    <tr key={i}>{r.map((c, j) => <td key={j} className="max-w-[12rem] truncate !py-1.5">{c}</td>)}</tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {step === 'preview' && preview && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
            <StatTile label="New" value={preview.counts.create} icon={UserPlus} tone="green" />
            <StatTile label="Update" value={preview.counts.update} icon={RefreshCw} tone="blue" />
            <StatTile label="Skip" value={preview.counts.skip} icon={SkipForward} tone="slate" />
            <StatTile label="Errors" value={preview.counts.error} icon={CircleAlert} tone="red" />
            <StatTile label="Opt-in" value={preview.counts.opt_in} icon={ShieldCheck} tone="cyan" />
          </div>
          {preview.counts.opt_in > 0 && (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
              <Field label={preview.counts.opt_in === 1 ? 'Where did this contact agree to WhatsApp messages?' : `Where did these ${formatNumber(preview.counts.opt_in)} contacts agree to WhatsApp messages?`} required error={srcError} htmlFor="wa-imp-src2" hint="Stored with every opt-in, e.g. “Education fair 2026 sign-up sheet” or “Admission form 2026-27 declaration”.">
                <Input id="wa-imp-src2" inputSize="sm" value={optSource} onChange={(e) => { setOptSource(e.target.value); setSrcError(null); }} maxLength={80} invalid={!!srcError} />
              </Field>
              <ConsentHelp className="mt-2 bg-white dark:bg-slate-900/40" />
            </div>
          )}
          <p className="text-xs text-slate-500">Rows with problems are listed first (up to 100 rows shown of {formatNumber(preview.total)}). Duplicates inside the file are skipped; existing numbers follow your merge choice.</p>
          <div className="table-wrap max-h-80 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700">
            <table className="data-table text-sm">
              <thead>
                <tr><th>Row</th><th>Number</th><th>Name</th><th>Action</th><th>Notes</th></tr>
              </thead>
              <tbody>
                {preview.rows.map((r) => (
                  <tr key={r.row}>
                    <td className="!py-1.5 text-xs text-slate-500">{r.row}</td>
                    <td className="!py-1.5 whitespace-nowrap">{r.phone ? formatPhone(`+${r.phone}`) : <span className="text-red-600">{r.raw_phone || '—'}</span>}</td>
                    <td className="!py-1.5">{r.name || <span className="text-slate-400">—</span>}{r.existing_name && r.existing_name !== r.name && <span className="block text-xs text-slate-500">Now: {r.existing_name}</span>}</td>
                    <td className="!py-1.5"><Badge color={ACTION_COLORS[r.action]}>{r.action === 'create' ? 'New' : r.action === 'update' ? 'Update' : r.action === 'skip' ? 'Skip' : 'Error'}</Badge></td>
                    <td className="!py-1.5 text-xs">
                      {[...r.errors, ...r.warnings].join('; ') || (r.consent ? <span className="text-emerald-600 dark:text-emerald-400">Consent: yes</span> : '')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {step === 'done' && report && (
        <div className="space-y-4">
          <Alert variant={report.failed ? 'warning' : 'success'} title="Import finished">
            {formatNumber(report.created)} new, {formatNumber(report.updated)} updated, {formatNumber(report.skipped)} skipped, {formatNumber(report.failed)} failed — {formatNumber(report.opted_in)} contacts opted in from <strong>{report.file}</strong>.
          </Alert>
          {report.errors.length > 0 && (
            <div className="table-wrap max-h-64 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700">
              <table className="data-table text-sm">
                <thead><tr><th>Row</th><th>Problem</th></tr></thead>
                <tbody>
                  {report.errors.map((e, i) => (
                    <tr key={i}><td className="!py-1.5">{e.row}</td><td className="!py-1.5 text-red-600 dark:text-red-400">{e.message}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
