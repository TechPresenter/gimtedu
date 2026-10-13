import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { Bold, Code2, Heading2, Heading3, Italic, Link2, List, ListOrdered, Quote, RemoveFormatting, Underline } from 'lucide-react';

interface RichTextProps {
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
  invalid?: boolean;
  minHeight?: number;
  id?: string;
}

/**
 * Lightweight WYSIWYG editor (contentEditable + toolbar) with an HTML source mode.
 * Output is sanitised server-side (sanitize_html) before storage.
 */
export function RichText({ value, onChange, placeholder = 'Write here…', invalid, minHeight = 180, id }: RichTextProps) {
  const ref = useRef<HTMLDivElement>(null);
  const [source, setSource] = useState(false);
  const lastValue = useRef(value);

  useEffect(() => {
    if (ref.current && value !== lastValue.current && !source) {
      ref.current.innerHTML = value || '';
      lastValue.current = value;
    }
  }, [value, source]);

  useEffect(() => {
    if (ref.current && !source) ref.current.innerHTML = value || '';
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source]);

  const emit = () => {
    const html = ref.current?.innerHTML ?? '';
    const clean = html === '<br>' ? '' : html;
    lastValue.current = clean;
    onChange(clean);
  };
  const cmd = (command: string, arg?: string) => {
    ref.current?.focus();
    document.execCommand(command, false, arg);
    emit();
  };
  const tools = [
    { icon: Bold, label: 'Bold', run: () => cmd('bold') },
    { icon: Italic, label: 'Italic', run: () => cmd('italic') },
    { icon: Underline, label: 'Underline', run: () => cmd('underline') },
    { icon: Heading2, label: 'Heading', run: () => cmd('formatBlock', 'H2') },
    { icon: Heading3, label: 'Sub heading', run: () => cmd('formatBlock', 'H3') },
    { icon: List, label: 'Bulleted list', run: () => cmd('insertUnorderedList') },
    { icon: ListOrdered, label: 'Numbered list', run: () => cmd('insertOrderedList') },
    { icon: Quote, label: 'Quote', run: () => cmd('formatBlock', 'BLOCKQUOTE') },
    {
      icon: Link2,
      label: 'Insert link',
      run: () => {
        const url = window.prompt('Link URL (https://…)');
        if (url) cmd('createLink', url);
      },
    },
    { icon: RemoveFormatting, label: 'Clear formatting', run: () => cmd('removeFormat') },
  ];
  return (
    <div className={clsx('overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-slate-900', invalid ? 'border-red-400' : 'border-slate-200 dark:border-slate-700', 'focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/10')}>
      <div className="flex flex-wrap items-center gap-0.5 border-b border-slate-100 bg-slate-50/70 px-1.5 py-1 dark:border-slate-800 dark:bg-slate-800/40">
        {tools.map((t) => (
          <button key={t.label} type="button" title={t.label} aria-label={t.label} disabled={source} onMouseDown={(e) => e.preventDefault()} onClick={t.run} className="rounded-md p-1.5 text-slate-500 hover:bg-white hover:text-slate-900 disabled:opacity-40 dark:hover:bg-slate-700 dark:hover:text-white">
            <t.icon className="h-4 w-4" />
          </button>
        ))}
        <span className="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700" />
        <button type="button" onClick={() => setSource((s) => !s)} className={clsx('inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium', source ? 'bg-brand-800 text-white' : 'text-slate-500 hover:bg-white hover:text-slate-900 dark:hover:bg-slate-700')} aria-pressed={source}>
          <Code2 className="h-3.5 w-3.5" /> HTML
        </button>
      </div>
      {source ? (
        <textarea id={id} value={value} onChange={(e) => onChange(e.target.value)} className="block w-full border-0 bg-transparent p-3 font-mono text-xs text-slate-800 focus:ring-0 dark:text-slate-100" style={{ minHeight }} spellCheck={false} />
      ) : (
        <div
          id={id}
          ref={ref}
          contentEditable
          role="textbox"
          aria-multiline="true"
          data-placeholder={placeholder}
          onInput={emit}
          onBlur={emit}
          className="prose-editor max-w-none overflow-y-auto px-3.5 py-3 text-sm text-slate-800 outline-none empty:before:pointer-events-none empty:before:text-slate-400 empty:before:content-[attr(data-placeholder)] dark:text-slate-100 [&_a]:text-brand-700 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-slate-200 [&_blockquote]:pl-3 [&_blockquote]:text-slate-600 [&_h2]:mb-2 [&_h2]:mt-3 [&_h2]:text-lg [&_h2]:font-bold [&_h3]:mb-1.5 [&_h3]:mt-2 [&_h3]:text-base [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1.5 [&_ul]:list-disc [&_ul]:pl-5"
          style={{ minHeight }}
          suppressContentEditableWarning
        />
      )}
    </div>
  );
}
