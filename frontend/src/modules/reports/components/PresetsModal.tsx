import { useEffect, useState, type FormEvent } from 'react';
import { BookmarkPlus, Bookmark, Play, Trash2 } from 'lucide-react';
import { Button, EmptyState, Field, IconButton, Input, Modal, useToast } from '@/components/ui';
import { formatDateTime } from '@/lib/format';
import { loadPresets, savePresets, type Preset, type ReportMeta } from '../lib';

interface Props {
  open: boolean;
  onClose: () => void;
  meta: ReportMeta;
  userId: number | undefined;
  /** Filter part of the current URL (see filterQueryString). */
  currentQuery: string;
  onApply: (query: string) => void;
}

/** Human summary of a saved query: "Program: BBA · Status: Overdue · 01 Sep – 30 Sep". */
export function describeQuery(meta: ReportMeta, query: string): string {
  const p = new URLSearchParams(query);
  const parts: string[] = [];
  if (p.get('from') || p.get('to')) parts.push(`${p.get('from') ?? '…'} → ${p.get('to') ?? '…'}`);
  meta.filters.forEach((f) => {
    if (f.type === 'daterange') return;
    const v = p.get(f.param);
    if (v === null) return;
    const label = v === 'all' ? (f.all_label ?? 'All') : (f.options?.find((o) => o.value === v)?.label ?? v);
    parts.push(`${f.label}: ${label}`);
  });
  if (p.get('q')) parts.push(`Search “${p.get('q')}”`);
  if (p.get('sort')) {
    const col = meta.columns.find((c) => c.key === p.get('sort'));
    parts.push(`Sorted by ${col?.label ?? p.get('sort')} ${p.get('dir') === 'desc' ? '↓' : '↑'}`);
  }
  return parts.length ? parts.join(' · ') : 'Default filters';
}

/** Saved filter presets per user and report — kept in this browser's localStorage. */
export function PresetsModal({ open, onClose, meta, userId, currentQuery, onApply }: Props) {
  const toast = useToast();
  const [presets, setPresets] = useState<Preset[]>([]);
  const [name, setName] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (open) {
      setPresets(loadPresets(userId, meta.key));
      setError('');
    }
  }, [open, userId, meta.key]);

  const persist = (next: Preset[]) => {
    setPresets(next);
    savePresets(userId, meta.key, next);
  };

  const save = (e: FormEvent) => {
    e.preventDefault();
    const n = name.trim();
    if (!n) return setError('Give the preset a name.');
    if (n.length > 60) return setError('Keep the name under 60 characters.');
    if (presets.some((p) => p.name.toLowerCase() === n.toLowerCase())) return setError('A preset with this name already exists.');
    if (presets.length >= 20) return setError('You can keep up to 20 presets per report. Delete one first.');
    persist([{ id: `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`, name: n, query: currentQuery, created_at: new Date().toISOString() }, ...presets]);
    setName('');
    setError('');
    toast.success(`Preset “${n}” saved.`);
  };

  const remove = (p: Preset) => {
    persist(presets.filter((x) => x.id !== p.id));
    toast.success(`Preset “${p.name}” deleted.`);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      icon={<Bookmark className="h-5 w-5" />}
      title="Saved filter presets"
      description={`Save the current filters of “${meta.title}” and reopen them in one click. Presets are stored in this browser for your account — use Copy link to share a view with colleagues.`}
    >
      <form onSubmit={save} className="flex flex-col gap-2 sm:flex-row sm:items-start" noValidate>
        <Field label="Save the current filters as" htmlFor="preset-name" error={error} className="flex-1" hint={`Current filters: ${describeQuery(meta, currentQuery)}`}>
          <Input id="preset-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. BBA – overdue this month" maxLength={60} invalid={!!error} />
        </Field>
        <Button type="submit" icon={BookmarkPlus} className="sm:mt-6">
          Save preset
        </Button>
      </form>

      <div className="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Your presets ({presets.length})</h3>
        {presets.length === 0 ? (
          <EmptyState icon={Bookmark} title="No presets yet" description="Set the filters you use often, give them a name above and save." className="!py-6" />
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {presets.map((p) => (
              <li key={p.id} className="flex items-center gap-3 py-2.5">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{p.name}</p>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400" title={describeQuery(meta, p.query)}>
                    {describeQuery(meta, p.query)} · saved {formatDateTime(p.created_at)}
                  </p>
                </div>
                <Button size="sm" variant="soft" icon={Play} onClick={() => { onApply(p.query); onClose(); toast.success(`Applied “${p.name}”.`); }}>
                  Apply
                </Button>
                <IconButton size="sm" icon={Trash2} label={`Delete preset ${p.name}`} tone="danger" onClick={() => remove(p)} />
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  );
}
