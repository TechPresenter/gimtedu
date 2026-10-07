import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import clsx from 'clsx';
import { AlertCircle, Search, X } from 'lucide-react';
import type { Option } from '@/lib/types';

interface FieldProps {
  label?: ReactNode;
  required?: boolean;
  error?: string | null;
  hint?: ReactNode;
  htmlFor?: string;
  className?: string;
  children: ReactNode;
  /** right-aligned helper (e.g. character count) */
  aside?: ReactNode;
}

/** Label + control + inline error/hint wrapper. */
export function Field({ label, required, error, hint, htmlFor, className, children, aside }: FieldProps) {
  return (
    <div className={className}>
      {label && (
        <div className="flex items-baseline justify-between gap-2">
          <label htmlFor={htmlFor} className="form-label">
            {label}
            {required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
          </label>
          {aside && <span className="text-xs text-slate-400">{aside}</span>}
        </div>
      )}
      {children}
      {error ? (
        <p className="form-error" role="alert">
          <AlertCircle className="h-3.5 w-3.5" aria-hidden />
          {error}
        </p>
      ) : hint ? (
        <p className="form-hint">{hint}</p>
      ) : null}
    </div>
  );
}

interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
  invalid?: boolean;
  prefix?: ReactNode;
  suffix?: ReactNode;
  inputSize?: 'sm' | 'md';
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ invalid, prefix, suffix, className, inputSize = 'md', ...rest }, ref) {
  const input = (
    <input
      ref={ref}
      aria-invalid={invalid || undefined}
      className={clsx('form-input', inputSize === 'sm' && 'form-input-sm', invalid && 'form-input-error', prefix && 'pl-9', suffix && 'pr-12', className)}
      {...rest}
    />
  );
  if (!prefix && !suffix) return input;
  return (
    <div className="relative">
      {prefix && <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-slate-400">{prefix}</span>}
      {input}
      {suffix && <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-slate-400">{suffix}</span>}
    </div>
  );
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(function Textarea({ invalid, className, rows = 3, ...rest }, ref) {
  return <textarea ref={ref} rows={rows} aria-invalid={invalid || undefined} className={clsx('form-input resize-y', invalid && 'form-input-error', className)} {...rest} />;
});

interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
  options: Option[] | Record<string, string>;
  placeholder?: string;
  invalid?: boolean;
  inputSize?: 'sm' | 'md';
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select({ options, placeholder, invalid, className, inputSize = 'md', ...rest }, ref) {
  const opts: Option[] = Array.isArray(options) ? options : Object.entries(options).map(([value, label]) => ({ value, label }));
  return (
    <select ref={ref} aria-invalid={invalid || undefined} className={clsx('form-input pr-9', inputSize === 'sm' && 'form-input-sm', invalid && 'form-input-error', className)} {...rest}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {opts.map((o) => (
        <option key={String(o.value)} value={String(o.value)}>
          {o.label}
        </option>
      ))}
    </select>
  );
});

export function Checkbox({ label, description, className, ...rest }: InputHTMLAttributes<HTMLInputElement> & { label?: ReactNode; description?: ReactNode }) {
  const id = useId();
  return (
    <div className={clsx('flex items-start gap-2.5', className)}>
      <input id={rest.id ?? id} type="checkbox" className="form-checkbox mt-0.5" {...rest} />
      {(label || description) && (
        <label htmlFor={rest.id ?? id} className="cursor-pointer select-none text-sm">
          <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
          {description && <span className="block text-xs text-slate-500">{description}</span>}
        </label>
      )}
    </div>
  );
}

interface ToggleProps {
  checked: boolean;
  onChange: (v: boolean) => void;
  label?: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
  id?: string;
}

/** Accessible switch. */
export function Toggle({ checked, onChange, label, description, disabled, id }: ToggleProps) {
  const autoId = useId();
  const tid = id ?? autoId;
  return (
    <div className="flex items-start gap-3">
      <button
        id={tid}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={clsx(
          'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
          checked ? 'bg-accent-600' : 'bg-slate-200 dark:bg-slate-700',
        )}
      >
        <span className={clsx('pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition', checked ? 'translate-x-5' : 'translate-x-0')} />
      </button>
      {(label || description) && (
        <label htmlFor={tid} className="cursor-pointer select-none text-sm">
          <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
          {description && <span className="block text-xs text-slate-500 dark:text-slate-400">{description}</span>}
        </label>
      )}
    </div>
  );
}

export function RadioGroup({ name, value, onChange, options, inline = true }: { name: string; value: string; onChange: (v: string) => void; options: Option[]; inline?: boolean }) {
  return (
    <div className={clsx('flex gap-x-5 gap-y-2', inline ? 'flex-wrap' : 'flex-col')} role="radiogroup">
      {options.map((o) => (
        <label key={String(o.value)} className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
          <input type="radio" name={name} value={String(o.value)} checked={String(value) === String(o.value)} onChange={() => onChange(String(o.value))} className="h-4 w-4 border-slate-300 text-brand-700 focus:ring-brand-500/30" />
          {o.label}
        </label>
      ))}
    </div>
  );
}

interface SearchInputProps {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  className?: string;
  autoFocus?: boolean;
  size?: 'sm' | 'md';
}

export function SearchInput({ value, onChange, placeholder = 'Search…', className, autoFocus, size = 'md' }: SearchInputProps) {
  return (
    <div className={clsx('relative', className)}>
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
      <input
        type="search"
        value={value}
        autoFocus={autoFocus}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className={clsx('form-input pl-9 pr-8 [&::-webkit-search-cancel-button]:hidden', size === 'sm' && 'form-input-sm')}
      />
      {value && (
        <button type="button" onClick={() => onChange('')} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Clear search">
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  );
}
