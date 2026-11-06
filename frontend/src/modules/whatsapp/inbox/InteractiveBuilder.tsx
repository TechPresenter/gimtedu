import { useEffect, useState } from 'react';
import { List, ListPlus, MousePointerClick, Plus, Send, Trash2 } from 'lucide-react';
import { Alert, Button, Field, IconButton, Input, Modal, Textarea } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { WaBubble, type WaInteractive } from '../shared';
import type { SendPayload } from './types';

type Mode = 'buttons' | 'list';
interface Row {
  title: string;
  description: string;
}
interface Section {
  title: string;
  rows: Row[];
}

interface Props {
  open: boolean;
  mode: Mode;
  onClose: () => void;
  onSend: (payload: SendPayload, preview: { type: 'interactive_button' | 'interactive_list'; body: string; interactive: WaInteractive }) => Promise<unknown>;
}

const emptyRow = (): Row => ({ title: '', description: '' });

/** Session-only interactive messages: up to 3 reply buttons, or a list (max 10 rows across sections). */
export function InteractiveBuilder({ open, mode, onClose, onSend }: Props) {
  const [body, setBody] = useState('');
  const [footer, setFooter] = useState('');
  const [buttons, setButtons] = useState<string[]>(['', '']);
  const [listButton, setListButton] = useState('View options');
  const [sections, setSections] = useState<Section[]>([{ title: '', rows: [emptyRow(), emptyRow()] }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    if (open) {
      setBody('');
      setFooter('');
      setButtons(['', '']);
      setListButton('View options');
      setSections([{ title: '', rows: [emptyRow(), emptyRow()] }]);
      setErrors({});
      setFormError(null);
    }
  }, [open, mode]);

  const rowCount = sections.reduce((n, s) => n + s.rows.length, 0);
  const cleanButtons = buttons.map((b) => b.trim()).filter(Boolean);
  const cleanSections = sections
    .map((s) => ({ title: s.title.trim(), rows: s.rows.filter((r) => r.title.trim()).map((r) => ({ title: r.title.trim(), description: r.description.trim() || undefined })) }))
    .filter((s) => s.rows.length);

  const interactive: WaInteractive =
    mode === 'buttons'
      ? { buttons: cleanButtons.map((t, i) => ({ id: `b${i}`, title: t })), footer: footer.trim() || undefined }
      : {
          button: listButton.trim() || 'View options',
          sections: cleanSections.map((s, si) => ({ title: s.title || 'Options', rows: s.rows.map((r, ri) => ({ id: `r${si}${ri}`, title: r.title, description: r.description })) })),
          footer: footer.trim() || undefined,
        };

  const validate = () => {
    const e: Record<string, string> = {};
    if (!body.trim()) e.body = 'Type the message text.';
    if (footer.length > 60) e.footer = 'At most 60 characters.';
    if (mode === 'buttons') {
      if (!cleanButtons.length) e.buttons = 'Add at least one button.';
      if (cleanButtons.some((b) => b.length > 20)) e.buttons = 'Button titles can be at most 20 characters.';
      if (new Set(cleanButtons.map((b) => b.toLowerCase())).size !== cleanButtons.length) e.buttons = 'Button titles must be different.';
    } else {
      if (!listButton.trim()) e.list_button = 'Enter the label of the button that opens the list.';
      const rows = cleanSections.reduce((n, s) => n + s.rows.length, 0);
      if (!rows) e.sections = 'Add at least one row.';
      if (rows > 10) e.sections = 'WhatsApp lists can have at most 10 rows.';
    }
    setErrors(e);
    return !Object.keys(e).length;
  };

  const submit = async () => {
    if (!validate()) return;
    setSending(true);
    setFormError(null);
    const payload: SendPayload =
      mode === 'buttons'
        ? { type: 'interactive_button', body: body.trim(), buttons: cleanButtons.map((title) => ({ title })), footer: footer.trim() || undefined }
        : { type: 'interactive_list', body: body.trim(), list_button: listButton.trim(), sections: cleanSections, footer: footer.trim() || undefined };
    try {
      await onSend(payload, { type: mode === 'buttons' ? 'interactive_button' : 'interactive_list', body: body.trim(), interactive });
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setFormError(err.message);
      setErrors(err.errors ?? {});
    } finally {
      setSending(false);
    }
  };

  const setRow = (si: number, ri: number, patch: Partial<Row>) =>
    setSections((p) => p.map((s, i) => (i === si ? { ...s, rows: s.rows.map((r, j) => (j === ri ? { ...r, ...patch } : r)) } : s)));

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title={mode === 'buttons' ? 'Reply buttons message' : 'List message'}
      description={mode === 'buttons' ? 'The contact taps one of up to 3 buttons to answer. Only inside the 24-hour window.' : 'The contact opens a menu and picks one option (max 10). Only inside the 24-hour window.'}
      icon={<span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{mode === 'buttons' ? <MousePointerClick className="h-5 w-5" aria-hidden /> : <List className="h-5 w-5" aria-hidden />}</span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>Cancel</Button>
          <Button icon={Send} onClick={submit} loading={sending}>Send</Button>
        </>
      }
    >
      <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,300px)]">
        <div className="space-y-4">
          {formError && <Alert variant="error">{formError}</Alert>}
          <Field label="Message" htmlFor="ib-body" required error={errors.body} aside={`${body.length}/${mode === 'buttons' ? 1024 : 4096}`}>
            <Textarea id="ib-body" rows={3} value={body} maxLength={mode === 'buttons' ? 1024 : 4096} invalid={!!errors.body} onChange={(e) => setBody(e.target.value)} placeholder={mode === 'buttons' ? 'Would you like a callback from our counsellor?' : 'Please choose the programme you are interested in.'} />
          </Field>

          {mode === 'buttons' ? (
            <fieldset>
              <legend className="form-label">Buttons <span className="font-normal text-slate-400">(max 3, 20 characters each)</span></legend>
              <div className="space-y-2">
                {buttons.map((b, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Input inputSize="sm" value={b} maxLength={20} aria-label={`Button ${i + 1}`} placeholder={['Yes, call me', 'Not now', 'Visit campus'][i]} onChange={(e) => setButtons((p) => p.map((x, j) => (j === i ? e.target.value : x)))} />
                    <IconButton size="sm" icon={Trash2} label={`Remove button ${i + 1}`} tone="danger" disabled={buttons.length === 1} onClick={() => setButtons((p) => p.filter((_, j) => j !== i))} />
                  </div>
                ))}
              </div>
              {errors.buttons && <p className="form-error" role="alert">{errors.buttons}</p>}
              {buttons.length < 3 && <Button size="xs" variant="ghost" icon={Plus} className="mt-2" onClick={() => setButtons((p) => [...p, ''])}>Add button</Button>}
            </fieldset>
          ) : (
            <>
              <Field label="List button label" htmlFor="ib-lb" required error={errors.list_button} aside={`${listButton.length}/20`}>
                <Input id="ib-lb" inputSize="sm" value={listButton} maxLength={20} invalid={!!errors.list_button} onChange={(e) => setListButton(e.target.value)} />
              </Field>
              <div className="space-y-3">
                {sections.map((s, si) => (
                  <fieldset key={si} className="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <div className="mb-2 flex items-center gap-2">
                      <Input inputSize="sm" value={s.title} maxLength={24} aria-label={`Section ${si + 1} title`} placeholder={`Section ${si + 1} title (optional)`} onChange={(e) => setSections((p) => p.map((x, i) => (i === si ? { ...x, title: e.target.value } : x)))} />
                      <IconButton size="sm" icon={Trash2} label={`Remove section ${si + 1}`} tone="danger" disabled={sections.length === 1} onClick={() => setSections((p) => p.filter((_, i) => i !== si))} />
                    </div>
                    <div className="space-y-2">
                      {s.rows.map((r, ri) => (
                        <div key={ri} className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_auto]">
                          <Input inputSize="sm" value={r.title} maxLength={24} aria-label={`Row ${ri + 1} title`} placeholder="Row title (24)" onChange={(e) => setRow(si, ri, { title: e.target.value })} />
                          <Input inputSize="sm" value={r.description} maxLength={72} aria-label={`Row ${ri + 1} description`} placeholder="Description (optional, 72)" onChange={(e) => setRow(si, ri, { description: e.target.value })} />
                          <IconButton size="sm" icon={Trash2} label={`Remove row ${ri + 1}`} tone="danger" disabled={s.rows.length === 1} onClick={() => setSections((p) => p.map((x, i) => (i === si ? { ...x, rows: x.rows.filter((_, j) => j !== ri) } : x)))} />
                        </div>
                      ))}
                    </div>
                    <Button size="xs" variant="ghost" icon={Plus} className="mt-2" disabled={rowCount >= 10} onClick={() => setSections((p) => p.map((x, i) => (i === si ? { ...x, rows: [...x.rows, emptyRow()] } : x)))}>Add row</Button>
                  </fieldset>
                ))}
              </div>
              {errors.sections && <p className="form-error" role="alert">{errors.sections}</p>}
              <div className="flex items-center justify-between">
                <Button size="xs" variant="secondary" icon={ListPlus} disabled={rowCount >= 10 || sections.length >= 10} onClick={() => setSections((p) => [...p, { title: '', rows: [emptyRow()] }])}>Add section</Button>
                <span className="text-xs text-slate-400">{rowCount}/10 rows</span>
              </div>
            </>
          )}

          <Field label="Footer" htmlFor="ib-footer" error={errors.footer} hint="Optional small grey text, max 60 characters.">
            <Input id="ib-footer" inputSize="sm" value={footer} maxLength={60} onChange={(e) => setFooter(e.target.value)} placeholder="GIMT Admissions" />
          </Field>
        </div>
        <div className="rounded-xl bg-[#EFEAE2] p-3 dark:bg-[#0B141A]">
          <p className="mb-2 text-center text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Preview</p>
          <WaBubble direction="out" type={mode === 'buttons' ? 'interactive_button' : 'interactive_list'} body={body || 'Your message…'} interactive={interactive} status="queued" showStatus={false} />
        </div>
      </div>
    </Modal>
  );
}
