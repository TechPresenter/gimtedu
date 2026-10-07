import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Download, FileSpreadsheet } from 'lucide-react';
import { Alert, Button, Field, FileUpload, Modal, RadioGroup, useToast } from '@/components/ui';
import { api, ApiError, downloadFile } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import type { ImportSummary } from './types';

export function ImportDialog({ open, onClose, module, title }: { open: boolean; onClose: () => void; module: string; title: string }) {
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
      fd.append('file', file);
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
