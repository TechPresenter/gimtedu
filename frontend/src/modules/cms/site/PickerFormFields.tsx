import clsx from 'clsx';
import { Field, useToast } from '@/components/ui';
import { useCrudForm, type CrudField } from '@/components/crud';
import { FieldInput } from '@/components/crud/FieldInput';
import { ImageField } from './ImagePicker';

const colClass: Record<number, string> = {
  12: 'sm:col-span-12', 9: 'sm:col-span-9', 8: 'sm:col-span-8', 6: 'sm:col-span-6', 4: 'sm:col-span-4', 3: 'sm:col-span-3', 2: 'sm:col-span-2',
};

/**
 * CRUD form grid (same layout as CrudFormFields) where the fields named in `images` hold an image PATH chosen with the
 * CMS image picker — campus photos, the media library or a new upload — instead of a one-off file upload. Used by the
 * page details form (banner image) and the banner form (desktop / mobile image).
 */
export function PickerFormFields({ state, fields, id, images }: { state: ReturnType<typeof useCrudForm>; fields: CrudField[]; id?: number | null; images: string[] }) {
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
        const col = colClass[f.col ?? 6] ?? 'sm:col-span-6';
        if (images.includes(f.name)) {
          return (
            <Field key={f.name} className={clsx('col-span-1', col)} label={f.label} required={f.required} htmlFor={`f-${f.name}`} error={state.errors[f.name]} hint={f.help}>
              <ImageField id={`f-${f.name}`} value={String(state.values[f.name] ?? '')} onChange={(v) => state.setValue(f.name, v)} invalid={!!state.errors[f.name]} disabled={disabled} />
            </Field>
          );
        }
        return (
          <Field key={f.name} className={clsx('col-span-1', col)} label={f.label} required={f.required && !(f.type === 'password' && id)} htmlFor={`f-${f.name}`} error={state.errors[f.name]} hint={f.help}>
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
