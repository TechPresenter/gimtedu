import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Block, BlockDef, CmsSchema, Data, PreviewData, ShapeField } from './types';

/** Section + block definitions, icons and option lists (GET /api/cms/schema) — cached for the session. */
export function useCmsSchema() {
  return useQuery({ queryKey: ['cms', 'schema'], queryFn: () => api.get<CmsSchema>('cms/schema'), staleTime: 10 * 60 * 1000 });
}

/** Programs, faculty, testimonials … used to render previews. */
export function usePreviewData(enabled = true) {
  return useQuery({ queryKey: ['cms', 'preview-data'], queryFn: () => api.get<PreviewData>('cms/preview-data'), staleTime: 2 * 60 * 1000, enabled });
}

/** Does a field's `when` condition hold for the sibling values? (mirrors cms_when_matches()) */
export function whenMatches(field: ShapeField, values: Data): boolean {
  if (!field.when) return true;
  return Object.entries(field.when).every(([k, expected]) => {
    const actual = values?.[k];
    if (typeof expected === 'boolean') return !!actual === expected;
    const list = Array.isArray(expected) ? expected : [expected];
    return list.map(String).includes(String(actual ?? ''));
  });
}

/** Empty value for a field type (used for new repeater items). */
export function emptyValue(f: ShapeField): unknown {
  if (f.default !== undefined) return f.default;
  switch (f.type) {
    case 'link':
      return { label: '', url: '' };
    case 'multiselect':
    case 'list':
    case 'repeater':
    case 'programs':
      return [];
    case 'toggle':
      return false;
    case 'number':
    case 'department':
    case 'gallery':
      return null;
    default:
      return '';
  }
}

export function emptyItem(fields: ShapeField[] = []): Data {
  const out: Data = {};
  fields.forEach((f) => {
    out[f.name] = emptyValue(f);
  });
  return out;
}

let seq = 0;
/** Client-side block id (server accepts [a-z0-9_-]{1,40}). */
export function newBlockId(): string {
  seq += 1;
  return `b${Date.now().toString(36)}${seq.toString(36)}`.slice(0, 20);
}

export function newBlock(def: BlockDef): Block {
  return {
    id: newBlockId(),
    type: def.type,
    hidden: false,
    settings: { anchor: '', background: 'white', padding: 'normal' },
    data: { ...emptyItem(def.fields), ...JSON.parse(JSON.stringify(def.default ?? {})) },
  };
}

/** Errors for a given path prefix, with the prefix stripped ("blocks.2.data.title" → "title"). */
export function errorsUnder(errors: Record<string, string>, prefix: string): Record<string, string> {
  const out: Record<string, string> = {};
  Object.entries(errors).forEach(([k, v]) => {
    if (k === prefix) out[''] = v;
    else if (k.startsWith(`${prefix}.`)) out[k.slice(prefix.length + 1)] = v;
  });
  return out;
}

export function hasErrorsUnder(errors: Record<string, string>, prefix: string): boolean {
  return Object.keys(errors).some((k) => k === prefix || k.startsWith(`${prefix}.`));
}

/** Short human summary of a block (outline list). */
export function blockSummary(b: Block): string {
  const d = b.data ?? {};
  const first = [d.title, d.heading, d.caption, d.alt, d.eyebrow].find((v) => typeof v === 'string' && v.trim());
  if (first) return String(first);
  if (b.type === 'text' && typeof d.html === 'string') return d.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80) || 'Empty text';
  if (Array.isArray(d.items)) return `${d.items.length} item${d.items.length === 1 ? '' : 's'}`;
  return 'Untitled';
}

/** Accepts "/about", "#x", "https://…", "mailto:", "tel:" (mirrors cms_valid_url()). */
export function isValidLink(v: string): boolean {
  if (!v) return true;
  if (/[\s<>"]/.test(v) || /^\s*(javascript|data|vbscript):/i.test(v)) return false;
  if (v.startsWith('/') || v.startsWith('#') || v.startsWith('?')) return !v.startsWith('//');
  if (/^(mailto:|tel:)\S+$/i.test(v)) return true;
  return /^https?:\/\/[^\s/$.?#][^\s]*$/i.test(v);
}

export function deepClone<T>(v: T): T {
  return JSON.parse(JSON.stringify(v)) as T;
}
