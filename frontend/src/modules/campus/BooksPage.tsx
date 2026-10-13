import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { BookCheck, BookMarked, BookOpen, Building2, CopyPlus, Eye, FolderTree, History, Library, PenLine, Printer, Tags, Wrench } from 'lucide-react';
import { Button, PageHeader, StatTile, Stagger, Tabs } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatNumber } from '@/lib/format';
import type { Paginated, Row } from '@/lib/types';
import { AddCopiesDialog, CopyStatusDialog, type CopyTarget } from './library/bookDialogs';
import { BookCover, CopyBorrowerCell, libCrumbs, openPrint } from './library/shared';
import type { DashboardPayload } from './library/types';

const TABS = [
  { key: 'catalogue', label: 'Catalogue', icon: BookMarked },
  { key: 'copies', label: 'Copies', icon: Library },
  { key: 'categories', label: 'Categories', icon: FolderTree },
  { key: 'authors', label: 'Authors', icon: PenLine },
  { key: 'publishers', label: 'Publishers', icon: Building2 },
];

export default function BooksPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : 'catalogue';
  const dash = useApi<DashboardPayload>(['library', 'dashboard'], 'library/dashboard');
  const k = dash.data?.kpis;
  const cats = useApi<Paginated<Row>>(['crud', 'book_categories', { per_page: 1 }], 'crud/book_categories', { per_page: 1 });
  const [addCopies, setAddCopies] = useState<CopyTarget | null>(null);
  const [statusIds, setStatusIds] = useState<{ ids: number[]; preset?: string; clear?: () => void } | null>(null);

  const go = (key: string, extra: Record<string, string> = {}) => {
    const next = new URLSearchParams();
    if (key !== 'catalogue') next.set('tab', key);
    Object.entries(extra).forEach(([a, b]) => next.set(a, b));
    setParams(next, { replace: false });
  };

  const bookMenu = (r: Row) => [
    { label: 'Open book page', icon: Eye, to: `/library/books/${r.id}` },
    can('library', 'create') && { label: 'Add copies…', icon: CopyPlus, onClick: () => setAddCopies({ id: Number(r.id), title: String(r.title), price: r.price ? Number(r.price) : null, rack_no: r.rack_no ?? null }) },
    { label: 'Print accession labels', icon: Tags, onClick: () => openPrint('labels', { book_id: r.id }) },
    { label: 'Circulation history', icon: History, to: `/library/circulation?tab=history&q=${encodeURIComponent(String(r.title))}` },
  ];
  const copyMenu = (r: Row) => [
    { label: 'Open book page', icon: Eye, to: `/library/books/${r.book_id}` },
    { label: 'Print label', icon: Printer, onClick: () => openPrint('labels', { ids: r.id }) },
    can('library', 'edit') && r.status !== 'issued' && { label: 'Change status…', icon: Wrench, onClick: () => setStatusIds({ ids: [Number(r.id)], preset: r.status === 'available' ? 'damaged' : 'available' }) },
    r.status === 'issued' && { label: 'Return at the desk', icon: BookCheck, to: `/library/circulation?tab=return&code=${encodeURIComponent(String(r.accession_no))}` },
  ];

  return (
    <>
      <PageHeader
        title="Books & Copies"
        description="The library catalogue: titles, physical copies (accession register), categories, authors and publishers."
        breadcrumbs={libCrumbs('Books & Copies')}
        actions={
          <>
            <Button variant="secondary" icon={BookOpen} to="/library/circulation">
              Issue / Return
            </Button>
          </>
        }
      />

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="t" label="Titles in catalogue" value={k?.titles ?? 0} icon={BookMarked} tone="navy" loading={dash.isLoading} sub={`Across ${formatNumber(cats.data?.total ?? 0)} categories`} />,
          <StatTile key="c" label="Physical copies" value={k?.copies ?? 0} icon={Library} tone="blue" loading={dash.isLoading} sub={`${formatNumber(k?.damaged_lost ?? 0)} damaged / lost`} />,
          <StatTile key="a" label="On the shelf" value={k?.available ?? 0} icon={BookCheck} tone="green" loading={dash.isLoading} sub={`${formatNumber(k?.issued ?? 0)} on loan`} />,
          <StatTile key="p" label="Most borrowed" value={dash.data?.popular[0]?.issues ?? 0} icon={BookOpen} tone="purple" loading={dash.isLoading} sub={dash.data?.popular[0]?.title ?? '—'} />,
        ]}
      </Stagger>

      <Tabs tabs={TABS} value={tab} onChange={(t) => go(t)} className="mb-4" />

      {tab === 'catalogue' && (
        <CrudTable
          key="books"
          module="books"
          urlState
          title="Catalogue"
          viewTo={(r) => `/library/books/${r.id}`}
          rowMenu={bookMenu}
          addLabel="Add Book"
          emptyText="Add the first title to the library catalogue, then add its copies."
          renderers={{
            title: (r) => (
              <div className="flex min-w-[14rem] max-w-xs items-center gap-3">
                <BookCover title={String(r.title)} src={r.cover_image} size="xs" />
                <div className="min-w-0 leading-tight">
                  <Link to={`/library/books/${r.id}`} className="line-clamp-1 font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                    {r.title}
                  </Link>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">{r.authors || '—'}{r.edition ? ` · ${r.edition}` : ''}{r.publish_year ? ` · ${r.publish_year}` : ''}</p>
                </div>
              </div>
            ),
            category_name: (r) => <span className="block max-w-[9rem] truncate whitespace-nowrap" title={r.category_name ?? ''}>{r.category_name ?? '—'}</span>,
            available_copies: (r) => <Availability available={Number(r.available_copies)} total={Number(r.total_copies)} type={String(r.type)} />,
            price: (r) => (r.price ? <span className="tabular-nums">{formatMoney(r.price)}</span> : <span className="text-slate-400">—</span>),
          }}
          hideColumns={['total_copies']}
        />
      )}

      {tab === 'copies' && (
        <CrudTable
          key="copies"
          module="book_copies"
          urlState
          title="Accession register"
          rowMenu={copyMenu}
          addLabel="Add Copy"
          emptyText="Add copies from a book's page or here — accession numbers are generated automatically."
          bulkActions={[
            ...(can('library', 'edit') ? [{ label: 'Change status', icon: Wrench, onClick: (ids: number[], clear: () => void) => setStatusIds({ ids, clear }) }] : []),
            { label: 'Print labels', icon: Tags, onClick: (ids: number[]) => openPrint('labels', { ids: ids.join(',') }) },
          ]}
          renderers={{
            book_title: (r) => (
              <div className="w-56 leading-tight xl:w-64">
                <Link to={`/library/books/${r.book_id}`} className="line-clamp-1 font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" title={String(r.book_title)}>{r.book_title}</Link>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">{r.authors ?? '—'}</p>
              </div>
            ),
            borrower: (r) => <CopyBorrowerCell borrower={r.borrower} due={r.due_date} />,
          }}
        />
      )}

      {tab === 'categories' && (
        <CrudTable
          key="cats"
          module="book_categories"
          urlState
          addLabel="Add Category"
          rowMenu={(r) => [{ label: 'Browse books', icon: BookMarked, onClick: () => go('catalogue', { 'f.category_id': String(r.id) }) }]}
        />
      )}
      {tab === 'authors' && (
        <CrudTable
          key="authors"
          module="authors"
          urlState
          addLabel="Add Author"
          rowMenu={(r) => [{ label: 'Browse titles', icon: BookMarked, onClick: () => go('catalogue', { q: String(r.name) }) }]}
        />
      )}
      {tab === 'publishers' && (
        <CrudTable
          key="publishers"
          module="publishers"
          urlState
          addLabel="Add Publisher"
          renderers={{
            website: (r) =>
              r.website ? (
                <a href={String(r.website)} target="_blank" rel="noopener noreferrer" className="link !font-normal">
                  {String(r.website).replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '')}
                </a>
              ) : (
                <span className="text-slate-400">—</span>
              ),
          }}
          rowMenu={(r) => [{ label: 'Browse books', icon: BookMarked, onClick: () => go('catalogue', { 'f.publisher_id': String(r.id) }) }]}
        />
      )}

      <AddCopiesDialog book={addCopies} onClose={() => setAddCopies(null)} />
      <CopyStatusDialog ids={statusIds?.ids ?? null} preset={statusIds?.preset} onClose={() => setStatusIds(null)} onDone={() => statusIds?.clear?.()} />
    </>
  );
}

function Availability({ available, total, type }: { available: number; total: number; type: string }) {
  if (type === 'reference') {
    return <span className="badge badge-purple whitespace-nowrap">Reference</span>;
  }
  const tone = total === 0 ? 'badge-slate' : available === 0 ? 'badge-red' : available / total < 0.34 ? 'badge-amber' : 'badge-green';
  return (
    <span className={clsx('badge whitespace-nowrap tabular-nums', tone)} title={`${available} of ${total} copies on the shelf`}>
      {total === 0 ? 'No copies' : `${available} of ${total}`}
    </span>
  );
}
