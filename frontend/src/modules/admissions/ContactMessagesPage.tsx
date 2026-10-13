import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  Archive, ArchiveRestore, ArrowLeft, CheckCheck, CheckCircle2, ChevronLeft, ChevronRight, Download, FileSpreadsheet, FileText, Inbox, Mail, MailOpen, MessageCircle, Phone,
  Printer, RotateCcw, Send, Star, Trash2, UserPlus, Reply, type LucideIcon,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, Dropdown, EmptyState, Field, IconButton, Input, PageHeader, SearchInput, Select, Skeleton, StatusBadge, Textarea, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError, apiUrl, downloadFile } from '@/lib/api';
import { useApi, useCrudList, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatDateTime, formatNumber, labelize, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import type { BadgeColor } from '@/lib/status';

type Folder = 'inbox' | 'unread' | 'starred' | 'replied' | 'resolved' | 'archived';
const FOLDERS: { key: Folder; label: string; icon: LucideIcon }[] = [
  { key: 'inbox', label: 'Inbox', icon: Inbox },
  { key: 'unread', label: 'Unread', icon: Mail },
  { key: 'starred', label: 'Starred', icon: Star },
  { key: 'replied', label: 'Replied', icon: Reply },
  { key: 'resolved', label: 'Resolved', icon: CheckCircle2 },
  { key: 'archived', label: 'Archived', icon: Archive },
];
const TYPES: Record<string, string> = { admission: 'Admission', academic: 'Academic', placement: 'Placement', general: 'General', other: 'Other' };
const STATUS_COLORS: Record<string, BadgeColor> = { new: 'blue', read: 'slate', replied: 'green', closed: 'purple' };
const STATUS_LABEL: Record<string, string> = { new: 'Unread', read: 'Read', replied: 'Replied', closed: 'Resolved' };
const TEMPLATES = [
  { label: 'Admission info', text: 'Thank you for your interest in GIMT. Admissions for the 2026-27 session are open for select programs. Our admission counsellor will call you within 24 hours with the complete process, fee structure and scholarship details.' },
  { label: 'Campus visit', text: 'You are welcome to visit the campus Monday to Saturday between 9:00 AM and 5:00 PM. Please bring a photo ID; our admission desk at the main block will arrange a guided tour.' },
  { label: 'Forwarded', text: 'Thank you for writing to us. We have forwarded your request to the concerned department and they will get back to you within two working days.' },
];

interface Detail {
  message: Row;
  thread: { id: number; recipient: string; subject: string; body: string; status: string; error: string | null; sent_at: string | null; created_at: string }[];
  enquiry: { id: number; status: string; assignee_name: string | null } | null;
  other_messages: number;
  can: { edit: boolean; delete: boolean; convert: boolean };
}
type Counts = Record<Folder | 'total' | 'this_week', number>;

function htmlToText(html: string): string {
  const doc = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n\n').replace(/<hr[^>]*>/gi, '\n—\n'), 'text/html');
  return (doc.body.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim();
}

export default function ContactMessagesPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const folder = (params.get('folder') as Folder) || 'inbox';
  const selectedId = Number(params.get('id')) || null;
  const [q, setQ] = useState(params.get('q') ?? '');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [checked, setChecked] = useState<Set<number>>(new Set());
  const debouncedQ = useDebounce(q, 350);
  useEffect(() => {
    setPage(1);
    setChecked(new Set());
  }, [folder, debouncedQ, type]);

  const { data: counts } = useApi<Counts>(['cm-counts'], 'contact-messages/counts');
  const listQuery = { page, per_page: 20, q: debouncedQ || undefined, f: { folder, ...(type ? { enquiry_type: type } : {}) } };
  const { data: list, isLoading, error: listError } = useCrudList('contact_messages', listQuery);
  const rows = list?.rows ?? [];

  const setParam = (k: string, v: string | null) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v);
    else next.delete(k);
    setParams(next, { replace: k !== 'id' });
  };
  const select = (id: number | null) => setParam('id', id ? String(id) : null);
  const refresh = () => invalidate('crud', 'cm-counts', 'cm-detail');

  const bulk = async (action: string) => {
    const ids = [...checked];
    try {
      if (action === 'delete') {
        const ok = await confirm({ title: `Delete ${ids.length} message${ids.length === 1 ? '' : 's'}?`, message: 'Selected messages will be permanently deleted.', confirmText: 'Delete', danger: true });
        if (!ok) return;
        const res = await api.post('crud/contact_messages/bulk', { action: 'delete', ids });
        toast.success(res.message);
        if (selectedId && ids.includes(selectedId)) select(null);
      } else {
        const res = await api.post('contact-messages/bulk', { action, ids });
        toast.success(res.message);
      }
      setChecked(new Set());
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const exportAs = (format: 'csv' | 'xlsx' | 'print') => {
    const query = { format, q: debouncedQ || undefined, f: { folder, ...(type ? { enquiry_type: type } : {}) } };
    if (format === 'print') window.open(apiUrl('crud/contact_messages/export', query), '_blank', 'noopener');
    else downloadFile('crud/contact_messages/export', query);
  };

  return (
    <>
      <PageHeader
        title="Contact Messages"
        description="Messages from the website contact form — read, reply by email, convert to enquiries and resolve."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Contact Messages' }]}
        actions={
          can('contact_messages', 'export') && (
            <Dropdown
              label="Export"
              trigger={<><Download className="h-4 w-4" />Export</>}
              items={[
                { label: 'Export as CSV', icon: FileText, onClick: () => exportAs('csv') },
                { label: 'Export as Excel', icon: FileSpreadsheet, onClick: () => exportAs('xlsx') },
                { label: 'Print / Save as PDF', icon: Printer, onClick: () => exportAs('print') },
              ]}
            />
          )
        }
      />
      <Card className="overflow-hidden">
        <div className="grid min-h-[640px] lg:grid-cols-[200px_minmax(300px,380px)_1fr]">
          {/* Folders */}
          <nav aria-label="Folders" className={clsx('border-b border-slate-100 p-3 dark:border-slate-800 lg:border-b-0 lg:border-r', selectedId && 'hidden lg:block')}>
            <ul className="flex gap-1 overflow-x-auto scrollbar-none lg:flex-col">
              {FOLDERS.map((f) => {
                const n = counts?.[f.key];
                const on = folder === f.key;
                return (
                  <li key={f.key} className="shrink-0">
                    <button type="button" onClick={() => { setParam('folder', f.key === 'inbox' ? null : f.key); }} aria-current={on ? 'page' : undefined}
                      className={clsx('flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition', on ? 'bg-brand-50 font-semibold text-brand-900 dark:bg-brand-500/15 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800')}>
                      <f.icon className="h-4 w-4 shrink-0" aria-hidden />
                      <span className="flex-1 text-left">{f.label}</span>
                      {n !== undefined && n > 0 && <span className={clsx('rounded-full px-1.5 text-[11px] font-semibold tabular-nums', f.key === 'unread' ? 'bg-brand-700 text-white' : 'text-slate-400')}>{formatNumber(n)}</span>}
                    </button>
                  </li>
                );
              })}
            </ul>
            {counts && (
              <div className="mt-4 hidden rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/50 dark:text-slate-400 lg:block">
                <p><span className="font-semibold text-slate-700 dark:text-slate-200">{formatNumber(counts.this_week)}</span> received this week</p>
                <p className="mt-0.5"><span className="font-semibold text-slate-700 dark:text-slate-200">{formatNumber(counts.total)}</span> messages in total</p>
              </div>
            )}
          </nav>

          {/* Message list */}
          <section aria-label="Messages" className={clsx('flex min-w-0 flex-col border-slate-100 dark:border-slate-800 lg:border-r', selectedId && 'hidden lg:flex')}>
            <div className="space-y-2 border-b border-slate-100 p-3 dark:border-slate-800">
              <SearchInput value={q} onChange={setQ} placeholder="Search name, email, subject…" size="sm" />
              <Select inputSize="sm" value={type} onChange={(e) => setType(e.target.value)} options={TYPES} placeholder="All types" aria-label="Message type" />
            </div>
            {checked.size > 0 && (
              <div className="flex flex-wrap items-center gap-1 border-b border-brand-100 bg-brand-50/70 px-3 py-2 text-xs dark:border-brand-500/20 dark:bg-brand-500/10">
                <span className="mr-1 font-semibold text-brand-900 dark:text-brand-100">{checked.size} selected</span>
                <IconButton size="sm" icon={MailOpen} label="Mark as read" onClick={() => bulk('read')} />
                <IconButton size="sm" icon={Mail} label="Mark as unread" onClick={() => bulk('unread')} />
                <IconButton size="sm" icon={Star} label="Star" onClick={() => bulk('star')} />
                {folder === 'archived' ? <IconButton size="sm" icon={ArchiveRestore} label="Move to inbox" onClick={() => bulk('unarchive')} /> : <IconButton size="sm" icon={Archive} label="Archive" onClick={() => bulk('archive')} />}
                <IconButton size="sm" icon={CheckCheck} label="Mark resolved" onClick={() => bulk('resolve')} />
                {can('contact_messages', 'delete') && <IconButton size="sm" icon={Trash2} tone="danger" label="Delete" onClick={() => bulk('delete')} />}
                <button type="button" className="ml-auto text-brand-700 underline dark:text-brand-300" onClick={() => setChecked(new Set())}>Clear</button>
              </div>
            )}
            <div className="flex-1 overflow-y-auto lg:max-h-[640px]">
              {listError ? (
                <div className="p-4"><Alert variant="error">{(listError as ApiError).message}</Alert></div>
              ) : isLoading ? (
                <div className="space-y-4 p-4">{[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="space-y-2"><Skeleton className="h-4 w-1/2" /><Skeleton className="h-3 w-full" /><Skeleton className="h-3 w-4/5" /></div>)}</div>
              ) : rows.length === 0 ? (
                <EmptyState icon={Inbox} title={debouncedQ || type ? 'No matching messages' : `${FOLDERS.find((f) => f.key === folder)?.label} is empty`} description={debouncedQ || type ? 'Try a different search or type.' : 'Messages submitted on the website contact page appear here.'} className="!py-10" />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {rows.map((m) => (
                    <MessageRow key={String(m.id)} m={m} active={selectedId === Number(m.id)} checked={checked.has(Number(m.id))}
                      onCheck={(v) => setChecked((s) => { const n = new Set(s); if (v) n.add(Number(m.id)); else n.delete(Number(m.id)); return n; })}
                      onOpen={() => select(Number(m.id))} onChanged={refresh} canEdit={can('contact_messages', 'edit')} />
                  ))}
                </ul>
              )}
            </div>
            {list && list.total > 0 && (
              <div className="flex items-center justify-between border-t border-slate-100 px-3 py-2 text-xs text-slate-500 dark:border-slate-800">
                <span>{formatNumber(list.from ?? 0)}–{formatNumber(list.to ?? 0)} of {formatNumber(list.total)}</span>
                <div className="flex gap-1">
                  <IconButton size="sm" icon={ChevronLeft} label="Previous page" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} />
                  <IconButton size="sm" icon={ChevronRight} label="Next page" disabled={page >= list.pages} onClick={() => setPage((p) => p + 1)} />
                </div>
              </div>
            )}
          </section>

          {/* Reading pane */}
          <section aria-label="Message" className={clsx('min-w-0', !selectedId && 'hidden lg:block')}>
            {selectedId ? (
              <ReadingPane id={selectedId} onBack={() => select(null)} onChanged={refresh} onDeleted={() => { select(null); void refresh(); }} />
            ) : (
              <EmptyState icon={MailOpen} title="Select a message" description="Choose a message from the list to read it, reply by email, convert it to an enquiry or mark it resolved." className="h-full justify-center" />
            )}
          </section>
        </div>
      </Card>
    </>
  );
}

function MessageRow({ m, active, checked, onCheck, onOpen, onChanged, canEdit }: { m: Row; active: boolean; checked: boolean; onCheck: (v: boolean) => void; onOpen: () => void; onChanged: () => void; canEdit: boolean }) {
  const toast = useToast();
  const unread = m.status === 'new';
  const star = async () => {
    try {
      const res = await api.post(`contact-messages/${m.id}/star`, { starred: !Number(m.is_starred) });
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  return (
    <li className={clsx('group relative flex gap-2.5 px-3 py-3 transition', active ? 'bg-brand-50/80 dark:bg-brand-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40')}>
      {active && <span className="absolute inset-y-0 left-0 w-0.5 bg-brand-700 dark:bg-brand-400" aria-hidden />}
      <input type="checkbox" className="form-checkbox mt-1 shrink-0" checked={checked} onChange={(e) => onCheck(e.target.checked)} aria-label={`Select message from ${m.name}`} />
      <button type="button" onClick={onOpen} className="min-w-0 flex-1 text-left">
        <div className="flex items-baseline gap-2">
          {unread && <span className="h-2 w-2 shrink-0 rounded-full bg-brand-600" aria-label="Unread" />}
          <span className={clsx('min-w-0 flex-1 truncate text-sm', unread ? 'font-bold text-slate-900 dark:text-white' : 'font-medium text-slate-700 dark:text-slate-200')}>{String(m.name)}</span>
          <span className="shrink-0 text-[11px] text-slate-400" title={formatDateTime(m.created_at)}>{timeAgo(m.created_at)}</span>
        </div>
        <p className={clsx('truncate text-[13px]', unread ? 'font-semibold text-slate-800 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300')}>{String(m.subject ?? '(no subject)')}</p>
        <p className="line-clamp-1 text-xs text-slate-500 dark:text-slate-400">{String(m.preview ?? '')}</p>
        <div className="mt-1.5 flex flex-wrap gap-1">
          {m.enquiry_type && <span className="badge badge-slate !px-1.5 !py-0 !text-[10px]">{TYPES[String(m.enquiry_type)] ?? labelize(String(m.enquiry_type))}</span>}
          {m.status === 'replied' && <span className="badge badge-green !px-1.5 !py-0 !text-[10px]">Replied</span>}
          {m.status === 'closed' && <span className="badge badge-purple !px-1.5 !py-0 !text-[10px]">Resolved</span>}
          {m.enquiry_id && <span className="badge badge-blue !px-1.5 !py-0 !text-[10px]">Enquiry #{String(m.enquiry_id)}</span>}
        </div>
      </button>
      <button type="button" onClick={star} disabled={!canEdit} aria-pressed={!!Number(m.is_starred)} aria-label={Number(m.is_starred) ? 'Remove star' : 'Star message'}
        className={clsx('h-7 w-7 shrink-0 rounded-md p-1 transition hover:bg-amber-50 disabled:cursor-default dark:hover:bg-amber-500/10', Number(m.is_starred) ? 'text-amber-500' : 'text-slate-300 dark:text-slate-600')}>
        <Star className={clsx('h-4 w-4 transition-transform duration-200 group-hover:scale-110', Number(m.is_starred) && 'fill-current')} />
      </button>
    </li>
  );
}

function ReadingPane({ id, onBack, onChanged, onDeleted }: { id: number; onBack: () => void; onChanged: () => Promise<unknown>; onDeleted: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const { data, isLoading, error } = useApi<Detail>(['cm-detail', id], `contact-messages/${id}`, undefined, { retry: false });
  const [busy, setBusy] = useState<string | null>(null);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const replyRef = useRef<HTMLTextAreaElement>(null);
  const markedRef = useRef<number | null>(null);
  const m = data?.message;

  useEffect(() => {
    if (!m) return;
    setSubject(`Re: ${String(m.subject ?? 'Your message to GIMT')}`);
    setBody('');
    setErrors({});
    if (m.status === 'new' && markedRef.current !== id && data?.can.edit) {
      markedRef.current = id;
      void api.post(`contact-messages/${id}/read`, { read: true }).then(() => onChanged()).catch(() => undefined);
    }
  }, [m?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const thread = useMemo(() => (data?.thread ?? []).map((t) => ({ ...t, text: htmlToText(t.body) })), [data]);

  const act = async (key: string, path: string, payload: Record<string, unknown>) => {
    setBusy(key);
    try {
      const res = await api.post(path, payload);
      if (res.message) toast.success(res.message);
      await onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const del = async () => {
    if (!m) return;
    const ok = await confirm({ title: 'Delete message?', message: <>Delete the message from <strong>{String(m.name)}</strong>? This cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/contact_messages/${id}`);
      toast.success(res.message);
      onDeleted();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const send = async () => {
    const errs: Record<string, string> = {};
    if (!subject.trim()) errs.subject = 'Subject is required.';
    if (body.trim().length < 5) errs.message = 'Write a reply of at least 5 characters.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy('reply');
    try {
      const res = await api.post(`contact-messages/${id}/reply`, { subject, message: body });
      toast.success(res.message);
      setBody('');
      await onChanged();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setBusy(null);
    }
  };

  if (error) return <div className="p-6"><Alert variant="error" title="Unable to open the message">{(error as ApiError).message}</Alert><Button className="mt-4 lg:hidden" variant="secondary" icon={ArrowLeft} onClick={onBack}>Back</Button></div>;
  if (isLoading || !m || !data) return <div className="space-y-4 p-6"><Skeleton className="h-6 w-2/3" /><Skeleton className="h-4 w-1/3" /><Skeleton className="h-32 w-full" /></div>;
  const canEdit = data.can.edit;
  const starred = !!Number(m.is_starred);
  const archived = !!Number(m.is_archived);
  const resolved = m.status === 'closed';

  return (
    <article className="flex h-full flex-col">
      <header className="border-b border-slate-100 p-4 dark:border-slate-800 sm:p-5">
        <div className="flex items-start gap-2">
          <IconButton className="lg:hidden" icon={ArrowLeft} label="Back to messages" onClick={onBack} />
          <div className="min-w-0 flex-1">
            <h2 className="font-display text-lg font-semibold leading-snug text-slate-900 dark:text-white">{String(m.subject ?? '(no subject)')}</h2>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              <StatusBadge status={String(m.status)} label={STATUS_LABEL[String(m.status)]} colors={STATUS_COLORS} />
              {m.enquiry_type && <Badge color="slate">{TYPES[String(m.enquiry_type)] ?? labelize(String(m.enquiry_type))}</Badge>}
              {archived && <Badge color="slate">Archived</Badge>}
            </div>
          </div>
        </div>
        {canEdit && (
          <div className="mt-3 flex flex-wrap items-center gap-1.5">
            <Button size="xs" variant="secondary" icon={Reply} onClick={() => replyRef.current?.focus()}>Reply</Button>
            <Button size="xs" variant="secondary" icon={Star} className={clsx(starred && '!text-amber-600')} loading={busy === 'star'} onClick={() => act('star', `contact-messages/${id}/star`, { starred: !starred })}>{starred ? 'Starred' : 'Star'}</Button>
            <Button size="xs" variant="secondary" icon={Mail} loading={busy === 'read'} onClick={() => act('read', `contact-messages/${id}/read`, { read: false })}>Mark unread</Button>
            <Button size="xs" variant="secondary" icon={resolved ? RotateCcw : CheckCheck} loading={busy === 'resolve'} onClick={() => act('resolve', `contact-messages/${id}/resolve`, { resolved: !resolved })}>{resolved ? 'Reopen' : 'Resolve'}</Button>
            <Button size="xs" variant="secondary" icon={archived ? ArchiveRestore : Archive} loading={busy === 'archive'} onClick={() => act('archive', `contact-messages/${id}/archive`, { archived: !archived })}>{archived ? 'Move to inbox' : 'Archive'}</Button>
            {!data.enquiry && data.can.convert && (
              <Button size="xs" variant="soft" icon={UserPlus} loading={busy === 'convert'} disabled={!m.phone} title={m.phone ? 'Create an admission enquiry from this message' : 'The sender did not share a phone number, so an enquiry cannot be created.'}
                onClick={() => act('convert', `contact-messages/${id}/convert`, {})}>
                Convert to enquiry
              </Button>
            )}
            {data.can.delete && <IconButton size="sm" icon={Trash2} tone="danger" label="Delete message" onClick={del} />}
          </div>
        )}
      </header>

      <div className="flex-1 space-y-5 overflow-y-auto p-4 sm:p-5 lg:max-h-[560px]">
        <div className="flex items-start gap-3">
          <Avatar name={String(m.name)} size="md" />
          <div className="min-w-0 flex-1 text-sm">
            <p className="font-semibold text-slate-900 dark:text-white">{String(m.name)}</p>
            <p className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
              <a className="inline-flex items-center gap-1 hover:text-brand-700" href={`mailto:${m.email}`}><Mail className="h-3 w-3" />{String(m.email)}</a>
              {m.phone && <a className="inline-flex items-center gap-1 hover:text-brand-700" href={`tel:${String(m.phone).replace(/\s/g, '')}`}><Phone className="h-3 w-3" />{String(m.phone)}</a>}
              {m.phone && <a className="inline-flex items-center gap-1 hover:text-brand-700" href={`https://wa.me/${String(m.phone).replace(/\D/g, '')}`} target="_blank" rel="noreferrer"><MessageCircle className="h-3 w-3" />WhatsApp</a>}
            </p>
          </div>
          <span className="shrink-0 text-xs text-slate-400" title={formatDateTime(m.created_at)}>{formatDateTime(m.created_at)}</span>
        </div>
        <div className="whitespace-pre-line rounded-xl border border-slate-100 bg-slate-50/70 p-4 text-sm leading-relaxed text-slate-700 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-200">{String(m.message)}</div>
        {data.other_messages > 0 && <p className="text-xs text-slate-500">This sender has written {data.other_messages} other time{data.other_messages === 1 ? '' : 's'}.</p>}
        {data.enquiry && (
          <Alert variant="info" title={`Converted to enquiry #${data.enquiry.id}`} action={<Button size="xs" variant="secondary" to={`/enquiries?q=${encodeURIComponent(String(m.phone ?? m.email))}`}>Open</Button>}>
            Status: {labelize(data.enquiry.status)}{data.enquiry.assignee_name ? ` · assigned to ${data.enquiry.assignee_name}` : ' · not assigned yet'}
          </Alert>
        )}
        {(thread.length > 0 || m.reply) && (
          <div className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Replies</h3>
            {thread.length > 0 ? (
              thread.map((t) => (
                <div key={t.id} className="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 text-sm dark:border-emerald-500/20 dark:bg-emerald-500/5">
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                    <span className="font-semibold text-slate-700 dark:text-slate-200">{t.subject}</span>
                    <span>{t.status === 'sent' ? 'Sent' : 'Failed'} · {formatDateTime(t.sent_at ?? t.created_at)}</span>
                  </div>
                  <p className="whitespace-pre-line text-slate-700 dark:text-slate-200">{t.text}</p>
                </div>
              ))
            ) : (
              <div className="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 text-sm dark:border-emerald-500/20 dark:bg-emerald-500/5">
                <p className="mb-2 text-xs text-slate-500">Replied by {String(m.replied_by_name ?? 'staff')} · {formatDateTime(m.replied_at)}</p>
                <p className="whitespace-pre-line text-slate-700 dark:text-slate-200">{String(m.reply)}</p>
              </div>
            )}
          </div>
        )}
      </div>

      {canEdit && (
        <footer className="border-t border-slate-100 bg-slate-50/50 p-4 dark:border-slate-800 dark:bg-slate-800/20 sm:p-5">
          <div className="space-y-3">
            <Field label={`Reply to ${String(m.email)}`} error={errors.subject} htmlFor="cm-subject">
              <Input id="cm-subject" inputSize="sm" value={subject} onChange={(e) => setSubject(e.target.value)} invalid={!!errors.subject} maxLength={255} />
            </Field>
            <Field error={errors.message} htmlFor="cm-body">
              <Textarea id="cm-body" ref={replyRef} rows={4} value={body} onChange={(e) => setBody(e.target.value)} invalid={!!errors.message} maxLength={10000} placeholder="Write your reply… (a greeting and signature are added automatically)" aria-label="Reply message" />
            </Field>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs text-slate-500">Templates:</span>
              {TEMPLATES.map((t) => (
                <button key={t.label} type="button" onClick={() => setBody(t.text)} className="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200 transition hover:text-brand-800 hover:ring-brand-300 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                  {t.label}
                </button>
              ))}
              <Button className="ml-auto" size="sm" icon={Send} loading={busy === 'reply'} onClick={send}>Send reply</Button>
            </div>
          </div>
        </footer>
      )}
    </article>
  );
}
