import { useEffect, useId, useState } from 'react';
import { Plus } from 'lucide-react';
import { Button, Field, Input, Modal, Select, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useWaRefresh } from '../shared';
import type { WaEvent, WaEventOptions } from './types';

const EMPTY = { name: '', code: '', category: 'custom', source: 'api', description: '', sample_payload: '{\n  "phone": "+919876543210",\n  "name": "Student Name"\n}' };

function codeFrom(name: string) {
  const slug = name.toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  return slug ? `GIMT_CUSTOM_${slug}`.slice(0, 80) : '';
}

/** Create a custom (non-system) event for an external system, the website or a workflow. */
export function CustomEventModal({ open, onClose, options, onCreated }: { open: boolean; onClose: () => void; options: WaEventOptions | undefined; onCreated: (e: WaEvent) => void }) {
  const toast = useToast();
  const refresh = useWaRefresh();
  const uid = useId();
  const [f, setF] = useState(EMPTY);
  const [codeTouched, setCodeTouched] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (open) {
      setF(EMPTY);
      setErrors({});
      setCodeTouched(false);
    }
  }, [open]);

  const set = (k: keyof typeof EMPTY, v: string) => setF((x) => ({ ...x, [k]: v, ...(k === 'name' && !codeTouched ? { code: codeFrom(v) } : {}) }));

  const submit = async () => {
    const errs: Record<string, string> = {};
    if (f.name.trim().length < 3) errs.name = 'Enter a name (at least 3 characters).';
    if (!/^[A-Z][A-Z0-9_]{2,79}$/.test(f.code)) errs.code = 'Capital letters, digits and underscores only (e.g. GIMT_CUSTOM_OPEN_DAY).';
    try {
      const v = JSON.parse(f.sample_payload || '{}');
      if (!v || typeof v !== 'object' || Array.isArray(v)) errs.sample_payload = 'Must be a JSON object.';
    } catch {
      errs.sample_payload = 'Not valid JSON.';
    }
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy(true);
    try {
      const r = await api.post<WaEvent>('whatsapp/events', f);
      toast.success(r.message || 'Custom event created.');
      await refresh('events');
      onCreated(r.data);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      size="lg"
      title="New custom event"
      description="Fire it from an external system (API token), website JavaScript, a workflow or GIMT code; then link a template and activate it."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button icon={Plus} loading={busy} onClick={submit}>Create event</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Name" required htmlFor={`${uid}-n`} error={errors.name} className="sm:col-span-2">
          <Input id={`${uid}-n`} value={f.name} maxLength={150} autoFocus placeholder="Open day registration" invalid={!!errors.name} onChange={(e) => set('name', e.target.value)} />
        </Field>
        <Field label="Event code" required htmlFor={`${uid}-c`} error={errors.code} hint="Used by the API and in code. Cannot be changed later." className="sm:col-span-2">
          <Input id={`${uid}-c`} className="font-mono" value={f.code} maxLength={80} invalid={!!errors.code} onChange={(e) => { setCodeTouched(true); set('code', e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_')); }} />
        </Field>
        <Field label="Category" htmlFor={`${uid}-cat`} error={errors.category}>
          <Select id={`${uid}-cat`} value={f.category} onChange={(e) => set('category', e.target.value)} options={Object.entries(options?.categories ?? { custom: 'Custom events' }).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Source" htmlFor={`${uid}-src`} error={errors.source}>
          <Select id={`${uid}-src`} value={f.source} onChange={(e) => set('source', e.target.value)} options={Object.entries(options?.sources ?? { api: 'External API' }).filter(([value]) => value !== 'cron').map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Description" htmlFor={`${uid}-d`} className="sm:col-span-2">
          <Textarea id={`${uid}-d`} rows={2} value={f.description} onChange={(e) => set('description', e.target.value)} />
        </Field>
        <Field label="Sample payload (JSON)" htmlFor={`${uid}-p`} error={errors.sample_payload} hint="The keys you will send; they become the mappable template variables." className="sm:col-span-2">
          <Textarea id={`${uid}-p`} rows={6} spellCheck={false} className="font-mono text-xs" value={f.sample_payload} invalid={!!errors.sample_payload} onChange={(e) => set('sample_payload', e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}
