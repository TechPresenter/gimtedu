import { useState } from 'react';
import { Link } from 'react-router-dom';
import { BriefcaseBusiness, ExternalLink, Mail, Pencil, Phone, Plus, Users } from 'lucide-react';
import { Alert, Badge, Button, DescriptionList, Drawer, EmptyState, PageLoader, PersonCell, StatusBadge } from '@/components/ui';
import { BarChart, chartColor } from '@/components/charts';
import { CrudFormModal } from '@/components/crud';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import type { CompanyHistory } from './types';
import { COMPANY_TYPES, CompanyLogo, DRIVE_STATUS, DRIVE_STATUS_COLORS, JOB_TYPES, OFFER_STATUS, OFFER_STATUS_COLORS, TIE_UP, TIE_UP_COLORS, ctcRange, lpa, usePlacementRefresh } from './shared';

/** Company profile + hiring history (drives, hires by session, recent offers). */
export function CompanyDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const refresh = usePlacementRefresh();
  const { data, isLoading, error } = useApi<CompanyHistory>(['placement', 'company', id], `placement/companies/${id}/history`, undefined, { enabled: !!id });
  const { data: meta } = useCrudMeta('companies');
  const [editing, setEditing] = useState(false);
  const c = data?.company;
  const s = data?.stats;

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-2xl"
        title={c ? String(c.name) : 'Company'}
        description={c ? [c.industry, COMPANY_TYPES[String(c.company_type)] ?? null, c.headquarters].filter(Boolean).join(' · ') : undefined}
        footer={
          c && (
            <div className="flex w-full flex-wrap justify-end gap-2">
              {c.website && (
                <Button variant="ghost" size="sm" icon={ExternalLink} href={String(c.website)} target="_blank">
                  Website
                </Button>
              )}
              {can('placement', 'edit') && (
                <Button variant="secondary" size="sm" icon={Pencil} onClick={() => setEditing(true)}>
                  Edit
                </Button>
              )}
              {can('placement', 'create') && (
                <Button size="sm" icon={Plus} to={`/placement/drives?add=1&company=${c.id}`}>
                  Schedule drive
                </Button>
              )}
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the company">{(error as Error).message}</Alert>
        ) : isLoading || !c || !s || !data ? (
          <PageLoader label="Loading company…" />
        ) : (
          <div className="space-y-6">
            <div className="flex items-center gap-4">
              <CompanyLogo name={String(c.name)} src={c.logo as string | null} size="lg" />
              <div className="min-w-0">
                <div className="flex flex-wrap gap-1.5">
                  <StatusBadge status={String(c.tie_up_status)} label={TIE_UP[String(c.tie_up_status)]} colors={TIE_UP_COLORS} />
                  {c.status !== 'active' && <StatusBadge status={String(c.status)} />}
                  {Number(c.is_recruiter) ? <Badge color="cyan">On website</Badge> : null}
                </div>
                <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                  {s.last_drive ? `Last drive ${formatDate(s.last_drive)}` : 'No drives yet'}
                  {s.last_offer ? ` · last hire ${formatDate(s.last_offer)}` : ''}
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[
                { label: 'Drives', value: s.drives },
                { label: 'Students hired', value: s.students },
                { label: 'Highest CTC', value: lpa(s.highest) },
                { label: 'Average CTC', value: lpa(s.average) },
              ].map((t) => (
                <div key={t.label} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{t.label}</p>
                  <p className="mt-0.5 font-display text-lg font-bold text-slate-900 dark:text-white">{t.value}</p>
                </div>
              ))}
            </div>

            {data.by_session.length > 0 && (
              <section>
                <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Hires by session</h3>
                <BarChart labels={data.by_session.map((b) => b.session)} series={[{ label: 'Hires', data: data.by_session.map((b) => b.offers), color: chartColor(1) }]} height={150} />
              </section>
            )}

            <section>
              <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">HR / campus contact</h3>
              <DescriptionList
                items={[
                  { label: 'Contact person', value: c.contact_person ? `${c.contact_person}${c.contact_designation ? ` — ${c.contact_designation}` : ''}` : null },
                  { label: 'Email', value: c.email ? <a className="link inline-flex items-center gap-1 !font-normal" href={`mailto:${c.email}`}><Mail className="h-3.5 w-3.5" />{String(c.email)}</a> : null },
                  { label: 'Phone', value: c.phone ? <a className="inline-flex items-center gap-1 hover:text-brand-700" href={`tel:${String(c.phone).replace(/\s/g, '')}`}><Phone className="h-3.5 w-3.5" />{String(c.phone)}</a> : null },
                  { label: 'Office', value: [c.address, c.city].filter(Boolean).join(', ') || null },
                  ...(c.description ? [{ label: 'About', value: String(c.description), full: true }] : []),
                  ...(c.notes ? [{ label: 'Internal notes', value: <span className="whitespace-pre-line">{String(c.notes)}</span>, full: true }] : []),
                ]}
              />
            </section>

            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Drives ({data.drives.length})</h3>
              {!data.drives.length ? (
                <EmptyState icon={BriefcaseBusiness} title="No drives yet" description="Schedule the first campus drive for this company." className="!py-8" />
              ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                  {data.drives.map((d) => (
                    <li key={d.id}>
                      <Link to={`/placement/drives/${d.id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{d.title}</p>
                          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                            {formatDate(d.drive_date)} · {JOB_TYPES[d.job_type] ?? d.job_type} · {ctcRange(d.package_min, d.package_max)}
                          </p>
                        </div>
                        <span className="text-xs tabular-nums text-slate-500 dark:text-slate-400">{d.applicants} applied · {d.selected} selected · {d.offers} offers</span>
                        <StatusBadge status={d.status} label={DRIVE_STATUS[d.status]} colors={DRIVE_STATUS_COLORS} />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Recent hires</h3>
              {!data.offers.length ? (
                <EmptyState icon={Users} title="No students hired yet" className="!py-8" />
              ) : (
                <ul className="space-y-3">
                  {data.offers.map((o) => (
                    <li key={o.id} className="flex items-center gap-3">
                      <div className="min-w-0 flex-1">
                        <PersonCell name={o.student_name} src={o.photo} sub={`${o.student_uid} · ${o.program_short} · ${o.job_role}`} size="sm" />
                      </div>
                      <div className="shrink-0 text-right">
                        <p className="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{lpa(o.package_lpa)}</p>
                        <StatusBadge status={o.status} label={OFFER_STATUS[o.status]} colors={OFFER_STATUS_COLORS} dot={false} />
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        )}
      </Drawer>
      <CrudFormModal open={editing && !!c} onClose={() => setEditing(false)} meta={meta} module="companies" id={c ? Number(c.id) : null} onSaved={() => void refresh('companies')} />
    </>
  );
}
