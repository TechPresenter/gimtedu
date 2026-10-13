import type { Option } from '@/lib/types';

export type FieldType =
  | 'text' | 'email' | 'tel' | 'url' | 'password' | 'number' | 'decimal' | 'money' | 'date' | 'datetime' | 'time' | 'color'
  | 'textarea' | 'richtext' | 'select' | 'combobox' | 'multiselect' | 'radio' | 'boolean' | 'toggle' | 'checkbox'
  | 'image' | 'file' | 'slug' | 'json' | 'hidden' | 'section' | 'repeater';

export interface CrudField {
  name: string;
  label: string;
  type: FieldType;
  required?: boolean;
  col?: number;
  placeholder?: string;
  help?: string;
  default?: unknown;
  rows?: number;
  accept?: string;
  max_size?: number;
  multiple?: boolean;
  readonly_on_edit?: boolean;
  create_only?: boolean;
  edit_only?: boolean;
  min?: number;
  max?: number;
  step?: number;
  depends?: Record<string, string>;
  slug_from?: string;
  prefix?: string;
  suffix?: string;
  maxlength?: number;
  options?: Option[];
  options_url?: string;
  async?: boolean;
  private?: boolean;
  hidden?: boolean;
  pattern?: string;
}

export type ColumnFormat =
  | 'text' | 'title' | 'person' | 'badge' | 'date' | 'datetime' | 'time' | 'money' | 'number' | 'percent' | 'boolean'
  | 'email' | 'phone' | 'image' | 'link' | 'tags' | 'color' | 'truncate' | 'code';

export interface CrudColumn {
  key: string;
  label: string;
  format: ColumnFormat;
  sortable: boolean;
  sub?: string;
  image?: string;
  link?: string;
  align?: 'left' | 'center' | 'right';
  hidden?: boolean;
  colors?: Record<string, string>;
  width?: string;
  truncate?: number;
  prefix?: string;
  suffix?: string;
}

export interface CrudFilter {
  key: string;
  label: string;
  type: 'select' | 'multiselect' | 'date' | 'daterange' | 'text' | 'boolean';
  options?: Option[];
  options_url?: string;
  async?: boolean;
  placeholder?: string;
  default?: unknown;
  depends?: Record<string, string>;
}

export interface CrudMeta {
  key: string;
  title: string;
  singular: string;
  description: string;
  icon: string;
  can: { view: boolean; create: boolean; edit: boolean; delete: boolean; export: boolean; import: boolean };
  columns: CrudColumn[];
  filters: CrudFilter[];
  fields: CrudField[];
  bulk: { delete: boolean; status: string[] | null; status_field: string };
  export: boolean;
  import: boolean;
  view: { type: 'modal' | 'page'; url?: string };
  form: { size: 'sm' | 'md' | 'lg' | 'xl' | '2xl'; mode: 'modal' | 'page' };
  per_page: number;
  search_placeholder: string;
  default_sort: { key: string; dir: 'asc' | 'desc' } | null;
}

export interface CrudRecordPayload {
  row: Record<string, unknown>;
  values: Record<string, unknown>;
  labels: Record<string, Option[]>;
}

export interface ImportSummary {
  total: number;
  inserted: number;
  updated: number;
  skipped: number;
  failed: number;
  errors: { row: number; message: string }[];
}
