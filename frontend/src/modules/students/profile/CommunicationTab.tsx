import { useState } from 'react';
import clsx from 'clsx';
import { Mail, MessageCircle, MessageSquare, Smartphone } from 'lucide-react';
import { Card, CardHeader, EmptyState, Pagination, StatusBadge, Tabs } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { PagedRows } from '../types';
import { TabError, TabLoading, type TabProps } from './shared';

interface MsgRow { id: number; channel: 'email' | 'sms' | 'whatsapp'; recipient: string; subject: string | null; excerpt: string; status: string; error: string | null; related_type: string | null; sent_at: string | null; created_at: string }

const channelStyle = {
  email: { icon: Mail, cls: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', label: 'Email' },
  sms: { icon: Smartphone, cls: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', label: 'SMS' },
  whatsapp: { icon: MessageCircle, cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', label: 'WhatsApp' },
};

export default function CommunicationTab({ studentId }: TabProps) {
  const [channel, setChannel] = useState('all');
  const [page, setPage] = useState(1);
  const q = useApi<PagedRows<MsgRow>>(['students', studentId, 'communication', channel, page], `students/${studentId}/communication`, { channel: channel === 'all' ? undefined : channel, page, per_page: 15 });
  if (q.isLoading) return <TabLoading cards={1} />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data!;
  return (
    <Card className="overflow-hidden">
      <CardHeader title="Messages sent" subtitle="Emails, SMS and WhatsApp messages to the student and their parents" icon={MessageSquare}
        actions={<Tabs variant="pills" value={channel} onChange={(c) => { setChannel(c); setPage(1); }} tabs={[{ key: 'all', label: 'All' }, { key: 'email', label: 'Email' }, { key: 'sms', label: 'SMS' }, { key: 'whatsapp', label: 'WhatsApp' }]} />} />
      {d.rows.length === 0 ? (
        <EmptyState icon={MessageSquare} title="No messages yet" description="Fee reminders, attendance alerts, notices and admission emails sent to this student or their parents will appear here." />
      ) : (
        <>
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {d.rows.map((m) => {
              const st = channelStyle[m.channel] ?? channelStyle.email;
              return (
                <li key={m.id} className="flex gap-3 px-5 py-3.5 transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                  <span className={clsx('mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', st.cls)} title={st.label}>
                    <st.icon className="h-4 w-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                      <p className="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-white">{m.subject || `${st.label} message`}</p>
                      <span className="shrink-0 text-xs text-slate-500" title={formatDateTime(m.sent_at ?? m.created_at)}>{timeAgo(m.sent_at ?? m.created_at)}</span>
                    </div>
                    <p className="mt-0.5 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{m.excerpt}</p>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <span>To {m.recipient}</span>
                      <StatusBadge status={m.status} />
                      {m.error && <span className="text-red-600 dark:text-red-400" title={m.error}>{m.error.slice(0, 60)}</span>}
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
          {d.pages > 1 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={d.page} pages={d.pages} total={d.total} perPage={d.per_page} onPage={setPage} />}
        </>
      )}
    </Card>
  );
}
