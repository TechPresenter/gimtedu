import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarDays, ChevronLeft, ChevronRight, Fingerprint, KeyRound, LockKeyhole, Megaphone, Paperclip, Pin, ShieldCheck, Smartphone } from 'lucide-react';
import { SearchInput } from '@/components/ui';
import { formatDate, formatNumber, labelize } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { areaPath, type Area } from '../lib/config';
import { usePortalQuery } from '../lib/hooks';
import { usePortal } from '../lib/session';
import { ChangePasswordForm } from './ChangePasswordForm';
import { CategoryBadge, NoticeDrawer, type NoticeList } from './Notices';
import { EmptyBlock, ErrorState, PageHead, Section } from './Ui';

/* ------------------------------------------------------------------ Change password screen */

export function ChangePasswordScreen({ area }: { area: Area }) {
  const { session } = usePortal();
  return (
    <div>
      <PageHead area={area} title="Change Password" subtitle="Keep your portal account secure." crumbs={[{ label: 'Dashboard', to: areaPath(area) }, { label: 'My Profile', to: areaPath(area, 'profile') }, { label: 'Change Password' }]} />
      <div className="grid gap-5 lg:grid-cols-5">
        <Section title="Set a new password" icon={KeyRound} className="lg:col-span-3" bodyClassName="p-5 sm:p-6">
          <ChangePasswordForm area={area} policy={session.app.password_policy} />
        </Section>
        <div className="space-y-5 lg:col-span-2">
          <Section title="Password tips" icon={ShieldCheck}>
            <ul className="space-y-3.5 text-sm text-slate-600 dark:text-slate-300">
              {[
                { icon: Fingerprint, text: 'Use a phrase only you know — e.g. three random words with a number and a symbol.' },
                { icon: LockKeyhole, text: 'Never reuse your email, banking or social-media password here.' },
                { icon: Smartphone, text: 'Changing your password signs out every other device that used the old one.' },
              ].map((t, i) => (
                <li key={i} className="flex gap-3">
                  <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300">
                    <t.icon className="h-4 w-4" aria-hidden />
                  </span>
                  <span className="pt-1">{t.text}</span>
                </li>
              ))}
            </ul>
          </Section>
          <div className="rounded-2xl border border-dashed border-slate-300 p-4 text-[13px] text-slate-500 dark:border-slate-700 dark:text-slate-400">
            The institute will <strong className="text-slate-700 dark:text-slate-200">never</strong> ask for your password by phone, SMS, WhatsApp or email.
          </div>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ Notices screen */

const CATEGORIES = ['academic', 'examination', 'circular', 'holiday', 'event', 'placement', 'fee', 'admission', 'general', 'urgent'];

export function NoticesScreen({ area }: { area: Area }) {
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState('');
  const query = useDebounce(q, 300);
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const openId = Number(params.get('id')) || null;
  const { data, isLoading, isFetching, error, refetch } = usePortalQuery<NoticeList>(area, ['notices', 'list'], 'notices', { q: query || undefined, category: category || undefined, page, limit: 12 });
  useEffect(() => setPage(1), [query, category]);
  const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const setOpen = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('id', String(id));
    else next.delete('id');
    setParams(next, { replace: true });
  };

  return (
    <div>
      <PageHead area={area} title="Notices" subtitle="Circulars and announcements published for you by the institute." crumbs={[{ label: 'Dashboard', to: areaPath(area) }, { label: 'Notices' }]} />
      <div className="card mb-4 flex flex-col gap-3 p-3 sm:flex-row sm:items-center">
        <SearchInput value={q} onChange={setQ} placeholder="Search notices…" className="sm:w-72" />
        <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5 scrollbar-none" role="tablist" aria-label="Filter by category">
          {['', ...CATEGORIES].map((c) => (
            <button
              key={c || 'all'}
              type="button"
              role="tab"
              aria-selected={category === c}
              onClick={() => setCategory(c)}
              className={clsx(
                'whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                category === c ? 'border-brand-900 bg-brand-900 text-white dark:border-white dark:bg-white dark:text-brand-900' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-white',
              )}
            >
              {c ? labelize(c) : 'All'}
            </button>
          ))}
        </div>
      </div>
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="grid gap-3 md:grid-cols-2">
          {[0, 1, 2, 3, 4, 5].map((i) => (
            <div key={i} className="skeleton h-36 rounded-2xl" />
          ))}
        </div>
      ) : data.items.length === 0 ? (
        <div className="card">
          <EmptyBlock
            icon={Megaphone}
            title={query || category ? 'No notices match your filters' : 'No notices right now'}
            text={query || category ? 'Try a different search word or category.' : 'Announcements for your program, department and semester will appear here.'}
            action={
              query || category ? (
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => { setQ(''); setCategory(''); }}>
                  Clear filters
                </button>
              ) : undefined
            }
          />
        </div>
      ) : (
        <>
          <ul className={clsx('grid gap-3 md:grid-cols-2', isFetching && 'opacity-70 transition-opacity')}>
            {data.items.map((n) => (
              <li key={n.id}>
                <button type="button" onClick={() => setOpen(n.id)} className="card lift group flex h-full w-full flex-col p-4 text-left sm:p-5">
                  <div className="flex items-center gap-2">
                    <CategoryBadge category={n.category} />
                    {n.is_pinned && (
                      <span className="badge badge-flame">
                        <Pin className="h-3 w-3" aria-hidden /> Pinned
                      </span>
                    )}
                    {n.attachment && <Paperclip className="ml-auto h-4 w-4 text-slate-400" aria-label="Has attachment" />}
                  </div>
                  <h2 className="mt-2.5 font-display text-[15px] font-bold leading-snug text-slate-900 group-hover:text-flame-700 dark:text-white dark:group-hover:text-flame-300">{n.title}</h2>
                  <p className="mt-1.5 line-clamp-2 flex-1 text-[13px] leading-relaxed text-slate-500 dark:text-slate-400">{n.excerpt}</p>
                  <p className="mt-3 flex items-center gap-1.5 text-xs text-slate-400">
                    <CalendarDays className="h-3.5 w-3.5" aria-hidden /> {formatDate(n.date)}
                    {n.expiry_date && <span>· till {formatDate(n.expiry_date)}</span>}
                  </p>
                </button>
              </li>
            ))}
          </ul>
          {pages > 1 && (
            <nav className="mt-5 flex items-center justify-between gap-3" aria-label="Pagination">
              <p className="text-xs text-slate-500">
                Page {page} of {pages} · {formatNumber(data.total)} notices
              </p>
              <div className="flex gap-2">
                <button type="button" className="btn btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  <ChevronLeft className="h-4 w-4" aria-hidden /> Previous
                </button>
                <button type="button" className="btn btn-secondary btn-sm" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
                  Next <ChevronRight className="h-4 w-4" aria-hidden />
                </button>
              </div>
            </nav>
          )}
        </>
      )}
      <NoticeDrawer area={area} id={openId} onClose={() => setOpen(null)} />
    </div>
  );
}
