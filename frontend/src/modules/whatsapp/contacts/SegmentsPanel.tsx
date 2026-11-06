import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Filter, Layers, Megaphone, Pencil, ShieldCheck, Trash2, Users } from 'lucide-react';
import { Alert, Badge, Button, Card, CardSkeleton, EmptyState, Field, Input, Stagger, useConfirm, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { crmKeys, stageInfo, TYPE_LABELS, useCrmRefresh, useFacets, type Facets, type Segment } from './crm';
import { DialogShell, fieldErrors } from './crmUi';

const LABELS: Record<string, string> = {
  q: 'Search', scope: 'Scope', type: 'Type', program_id: 'Programme', stage: 'Stage', admission_status: 'Admission', payment_status: 'Payment', city: 'City',
  state: 'State', batch: 'Batch', counsellor_id: 'Counsellor', tag: 'Tags', tag_mode: 'Tag match', opt_in: 'Opt-in', status: 'Status', window: 'Window',
  last_from: 'Last message from', last_to: 'Last message to', created_from: 'Added from', created_to: 'Added to', source: 'Added via', lead_source: 'Lead source',
  language: 'Language', has_followup: 'Follow-up',
};

/** Human summary of a segment's filters. */
export function describeFilters(filters: Record<string, string | string[]>, facets?: Facets | null): string[] {
  const find = (list: { value: string | number; label: string }[] | undefined, v: string) => list?.find((o) => String(o.value) === v)?.label ?? v;
  return Object.entries(filters).map(([k, raw]) => {
    const vals = Array.isArray(raw) ? raw : String(raw).split(',');
    const names = vals.map((v) => {
      switch (k) {
        case 'type': return TYPE_LABELS[v] ?? v;
        case 'stage': return stageInfo(v, facets).label;
        case 'program_id': return find(facets?.programs, v);
        case 'counsellor_id': return v === 'none' ? 'Unassigned' : v === 'me' ? 'Me' : find(facets?.counsellors, v);
        case 'tag': return find(facets?.tags, v);
        case 'opt_in': return v === '1' ? 'Opted in' : 'Not opted in';
        case 'window': return v === 'open' ? 'Open' : 'Closed';
        case 'has_followup': return v === '1' ? 'Scheduled' : 'None scheduled';
        case 'tag_mode': return v === 'all' ? 'All tags' : 'Any tag';
        case 'scope': return v === 'leads' ? 'Admission leads' : v;
        default: return v.replace(/_/g, ' ');
      }
    });
    return `${LABELS[k] ?? k}: ${names.join(', ')}`;
  });
}

/** Saved segments (named filter sets) — apply to the list, edit, delete, or start a campaign. */
export function SegmentsPanel({ onApply }: { onApply: (filters: Record<string, string | string[]>) => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCrmRefresh();
  const { data: facets } = useFacets();
  const { data, isLoading, error, refetch } = useQuery({ queryKey: crmKeys.segments, queryFn: ({ signal }) => api.get<Segment[]>('whatsapp/contacts/segments', undefined, { signal }) });
  const [editing, setEditing] = useState<Segment | null>(null);

  const remove = async (s: Segment) => {
    if (!(await confirm({ title: `Delete segment "${s.name}"?`, message: 'Contacts are not affected. Campaigns already created keep their recipient list.', confirmText: 'Delete', danger: true }))) return;
    try {
      const r = await api.del(`whatsapp/contacts/segments/${s.id}`);
      toast.success(r.message);
      refresh();
    } catch (e) {
      toast.error((e as Error).message);
    }
  };

  if (error) {
    return (
      <Alert variant="error" title="Segments could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
        {(error as Error).message}
      </Alert>
    );
  }
  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {[0, 1, 2].map((i) => <CardSkeleton key={i} lines={3} />)}
      </div>
    );
  }
  if (!data?.length) {
    return (
      <Card>
        <EmptyState icon={Layers} title="No segments yet" description="Filter the contact list (e.g. Hot leads for MBA who opted in) and choose “Save segment”. Campaigns can then target the segment." action={<Button icon={Filter} onClick={() => onApply({})}>Go to contacts</Button>} />
      </Card>
    );
  }
  return (
    <>
      <Stagger className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" step={50}>
        {data.map((s) => (
          <Card key={s.id} className="flex h-full flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-soft">
            <div className="flex items-start gap-3">
              <span className="kpi-icon shrink-0 bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200">
                <Layers className="h-5 w-5" aria-hidden />
              </span>
              <div className="min-w-0 flex-1">
                <h3 className="truncate font-semibold text-slate-900 dark:text-white" title={s.name}>{s.name}</h3>
                {s.description && <p className="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{s.description}</p>}
              </div>
            </div>
            <div className="mt-4 flex items-center gap-4 text-sm">
              <span className="inline-flex items-center gap-1.5 font-semibold text-slate-900 dark:text-white"><Users className="h-4 w-4 text-slate-400" aria-hidden />{formatNumber(s.contacts_count)}</span>
              <span className="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-300"><ShieldCheck className="h-4 w-4" aria-hidden />{formatNumber(s.opted_in_count)} opted in</span>
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">
              {describeFilters(s.filters, facets).map((d) => (
                <Badge key={d} color="slate" className="!normal-case">{d}</Badge>
              ))}
            </div>
            <p className="mt-3 text-[11px] text-slate-400">Counted {s.counted_at ? timeAgo(s.counted_at) : '—'}{s.created_by_name ? ` · by ${s.created_by_name}` : ''}</p>
            <div className="mt-auto flex flex-wrap gap-2 pt-4">
              <Button size="sm" variant="secondary" icon={Filter} onClick={() => onApply(s.filters)}>Show contacts</Button>
              {can('whatsapp_campaigns', 'create') && <Button size="sm" variant="soft" icon={Megaphone} to={`/whatsapp/campaigns/new?segment=${s.id}`}>Campaign</Button>}
              {can('whatsapp_contacts', 'edit') && (
                <span className="ml-auto flex gap-1">
                  <Button size="sm" variant="ghost" icon={Pencil} aria-label={`Rename ${s.name}`} onClick={() => setEditing(s)} />
                  <Button size="sm" variant="ghost" icon={Trash2} aria-label={`Delete ${s.name}`} onClick={() => remove(s)} />
                </span>
              )}
            </div>
          </Card>
        ))}
      </Stagger>
      <EditSegmentDialog segment={editing} onClose={() => setEditing(null)} />
    </>
  );
}

function EditSegmentDialog({ segment, onClose }: { segment: Segment | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (segment) {
      setName(segment.name);
      setDescription(segment.description ?? '');
      setErrors({});
    }
  }, [segment]);
  const submit = async () => {
    if (!segment) return;
    setBusy(true);
    try {
      const r = await api.put(`whatsapp/contacts/segments/${segment.id}`, { name, description, filters: segment.filters });
      toast.success(r.message);
      refresh();
      onClose();
    } catch (e) {
      setErrors(fieldErrors(e));
      if (!Object.keys(fieldErrors(e)).length) toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={!!segment} onClose={onClose} title="Edit segment" description="Change the filters by applying the segment, adjusting filters and saving a new one." busy={busy} onSubmit={submit} submitLabel="Save">
      <Field label="Name" required error={errors.name} htmlFor="wa-seg-ename">
        <Input id="wa-seg-ename" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} />
      </Field>
      <Field label="Description" htmlFor="wa-seg-edesc">
        <Input id="wa-seg-edesc" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} />
      </Field>
    </DialogShell>
  );
}
