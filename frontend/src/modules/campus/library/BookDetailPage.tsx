import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeftRight, BookCheck, BookOpen, CopyPlus, History, Library, Pencil, Printer, Tags, Trash2, TrendingUp, Users, Wrench } from 'lucide-react';
import {
  Badge, Button, Card, CardBody, CardHeader, DescriptionList, EmptyState, PageHeader, Reveal, Skeleton, StatTile, Stagger, useConfirm, useToast,
} from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { formatDate, formatMoney, formatNumber, labelize } from '@/lib/format';
import { AddCopiesDialog, CopyStatusDialog, type CopyTarget } from './bookDialogs';
import { useLoanActions } from './dialogs';
import { BookCover, CopyBorrowerCell, LoanRow, libCrumbs, openPrint, useLibraryRefresh } from './shared';
import type { BookDetail } from './types';

export default function BookDetailPage() {
  const { id } = useParams();
  const bookId = Number(id);
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useLibraryRefresh();
  const q = useApi<BookDetail>(['library', 'book', bookId], `library/books/${bookId}`, undefined, { enabled: bookId > 0 });
  const meta = useCrudMeta('books');
  const [editOpen, setEditOpen] = useState(false);
  const [addCopies, setAddCopies] = useState<CopyTarget | null>(null);
  const [statusIds, setStatusIds] = useState<{ ids: number[]; clear?: () => void } | null>(null);
  const loanActions = useLoanActions(() => void q.refetch());
  const d = q.data;
  const b = d?.book;

  const remove = async () => {
    if (!b) return;
    const ok = await confirm({ title: 'Delete book?', message: <>Are you sure you want to delete <strong>{b.title}</strong> and its {d?.copies.total ?? 0} copies? This action cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/books/${b.id}`);
      toast.success(res.message);
      await refresh();
      navigate('/library/books');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  if (q.error) {
    return (
      <>
        <PageHeader title="Book" breadcrumbs={[...libCrumbs(), { label: 'Books & Copies', to: '/library/books' }, { label: 'Not found' }]} />
        <Card>
          <EmptyState icon={BookOpen} title="Book not found" description={(q.error as ApiError).message} action={<Button to="/library/books">Back to catalogue</Button>} />
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={b?.title ?? 'Loading…'}
        description={b ? [b.authors, b.publisher_name, b.edition].filter(Boolean).join(' · ') : undefined}
        breadcrumbs={[...libCrumbs(), { label: 'Books & Copies', to: '/library/books' }, { label: b ? (b.title.length > 40 ? `${b.title.slice(0, 40)}…` : b.title) : 'Book' }]}
        actions={
          b && (
            <>
              <Button variant="secondary" icon={Tags} onClick={() => openPrint('labels', { book_id: b.id })}>
                Labels
              </Button>
              {d?.can.delete && (
                <Button variant="secondary" icon={Trash2} onClick={remove} className="text-red-600 dark:text-red-400">
                  Delete
                </Button>
              )}
              {d?.can.edit && (
                <Button variant="secondary" icon={Pencil} onClick={() => setEditOpen(true)}>
                  Edit
                </Button>
              )}
              {d?.can.create && (
                <Button icon={CopyPlus} onClick={() => setAddCopies({ id: b.id, title: b.title, price: b.price, rack_no: b.rack_no })}>
                  Add copies
                </Button>
              )}
            </>
          )
        }
      />

      <div className="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardBody className="flex flex-col gap-5 sm:flex-row">
              {b ? <BookCover title={b.title} src={b.cover_image} size="xl" className="self-center sm:self-start" /> : <Skeleton className="h-52 w-36 self-center" />}
              <div className="min-w-0 flex-1 space-y-4">
                {b ? (
                  <>
                    <div className="flex flex-wrap items-center gap-2">
                      {b.category_name && <Badge color="navy">{b.category_name}</Badge>}
                      <Badge color={b.type === 'reference' ? 'purple' : 'blue'}>{labelize(b.type)}</Badge>
                      <Badge color={b.status === 'active' ? 'green' : 'slate'}>{labelize(b.status)}</Badge>
                      {b.language && <Badge color="slate">{b.language}</Badge>}
                    </div>
                    {b.subtitle && <p className="text-sm font-medium text-slate-700 dark:text-slate-200">{b.subtitle}</p>}
                    <DescriptionList
                      columns={3}
                      items={[
                        { label: 'Authors', value: b.authors, full: true },
                        { label: 'ISBN', value: b.isbn ? <span className="font-mono">{b.isbn}</span> : null },
                        { label: 'Publisher', value: b.publisher_name ? `${b.publisher_name}${b.publisher_city ? `, ${b.publisher_city}` : ''}` : null },
                        { label: 'Edition / Year', value: [b.edition, b.publish_year].filter(Boolean).join(' · ') },
                        { label: 'Location', value: [b.rack_no && `Rack ${b.rack_no}`, b.shelf_no && `Shelf ${b.shelf_no}`].filter(Boolean).join(' · ') },
                        { label: 'Price', value: b.price ? formatMoney(b.price) : null },
                        { label: 'Pages', value: b.pages ? formatNumber(b.pages) : null },
                        { label: 'Department', value: b.department_name },
                        { label: 'Added on', value: formatDate(b.created_at) },
                      ]}
                    />
                    {b.description && <p className="text-sm leading-relaxed text-slate-600 dark:text-slate-300">{b.description}</p>}
                  </>
                ) : (
                  <div className="space-y-3">
                    <Skeleton className="h-5 w-1/2" />
                    <Skeleton className="h-4 w-full" />
                    <Skeleton className="h-4 w-5/6" />
                    <Skeleton className="h-4 w-2/3" />
                  </div>
                )}
              </div>
            </CardBody>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Issues per month" subtitle="Last 12 months" icon={TrendingUp} />
            <CardBody className="pt-0">
              {d ? (
                d.stats.loans ? (
                  <MonthBars data={d.trend} />
                ) : (
                  <EmptyState icon={BookOpen} title="Never borrowed" description="This title has not been issued yet." className="py-8" />
                )
              ) : (
                <Skeleton className="h-[200px] w-full" />
              )}
            </CardBody>
          </Card>
        </Reveal>
      </div>

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="c" label="Copies" value={d?.copies.total ?? 0} icon={Library} tone="navy" loading={!d} sub={d ? copyIssues(d.copies) : undefined} />,
          <StatTile key="a" label="Available now" value={d?.copies.available ?? 0} icon={BookCheck} tone="green" loading={!d} sub={d ? `${d.copies.issued} on loan${d.stats.overdue ? ` · ${d.stats.overdue} overdue` : ''}` : undefined} />,
          <StatTile key="l" label="Times borrowed" value={d?.stats.loans ?? 0} icon={History} tone="purple" loading={!d} sub={d ? `${d.stats.loans_90} in the last 90 days` : undefined} />,
          <StatTile key="r" label="Unique readers" value={d?.stats.readers ?? 0} icon={Users} tone="blue" loading={!d} sub={d?.stats.last_issued ? `Last issued ${formatDate(d.stats.last_issued)}` : 'Not issued yet'} />,
        ]}
      </Stagger>

      {d && d.current.length > 0 && (
        <Reveal className="mb-5">
          <Card>
            <CardHeader title="Currently on loan" subtitle={`${d.current.length} cop${d.current.length === 1 ? 'y' : 'ies'} with members`} icon={ArrowLeftRight} />
            <CardBody className="pt-0">
              <ul className="space-y-2">
                {d.current.map((l) => (
                  <LoanRow
                    key={l.id}
                    loan={l}
                    showMember
                    busy={loanActions.busy}
                    onReturn={loanActions.canEdit ? loanActions.openReturn : undefined}
                    onRenew={loanActions.canEdit ? loanActions.renew : undefined}
                    onLost={loanActions.canEdit ? loanActions.openLost : undefined}
                  />
                ))}
              </ul>
            </CardBody>
          </Card>
        </Reveal>
      )}

      {b && (
        <>
          <Reveal className="mb-5">
            <CrudTable
              module="book_copies"
              scope={{ book_id: b.id }}
              title="Copies of this book"
              description="Accession numbers, condition and status of every physical copy."
              hideColumns={['book_title']}
              hideFilters={['category']}
              formDefaults={{ book_id: b.id }}
              addLabel="Add Copy"
              emptyTitle="No copies yet"
              emptyText="Add copies of this title — accession numbers and barcodes are generated automatically."
              onSaved={() => void q.refetch()}
              renderers={{ borrower: (r) => <CopyBorrowerCell borrower={r.borrower} due={r.due_date} /> }}
              bulkActions={[
                ...(d?.can.edit ? [{ label: 'Change status', icon: Wrench, onClick: (ids: number[], clear: () => void) => setStatusIds({ ids, clear }) }] : []),
                { label: 'Print labels', icon: Printer, onClick: (ids: number[]) => openPrint('labels', { ids: ids.join(',') }) },
              ]}
              rowMenu={(r) => [
                { label: 'Print label', icon: Printer, onClick: () => openPrint('labels', { ids: r.id }) },
                d?.can.edit && r.status !== 'issued' && { label: 'Change status…', icon: Wrench, onClick: () => setStatusIds({ ids: [Number(r.id)] }) },
              ]}
            />
          </Reveal>
          <Reveal>
            <CrudTable
              module="library_transactions"
              scope={{ book_id: b.id }}
              title="Circulation history"
              description="Every loan of this title."
              hideColumns={['book_title']}
              hideFilters={['category']}
              emptyTitle="No loans yet"
              emptyText="This title has not been issued yet. Issue a copy from the circulation desk."
              viewTo={(r) => `/library/circulation?tab=history&q=${encodeURIComponent(String(r.accession_no))}`}
            />
          </Reveal>
        </>
      )}

      <CrudFormModal
        open={editOpen}
        onClose={() => setEditOpen(false)}
        meta={meta.data}
        module="books"
        id={b?.id ?? null}
        onSaved={() => {
          void refresh();
          void q.refetch();
        }}
      />
      <AddCopiesDialog book={addCopies} onClose={() => setAddCopies(null)} onDone={() => void q.refetch()} />
      <CopyStatusDialog ids={statusIds?.ids ?? null} onClose={() => setStatusIds(null)} onDone={() => { statusIds?.clear?.(); void q.refetch(); }} />
      {loanActions.dialogs}
    </>
  );
}

function copyIssues(c: BookDetail['copies']) {
  const parts = [c.damaged && `${c.damaged} damaged`, c.lost && `${c.lost} lost`, c.withdrawn && `${c.withdrawn} withdrawn`].filter(Boolean);
  return parts.length ? parts.join(' · ') : 'All copies in circulation';
}

/** Small monthly issue bars with value labels (exact integers, no fractional axis ticks). */
function MonthBars({ data }: { data: BookDetail['trend'] }) {
  const max = Math.max(1, ...data.map((d) => d.issues));
  return (
    <div className="flex h-[200px] items-end gap-1.5" role="img" aria-label={`Issues per month: ${data.map((d) => `${d.label} ${d.issues}`).join(', ')}`}>
      {data.map((d) => (
        <div key={d.month} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
          <span className="text-[10px] font-semibold tabular-nums text-slate-500 dark:text-slate-400">{d.issues || ''}</span>
          <div
            className={d.issues ? 'w-full max-w-[22px] rounded-t-md bg-gradient-to-t from-brand-700 to-blue-500 transition-all duration-500' : 'w-full max-w-[22px] rounded-t-md bg-slate-100 dark:bg-slate-800'}
            style={{ height: `${d.issues ? Math.max(6, (d.issues / max) * 150) : 4}px` }}
            title={`${d.label}: ${d.issues} issue${d.issues === 1 ? '' : 's'}`}
          />
          <span className="text-[10px] text-slate-400">{d.label.slice(0, 3)}</span>
        </div>
      ))}
    </div>
  );
}
