import clsx from 'clsx';
import { Activity, CalendarClock, CheckCheck, Copy, Eye, FlaskConical, GitBranchPlus, Megaphone, MousePointerClick, PencilLine, Send, SendHorizontal, Variable, Workflow, XCircle, Zap } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Badge, Button, Drawer, Dropdown, EmptyState, Skeleton, Timeline, type TimelineItem } from '@/components/ui';
import { LineChart } from '@/components/charts';
import type { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { tplMenuItems, type TplActions } from './actions';
import { TemplatePreview } from './TemplatePreview';
import { recategorisedText } from './RecategorisedNote';
import { CATEGORY_TONE, STATUS_COLORS, STATUS_LABELS, previewFromNamed, rate, useTplDetail } from './lib';
import type { TplMeta } from './types';

/** Template detail: preview, analytics (all time + 30-day trend), usage, review timeline, variable mapping, actions. */
export function TemplateDetailDrawer({ id, onClose, meta, actions }: { id: number | null; onClose: () => void; meta?: TplMeta; actions: TplActions }) {
  const { can } = useAuth();
  const { data, isLoading, error, refetch } = useTplDetail(id);
  const [showVars, setShowVars] = useState(false);
  const t = data?.template;

  const timeline: TimelineItem[] = [];
  if (t) {
    timeline.push({ title: 'Created', description: t.created_by_name ? `by ${t.created_by_name}` : undefined, time: formatDateTime(t.created_at), tone: 'slate' });
    if (t.submitted_at) timeline.push({ title: 'Submitted to Interakt', time: formatDateTime(t.submitted_at), tone: 'amber' });
    if (t.approved_at) timeline.push({ title: 'Approved by Meta', time: formatDateTime(t.approved_at), tone: 'green' });
    if (t.status === 'rejected') timeline.push({ title: 'Rejected by Meta', description: t.rejection_reason ?? undefined, time: t.synced_at ? formatDateTime(t.synced_at) : '', tone: 'red' });
    if (t.status === 'paused') timeline.push({ title: 'Paused by Meta (quality)', time: t.synced_at ? formatDateTime(t.synced_at) : '', tone: 'purple' });
    if (t.recategorised) {
      timeline.push({
        title: 'Re-categorised by Meta',
        description: `${CATEGORY_TONE[t.recategorised.from]?.label ?? t.recategorised.from} → ${CATEGORY_TONE[t.recategorised.to]?.label ?? t.recategorised.to}`,
        time: formatDateTime(t.recategorised.at),
        tone: t.recategorised.to === 'MARKETING' ? 'amber' : 'blue',
      });
    }
    if (t.archived_at) timeline.push({ title: 'Archived in GIMT', time: formatDateTime(t.archived_at), tone: 'slate' });
    if (t.synced_at) timeline.push({ title: 'Last status sync', time: formatDateTime(t.synced_at), tone: 'blue' });
  }

  const st = data?.stats;
  const usage = data?.usage;
  const usedCount = usage ? usage.events.length + usage.workflows.length + usage.followups.length + usage.campaigns.length : 0;
  const menu = t ? tplMenuItems(t, actions, can, { canSimulate: !!meta?.can_simulate }) : [];

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width="max-w-4xl"
      title={t ? t.display_name || t.name : 'Template'}
      description={t ? <code className="text-xs">{t.name}</code> : undefined}
      footer={
        t ? (
          <div className="flex w-full flex-wrap items-center gap-2">
            {t.status === 'draft' && can('whatsapp_templates', 'edit') && (
              <Button icon={PencilLine} to={`/whatsapp/templates/designer/${t.id}`}>
                Edit in designer
              </Button>
            )}
            {t.status === 'draft' && can('whatsapp_templates', 'approve') && (
              <Button variant="secondary" icon={Send} onClick={() => void actions.submit(t)} loading={actions.busy === `submit-${t.id}`}>
                Submit for approval
              </Button>
            )}
            {t.status !== 'draft' && can('whatsapp_templates', 'edit') && (
              <Button variant="secondary" icon={Variable} to={`/whatsapp/templates/designer/${t.id}`}>
                Map variables
              </Button>
            )}
            {t.status === 'approved' && can('whatsapp', 'create') && (
              <Button variant="secondary" icon={SendHorizontal} onClick={() => actions.openTest(t)}>
                Send test
              </Button>
            )}
            {meta?.can_simulate && ['pending', 'approved', 'rejected', 'paused'].includes(t.status) && (
              <Button variant="soft" icon={FlaskConical} onClick={() => actions.openSimulate(t)}>
                Simulate review
              </Button>
            )}
            <span className="ml-auto" />
            {menu.length > 0 && <Dropdown label="More template actions" align="right" triggerClassName="btn btn-ghost" trigger="More" items={menu} />}
          </div>
        ) : undefined
      }
    >
      {isLoading ? (
        <div className="space-y-4">
          <Skeleton className="h-6 w-1/2" />
          <div className="grid gap-4 md:grid-cols-2">
            <Skeleton className="h-[440px] w-full rounded-[2.6rem]" />
            <Skeleton className="h-[440px] w-full" />
          </div>
        </div>
      ) : error ? (
        <Alert variant="error" title="Unable to load the template" action={<Button size="sm" variant="secondary" onClick={() => void refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : t && data ? (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-1.5">
            <Badge color={STATUS_COLORS[t.status]} dot>
              {STATUS_LABELS[t.status]}
            </Badge>
            <Badge color={CATEGORY_TONE[t.category].badge}>{CATEGORY_TONE[t.category].label}</Badge>
            <Badge color="slate">{meta?.languages[t.language]?.label ?? t.language}</Badge>
            {t.is_system ? <Badge color="navy">Built-in</Badge> : null}
            {t.interakt_id && <span className="text-[11px] text-slate-500 dark:text-slate-400">Interakt {t.interakt_id}</span>}
            {t.wa_template_id && <span className="text-[11px] text-slate-500 dark:text-slate-400">· Meta {t.wa_template_id}</span>}
          </div>
          {t.description && <p className="-mt-3 text-sm text-slate-600 dark:text-slate-300">{t.description}</p>}

          {t.status === 'rejected' && (
            <Alert
              variant="error"
              title="Rejected by Meta"
              action={
                can('whatsapp_templates', 'create') ? (
                  <Button size="sm" variant="secondary" icon={GitBranchPlus} onClick={() => void actions.duplicate(t, true)}>
                    Fix in a new version
                  </Button>
                ) : undefined
              }
            >
              {t.rejection_reason || 'No reason was given.'}
            </Alert>
          )}
          {t.recategorised && (
            <Alert
              variant={t.recategorised.to === 'MARKETING' ? 'warning' : 'info'}
              title={`Re-categorised by Meta: ${CATEGORY_TONE[t.recategorised.from]?.label ?? t.recategorised.from} → ${CATEGORY_TONE[t.recategorised.to]?.label ?? t.recategorised.to}`}
            >
              {recategorisedText(t.recategorised, t.name)}
              {t.recategorised.to === 'MARKETING' && can('whatsapp_templates', 'create') && (
                <span className="mt-2 block">
                  <Button size="sm" variant="secondary" icon={GitBranchPlus} loading={actions.busy === `dup-${t.id}`} onClick={() => void actions.duplicate(t, true)}>
                    Create a new version
                  </Button>
                </span>
              )}
            </Alert>
          )}
          {t.status === 'paused' && <Alert variant="warning" title="Paused by Meta">Meta paused this template after low-quality feedback (blocks or reports). Sends fail until Meta re-activates it; consider improving the wording in a new version.</Alert>}
          {t.status === 'draft' && data.issues.some((i) => i.level !== 'warning') && (
            <Alert variant="warning" title="Not ready to submit">
              <ul className="list-disc space-y-0.5 pl-4">
                {data.issues
                  .filter((i) => i.level !== 'warning')
                  .slice(0, 5)
                  .map((i, n) => (
                    <li key={n}>{i.message}</li>
                  ))}
              </ul>
            </Alert>
          )}

          <div className="grid gap-6 md:grid-cols-[300px_minmax(0,1fr)]">
            <TemplatePreview data={previewFromNamed(t, data.named, showVars)} showVars={showVars} onToggleVars={setShowVars} businessName={meta?.business_name} height={440} />
            <div className="min-w-0 space-y-5">
              <section>
                <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Performance (all time)</h3>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                  <Metric icon={Send} label="Sent" value={formatNumber(st?.sent ?? 0)} sub={st?.queued ? `${st.queued} queued` : undefined} />
                  <Metric icon={CheckCheck} label="Delivered" value={formatNumber(st?.delivered ?? 0)} sub={rate(st?.delivered ?? 0, st?.sent ?? 0)} />
                  <Metric icon={Eye} label="Read" value={formatNumber(st?.read ?? 0)} sub={rate(st?.read ?? 0, st?.sent ?? 0)} tone="blue" />
                  <Metric icon={XCircle} label="Failed" value={formatNumber(st?.failed ?? 0)} sub={rate(st?.failed ?? 0, st?.total ?? 0)} tone={st?.failed ? 'red' : undefined} />
                  <Metric
                    icon={MousePointerClick}
                    label="Button clicks"
                    value={formatNumber(st?.clicks ?? 0)}
                    sub={t.buttons.length ? `${rate(st?.clicked ?? 0, st?.delivered ?? 0)} of delivered clicked` : 'No buttons'}
                  />
                  <Metric icon={Activity} label="Replied in 24 h" value={formatNumber(st?.replies ?? 0)} sub={`${rate(st?.replies ?? 0, st?.delivered ?? 0)} of delivered`} />
                </div>
                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                  {st?.last_used_at ? `Last sent ${timeAgo(st.last_used_at)}` : 'Not sent yet'}
                  {st?.cost ? ` · Interakt cost so far ₹${Number(st.cost).toFixed(2)}` : ''}
                </p>
              </section>
              <section>
                <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Last 30 days</h3>
                {data.stats_30d.total ? (
                  <LineChart
                    height={190}
                    labels={data.trend.labels.map((d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }))}
                    beginAtZero
                    stepSize={Math.max(...data.trend.sent, 0) <= 8 ? 1 : undefined}
                    series={[
                      { label: 'Sent', data: data.trend.sent, color: '#1D4ED8' },
                      { label: 'Read', data: data.trend.read, color: '#F05A28' },
                      { label: 'Failed', data: data.trend.failed, color: '#DC2626', dashed: true },
                    ]}
                    ariaLabel="Messages sent with this template per day, last 30 days"
                  />
                ) : (
                  <p className="rounded-xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">No messages in the last 30 days.</p>
                )}
              </section>
            </div>
          </div>

          <section>
            <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Where it is used {usedCount ? `(${usedCount})` : ''}</h3>
            {usage && usedCount ? (
              <div className="grid gap-2 sm:grid-cols-2">
                {usage.events.map((e) => (
                  <UsageLink key={`e${e.id}`} to={`/whatsapp/events?event=${e.id}`} icon={Zap} title={e.name} sub={`Event ${e.code}`} active={!!Number(e.is_active)} />
                ))}
                {usage.workflows.map((w) => (
                  <UsageLink key={`w${w.id}`} to={`/whatsapp/automations/${w.id}`} icon={Workflow} title={w.name} sub="Workflow" active={w.status === 'active'} />
                ))}
                {usage.followups.map((f) => (
                  <UsageLink key={`f${f.id}`} to="/whatsapp/followups" icon={CalendarClock} title={f.name} sub={`Follow-up stage ${f.stage_key}`} active={!!Number(f.is_active)} />
                ))}
                {usage.campaigns.map((c) => (
                  <UsageLink key={`c${c.id}`} to={`/whatsapp/campaigns/${c.id}`} icon={Megaphone} title={c.name} sub={`Campaign · ${c.status}`} active={['scheduled', 'sending'].includes(c.status)} />
                ))}
              </div>
            ) : (
              <p className="text-sm text-slate-500 dark:text-slate-400">Not linked to any event, workflow, follow-up stage or campaign yet.</p>
            )}
          </section>

          <div className="grid gap-6 md:grid-cols-2">
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Variable mapping</h3>
              {[...t.variables.header, ...t.variables.body].length || Object.keys(t.variables.buttons).length ? (
                <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                      <tr>
                        <th className="px-3 py-2 font-semibold">Meta</th>
                        <th className="px-3 py-2 font-semibold">GIMT variable</th>
                        <th className="px-3 py-2 font-semibold">Sample</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {[
                        ...t.variables.header.map((v) => ({ k: `h${v.pos}`, pos: `Header {{${v.pos}}}`, key: v.key, sample: v.sample })),
                        ...t.variables.body.map((v) => ({ k: `b${v.pos}`, pos: `{{${v.pos}}}`, key: v.key, sample: v.sample })),
                        ...Object.entries(t.variables.buttons).map(([i, v]) => ({ k: `x${i}`, pos: `Button ${Number(i) + 1}`, key: v.key, sample: v.sample })),
                      ].map((r) => (
                        <tr key={r.k} className="text-slate-700 dark:text-slate-200">
                          <td className="whitespace-nowrap px-3 py-1.5 font-mono">{r.pos}</td>
                          <td className="px-3 py-1.5 font-mono text-accent-700 dark:text-accent-300">{r.key}</td>
                          <td className="max-w-[10rem] truncate px-3 py-1.5" title={r.sample}>
                            {r.sample || '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-slate-500 dark:text-slate-400">No variables.</p>
              )}
              {data.versions.length > 0 && (
                <div className="mt-4">
                  <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Related versions</h3>
                  <ul className="space-y-1">
                    {data.versions.map((v) => (
                      <li key={v.id} className="flex items-center gap-2 text-sm">
                        <Copy size={13} className="text-slate-400" aria-hidden />
                        <Link to={`/whatsapp/templates?view=${v.id}`} className="font-mono text-brand-700 hover:underline dark:text-brand-300">
                          {v.name}
                        </Link>
                        <Badge color={STATUS_COLORS[v.status]}>{STATUS_LABELS[v.status]}</Badge>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Review history</h3>
              <Timeline items={timeline} />
            </section>
          </div>
        </div>
      ) : (
        <EmptyState icon={XCircle} title="Template not found" />
      )}
    </Drawer>
  );
}

function Metric({ icon: Icon, label, value, sub, tone }: { icon: typeof Send; label: string; value: string; sub?: string; tone?: 'blue' | 'red' }) {
  return (
    <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
      <p className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
        <Icon size={12} aria-hidden /> {label}
      </p>
      <p className={clsx('mt-1 text-lg font-bold tabular-nums', tone === 'red' ? 'text-red-600 dark:text-red-400' : tone === 'blue' ? 'text-brand-700 dark:text-brand-300' : 'text-slate-900 dark:text-white')}>{value}</p>
      {sub && <p className="text-[11px] text-slate-500 dark:text-slate-400">{sub}</p>}
    </div>
  );
}

function UsageLink({ to, icon: Icon, title, sub, active }: { to: string; icon: typeof Send; title: string; sub: string; active: boolean }) {
  return (
    <Link
      to={to}
      className="flex items-center gap-2.5 rounded-xl border border-slate-200 p-2.5 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-700 dark:hover:border-brand-500/50"
    >
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
        <Icon size={15} aria-hidden />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium text-slate-900 dark:text-white">{title}</span>
        <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">{sub}</span>
      </span>
      <Badge color={active ? 'green' : 'slate'}>{active ? 'Active' : 'Inactive'}</Badge>
    </Link>
  );
}

export default TemplateDetailDrawer;
