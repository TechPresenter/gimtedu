import { useMemo, useState } from 'react';
import { BadgeCheck, BookOpenCheck, ExternalLink, FileQuestion, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { Alert, Badge, Button, Card, CardBody, CardHeader, CardSkeleton, EmptyState, IconButton, SearchInput, Select, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber, timeAgo } from '@/lib/format';
import { WaText, useWaRefresh } from '../shared';
import { useBotFaqs, useBotKnowledge } from './api';
import { KnowledgeModal } from './KnowledgeModal';
import type { BotKnowledge } from './types';

const STATUS_COLOR = { active: 'green', draft: 'amber', archived: 'slate' } as const;

/**
 * Knowledge the bot answers from: WhatsApp-only verified answers (EN + HI, live placeholders) and the website FAQs
 * (CMS) with a per-FAQ WhatsApp switch. Programs, fees and eligibility come from Course information.
 */
export function KnowledgeTab() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('');
  const query = useMemo(() => ({ q: search || undefined, category: category || undefined, status: status || undefined }), [search, category, status]);
  const list = useBotKnowledge(query);
  const [editing, setEditing] = useState<BotKnowledge | null>(null);
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);
  const cats = list.data?.categories ?? {};

  const verify = async (k: BotKnowledge) => {
    setBusy(k.id);
    try {
      const r = await api.post(`whatsapp/bot/knowledge/${k.id}/verify`);
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const remove = async (k: BotKnowledge) => {
    const ok = await confirm({ title: 'Delete this answer?', message: `"${k.question}" will no longer be used by the bot. This cannot be undone.`, confirmText: 'Delete', danger: true });
    if (!ok) return;
    setBusy(k.id);
    try {
      const r = await api.del(`whatsapp/bot/knowledge/${k.id}`);
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="grid gap-5 xl:grid-cols-3">
      <Card className="xl:col-span-2">
        <CardHeader
          title="Verified WhatsApp answers"
          subtitle={
            list.data
              ? `${formatNumber(list.data.counts.active ?? 0)} active · ${formatNumber(list.data.counts.draft ?? 0)} draft — admission process, documents, payment methods, exams, results, certificates…`
              : 'Admission process, documents, payment methods, exams, results, certificates…'
          }
          icon={BookOpenCheck}
          actions={can('whatsapp_automation', 'create') ? <Button size="sm" icon={Plus} onClick={() => setCreating(true)}>Add answer</Button> : undefined}
        />
        <CardBody className="space-y-4">
          <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
            <SearchInput value={search} onChange={setSearch} placeholder="Search questions, keywords, answers…" />
            <Select aria-label="Category" value={category} onChange={(e) => setCategory(e.target.value)} options={cats} placeholder="All categories" className="sm:w-44" />
            <Select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} options={{ active: 'Active', draft: 'Draft', archived: 'Archived' }} placeholder="Any status" className="sm:w-36" />
          </div>
          {list.error ? (
            <Alert variant="error" action={<Button size="sm" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>
              {(list.error as ApiError).message}
            </Alert>
          ) : list.isLoading ? (
            <CardSkeleton lines={8} />
          ) : !list.data?.rows.length ? (
            <EmptyState
              icon={BookOpenCheck}
              title={search || category || status ? 'No answers match' : 'No verified answers yet'}
              description="Add the institute's confirmed answers about admissions, documents, payments, exams and certificates."
              action={can('whatsapp_automation', 'create') ? <Button icon={Plus} onClick={() => setCreating(true)}>Add answer</Button> : undefined}
            />
          ) : (
            <ul className="space-y-3">
              {list.data.rows.map((k) => (
                <li key={k.id} className="rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 flex flex-wrap items-center gap-1.5">
                        <Badge color={STATUS_COLOR[k.status]} dot>
                          {k.status === 'active' ? 'Active' : k.status === 'draft' ? 'Draft — not used' : 'Archived'}
                        </Badge>
                        <Badge color="navy">{cats[k.category] ?? k.category}</Badge>
                        {k.answer_hi && <Badge color="purple">EN + हिंदी</Badge>}
                      </div>
                      <p className="font-semibold text-slate-900 dark:text-white">{k.question}</p>
                      {k.keywords && <p className="mt-0.5 line-clamp-1 text-xs text-slate-500 dark:text-slate-400">Also matches: {k.keywords}</p>}
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                      {can('whatsapp_automation', 'edit') && k.status !== 'archived' && (
                        <IconButton icon={ShieldCheck} label="Mark verified by me" size="sm" disabled={busy === k.id} onClick={() => verify(k)} />
                      )}
                      {can('whatsapp_automation', 'edit') && <IconButton icon={Pencil} label="Edit answer" size="sm" onClick={() => setEditing(k)} />}
                      {can('whatsapp_automation', 'delete') && <IconButton icon={Trash2} label="Delete answer" size="sm" tone="danger" disabled={busy === k.id} onClick={() => remove(k)} />}
                    </div>
                  </div>
                  <div className="mt-2 line-clamp-4 rounded-lg bg-slate-50 px-3 py-2 text-[13px] leading-relaxed text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
                    <WaText text={k.answer_en} />
                  </div>
                  <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                    {k.verified_at ? (
                      <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400">
                        <BadgeCheck className="h-3.5 w-3.5" aria-hidden /> Verified {k.verified_by_name ? `by ${k.verified_by_name}` : '(from website content)'} · {formatDate(k.verified_at)}
                      </span>
                    ) : (
                      <span className="text-amber-700 dark:text-amber-400">Not verified yet</span>
                    )}
                    <span>Used {formatNumber(k.hits)}×{k.last_used_at ? ` · last ${timeAgo(k.last_used_at)}` : ''}</span>
                  </p>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
      <WebsiteFaqs />
      <KnowledgeModal
        open={creating || !!editing}
        onClose={() => {
          setCreating(false);
          setEditing(null);
        }}
        onSaved={() => void refresh('bot')}
        entry={editing}
        categories={cats}
        placeholders={list.data?.placeholders ?? {}}
      />
    </div>
  );
}

/** CMS FAQs (shared with the website) with a per-FAQ "use on WhatsApp" switch. */
function WebsiteFaqs() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const q = useBotFaqs();
  const [busy, setBusy] = useState<number | null>(null);
  const [showAll, setShowAll] = useState(false);
  const rows = q.data?.rows ?? [];
  const visible = showAll ? rows : rows.slice(0, 12);

  const toggle = async (id: number, enabled: boolean) => {
    setBusy(id);
    try {
      const r = await api.post(`whatsapp/bot/faqs/${id}/toggle`, { enabled });
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="self-start">
      <CardHeader
        title="Website FAQs"
        subtitle={q.data ? `${rows.filter((r) => r.whatsapp).length} of ${rows.length} used on WhatsApp` : 'Shared with the website'}
        icon={FileQuestion}
        actions={
          q.data?.can_edit_cms ? (
            <Button size="xs" variant="ghost" iconRight={ExternalLink} to="/cms/faqs">
              Edit FAQs
            </Button>
          ) : undefined
        }
      />
      <CardBody className="p-0">
        {q.error ? (
          <div className="p-5">
            <Alert variant="error">{(q.error as ApiError).message}</Alert>
          </div>
        ) : q.isLoading ? (
          <div className="p-5">
            <CardSkeleton lines={6} />
          </div>
        ) : !rows.length ? (
          <EmptyState icon={FileQuestion} title="No website FAQs" description="FAQs added in CMS › FAQs can also answer WhatsApp questions." />
        ) : (
          <>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {visible.map((f) => (
                <li key={f.id} className="flex items-start justify-between gap-3 px-5 py-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-800 dark:text-slate-100">{f.question}</p>
                    <p className="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{f.answer}</p>
                    {f.status !== 'active' && <Badge color="slate" className="mt-1">Inactive on the website</Badge>}
                  </div>
                  <Toggle checked={!f.excluded} disabled={!can('whatsapp_automation', 'edit') || busy === f.id} onChange={(v) => toggle(f.id, v)} label={<span className="sr-only">Use on WhatsApp</span>} />
                </li>
              ))}
            </ul>
            {rows.length > 12 && (
              <div className="border-t border-slate-100 px-5 py-2.5 dark:border-slate-800">
                <Button size="xs" variant="ghost" onClick={() => setShowAll((v) => !v)}>
                  {showAll ? 'Show fewer' : `Show all ${rows.length}`}
                </Button>
              </div>
            )}
          </>
        )}
      </CardBody>
    </Card>
  );
}
