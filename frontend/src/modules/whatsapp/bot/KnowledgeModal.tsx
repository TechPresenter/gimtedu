import { useEffect, useRef, useState } from 'react';
import { Braces } from 'lucide-react';
import { Button, Field, Input, Modal, Select, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { WaText } from '../shared';
import type { BotKnowledge } from './types';

interface Form {
  category: string;
  question: string;
  keywords: string;
  answer_en: string;
  answer_hi: string;
  status: 'active' | 'draft' | 'archived';
}

const EMPTY: Form = { category: 'general', question: '', keywords: '', answer_en: '', answer_hi: '', status: 'active' };

/**
 * Add / edit a verified WhatsApp answer. `prefill` (question + turn ids) comes from the unanswered-questions list: the
 * questions are marked reviewed when the answer is saved. {placeholders} are filled from live settings when sent.
 */
export function KnowledgeModal({
  open,
  onClose,
  onSaved,
  entry,
  prefill,
  categories,
  placeholders,
}: {
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
  entry?: BotKnowledge | null;
  prefill?: { question: string; turn_ids: number[] } | null;
  categories: Record<string, string>;
  placeholders: Record<string, string>;
}) {
  const toast = useToast();
  const [form, setForm] = useState<Form>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [lang, setLang] = useState<'en' | 'hi'>('en');

  // reset only when the modal opens for another answer — not on every parent render (a new `prefill` object per render
  // used to wipe what was typed and the inline validation errors whenever a toast or a refetch re-rendered the parent)
  const resetKey = open ? `${entry?.id ?? 'new'}:${prefill?.question ?? ''}` : null;
  const entryRef = useRef(entry);
  entryRef.current = entry;
  const prefillRef = useRef(prefill);
  prefillRef.current = prefill;
  useEffect(() => {
    if (resetKey === null) return;
    const e = entryRef.current;
    setErrors({});
    setLang('en');
    setForm(
      e
        ? { category: e.category, question: e.question, keywords: e.keywords ?? '', answer_en: e.answer_en, answer_hi: e.answer_hi ?? '', status: e.status }
        : { ...EMPTY, question: prefillRef.current?.question ?? '' },
    );
  }, [resetKey]);

  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));
  const preview = (form[lang === 'en' ? 'answer_en' : 'answer_hi'] || (lang === 'hi' ? form.answer_en : '')).replace(/\{([a-z_]+)\}/g, (m, key: string) => placeholders[key] ?? m);

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const body = { ...form, turn_ids: prefill?.turn_ids ?? [] };
      const r = entry ? await api.put(`whatsapp/bot/knowledge/${entry.id}`, body) : await api.post('whatsapp/bot/knowledge', body);
      toast.success(r.message);
      onSaved();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const insert = (key: string) => {
    const field = lang === 'en' ? 'answer_en' : 'answer_hi';
    set(field, `${form[field]}${form[field] && !form[field].endsWith(' ') ? ' ' : ''}{${key}}`);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      static={saving}
      title={entry ? 'Edit verified answer' : 'Add a verified answer'}
      description="The bot quotes this answer word for word on WhatsApp. Only write facts the institute has confirmed."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={save} loading={saving}>
            {entry ? 'Save changes' : 'Add answer'}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 lg:grid-cols-5">
        <div className="space-y-4 lg:col-span-3">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Category" htmlFor="kb-cat" error={errors.category}>
              <Select id="kb-cat" value={form.category} onChange={(e) => set('category', e.target.value)} options={categories} invalid={!!errors.category} />
            </Field>
            <Field label="Status" htmlFor="kb-status" error={errors.status}>
              <Select id="kb-status" value={form.status} onChange={(e) => set('status', e.target.value as Form['status'])} options={{ active: 'Active (bot uses it)', draft: 'Draft (needs checking)', archived: 'Archived' }} />
            </Field>
          </div>
          <Field label="Question" required htmlFor="kb-q" error={errors.question} hint="As a student would ask it." aside={`${form.question.length}/255`}>
            <Input id="kb-q" value={form.question} maxLength={255} onChange={(e) => set('question', e.target.value)} invalid={!!errors.question} placeholder="Which documents are required for admission?" />
          </Field>
          <Field label="Other phrasings / keywords" htmlFor="kb-kw" error={errors.keywords} hint="Comma separated, in English, Hindi or Hinglish (e.g. documents kya lagenge, दस्तावेज़). Phrases of 2+ words match strongly.">
            <Textarea id="kb-kw" rows={2} value={form.keywords} maxLength={500} onChange={(e) => set('keywords', e.target.value)} invalid={!!errors.keywords} />
          </Field>
          <div>
            <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
              <div className="pill-tabs" role="tablist">
                {(['en', 'hi'] as const).map((l) => (
                  <button key={l} type="button" role="tab" aria-selected={lang === l} className={`pill-tab ${lang === l ? 'pill-tab-active' : ''}`} onClick={() => setLang(l)}>
                    {l === 'en' ? 'English answer' : 'Hindi answer'}
                    {(l === 'en' ? errors.answer_en : errors.answer_hi) && <span className="ml-1 text-red-500">•</span>}
                  </button>
                ))}
              </div>
              <span className="text-xs text-slate-400">{(lang === 'en' ? form.answer_en : form.answer_hi).length}/2000</span>
            </div>
            {lang === 'en' ? (
              <Field error={errors.answer_en} hint="WhatsApp formatting: *bold*, _italic_. Keep it short.">
                <Textarea rows={8} value={form.answer_en} maxLength={2000} onChange={(e) => set('answer_en', e.target.value)} invalid={!!errors.answer_en} aria-label="English answer" />
              </Field>
            ) : (
              <Field error={errors.answer_hi} hint="Optional. Hindi (Devanagari) chats get this answer; English is used when it is empty.">
                <Textarea rows={8} value={form.answer_hi} maxLength={2000} onChange={(e) => set('answer_hi', e.target.value)} invalid={!!errors.answer_hi} aria-label="Hindi answer" />
              </Field>
            )}
          </div>
        </div>
        <div className="space-y-3 lg:col-span-2">
          <div>
            <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
              <Braces className="h-3.5 w-3.5" aria-hidden /> Live values — click to insert
            </p>
            <div className="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
              {Object.entries(placeholders).map(([key, value]) => (
                <button
                  key={key}
                  type="button"
                  title={value || '(empty)'}
                  onClick={() => insert(key)}
                  className="rounded-md border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 transition hover:border-accent-300 hover:bg-accent-50 hover:text-accent-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-accent-500/50 dark:hover:bg-accent-500/10"
                >
                  {`{${key}}`}
                </button>
              ))}
            </div>
          </div>
          <div>
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">WhatsApp preview</p>
            <div className="rounded-xl bg-[#EFEAE2] p-3 dark:bg-[#0B141A]">
              <div className="max-w-full rounded-lg rounded-tl-none bg-white px-3 py-2 text-[13px] leading-relaxed text-slate-800 shadow-sm dark:bg-[#202C33] dark:text-slate-100">
                {preview.trim() ? <WaText text={preview} /> : <span className="text-slate-400">The answer appears here.</span>}
              </div>
            </div>
          </div>
        </div>
      </div>
    </Modal>
  );
}
