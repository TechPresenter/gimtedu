import { useState, type ReactNode } from 'react';
import { Send } from 'lucide-react';
import { Alert, Button, Modal, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';
import type { ResultCounts } from './types';

/**
 * Verify / unlock / publish / unpublish / recompute actions of an exam with confirmations and toasts.
 * Render `modal` once in the page.
 */
export function useExamWorkflow(exam: { id: number; name: string } | null | undefined, counts?: ResultCounts | null) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const [busy, setBusy] = useState<string | null>(null);
  const [publishOpen, setPublishOpen] = useState(false);
  const [notify, setNotify] = useState(true);

  const call = async (key: string, path: string, body?: unknown) => {
    if (!exam) return false;
    setBusy(key);
    try {
      const res = await api.post(`exams/${exam.id}/${path}`, body);
      toast.success(res.message);
      await invalidate('exams', 'results', 'crud', 'marks');
      return true;
    } catch (e) {
      toast.error((e as ApiError).message);
      return false;
    } finally {
      setBusy(null);
    }
  };

  const verify = async () => {
    if (!exam) return;
    const ok = await confirm({
      title: 'Verify and lock marks?',
      message: <>All marks of <strong>{exam.name}</strong> will be locked and results (totals, grades, SGPA, CGPA, ranks) computed. Faculty can no longer edit marks until they are unlocked.</>,
      confirmText: 'Verify & lock',
    });
    if (ok) await call('verify', 'verify');
  };
  const unverify = async () => {
    if (!exam) return;
    const ok = await confirm({ title: 'Unlock marks?', message: 'Marks become editable again for corrections. Computed results are kept until the marks are verified again.', confirmText: 'Unlock marks' });
    if (ok) await call('unverify', 'unverify');
  };
  const unpublish = async () => {
    if (!exam) return;
    const ok = await confirm({ title: 'Unpublish results?', message: 'Students will no longer see these results. Issued marksheets must be revoked first.', confirmText: 'Unpublish', danger: true });
    if (ok) await call('unpublish', 'unpublish');
  };
  const recompute = async () => {
    if (!exam) return;
    const ok = await confirm({ title: 'Recompute results?', message: 'Grades, SGPA, CGPA and ranks are recalculated from the locked marks and the current grading scale. Withheld results stay withheld.', confirmText: 'Recompute' });
    if (ok) await call('recompute', 'recompute');
  };
  const publish = () => setPublishOpen(true);

  const modal: ReactNode = (
    <Modal
      open={publishOpen}
      onClose={() => setPublishOpen(false)}
      static={busy === 'publish'}
      title="Publish results"
      description={exam?.name}
      icon={<span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><Send className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={() => setPublishOpen(false)} disabled={busy === 'publish'}>
            Cancel
          </Button>
          <Button
            variant="success"
            icon={Send}
            loading={busy === 'publish'}
            onClick={async () => {
              if (await call('publish', 'publish', { notify })) setPublishOpen(false);
            }}
          >
            Publish results
          </Button>
        </>
      }
    >
      <div className="space-y-4 text-sm text-slate-600 dark:text-slate-300">
        <p>Results are recomputed from the locked marks and become visible to students. SGPA and CGPA are written to each student's academic record.</p>
        {counts && counts.total > 0 && (
          <div className="grid grid-cols-3 gap-2 text-center">
            <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{counts.total}</p>
              <p className="text-xs text-slate-500">Results</p>
            </div>
            <div className="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
              <p className="font-display text-lg font-bold text-emerald-700 dark:text-emerald-300">{counts.pass_percent ?? 0}%</p>
              <p className="text-xs text-slate-500">Pass</p>
            </div>
            <div className="rounded-xl bg-violet-50 p-3 dark:bg-violet-500/10">
              <p className="font-display text-lg font-bold text-violet-700 dark:text-violet-300">{counts.withheld}</p>
              <p className="text-xs text-slate-500">Withheld</p>
            </div>
          </div>
        )}
        {counts && counts.withheld > 0 && <Alert variant="info">Withheld results stay hidden until they are released.</Alert>}
        <Toggle checked={notify} onChange={setNotify} label="Notify students" description="Send the result notification by email and in the student portal." />
      </div>
    </Modal>
  );

  return { verify, unverify, publish, unpublish, recompute, busy, modal };
}
