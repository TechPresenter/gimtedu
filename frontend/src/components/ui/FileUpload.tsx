import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { FileText, ImageIcon, Trash2, UploadCloud } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { humanFileSize } from '@/lib/format';

interface FileUploadProps {
  /** Newly chosen file (controlled) */
  file: File | null;
  onFile: (f: File | null) => void;
  /** Existing stored path (shown when no new file is chosen) */
  currentPath?: string | null;
  onRemoveCurrent?: () => void;
  accept?: string;
  maxSize?: number;
  image?: boolean;
  invalid?: boolean;
  onError?: (msg: string) => void;
  id?: string;
  /** Private files are downloaded via the API instead of a public URL */
  privateFile?: boolean;
  compact?: boolean;
}

/** Drag & drop file picker with type/size validation and image preview. */
export function FileUpload({ file, onFile, currentPath, onRemoveCurrent, accept, maxSize, image, invalid, onError, id, privateFile, compact }: FileUploadProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [drag, setDrag] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);

  useEffect(() => {
    if (file && image && file.type.startsWith('image/')) {
      const url = URL.createObjectURL(file);
      setPreview(url);
      return () => URL.revokeObjectURL(url);
    }
    setPreview(null);
  }, [file, image]);

  const pick = (f: File | undefined | null) => {
    if (!f) return;
    const allowed = (accept ?? '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean);
    const ext = `.${f.name.split('.').pop()?.toLowerCase()}`;
    if (allowed.length && !allowed.includes(ext) && !allowed.some((a) => a.endsWith('/*') && f.type.startsWith(a.slice(0, -1)))) {
      onError?.(`File type ${ext} is not allowed.`);
      return;
    }
    if (maxSize && f.size > maxSize) {
      onError?.(`File must be smaller than ${humanFileSize(maxSize)}.`);
      return;
    }
    onFile(f);
  };

  const existing = !file && currentPath;
  const showImage = preview || (existing && image && !privateFile ? appUrl(currentPath as string) : null);

  return (
    <div>
      <div
        role="button"
        tabIndex={0}
        aria-label="Upload file"
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDrag(true);
        }}
        onDragLeave={() => setDrag(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDrag(false);
          pick(e.dataTransfer.files?.[0]);
        }}
        className={clsx(
          'flex cursor-pointer items-center gap-4 rounded-xl border-2 border-dashed px-4 transition',
          compact ? 'py-2.5' : 'py-4',
          drag ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10' : 'border-slate-200 hover:border-brand-400 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50',
          invalid && 'border-red-300',
        )}
      >
        {showImage ? (
          <img src={showImage} alt="Preview" className="h-14 w-14 shrink-0 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
        ) : (
          <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
            {image ? <ImageIcon className="h-5 w-5" /> : <UploadCloud className="h-5 w-5" />}
          </span>
        )}
        <div className="min-w-0 flex-1 text-sm">
          {file ? (
            <>
              <p className="truncate font-medium text-slate-800 dark:text-slate-100">{file.name}</p>
              <p className="text-xs text-slate-500">{humanFileSize(file.size)} · ready to upload</p>
            </>
          ) : existing ? (
            <>
              <p className="flex items-center gap-1.5 truncate font-medium text-slate-800 dark:text-slate-100">
                <FileText className="h-3.5 w-3.5" /> {String(currentPath).split('/').pop()}
              </p>
              <p className="text-xs text-slate-500">Click or drop a file to replace</p>
            </>
          ) : (
            <>
              <p className="font-medium text-slate-700 dark:text-slate-200">
                <span className="text-brand-700 dark:text-brand-300">Click to upload</span> or drag and drop
              </p>
              <p className="text-xs text-slate-500">
                {accept ? accept.replace(/\./g, '').toUpperCase().split(',').slice(0, 6).join(', ') : 'Any file'}
                {maxSize ? ` · max ${humanFileSize(maxSize)}` : ''}
              </p>
            </>
          )}
        </div>
        {(file || (existing && onRemoveCurrent)) && (
          <button
            type="button"
            className="btn-icon shrink-0 hover:!bg-red-50 hover:!text-red-600"
            aria-label="Remove file"
            onClick={(e) => {
              e.stopPropagation();
              if (file) onFile(null);
              else onRemoveCurrent?.();
            }}
          >
            <Trash2 className="h-4 w-4" />
          </button>
        )}
      </div>
      <input
        ref={inputRef}
        id={id}
        type="file"
        accept={accept}
        className="sr-only"
        onChange={(e) => {
          pick(e.target.files?.[0]);
          e.target.value = '';
        }}
      />
    </div>
  );
}
