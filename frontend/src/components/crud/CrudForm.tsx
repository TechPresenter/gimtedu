import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import { Field, Button, Modal, Alert, PageLoader, useToast, type ModalSize } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import type { Option } from '@/lib/types';
import { FieldInput } from './FieldInput';
import type { CrudField, CrudMeta, CrudRecordPayload } from './types';

const colClass: Record<number, string> = {
  12: 'sm:col-span-12', 9: 'sm:col-span-9', 8: 'sm:col-span-8', 6: 'sm:col-span-6', 4: 'sm:col-span-4', 3: 'sm:col-span-3', 2: 'sm:col-span-2',
};

export interface UseCrudFormOptions {
  meta: CrudMeta | undefined;
  module: string;
  id?: number | null;
  defaults?: Record<string, unknown>;
  scope?: Record<string, string | number>;
  onSaved?: (row: Record<string, unknown> | null, id: number) => void;
}

/** State + submit logic for a CRUD form (used by modal and full-page forms). */
export function useCrudForm({ meta, module, id, defaults, scope, onSaved }: UseCrudFormOptions) {
  const toast = useToast();
  const qc = useQueryClient();
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [labels, setLabels] = useState<Record<string, Option[]>>({});
  const [files, setFiles] = useState<Record<string, File | null>>({});
  const [removed, setRemoved] = useState<Record<string, boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!meta) return;
    setErrors({});
    setFormError(null);
    setFiles({});
    setRemoved({});
    if (id) {
      setLoading(true);
      api
        .get<CrudRecordPayload>(`crud/${module}/${id}`)
        .then((p) => {
          setValues(p.values);
          setLabels((p.labels as Record<string, Option[]>) ?? {});
        })
        .catch((e: ApiError) => setFormError(e.message))
        .finally(() => setLoading(false));
    } else {
      const init: Record<string, unknown> = {};
      meta.fields.forEach((f) => {
        if (f.type === 'section') return;
        init[f.name] = f.default ?? (['boolean', 'toggle', 'checkbox'].includes(f.type) ? false : f.type === 'multiselect' ? [] : '');
      });
      setValues({ ...init, ...(defaults ?? {}) });
      setLabels({});
    }
  }, [meta, module, id, defaults]);

  const setValue = (name: string, v: unknown) => {
    setValues((prev) => {
      const next = { ...prev, [name]: v };
      // Clear dependent children when a parent changes
      meta?.fields.forEach((f) => {
        if (f.depends && Object.values(f.depends).includes(name) && prev[name] !== v) next[f.name] = f.type === 'multiselect' ? [] : '';
        if (f.type === 'slug' && f.slug_from === name && !id) {
          next[f.name] = String(v ?? '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        }
      });
      return next;
    });
    if (errors[name]) setErrors((e) => ({ ...e, [name]: '' }));
  };

  const visibleFields = useMemo(
    () => (meta?.fields ?? []).filter((f) => !f.hidden && f.type !== 'hidden' && !(f.create_only && id) && !(f.edit_only && !id)),
    [meta, id],
  );

  const submit = async (e?: FormEvent) => {
    e?.preventDefault();
    if (!meta) return false;
    // Client-side required check (server validates again)
    const clientErrors: Record<string, string> = {};
    visibleFields.forEach((f) => {
      if (!f.required || f.type === 'section' || f.readonly_on_edit && id) return;
      if (f.type === 'image' || f.type === 'file') {
        if (!id && !files[f.name]) clientErrors[f.name] = `${f.label} is required.`;
        return;
      }
      if (f.type === 'password' && id) return;
      const v = values[f.name];
      if (v === '' || v === null || v === undefined || (Array.isArray(v) && !v.length)) clientErrors[f.name] = `${f.label} is required.`;
    });
    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors);
      setFormError('Please fill in the required fields.');
      return false;
    }
    setSaving(true);
    setFormError(null);
    try {
      const payload: Record<string, unknown> = {};
      visibleFields.forEach((f) => {
        if (f.type === 'section' || f.type === 'image' || f.type === 'file') return;
        if (f.readonly_on_edit && id) return;
        payload[f.name] = values[f.name];
      });
      Object.entries(files).forEach(([k, fl]) => fl && (payload[k] = fl));
      Object.entries(removed).forEach(([k, r]) => r && (payload[`${k}__remove`] = 1));
      if (scope && !id) payload.__scope = scope;
      const hasFiles = Object.values(files).some(Boolean);
      const body = hasFiles ? toFormData(payload) : payload;
      const res = await api.post<{ id: number; row: Record<string, unknown> | null }>(id ? `crud/${module}/${id}` : `crud/${module}`, body);
      toast.success(res.message || `${meta.singular} saved successfully.`);
      await qc.invalidateQueries({ queryKey: ['crud', module] });
      onSaved?.(res.data.row, res.data.id);
      return true;
    } catch (err) {
      const e2 = err as ApiError;
      setErrors(e2.errors ?? {});
      setFormError(e2.message || 'Unable to save. Please try again.');
      if (!Object.keys(e2.errors ?? {}).length) toast.error(e2.message || `Unable to save ${meta.singular.toLowerCase()}. Please try again.`);
      return false;
    } finally {
      setSaving(false);
    }
  };

  return { values, setValue, labels, files, setFiles, removed, setRemoved, errors, setErrors, formError, loading, saving, submit, visibleFields };
}

type FormState = ReturnType<typeof useCrudForm>;

/** Grid of fields for a CRUD form. */
export function CrudFormFields({ state, fields, id }: { state: FormState; fields: CrudField[]; id?: number | null }) {
  const toast = useToast();
  return (
    <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">
      {fields.map((f, i) => {
        if (f.type === 'section') {
          return (
            <div key={`s${i}`} className={clsx('sm:col-span-12', i > 0 && 'mt-2')}>
              <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">{f.label}</h3>
              {f.help && <p className="mt-0.5 text-xs text-slate-500">{f.help}</p>}
              <div className="mt-2 border-t border-slate-100 dark:border-slate-800" />
            </div>
          );
        }
        const disabled = !!(f.readonly_on_edit && id);
        return (
          <Field key={f.name} className={clsx('col-span-1', colClass[f.col ?? 6] ?? 'sm:col-span-6')} label={f.label} required={f.required && !(f.type === 'password' && id)} htmlFor={`f-${f.name}`} error={state.errors[f.name]} hint={f.help}>
            <FieldInput
              field={f}
              value={state.values[f.name]}
              values={state.values}
              labels={state.labels[f.name]}
              onChange={(v) => state.setValue(f.name, v)}
              invalid={!!state.errors[f.name]}
              file={state.files[f.name]}
              onFile={(fl) => state.setFiles((s) => ({ ...s, [f.name]: fl }))}
              removed={state.removed[f.name]}
              onRemoveCurrent={() => state.setRemoved((s) => ({ ...s, [f.name]: true }))}
              onError={(m) => toast.error(m)}
              disabled={disabled}
            />
          </Field>
        );
      })}
    </div>
  );
}

interface CrudFormModalProps {
  open: boolean;
  onClose: () => void;
  meta: CrudMeta | undefined;
  module: string;
  id?: number | null;
  defaults?: Record<string, unknown>;
  scope?: Record<string, string | number>;
  onSaved?: (row: Record<string, unknown> | null, id: number) => void;
  title?: string;
  size?: ModalSize;
  /** Extra content shown above the fields */
  intro?: ReactNode;
}

export function CrudFormModal({ open, onClose, meta, module, id, defaults, scope, onSaved, title, size, intro }: CrudFormModalProps) {
  const state = useCrudForm({
    meta: open ? meta : undefined,
    module,
    id,
    defaults,
    scope,
    onSaved: (row, newId) => {
      onSaved?.(row, newId);
      onClose();
    },
  });
  const formId = `crud-form-${module}`;
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={state.saving}
      size={size ?? meta?.form.size ?? 'lg'}
      title={title ?? (id ? `Edit ${meta?.singular ?? ''}` : `Add ${meta?.singular ?? ''}`)}
      description={id ? 'Update the details below and save your changes.' : 'Fill in the details below. Fields marked * are required.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={state.saving}>
            Cancel
          </Button>
          <Button type="submit" form={formId} loading={state.saving} disabled={state.loading}>
            {id ? 'Save changes' : `Create ${meta?.singular?.toLowerCase() ?? ''}`}
          </Button>
        </>
      }
    >
      {state.loading || !meta ? (
        <PageLoader label="Loading record…" />
      ) : (
        <form id={formId} onSubmit={state.submit} noValidate className="space-y-4">
          {intro}
          {state.formError && <Alert variant="error">{state.formError}</Alert>}
          <CrudFormFields state={state} fields={state.visibleFields} id={id} />
        </form>
      )}
    </Modal>
  );
}
