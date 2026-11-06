import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { CheckCircle2, GitMerge, PhoneCall, RefreshCw, UserRoundSearch } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, CardSkeleton, EmptyState, useConfirm, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { timeAgo } from '@/lib/format';
import { crmKeys, showPhone, TYPE_LABELS, useCrmRefresh, useFacets, type WaContact } from './crm';
import { StageBadge, TypeBadge } from './crmUi';

interface DupContact extends WaContact {
  messages: number;
}
interface Duplicates {
  same_person: { reason: string; contacts: DupContact[] }[];
  same_number: { contact_id: number; phone: string; name: string | null; contact_type: string; names: { name: string; source: string }[] }[];
  checked_at: string;
}

/** Duplicate report: same person on different numbers (merge) and same number with different names (pick the name). */
export function DuplicatesPanel() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCrmRefresh();
  const { data: facets } = useFacets();
  const { data, isLoading, isFetching, error, refetch } = useQuery({ queryKey: crmKeys.duplicates, queryFn: ({ signal }) => api.get<Duplicates>('whatsapp/contacts/duplicates', undefined, { signal }), staleTime: 60_000 });
  const [keep, setKeep] = useState<Record<number, number>>({});
  const [busy, setBusy] = useState<string | null>(null);

  const merge = async (gi: number, contacts: DupContact[]) => {
    const keepId = keep[gi] ?? pickDefault(contacts);
    const others = contacts.filter((c) => c.id !== keepId);
    const kept = contacts.find((c) => c.id === keepId)!;
    if (!(await confirm({ title: 'Merge these contacts?', message: `${others.map((o) => o.name || showPhone(o.phone)).join(', ')} will be merged into ${kept.name || showPhone(kept.phone)} (${showPhone(kept.phone)}). Messages, notes, tags and follow-ups move over; the other numbers are kept as alternate numbers. This cannot be undone.`, confirmText: 'Merge', danger: true }))) return;
    setBusy(`g${gi}`);
    try {
      const r = await api.post('whatsapp/contacts/merge', { keep_id: keepId, merge_ids: others.map((o) => o.id) });
      toast.success(r.message);
      refresh();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(null);
    }
  };
  const rename = async (contactId: number, name: string) => {
    setBusy(`n${contactId}${name}`);
    try {
      const r = await api.post(`whatsapp/contacts/${contactId}/rename`, { name });
      toast.success(r.message);
      refresh();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(null);
    }
  };

  if (error) {
    return (
      <Alert variant="error" title="The duplicate check failed" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
        {(error as Error).message}
      </Alert>
    );
  }
  if (isLoading) {
    return (
      <div className="space-y-4">
        <CardSkeleton lines={4} />
        <CardSkeleton lines={4} />
      </div>
    );
  }
  const groups = data?.same_person ?? [];
  const numbers = data?.same_number ?? [];
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-500 dark:text-slate-400">Checked {data ? timeAgo(data.checked_at) : ''} across contacts and the enquiry, application and student records behind each number.</p>
        <Button size="sm" variant="secondary" icon={RefreshCw} loading={isFetching} onClick={() => refetch()}>Check again</Button>
      </div>

      <Card>
        <CardHeader title="Same person, different numbers" subtitle="Same email, same application / enquiry, or same name and programme. Keep one contact and merge the rest." icon={UserRoundSearch} />
        <div className="card-body space-y-4">
          {!groups.length ? (
            <EmptyState icon={CheckCircle2} title="No duplicate people found" description="Every person has a single WhatsApp contact." className="!py-8" />
          ) : (
            groups.map((g, gi) => {
              const keepId = keep[gi] ?? pickDefault(g.contacts);
              return (
                <div key={gi} className="rounded-2xl border border-slate-200 p-3 dark:border-slate-700">
                  <div className="mb-2 flex flex-wrap items-center gap-2">
                    <Badge color="amber">{g.reason}</Badge>
                    <span className="text-xs text-slate-500">Choose the contact to keep</span>
                    {can('whatsapp_contacts', 'delete') && (
                      <Button size="xs" className="ml-auto" icon={GitMerge} loading={busy === `g${gi}`} onClick={() => merge(gi, g.contacts)}>Merge into selected</Button>
                    )}
                  </div>
                  <div className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                    {g.contacts.map((c) => (
                      <label key={c.id} className={clsx('flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition', keepId === c.id ? 'border-accent-500 bg-accent-50/60 dark:border-accent-500/60 dark:bg-accent-500/10' : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60')}>
                        <input type="radio" name={`keep-${gi}`} className="mt-1 h-4 w-4 text-accent-600" checked={keepId === c.id} onChange={() => setKeep((k) => ({ ...k, [gi]: c.id }))} />
                        <div className="min-w-0 text-sm">
                          <Link to={`/whatsapp/contacts/${c.id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{c.name || '—'}</Link>
                          <p className="text-xs text-slate-500">{showPhone(c.phone)}{c.email ? ` · ${c.email}` : ''}</p>
                          <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <TypeBadge type={c.contact_type} />
                            <StageBadge stage={c.stage} facets={facets} />
                            <span className="text-[11px] text-slate-500">{c.messages} messages</span>
                          </div>
                        </div>
                      </label>
                    ))}
                  </div>
                </div>
              );
            })
          )}
        </div>
      </Card>

      <Card>
        <CardHeader title="Same number, different names" subtitle="One WhatsApp number used by several people in the portal (a student using a parent’s phone, siblings, typos). Pick the name to show." icon={PhoneCall} />
        <div className="card-body">
          {!numbers.length ? (
            <EmptyState icon={CheckCircle2} title="No shared numbers found" className="!py-8" />
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {numbers.map((n) => (
                <li key={n.contact_id} className="flex flex-col gap-2 py-3 md:flex-row md:items-center">
                  <div className="min-w-0 md:w-64">
                    <Link to={`/whatsapp/contacts/${n.contact_id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{n.name || '—'}</Link>
                    <p className="text-xs text-slate-500">{showPhone(n.phone)} · {TYPE_LABELS[n.contact_type] ?? n.contact_type}</p>
                  </div>
                  <div className="flex flex-1 flex-wrap gap-2">
                    {n.names.map((x) => {
                      const current = (n.name ?? '').trim().toLowerCase() === x.name.trim().toLowerCase();
                      return (
                        <span key={x.source} className={clsx('inline-flex items-center gap-2 rounded-xl border px-2.5 py-1.5 text-xs', current ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-500/10' : 'border-slate-200 dark:border-slate-700')}>
                          <span>
                            <strong className="text-slate-800 dark:text-slate-100">{x.name}</strong>
                            <span className="block text-[11px] text-slate-500">{x.source}</span>
                          </span>
                          {current ? (
                            <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-label="Current name" />
                          ) : can('whatsapp_contacts', 'edit') ? (
                            <Button size="xs" variant="ghost" loading={busy === `n${n.contact_id}${x.name}`} onClick={() => rename(n.contact_id, x.name)}>Use</Button>
                          ) : null}
                        </span>
                      );
                    })}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>
    </div>
  );
}

/** Default contact to keep: the one with most messages, then the richest type (student > applicant > lead > guardian). */
function pickDefault(cs: DupContact[]): number {
  const rank: Record<string, number> = { staff: 6, alumni: 5, student: 4, applicant: 3, lead: 2, guardian: 1, other: 0 };
  return [...cs].sort((a, b) => b.messages - a.messages || (rank[b.contact_type] ?? 0) - (rank[a.contact_type] ?? 0) || a.id - b.id)[0].id;
}
