import { useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  Activity,
  BadgeCheck,
  Bot,
  BotOff,
  CalendarClock,
  CheckCircle2,
  ClipboardPlus,
  Copy,
  ExternalLink,
  GraduationCap,
  Hash,
  IndianRupee,
  Mail,
  MapPin,
  MessageSquareText,
  NotebookPen,
  Pencil,
  Plus,
  ShieldAlert,
  StickyNote,
  Tag,
  Trash2,
  UserRound,
  X,
} from 'lucide-react';
import { Alert, Avatar, Badge, Button, EmptyState, Select, Skeleton, Spinner, StatusBadge, Textarea, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney, labelize, timeAgo } from '@/lib/format';
import { BotSessionCard } from '../bot/BotSessionCard';
import { ENQUIRY_CONTACT_TYPES } from '../contacts/crm';
import { CreateEnquiryDialog } from '../contacts/CreateEnquiryDialog';
import { WindowBadge } from '../shared';
import { inboxKeys, useActivity, useAllTags, useApplyConversation, useNotes } from './hooks';
import type { ConvStatus, InboxAgent, InboxDetail, InboxNote, InboxSummary, InboxTag } from './types';
import { TAG_COLORS, TAG_SWATCH, tagClass } from './utils';

type Tab = 'details' | 'notes' | 'activity';

interface Props {
  convId: number;
  detail: InboxDetail | undefined;
  loading: boolean;
  agents: InboxAgent[];
  meId: number;
  onClose: () => void;
  onChanged: (extra?: ('notes' | 'activity' | 'tags' | 'agents')[]) => void;
}

const LINK_ICON = { student: GraduationCap, admission: NotebookPen, enquiry: MessageSquareText } as const;

export function ContactPanel({ convId, detail, loading, agents, meId, onClose, onChanged, embedded = false }: Props & { embedded?: boolean }) {
  const [tab, setTab] = useState<Tab>('details');
  const conv = detail?.conversation;
  const profile = detail?.profile;
  const perms = detail?.permissions;

  if (loading || !detail || !conv || !profile || !perms) {
    return (
      <div className="space-y-4 p-4">
        <div className="flex flex-col items-center gap-2"><Skeleton className="h-16 w-16 rounded-full" /><Skeleton className="h-4 w-32" /><Skeleton className="h-3 w-24" /></div>
        <Skeleton className="h-24 w-full rounded-xl" />
        <Skeleton className="h-32 w-full rounded-xl" />
      </div>
    );
  }

  const tabs: { key: Tab; label: string; count?: number }[] = [
    { key: 'details', label: 'Details' },
    { key: 'notes', label: 'Notes', count: detail.notes_count },
    { key: 'activity', label: 'Activity' },
  ];

  return (
    <div className="flex h-full min-h-0 flex-col">
      {!embedded && (
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
          <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">Contact</span>
          <button type="button" onClick={onClose} className="btn-icon !h-8 !w-8" aria-label="Close contact details"><X className="h-4 w-4" /></button>
        </div>
      )}
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="flex flex-col items-center px-4 pb-3 pt-4 text-center">
          <Avatar name={profile.name || '#'} size="lg" />
          <p className="mt-2 font-display text-base font-semibold text-slate-900 dark:text-white">{profile.name || profile.phone_display}</p>
          <p className="flex items-center gap-1 text-sm tabular-nums text-slate-500 dark:text-slate-400">
            {profile.phone_display}
            {!profile.phone_masked && <CopyButton value={`+${profile.phone}`} label="Copy phone number" />}
          </p>
          <div className="mt-2 flex flex-wrap justify-center gap-1.5">
            <Badge color="navy">{labelize(profile.contact_type)}</Badge>
            {profile.opt_in ? <Badge color="green" dot>Opted in</Badge> : profile.opted_out_at ? <Badge color="red" dot>Opted out</Badge> : <Badge color="amber" dot>No opt-in</Badge>}
            {profile.status === 'blocked' && <Badge color="red">Blocked</Badge>}
          </div>
          <div className="mt-2"><WindowBadge lastInboundAt={conv.window_last_inbound_at} /></div>
        </div>

        <div className="sticky top-0 z-[1] border-y border-slate-100 bg-white px-2 dark:border-slate-800 dark:bg-slate-900">
          <nav className="flex" role="tablist" aria-label="Contact panel">
            {tabs.map((t) => (
              <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} onClick={() => setTab(t.key)} className={clsx('flex-1 border-b-2 px-2 py-2 text-xs font-semibold transition', tab === t.key ? 'border-accent-600 text-accent-700 dark:text-accent-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200')}>
                {t.label}
                {!!t.count && <span className="ml-1 rounded-full bg-slate-100 px-1.5 text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{t.count}</span>}
              </button>
            ))}
          </nav>
        </div>

        {tab === 'details' && <DetailsTab convId={convId} detail={detail} agents={agents} meId={meId} onChanged={onChanged} />}
        {tab === 'notes' && <NotesTab convId={convId} canAdd={perms.send} onChanged={onChanged} />}
        {tab === 'activity' && <ActivityTab convId={convId} />}
      </div>
    </div>
  );
}

function CopyButton({ value, label }: { value: string; label: string }) {
  const toast = useToast();
  return (
    <button
      type="button"
      className="rounded p-0.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
      aria-label={label}
      title={label}
      onClick={() => {
        void navigator.clipboard?.writeText(value).then(() => toast.success('Copied.'), () => toast.error('Copy failed.'));
      }}
    >
      <Copy className="h-3.5 w-3.5" aria-hidden />
    </button>
  );
}

function Section({ title, icon: Icon, children, action }: { title: string; icon: typeof Tag; children: ReactNode; action?: ReactNode }) {
  return (
    <section className="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"><Icon className="h-3.5 w-3.5" aria-hidden />{title}</h3>
        {action}
      </div>
      {children}
    </section>
  );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-3 py-1 text-sm">
      <dt className="shrink-0 text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="min-w-0 text-right font-medium text-slate-800 dark:text-slate-100">{children}</dd>
    </div>
  );
}

function DetailsTab({ convId, detail, agents, meId, onChanged }: { convId: number; detail: InboxDetail; agents: InboxAgent[]; meId: number; onChanged: Props['onChanged'] }) {
  const toast = useToast();
  const conv = detail.conversation;
  const p = detail.profile;
  const perms = detail.permissions;
  const [busy, setBusy] = useState<string | null>(null);
  const [enquiryOpen, setEnquiryOpen] = useState(false);
  const applyConversation = useApplyConversation();

  const post = async (key: string, path: string, body: unknown, extra: ('notes' | 'activity' | 'tags' | 'agents')[] = []) => {
    setBusy(key);
    try {
      const res = await api.post<{ conversation?: InboxSummary } | null>(path, body);
      if (res.message) toast.success(res.message);
      applyConversation(convId, res.data?.conversation);
      onChanged(extra);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const location = [p.city, p.state].filter(Boolean).join(', ');

  return (
    <>
      <Section title="Conversation" icon={CheckCircle2}>
        <div className="space-y-3">
          <div>
            <label htmlFor="cp-assign" className="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Assigned to</label>
            {perms.edit ? (
              <div className="flex items-center gap-2">
                <Select id="cp-assign" inputSize="sm" value={conv.assigned_to ? String(conv.assigned_to) : ''} placeholder="Unassigned" options={agents.map((a) => ({ value: String(a.id), label: a.id === meId ? `${a.name} (you)` : a.name }))} onChange={(e) => void post('assign', `whatsapp/inbox/conversations/${convId}/assign`, { user_id: e.target.value ? Number(e.target.value) : null }, ['agents'])} disabled={busy === 'assign'} />
                {busy === 'assign' && <Spinner className="h-4 w-4" />}
              </div>
            ) : (
              <div className="flex items-center justify-between gap-2 text-sm">
                <span className="font-medium text-slate-800 dark:text-slate-100">{conv.assigned_name ?? 'Unassigned'}</span>
                {perms.claim && <Button size="xs" variant="secondary" loading={busy === 'assign'} onClick={() => void post('assign', `whatsapp/inbox/conversations/${convId}/assign`, { user_id: meId }, ['agents'])}>Claim</Button>}
              </div>
            )}
          </div>
          <div>
            <span className="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Status</span>
            <div className="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800" role="radiogroup" aria-label="Conversation status">
              {(['open', 'pending', 'resolved'] as ConvStatus[]).map((s) => (
                <button
                  key={s}
                  type="button"
                  role="radio"
                  aria-checked={conv.status === s}
                  disabled={!perms.edit || busy === 'status'}
                  onClick={() => conv.status !== s && void post('status', `whatsapp/inbox/conversations/${convId}/status`, { status: s }, ['agents'])}
                  className={clsx('rounded-md px-2 py-1 text-xs font-semibold capitalize transition disabled:cursor-not-allowed', conv.status === s ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200')}
                >
                  {s}
                </button>
              ))}
            </div>
            {conv.status === 'resolved' && conv.resolved_at && <p className="mt-1 text-[11px] text-slate-400">Resolved {timeAgo(conv.resolved_at)}{conv.resolved_by_name ? ` by ${conv.resolved_by_name}` : ''}</p>}
          </div>
          <div className={clsx('flex items-center gap-3 rounded-lg p-2.5', conv.bot_active ? 'bg-violet-50 dark:bg-violet-500/10' : 'bg-slate-50 dark:bg-slate-800/60')}>
            <span className={clsx('flex h-8 w-8 shrink-0 items-center justify-center rounded-full', conv.bot_active ? 'bg-violet-600 text-white' : 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-300')}>
              {conv.bot_active ? <Bot className="h-4 w-4" aria-hidden /> : <BotOff className="h-4 w-4" aria-hidden />}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{conv.bot_active ? 'AI bot is answering' : 'Handled by the team'}</p>
              <p className="text-[11px] text-slate-500 dark:text-slate-400">{conv.bot_active ? 'Answers only from verified GIMT data.' : conv.bot_handover_at ? `The bot handed over ${timeAgo(conv.bot_handover_at)} — it stays quiet now.` : 'The bot stays quiet in this chat.'}</p>
            </div>
            {perms.edit && (
              <Button size="xs" variant={conv.bot_active ? 'primary' : 'secondary'} loading={busy === 'bot'} onClick={() => void post('bot', `whatsapp/inbox/conversations/${convId}/bot`, { active: !conv.bot_active })}>
                {conv.bot_active ? 'Take over' : 'Hand back'}
              </Button>
            )}
          </div>
        </div>
      </Section>

      <TagsSection convId={convId} tags={conv.tags} canEdit={perms.edit} onChanged={onChanged} />

      <Section title="AI bot" icon={Bot}>
        <BotSessionCard contactId={conv.contact_id} compact />
      </Section>

      <Section title="Profile" icon={UserRound}>
        <dl className="divide-y divide-slate-100 dark:divide-slate-800">
          {p.email && <Row label="Email"><a href={`mailto:${p.email}`} className="inline-flex max-w-full items-center gap-1 truncate hover:underline"><Mail className="h-3 w-3 shrink-0" aria-hidden />{p.email}</a></Row>}
          {p.program_name && <Row label="Program">{p.program_name}</Row>}
          {p.batch && <Row label="Batch">{p.batch}</Row>}
          {p.application_no && <Row label="Application"><span className="inline-flex items-center gap-1 font-mono text-xs"><Hash className="h-3 w-3" aria-hidden />{p.application_no}</span></Row>}
          {p.stage_name || p.stage ? <Row label="Lead stage"><StatusBadge status={p.stage} label={p.stage_name ?? labelize(p.stage)} /></Row> : null}
          {p.admission_status && <Row label="Admission"><StatusBadge status={p.admission_status} /></Row>}
          {p.payment_status && <Row label="Payment"><StatusBadge status={p.payment_status} /></Row>}
          <Row label="Counsellor">{p.counsellor_name ?? <span className="text-slate-400">—</span>}</Row>
          {location && <Row label="Location"><span className="inline-flex items-center gap-1"><MapPin className="h-3 w-3" aria-hidden />{location}</span></Row>}
          <Row label="Language">{p.language === 'hi' ? 'Hindi' : 'English'}</Row>
          <Row label="WhatsApp opt-in">{p.opt_in ? <span className="text-emerald-700 dark:text-emerald-400">Yes{p.opt_in_source ? ` · ${p.opt_in_source}` : ''}</span> : p.opted_out_at ? <span className="text-red-600">Opted out {timeAgo(p.opted_out_at)}</span> : <span className="text-amber-700 dark:text-amber-400">Not recorded</span>}</Row>
          {p.source && <Row label="Source">{labelize(p.source)}</Row>}
          <Row label="Contact since">{formatDateTime(p.created_at)}</Row>
        </dl>
        {!p.opt_in && (
          <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-2 text-[11px] text-amber-800 dark:bg-amber-500/10 dark:text-amber-200"><ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden />Marketing templates are not sent without opt-in. Replies and utility templates still work.</p>
        )}
      </Section>

      {p.fees && (
        <Section title="Fees" icon={IndianRupee}>
          <dl>
            <Row label="Balance">{formatMoney(p.fees.balance)}</Row>
            <Row label="Overdue"><span className={p.fees.overdue > 0 ? 'text-red-600 dark:text-red-400' : ''}>{formatMoney(p.fees.overdue)}</span></Row>
            {p.fees.next_due && <Row label="Next due"><span className="inline-flex items-center gap-1"><CalendarClock className="h-3 w-3" aria-hidden />{p.fees.next_due}</span></Row>}
          </dl>
        </Section>
      )}

      <Section title="Linked records" icon={BadgeCheck} action={<Link to={`/whatsapp/contacts/${p.id}`} className="text-[11px] font-semibold text-brand-700 hover:underline dark:text-brand-300">Contact record</Link>}>
        {p.links.length === 0 ? (
          <>
            <p className="text-sm text-slate-500 dark:text-slate-400">Not linked to a student, application or enquiry yet.</p>
            {perms.create_enquiry && ENQUIRY_CONTACT_TYPES.includes(p.contact_type) && (
              <>
                <Button size="sm" variant="secondary" icon={ClipboardPlus} className="mt-2 w-full" onClick={() => setEnquiryOpen(true)}>
                  Create admission enquiry
                </Button>
                <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">Adds this lead to Admissions › Enquiries (source WhatsApp) — or links the open enquiry of this number.</p>
                <CreateEnquiryDialog open={enquiryOpen} onClose={() => setEnquiryOpen(false)} contact={p} origin="inbox" onDone={() => onChanged(['activity'])} />
              </>
            )}
          </>
        ) : (
          <ul className="space-y-1.5">
            {p.links.map((l) => {
              const Icon = LINK_ICON[l.type];
              return (
                <li key={`${l.type}-${l.id}`}>
                  <Link to={l.to} className="flex items-center gap-2.5 rounded-lg border border-slate-200 px-2.5 py-2 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft dark:border-slate-700 dark:hover:border-brand-500/40">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200"><Icon className="h-4 w-4" aria-hidden /></span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-800 dark:text-slate-100">{l.label}{l.ref && <span className="truncate font-mono font-normal text-slate-500">{l.ref}</span>}</span>
                      <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">{l.detail || l.title}</span>
                    </span>
                    {l.status && <StatusBadge status={l.status} dot={false} />}
                    <ExternalLink className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
      </Section>
    </>
  );
}

function TagsSection({ convId, tags, canEdit, onChanged }: { convId: number; tags: InboxTag[]; canEdit: boolean; onChanged: Props['onChanged'] }) {
  const toast = useToast();
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const [color, setColor] = useState<string>('blue');
  const [busy, setBusy] = useState<number | 'add' | null>(null);
  const all = useAllTags(adding);
  const applyConversation = useApplyConversation();
  const suggestions = useMemo(() => {
    const s = name.trim().toLowerCase();
    const mine = new Set(tags.map((t) => t.id));
    return (all.data ?? []).filter((t) => !mine.has(t.id) && (!s || t.name.toLowerCase().includes(s))).slice(0, 8);
  }, [all.data, name, tags]);
  const exists = (all.data ?? []).some((t) => t.name.toLowerCase() === name.trim().toLowerCase());

  const add = async (tagName: string, c?: string) => {
    if (!tagName.trim()) return;
    setBusy('add');
    try {
      const res = await api.post<{ tags: InboxTag[] }>(`whatsapp/inbox/conversations/${convId}/tags`, { name: tagName.trim(), color: c });
      applyConversation(convId, { tags: res.data.tags });
      setName('');
      setAdding(false);
      onChanged(['tags']);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (t: InboxTag) => {
    setBusy(t.id);
    try {
      const res = await api.del<{ tags: InboxTag[] }>(`whatsapp/inbox/conversations/${convId}/tags/${t.id}`);
      applyConversation(convId, { tags: res.data.tags });
      onChanged(['tags']);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Section title="Tags" icon={Tag} action={canEdit && !adding ? <button type="button" onClick={() => setAdding(true)} className="inline-flex items-center gap-0.5 text-[11px] font-semibold text-brand-700 hover:underline dark:text-brand-300"><Plus className="h-3 w-3" aria-hidden />Add</button> : undefined}>
      {tags.length === 0 && !adding ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">No tags yet.</p>
      ) : (
        <div className="flex flex-wrap gap-1.5">
          {tags.map((t) => (
            <span key={t.id} className={clsx('inline-flex items-center gap-1 rounded-full py-0.5 pl-2 text-xs font-medium ring-1 ring-inset', canEdit ? 'pr-1' : 'pr-2', tagClass(t.color))}>
              {t.name}
              {canEdit && (
                <button type="button" onClick={() => void remove(t)} disabled={busy === t.id} className="rounded-full p-0.5 opacity-70 hover:bg-black/5 hover:opacity-100 dark:hover:bg-white/10" aria-label={`Remove tag ${t.name}`}>
                  {busy === t.id ? <Spinner className="h-3 w-3" /> : <X className="h-3 w-3" />}
                </button>
              )}
            </span>
          ))}
        </div>
      )}
      {adding && (
        <div className="mt-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
          <label htmlFor="tag-new" className="sr-only">Tag name</label>
          <input
            id="tag-new"
            autoFocus
            value={name}
            maxLength={60}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                void add(name, exists ? undefined : color);
              }
              if (e.key === 'Escape') setAdding(false);
            }}
            placeholder="Find or create a tag…"
            className="form-input form-input-sm"
          />
          {suggestions.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1">
              {suggestions.map((t) => (
                <button key={t.id} type="button" onClick={() => void add(t.name)} className={clsx('rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset transition hover:opacity-80', tagClass(t.color))}>{t.name}</button>
              ))}
            </div>
          )}
          {name.trim() && !exists && (
            <div className="mt-2 flex items-center gap-1.5">
              <span className="text-[11px] text-slate-500">Colour</span>
              {TAG_COLORS.map((c) => (
                <button key={c} type="button" onClick={() => setColor(c)} className={clsx('h-4 w-4 rounded-full ring-offset-1 dark:ring-offset-slate-900', TAG_SWATCH[c], color === c && 'ring-2 ring-slate-400')} aria-label={`Colour ${c}`} aria-pressed={color === c} />
              ))}
            </div>
          )}
          <div className="mt-2 flex justify-end gap-1.5">
            <Button size="xs" variant="ghost" onClick={() => { setAdding(false); setName(''); }}>Cancel</Button>
            <Button size="xs" loading={busy === 'add'} disabled={!name.trim()} onClick={() => void add(name, exists ? undefined : color)}>{exists || !name.trim() ? 'Add tag' : `Create “${name.trim()}”`}</Button>
          </div>
        </div>
      )}
    </Section>
  );
}

function NotesTab({ convId, canAdd, onChanged }: { convId: number; canAdd: boolean; onChanged: Props['onChanged'] }) {
  const toast = useToast();
  const confirm = useConfirm();
  const notes = useNotes(convId);
  const qc = useQueryClient();
  const [body, setBody] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<{ id: number; body: string; error: string | null } | null>(null);

  const saveEdit = async () => {
    if (!editing) return;
    if (!editing.body.trim()) {
      setEditing({ ...editing, error: 'A note cannot be empty.' });
      return;
    }
    setSaving(true);
    try {
      const res = await api.put(`whatsapp/inbox/notes/${editing.id}`, { body: editing.body.trim() });
      toast.success(res.message || 'Note updated.');
      const saved = { id: editing.id, body: editing.body.trim() };
      qc.setQueryData<InboxNote[]>(inboxKeys.notes(convId), (old) => old?.map((x) => (x.id === saved.id ? { ...x, body: saved.body } : x)));
      setEditing(null);
      onChanged(['notes']);
    } catch (e) {
      const err = e as ApiError;
      setEditing((x) => (x ? { ...x, error: err.errors?.body ?? err.message } : x));
    } finally {
      setSaving(false);
    }
  };

  const add = async () => {
    if (!body.trim()) {
      setError('Write a note first.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await api.post<{ notes: InboxNote[] }>('whatsapp/inbox/conversations/' + convId + '/notes', { body: body.trim() });
      toast.success(res.message || 'Note added.');
      setBody('');
      if (res.data?.notes) qc.setQueryData(inboxKeys.notes(convId), res.data.notes);
      onChanged(['notes']);
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.body ?? err.message);
    } finally {
      setSaving(false);
    }
  };

  const remove = async (n: InboxNote) => {
    if (!(await confirm({ title: 'Delete this note?', message: 'The note will be removed for the whole team.', confirmText: 'Delete', danger: true }))) return;
    try {
      const res = await api.del(`whatsapp/inbox/notes/${n.id}`);
      toast.success(res.message || 'Note deleted.');
      qc.setQueryData<InboxNote[]>(inboxKeys.notes(convId), (old) => old?.filter((x) => x.id !== n.id));
      onChanged(['notes']);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <div className="space-y-3 p-4">
      {canAdd && (
        <div>
          <label htmlFor="note-body" className="form-label">Internal note <span className="font-normal text-slate-400">(never sent to the contact)</span></label>
          <Textarea id="note-body" rows={3} value={body} maxLength={2000} invalid={!!error} onChange={(e) => { setBody(e.target.value); setError(null); }} placeholder="e.g. Parents prefer a call after 6 PM" />
          {error && <p className="form-error" role="alert">{error}</p>}
          <div className="mt-2 flex justify-end"><Button size="sm" icon={StickyNote} loading={saving} onClick={add}>Add note</Button></div>
        </div>
      )}
      {notes.error ? (
        <Alert variant="error" title="Unable to load notes" action={<Button size="xs" variant="secondary" onClick={() => notes.refetch()}>Retry</Button>}>{(notes.error as ApiError).message}</Alert>
      ) : notes.isLoading ? (
        <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-16 w-full rounded-lg" />)}</div>
      ) : !notes.data?.length ? (
        <EmptyState icon={StickyNote} title="No notes yet" description="Notes help the team pick up where a colleague left off." className="!py-6" />
      ) : (
        <ul className="space-y-2">
          {notes.data.map((n) => (
            <li key={n.id} className="rounded-lg border border-amber-200/70 bg-amber-50/70 p-2.5 dark:border-amber-500/20 dark:bg-amber-500/5">
              <div className="flex items-center gap-2">
                <Avatar name={n.user_name} src={n.user_avatar} size="xs" />
                <span className="min-w-0 flex-1 truncate text-xs font-semibold text-slate-700 dark:text-slate-200">{n.user_name}</span>
                <time className="text-[11px] text-slate-400" title={formatDateTime(n.created_at)}>{timeAgo(n.created_at)}</time>
                {n.can_edit && editing?.id !== n.id && (
                  <button type="button" onClick={() => setEditing({ id: n.id, body: n.body, error: null })} className="rounded p-0.5 text-slate-400 hover:text-brand-700 dark:hover:text-brand-300" aria-label="Edit note"><Pencil className="h-3.5 w-3.5" /></button>
                )}
                {n.can_delete && (
                  <button type="button" onClick={() => void remove(n)} className="rounded p-0.5 text-slate-400 hover:text-red-600" aria-label="Delete note"><Trash2 className="h-3.5 w-3.5" /></button>
                )}
              </div>
              {editing?.id === n.id ? (
                <div className="mt-1.5">
                  <label htmlFor={`note-edit-${n.id}`} className="sr-only">Edit note</label>
                  <Textarea id={`note-edit-${n.id}`} rows={3} value={editing.body} maxLength={2000} invalid={!!editing.error} onChange={(e) => setEditing({ ...editing, body: e.target.value, error: null })} />
                  {editing.error && <p className="form-error" role="alert">{editing.error}</p>}
                  <div className="mt-1.5 flex justify-end gap-1.5">
                    <Button size="xs" variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
                    <Button size="xs" loading={saving} onClick={() => void saveEdit()}>Save</Button>
                  </div>
                </div>
              ) : (
                <p className="mt-1.5 whitespace-pre-wrap break-words text-sm text-slate-700 dark:text-slate-200">{n.body}</p>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const ACTION_TONE: Record<string, string> = {
  reply: 'bg-emerald-500', template: 'bg-accent-500', assign: 'bg-blue-500', unassign: 'bg-slate-400', resolve: 'bg-emerald-600', reopen: 'bg-amber-500',
  status: 'bg-amber-500', note: 'bg-amber-400', tag: 'bg-cyan-500', untag: 'bg-slate-400', handover: 'bg-violet-500', event: 'bg-brand-700', start: 'bg-slate-500', retry: 'bg-red-400',
};

function ActivityTab({ convId }: { convId: number }) {
  const q = useActivity(convId);
  if (q.error) return <div className="p-4"><Alert variant="error" title="Unable to load activity" action={<Button size="xs" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>{(q.error as ApiError).message}</Alert></div>;
  if (q.isLoading) return <div className="space-y-3 p-4">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>;
  if (!q.data?.length) return <EmptyState icon={Activity} title="No activity yet" description="Assignments, replies, notes and events show up here." className="!py-8" />;
  return (
    <ol className="relative space-y-3 p-4 before:absolute before:bottom-4 before:left-[1.4rem] before:top-5 before:w-px before:bg-slate-200 dark:before:bg-slate-700">
      {q.data.map((a) => (
        <li key={a.key} className="relative flex gap-3">
          <span className={clsx('relative z-[1] mt-1 h-2.5 w-2.5 shrink-0 rounded-full ring-4 ring-white dark:ring-slate-900', ACTION_TONE[a.action] ?? 'bg-slate-400')} aria-hidden />
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-slate-800 dark:text-slate-100">{a.title}{a.user_name && <span className="font-normal text-slate-500 dark:text-slate-400"> · {a.user_name}</span>}</p>
            {a.detail && <p className="break-words text-xs text-slate-500 dark:text-slate-400">{a.detail}</p>}
            <time className="text-[11px] text-slate-400" title={formatDateTime(a.at)}>{timeAgo(a.at)}</time>
          </div>
        </li>
      ))}
    </ol>
  );
}
