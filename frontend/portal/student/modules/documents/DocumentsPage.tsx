import clsx from 'clsx';
import { Download, Eye, FileImage, FileText, FolderOpen, ShieldCheck } from 'lucide-react';
import { Alert, Stagger } from '@/components/ui';
import { apiUrl } from '@/lib/api';
import { formatDate, humanFileSize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { useStudentSession } from '../../../lib/session';
import { ListSkeleton, StatusBadge, useStudent } from '../../shared/ui';
import type { StudentDocuments } from '../../shared/types';

const fileUrl = (id: number, download = false) => apiUrl(`portal/student/documents/${id}/file`, download ? { download: 1 } : undefined);

export default function StudentDocumentsPage() {
  const support = useStudentSession().app.support_email;
  const { data, isLoading, error, refetch } = useStudent<StudentDocuments>(['documents'], 'documents');

  return (
    <div>
      <PageHead area="student" title="My Documents" subtitle="Documents on your student record and their verification status." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'My Profile', to: '/student/profile' }, { label: 'My Documents' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <ListSkeleton rows={4} h="h-24" />
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              { label: 'On file', value: data.stats.total, cls: 'text-slate-900 dark:text-white' },
              { label: 'Verified', value: data.stats.verified, cls: 'text-emerald-600 dark:text-emerald-400' },
              { label: 'Under review', value: data.stats.pending, cls: 'text-amber-600 dark:text-amber-400' },
              { label: 'Rejected', value: data.stats.rejected, cls: 'text-red-600 dark:text-red-400' },
            ].map((k) => (
              <div key={k.label} className="card px-4 py-3">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{k.label}</p>
                <p className={clsx('font-display text-2xl font-extrabold', k.cls)}>{k.value}</p>
              </div>
            ))}
          </div>

          {data.stats.rejected > 0 && (
            <Alert variant="error" title="Some documents were rejected">
              Submit corrected copies at the academic office. The reason is shown on each rejected document below.
            </Alert>
          )}
          {data.missing.length > 0 && (
            <Alert variant="info" title="Not on file yet">
              {data.missing.join(', ')}. Submit these at the academic office{support ? ` or email ${support}` : ''} so your record is complete.
            </Alert>
          )}

          {data.documents.length === 0 ? (
            <div className="card">
              <EmptyBlock icon={FolderOpen} title="No documents on your record" text="Documents you submitted at admission (marksheets, ID proof, photo) appear here once the academic office uploads them." />
            </div>
          ) : (
            <Stagger className="grid grid-cols-1 gap-3 md:grid-cols-2" itemClassName="h-full">
              {data.documents.map((d) => {
                const isImage = (d.mime ?? '').startsWith('image/');
                return (
                  <article key={d.id} className={clsx('card flex h-full gap-3.5 p-4', d.status === 'rejected' && 'border-red-200 dark:border-red-500/30')}>
                    <span className={clsx('inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl', isImage ? 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300' : 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300')}>
                      {isImage ? <FileImage className="h-6 w-6" aria-hidden /> : <FileText className="h-6 w-6" aria-hidden />}
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{d.type_label}</p>
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{d.title}</p>
                        </div>
                        <StatusBadge status={d.status} label={d.status === 'pending' ? 'Under review' : undefined} />
                      </div>
                      <p className="mt-0.5 truncate text-xs text-slate-500">
                        {d.file_name ?? 'File'}
                        {d.size ? ` · ${humanFileSize(d.size)}` : ''} · added {formatDate(d.uploaded_at)}
                        {d.verified_at && d.status === 'verified' ? ` · verified ${formatDate(d.verified_at)}` : ''}
                      </p>
                      {d.remarks && <p className={clsx('mt-1.5 text-xs font-medium', d.status === 'rejected' ? 'text-red-600 dark:text-red-400' : 'text-slate-500')}>{d.remarks}</p>}
                      <div className="mt-3 flex flex-wrap gap-2">
                        <a href={fileUrl(d.id)} target="_blank" rel="noopener" className="btn btn-secondary btn-xs">
                          <Eye className="h-3.5 w-3.5" aria-hidden /> View
                        </a>
                        <a href={fileUrl(d.id, true)} className="btn btn-ghost btn-xs">
                          <Download className="h-3.5 w-3.5" aria-hidden /> Download
                        </a>
                      </div>
                    </div>
                  </article>
                );
              })}
            </Stagger>
          )}
          <p className="flex items-start gap-2 px-1 text-xs text-slate-500 dark:text-slate-400">
            <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> Your documents are stored privately and can only be opened by you and authorised institute staff.          </p>
        </div>
      )}
    </div>
  );
}
