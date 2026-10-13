import { useQuery } from '@tanstack/react-query';
import { Download, Pencil } from 'lucide-react';
import { Button, DescriptionList, Modal, PageLoader, StatusBadge } from '@/components/ui';
import { api, apiUrl } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatMoney, formatNumber, formatTime } from '@/lib/format';
import type { CrudField, CrudMeta, CrudRecordPayload } from './types';

function display(f: CrudField, v: unknown, labels?: { value: unknown; label: string }[]) {
  if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) return null;
  switch (f.type) {
    case 'date':
      return formatDate(v);
    case 'datetime':
      return formatDateTime(v);
    case 'time':
      return formatTime(v);
    case 'money':
      return formatMoney(v);
    case 'number':
    case 'decimal':
      return /year/i.test(f.name) ? String(v) : formatNumber(v, f.type === 'decimal' ? 2 : 0);
    case 'boolean':
    case 'toggle':
    case 'checkbox':
      return v ? 'Yes' : 'No';
    case 'select':
    case 'radio':
    case 'combobox': {
      const opt = [...(f.options ?? []), ...(labels ?? [])].find((o) => String(o.value) === String(v));
      if (f.name === 'status' || f.name.endsWith('_status') || f.name === 'stage') return <StatusBadge status={String(v)} label={opt?.label} />;
      return opt?.label ?? String(v);
    }
    case 'multiselect': {
      const arr = Array.isArray(v) ? v : String(v).split(',');
      return arr.map((x) => [...(f.options ?? []), ...(labels ?? [])].find((o) => String(o.value) === String(x))?.label ?? String(x)).join(', ');
    }
    case 'image':
      return <img src={appUrl(String(v))} alt="" className="h-28 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" />;
    case 'file':
      return (
        <a className="link inline-flex items-center gap-1" href={f.private ? apiUrl('files/download', { path: String(v) }) : appUrl(String(v))} target="_blank" rel="noreferrer">
          <Download className="h-3.5 w-3.5" /> {String(v).split('/').pop()}
        </a>
      );
    case 'richtext':
      return <div className="prose-sm max-w-none text-slate-700 dark:text-slate-200 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5" dangerouslySetInnerHTML={{ __html: String(v) }} />;
    case 'password':
      return '••••••••';
    case 'color':
      return <span className="inline-flex items-center gap-2"><span className="h-4 w-4 rounded" style={{ background: String(v) }} />{String(v)}</span>;
    default:
      return String(v);
  }
}

interface Props {
  open: boolean;
  onClose: () => void;
  meta: CrudMeta | undefined;
  module: string;
  id: number | null;
  onEdit?: () => void;
}

/** Read-only details of a record using the module's field definitions. */
export function CrudViewModal({ open, onClose, meta, module, id, onEdit }: Props) {
  const { data, isLoading } = useQuery({
    queryKey: ['crud-record', module, id],
    queryFn: () => api.get<CrudRecordPayload>(`crud/${module}/${id}`),
    enabled: open && !!id,
  });
  const fields = (meta?.fields ?? []).filter((f) => f.type !== 'section' && f.type !== 'password' && !f.hidden);
  return (
    <Modal
      open={open}
      onClose={onClose}
      size={meta?.form.size === 'xl' || meta?.form.size === '2xl' ? 'xl' : 'lg'}
      title={`${meta?.singular ?? 'Record'} details`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
          {onEdit && meta?.can.edit && (
            <Button icon={Pencil} onClick={onEdit}>
              Edit
            </Button>
          )}
        </>
      }
    >
      {isLoading || !data ? (
        <PageLoader />
      ) : (
        <DescriptionList
          items={fields.map((f) => ({
            label: f.label,
            value: display(f, data.values[f.name] ?? data.row[f.name], (data.labels as Record<string, { value: unknown; label: string }[]>)?.[f.name]),
            full: ['textarea', 'richtext', 'json', 'image'].includes(f.type) || (f.col ?? 6) >= 12,
          }))}
        />
      )}
    </Modal>
  );
}
