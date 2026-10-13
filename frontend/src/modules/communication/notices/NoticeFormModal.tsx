import { useMemo, useRef } from 'react';
import { Mail, MessageCircle, MessageSquareText, Save, Send, Users } from 'lucide-react';
import { Alert, Button, Modal, PageLoader } from '@/components/ui';
import { CrudFormFields, useCrudForm, type CrudField } from '@/components/crud';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatNumber } from '@/lib/format';
import type { AudienceSummary } from '../shared';

interface Props {
  open: boolean;
  id: number | null;
  onClose: () => void;
  /** publish = the user chose "Save & publish" */
  onSaved: (id: number, publish: boolean) => void;
}

/** Notice create / edit form with audience-dependent targeting fields and a live recipient count. */
export function NoticeFormModal({ open, id, onClose, onSaved }: Props) {
  const { can } = useAuth();
  const { data: meta } = useCrudMeta('notices');
  const publishRef = useRef(false);
  const state = useCrudForm({
    meta: open ? meta : undefined,
    module: 'notices',
    id,
    onSaved: (_row, newId) => {
      onSaved(newId, publishRef.current);
      publishRef.current = false;
    },
  });
  const aud = String(state.values.audience ?? 'all');
  const fields = useMemo<CrudField[]>(
    () =>
      state.visibleFields
        .filter((f) => {
          if (f.name === 'department_id') return aud === 'department';
          if (f.name === 'program_id') return ['course', 'semester', 'parents'].includes(aud);
          if (f.name === 'semester_no') return aud === 'semester';
          return true;
        })
        .map((f) => {
          if (f.name === 'department_id') return { ...f, required: true, col: 8 };
          if (f.name === 'program_id') return { ...f, required: aud === 'course', label: aud === 'course' ? 'Course / Program' : 'Course / Program (optional)', col: aud === 'semester' ? 4 : 8 };
          if (f.name === 'semester_no') return { ...f, required: true, col: 4 };
          return f;
        }),
    [state.visibleFields, aud],
  );

  const paramKey = useDebounce(
    JSON.stringify({ audience: aud, department_id: String(state.values.department_id ?? ''), program_id: String(state.values.program_id ?? ''), semester_no: String(state.values.semester_no ?? '') }),
    300,
  );
  const params = useMemo(() => JSON.parse(paramKey) as Record<string, string>, [paramKey]);
  const ready = aud !== 'department' || !!params.department_id;
  const { data: preview, isFetching } = useApi<AudienceSummary>(['comm', 'notice-audience', params], 'notices/audience-preview', params, { enabled: open && !!meta && ready });

  const save = async (publish: boolean) => {
    publishRef.current = publish;
    const ok = await state.submit();
    if (!ok) publishRef.current = false;
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={state.saving}
      size="xl"
      title={id ? 'Edit notice' : 'New notice'}
      description="Write the notice, choose who it is for and when it should appear. Fields marked * are required."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={state.saving} className="hidden sm:inline-flex">
            Cancel
          </Button>
          <Button variant={can('notices', 'publish') ? 'secondary' : 'primary'} icon={Save} loading={state.saving && !publishRef.current} disabled={state.loading || state.saving} onClick={() => void save(false)}>
            {id ? 'Save changes' : 'Save notice'}
          </Button>
          {can('notices', 'publish') && (
            <Button icon={Send} loading={state.saving && publishRef.current} disabled={state.loading || state.saving} onClick={() => void save(true)}>
              Save & publish…
            </Button>
          )}
        </>
      }
    >
      {state.loading || !meta ? (
        <PageLoader label="Loading notice…" />
      ) : (
        <form
          id="notice-form"
          noValidate
          onSubmit={(e) => {
            e.preventDefault();
            void save(false);
          }}
          className="space-y-4"
        >
          {state.formError && <Alert variant="error">{state.formError}</Alert>}
          <CrudFormFields state={state} fields={fields} id={id} />
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3 text-sm dark:border-brand-500/20 dark:bg-brand-500/10" aria-live="polite">
            <span className="inline-flex items-center gap-2 font-medium text-brand-900 dark:text-brand-100">
              <Users className="h-4 w-4" /> Audience reach
            </span>
            {!ready ? (
              <span className="text-slate-500">Select a department to see how many people will receive this notice.</span>
            ) : !preview || isFetching ? (
              <span className="skeleton h-4 w-56" />
            ) : (
              <>
                <span className="text-slate-700 dark:text-slate-200">
                  <strong className="tabular-nums">{formatNumber(preview.people)}</strong> people
                  {preview.breakdown.length > 1 && <span className="text-slate-500"> ({preview.breakdown.map((b) => `${formatNumber(b.count)} ${b.label.toLowerCase()}`).join(', ')})</span>}
                </span>
                <span className="inline-flex items-center gap-1 text-slate-600 dark:text-slate-300"><Mail className="h-3.5 w-3.5" />{formatNumber(preview.email)}</span>
                <span className="inline-flex items-center gap-1 text-slate-600 dark:text-slate-300"><MessageSquareText className="h-3.5 w-3.5" />{formatNumber(preview.sms)}</span>
                <span className="inline-flex items-center gap-1 text-slate-600 dark:text-slate-300"><MessageCircle className="h-3.5 w-3.5" />{formatNumber(preview.whatsapp)}</span>
              </>
            )}
          </div>
        </form>
      )}
    </Modal>
  );
}
