import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Link2 } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, PageLoader, Select, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import type { CrudRecordPayload } from '@/components/crud';
import { CODE_OPTIONS, type RedirectCheck, type RedirectDraft } from './types';

interface Props {
  open: boolean;
  onClose: () => void;
  /** Record to edit, or null to create */
  id?: number | null;
  /** Prefill for a new rule (e.g. from the 404 report) */
  initial?: Partial<RedirectDraft>;
  onSaved?: (id: number) => void;
}

const EMPTY: RedirectDraft = { from_path: '', to_url: '', status_code: '301', preserve_query: true, is_active: true, notes: '' };

/**
 * Add / edit a redirect with live server validation (POST /api/redirects/check): normalised URLs, loop errors, chain
 * flattening, "the old URL is a live page" and "the target does not exist" notes — before saving through the CRUD API.
 */
export function RedirectFormModal({ open, onClose, id, initial, onSaved }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [values, setValues] = useState<RedirectDraft>(EMPTY);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [check, setCheck] = useState<RedirectCheck | null>(null);
  const seq = useRef(0);

  useEffect(() => {
    if (!open) return;
    setTouched({});
    setServerErrors({});
    setFormError(null);
    setCheck(null);
    if (id) {
      setLoading(true);
      api
        .get<CrudRecordPayload>(`crud/redirects/${id}`)
        .then((p) => {
          const v = p.values;
          setValues({
            id, from_path: String(v.from_path ?? ''), to_url: String(v.to_url ?? ''), status_code: String(v.status_code ?? '301'),
            preserve_query: !!Number(v.preserve_query ?? 1), is_active: !!Number(v.is_active ?? 1), notes: String(v.notes ?? ''),
          });
        })
        .catch((e: ApiError) => setFormError(e.message))
        .finally(() => setLoading(false));
    } else {
      setValues({ ...EMPTY, ...(initial ?? {}) });
      if (initial?.from_path) setTouched({ from_path: true });
    }
  }, [open, id, initial]);

  const gone = values.status_code === '410';
  const debounced = useDebounce(values, 400);
  useEffect(() => {
    if (!open || loading || !debounced.from_path.trim()) {
      setCheck(null);
      return;
    }
    const n = ++seq.current;
    api
      .post<RedirectCheck>('redirects/check', { ...debounced, id: id ?? undefined })
      .then((r) => {
        if (n === seq.current) setCheck(r.data);
      })
      .catch(() => undefined);
  }, [debounced, open, loading, id]);

  const set = <K extends keyof RedirectDraft>(k: K, v: RedirectDraft[K]) => {
    setValues((x) => ({ ...x, [k]: v }));
    setServerErrors((e) => ({ ...e, [k]: '' }));
  };
  const errorFor = (k: string) => serverErrors[k] || (touched[k] ? check?.errors[k] : undefined) || undefined;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setTouched({ from_path: true, to_url: true, status_code: true });
    setSaving(true);
    setFormError(null);
    try {
      const payload = { from_path: values.from_path, to_url: gone ? '' : values.to_url, status_code: values.status_code, preserve_query: values.preserve_query,
        is_active: values.is_active, notes: values.notes, ...(values.source && !id ? { source: values.source } : {}) };
      const res = await api.post<{ id: number }>(id ? `crud/redirects/${id}` : 'crud/redirects', payload);
      toast.success(id ? 'Redirect updated.' : 'Redirect added.');
      void qc.invalidateQueries({ queryKey: ['crud', 'redirects'] });
      void qc.invalidateQueries({ queryKey: ['redirects'] });
      onSaved?.(res.data?.id ?? id ?? 0);
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setServerErrors(ex.errors ?? {});
      setFormError(Object.keys(ex.errors ?? {}).length ? null : ex.message);
      toast.error(ex.message || 'Unable to save the redirect. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const n = check?.normalized;
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      title={id ? 'Edit redirect' : 'Add redirect'}
      description="Send visitors and search engines from an old or moved URL to the right page."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="redirect-form" loading={saving} disabled={loading}>{id ? 'Save changes' : 'Add redirect'}</Button>
        </>
      }
    >
      {loading ? (
        <PageLoader label="Loading redirect…" />
      ) : (
        <form id="redirect-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-12" noValidate>
          {formError && <Alert variant="error" className="sm:col-span-12">{formError}</Alert>}
          <Field className="sm:col-span-12" label="Old URL (redirect from)" required htmlFor="rd-from" error={errorFor('from_path')}
            hint="A path on this website such as /old-page, or a full URL of this website. Add ?query to match only that exact query string.">
            <Input id="rd-from" value={values.from_path} onChange={(e) => set('from_path', e.target.value)} onBlur={() => setTouched((t) => ({ ...t, from_path: true }))}
              placeholder="/old-page" maxLength={500} autoComplete="off" spellCheck={false} prefix={<Link2 className="h-4 w-4" />} invalid={!!errorFor('from_path')} className="font-mono text-sm" />
          </Field>
          <Field className="sm:col-span-6" label="Redirect type" required htmlFor="rd-code" error={errorFor('status_code')}>
            <Select id="rd-code" value={values.status_code} onChange={(e) => set('status_code', e.target.value)} options={CODE_OPTIONS} />
          </Field>
          <div className="flex items-end sm:col-span-6">
            <p className="text-xs text-slate-500 dark:text-slate-400">
              {gone ? 'Search engines drop the URL from their index; visitors see the “page not found” screen.' : values.status_code === '301' || values.status_code === '308'
                ? 'Permanent: search engines move the old page’s ranking to the new URL.' : 'Temporary: search engines keep the old URL indexed.'}
            </p>
          </div>
          {!gone && (
            <Field className="sm:col-span-12" label="New URL (redirect to)" required htmlFor="rd-to" error={errorFor('to_url')}
              hint="A path on this website (e.g. /programs/mba) or a full https:// address on another website.">
              <Input id="rd-to" value={values.to_url} onChange={(e) => set('to_url', e.target.value)} onBlur={() => setTouched((t) => ({ ...t, to_url: true }))}
                placeholder="/new-page" maxLength={1000} autoComplete="off" spellCheck={false} prefix={<ArrowRight className="h-4 w-4" />} invalid={!!errorFor('to_url')} className="font-mono text-sm" />
            </Field>
          )}
          <div className="rounded-xl border border-slate-200 px-3.5 py-3 sm:col-span-6 dark:border-slate-700">
            <Toggle id="rd-query" checked={values.preserve_query} onChange={(v) => set('preserve_query', v)} label="Keep the query string" description="?utm_source=… is passed on to the new URL." disabled={gone} />
          </div>
          <div className="rounded-xl border border-slate-200 px-3.5 py-3 sm:col-span-6 dark:border-slate-700">
            <Toggle id="rd-active" checked={values.is_active} onChange={(v) => set('is_active', v)} label="Active" description="Inactive rules are kept but not applied." />
          </div>
          <Field className="sm:col-span-12" label="Notes" htmlFor="rd-notes" hint="Why this redirect exists (e.g. “Old website URL”, “Program renamed in 2026”).">
            <Textarea id="rd-notes" rows={2} maxLength={500} value={values.notes} onChange={(e) => set('notes', e.target.value)} />
          </Field>

          {n?.from_url && (!check || !Object.keys(check.errors).length) && (
            <div className="rounded-xl border border-brand-100 bg-brand-50/50 p-3.5 text-sm sm:col-span-12 dark:border-brand-500/20 dark:bg-brand-500/5">
              <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-brand-800 dark:text-brand-200">Preview</p>
              <p className="flex flex-wrap items-center gap-x-2 gap-y-1 break-all font-mono text-xs text-slate-700 dark:text-slate-200">
                <span>{n.from_url}</span>
                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />
                {gone ? <span className="font-sans font-semibold text-red-600 dark:text-red-400">410 Gone</span> : <span>{n.to_full ?? '…'}</span>}
                {!gone && <span className="rounded bg-white px-1.5 font-sans text-[11px] font-semibold text-brand-800 ring-1 ring-brand-100 dark:bg-slate-900 dark:text-brand-200 dark:ring-brand-500/30">{values.status_code}</span>}
              </p>
            </div>
          )}
          {check && check.notes.length > 0 && (
            <div className="space-y-2 sm:col-span-12">
              {check.notes.map((note) => (
                <Alert key={note} variant={note.startsWith('Chain avoided') ? 'info' : 'warning'}>
                  {note}
                </Alert>
              ))}
            </div>
          )}
        </form>
      )}
    </Modal>
  );
}
