import type { ReactNode } from 'react';
import clsx from 'clsx';
import { Alert, Button, Field, Modal, PageLoader, useToast, type ModalSize } from '@/components/ui';
import { useCrudForm } from '@/components/crud';
import { FieldInput } from '@/components/crud/FieldInput';
import type { CrudField } from '@/components/crud';
import { useCrudMeta } from '@/lib/queries';

const colClass: Record<number, string> = {
  12: 'sm:col-span-12', 9: 'sm:col-span-9', 8: 'sm:col-span-8', 6: 'sm:col-span-6', 4: 'sm:col-span-4', 3: 'sm:col-span-3', 2: 'sm:col-span-2',
};

export interface CustomFieldContext {
  field: CrudField;
  value: unknown;
  values: Record<string, unknown>;
  setValue: (v: unknown) => void;
  invalid: boolean;
  id: string;
}

interface ContentFormModalProps {
  open: boolean;
  onClose: () => void;
  module: string;
  id?: number | null;
  defaults?: Record<string, unknown>;
  onSaved?: (row: Record<string, unknown> | null, id: number) => void;
  /** Replace the control of specific fields (e.g. media pickers, star rating) */
  custom?: Record<string, (ctx: CustomFieldContext) => ReactNode>;
  /** Override column widths per field */
  cols?: Record<string, number>;
  title?: string;
  size?: ModalSize;
  intro?: ReactNode;
}

/**
 * CRUD form modal for the CMS content modules with pluggable controls for selected fields — same validation, inline
 * errors and toasts as the generic CrudFormModal.
 */
export function ContentFormModal({ open, onClose, module, id, defaults, onSaved, custom = {}, cols = {}, title, size, intro }: ContentFormModalProps) {
  const { data: meta, error } = useCrudMeta(module);
  const toast = useToast();
  const state = useCrudForm({
    meta: open ? meta : undefined,
    module,
    id,
    defaults,
    onSaved: (row, newId) => {
      onSaved?.(row, newId);
      onClose();
    },
  });
  const formId = `content-form-${module}`;
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={state.saving}
      size={size ?? meta?.form.size ?? 'lg'}
      title={title ?? (id ? `Edit ${meta?.singular?.toLowerCase() ?? ''}` : `Add ${meta?.singular?.toLowerCase() ?? ''}`)}
      description={id ? 'Update the details and save your changes.' : 'Fill in the details below. Fields marked * are required.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={state.saving}>
            Cancel
          </Button>
          <Button type="submit" form={formId} loading={state.saving} disabled={state.loading || !meta}>
            {id ? 'Save changes' : `Create ${meta?.singular?.toLowerCase() ?? ''}`}
          </Button>
        </>
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load the form">
          {(error as Error).message}
        </Alert>
      ) : state.loading || !meta ? (
        <PageLoader label="Loading…" />
      ) : (
        <form id={formId} onSubmit={state.submit} noValidate className="space-y-4">
          {intro}
          {state.formError && <Alert variant="error">{state.formError}</Alert>}
          <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">
            {state.visibleFields.map((f, i) => {
              if (f.type === 'section') {
                return (
                  <div key={`s${i}`} className={clsx('sm:col-span-12', i > 0 && 'mt-2')}>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">{f.label}</h3>
                    <div className="mt-2 border-t border-slate-100 dark:border-slate-800" />
                  </div>
                );
              }
              const fid = `f-${module}-${f.name}`;
              const err = state.errors[f.name];
              const renderCustom = custom[f.name];
              return (
                <Field
                  key={f.name}
                  className={clsx('col-span-1', colClass[cols[f.name] ?? f.col ?? 6] ?? 'sm:col-span-6')}
                  label={f.label}
                  required={f.required}
                  htmlFor={fid}
                  error={err}
                  hint={f.help}
                >
                  {renderCustom ? (
                    renderCustom({ field: f, value: state.values[f.name], values: state.values, setValue: (v) => state.setValue(f.name, v), invalid: !!err, id: fid })
                  ) : (
                    <FieldInput
                      field={{ ...f, name: `${module}-${f.name}` }}
                      value={state.values[f.name]}
                      values={state.values}
                      labels={state.labels[f.name]}
                      onChange={(v) => state.setValue(f.name, v)}
                      invalid={!!err}
                      file={state.files[f.name]}
                      onFile={(fl) => state.setFiles((s) => ({ ...s, [f.name]: fl }))}
                      removed={state.removed[f.name]}
                      onRemoveCurrent={() => state.setRemoved((s) => ({ ...s, [f.name]: true }))}
                      onError={(m) => toast.error(m)}
                      disabled={!!(f.readonly_on_edit && id)}
                    />
                  )}
                </Field>
              );
            })}
          </div>
        </form>
      )}
    </Modal>
  );
}
