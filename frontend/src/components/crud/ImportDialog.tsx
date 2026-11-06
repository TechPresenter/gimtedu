import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Download, FileSpreadsheet } from 'lucide-react';
import { Alert, Button, Field, FileUpload, Modal, RadioGroup, useToast } from '@/components/ui';
import { api, ApiError, downloadFile } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import type { ImportSummary } from './types';

/** A field the table is scoped to (e.g. the event of an embedded participants table). */
export interface ImportScopeField {
  name: string;
  label: string;
  value: string | number;
}

/* ---------------------------------------------------------------- minimal RFC 4180 CSV read / write */

function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          cell += '"';
          i++;
        } else quoted = false;
      } else cell += ch;
    } else if (ch === '"') quoted = true;
    else if (ch === ',') {
      row.push(cell);
      cell = '';
    } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
    } else cell += ch;
  }
  if (cell !== '' || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows;
}

const csvCell = (v: string) => (/[",\r\n]/.test(v) || v !== v.trim() ? `"${v.replace(/"/g, '""')}"` : v);
const headerKey = (h: string) => h.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');

/**
 * Scoped imports (e.g. participants inside one event): the server's import does not know the table's scope, so CSV
 * files get the scope column added / filled in the browser before upload. Cells that already hold a value are kept.
 * Excel files are uploaded unchanged (the dialog asks for the column instead).
 */
async function applyScope(file: File, scope: ImportScopeField[]): Promise<File> {
  if (!scope.length || !/\.csv$/i.test(file.name)) return file;
  const text = await file.text();
  const bom = text.charCodeAt(0) === 0xfeff;
  const rows = parseCsv(bom ? text.slice(1) : text);
  if (!rows.length) return file;
  const header = rows[0];
  scope.forEach((s) => {
    let idx = header.findIndex((h) => headerKey(h) === s.name.toLowerCase() || headerKey(h) === headerKey(s.label));
    if (idx === -1) {
      header.push(s.name);
      idx = header.length - 1;
    }
    for (let r = 1; r < rows.length; r++) {
      if (rows[r].every((c) => c.trim() === '')) continue;
      while (rows[r].length <= idx) rows[r].push('');
      if (rows[r][idx].trim() === '') rows[r][idx] = String(s.value);
    }
  });
  const out = (bom ? String.fromCharCode(0xfeff) : '') + rows.map((r) => r.map(csvCell).join(',')).join('\r\n');
  return new File([out], file.name, { type: 'text/csv' });
}

export function ImportDialog({ open, onClose, module, title, scope = [] }: { open: boolean; onClose: () => void; module: string; title: string; scope?: ImportScopeField[] }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [file, setFile] = useState<File | null>(null);
  const [mode, setMode] = useState('insert');
  const [busy, setBusy] = useState(false);
  const [summary, setSummary] = useState<ImportSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  const reset = () => {
    setFile(null);
    setSummary(null);
    setError(null);
  };
  const close = () => {
    reset();
    onClose();
  };
  const run = async () => {
    if (!file) {
      setError('Choose a CSV or Excel (.xlsx) file first.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const fd = new FormData();
      fd.append('file', await applyScope(file, scope));
      fd.append('mode', mode);
      const res = await api.post<ImportSummary>(`crud/${module}/import`, fd);
      setSummary(res.data);
      toast.success(res.message);
      await qc.invalidateQueries({ queryKey: ['crud', module] });
    } catch (e) {
      setError((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={close}
      size="lg"
      title={`Import ${title}`}
      description="Upload a CSV or Excel file. The first row must contain the column names from the template."
      footer={
        summary ? (
          <>
            <Button variant="secondary" onClick={reset}>
              Import another file
            </Button>
            <Button onClick={close}>Done</Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={close}>
              Cancel
            </Button>
            <Button icon={FileSpreadsheet} loading={busy} onClick={run}>
              Start import
            </Button>
          </>
        )
      }
    >
      {summary ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              ['Added', summary.inserted, 'text-emerald-600'],
              ['Updated', summary.updated, 'text-blue-600'],
              ['Skipped', summary.skipped, 'text-amber-600'],
              ['Failed', summary.failed, 'text-red-600'],
            ].map(([l, v, c]) => (
              <div key={String(l)} className="rounded-xl border border-slate-200 p-3 text-center dark:border-slate-700">
                <p className={`font-display text-2xl font-bold ${c}`}>{formatNumber(v)}</p>
                <p className="text-xs text-slate-500">{l}</p>
              </div>
            ))}
          </div>
          {summary.errors.length > 0 && (
            <div className="max-h-64 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Row</th>
                    <th>Issue</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.errors.map((e, i) => (
                    <tr key={i}>
                      <td className="!py-2 tabular-nums">{e.row}</td>
                      <td className="!py-2 text-xs">{e.message}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : (
        <div className="space-y-5">
          <Alert variant="info" action={<Button size="sm" variant="secondary" icon={Download} onClick={() => downloadFile(`crud/${module}/import-template`)}>Template</Button>}>
            Download the template, fill one record per row and upload it here. Dates use YYYY-MM-DD; for linked records you can use the ID or the exact name.
          </Alert>
          {scope.length > 0 && (
            <Alert variant="warning" title={`Rows are added to this ${scope.map((f) => f.label.toLowerCase()).join(' / ')}`}>
              CSV files get the {scope.map((f) => <code key={f.name} className="rounded bg-white/60 px-1 text-xs dark:bg-slate-900/60">{f.name}</code>)} column filled in automatically.
              In an Excel file, add that column with the value {scope.map((f) => <strong key={f.name}>{String(f.value)}</strong>)} on every row.
            </Alert>
          )}
          {error && <Alert variant="error">{error}</Alert>}
          <Field label="File" required>
            <FileUpload file={file} onFile={setFile} accept=".csv,.xlsx" maxSize={10 * 1024 * 1024} onError={setError} />
          </Field>
          <Field label="When a record already exists">
            <RadioGroup
              name="mode"
              value={mode}
              onChange={setMode}
              options={[
                { value: 'insert', label: 'Skip duplicates' },
                { value: 'upsert', label: 'Update existing records' },
              ]}
            />
          </Field>
        </div>
      )}
    </Modal>
  );
}
