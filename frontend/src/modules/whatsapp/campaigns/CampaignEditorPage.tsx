import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowLeft, ArrowRight, Check, Eye, Save } from 'lucide-react';
import { Alert, Button, Card, CardSkeleton, PageHeader, useToast } from '@/components/ui';
import { ApiError, api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { ModeBanner } from '../shared';
import { useCampaign, useCampaignMeta, useCampaignRefresh } from './api';
import { EMPTY_AUDIENCE, draftFromCampaign, editorPath, type Draft } from './lib';
import { StepAudience } from './StepAudience';
import { StepDetails } from './StepDetails';
import { StepPersonalise } from './StepPersonalise';
import { StepReview } from './StepReview';
import { StepTemplate } from './StepTemplate';
import { CMP_BREAD, CampaignStatusBadge } from './ui';
import type { Campaign } from './types';

export type StepKey = 'details' | 'template' | 'audience' | 'personalise' | 'review';
export const STEPS: { key: StepKey; label: string; hint: string }[] = [
  { key: 'details', label: 'Details', hint: 'Name & type' },
  { key: 'template', label: 'Template', hint: 'Approved message' },
  { key: 'audience', label: 'Audience', hint: 'Who receives it' },
  { key: 'personalise', label: 'Personalise', hint: 'Variables' },
  { key: 'review', label: 'Review & send', hint: 'Test, schedule' },
];

/** Fields each step saves (PUT only what the step owns, so a template change resets the mapping server-side). */
function payloadFor(step: StepKey, d: Draft): Record<string, unknown> {
  switch (step) {
    case 'details':
      return { name: d.name, type: d.type, description: d.description };
    case 'template':
      return { template_id: d.template_id };
    case 'audience':
      return { audience_source: d.audience_source, audience: d.audience };
    case 'personalise':
      return { variable_map: d.variable_map };
    default:
      return { rate_per_minute: d.rate_per_minute, respect_business_hours: d.respect_business_hours };
  }
}

const NEW_DRAFT: Draft = {
  name: '',
  type: '',
  description: '',
  template_id: null,
  audience_source: 'filters',
  audience: EMPTY_AUDIENCE,
  variable_map: {},
  rate_per_minute: '',
  respect_business_hours: false,
};

export default function CampaignEditorPage() {
  const params = useParams();
  const id = Number(params.id) || 0;
  const isNew = !id;
  const navigate = useNavigate();
  const toast = useToast();
  const refresh = useCampaignRefresh();
  const { can, session } = useAuth();
  const [search, setSearch] = useSearchParams();
  const meta = useCampaignMeta();
  // /whatsapp/campaigns/new?segment=ID (Contacts › Segments "Campaign" button): that saved segment becomes the audience
  const segmentParam = isNew ? Number(search.get('segment')) || 0 : 0;
  const presetSegment = segmentParam && meta.data ? meta.data.segments.find((s) => s.id === segmentParam) ?? null : null;
  const detail = useCampaign(id, { enabled: !isNew });
  const [draft, setDraft] = useState<Draft | null>(isNew ? NEW_DRAFT : null);
  const [saved, setSaved] = useState<string>(JSON.stringify(NEW_DRAFT));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const stepParam = (search.get('step') as StepKey | null) ?? 'details';
  const step: StepKey = isNew ? 'details' : STEPS.some((s) => s.key === stepParam) ? stepParam : 'details';
  const stepIndex = STEPS.findIndex((s) => s.key === step);

  // initialise the draft once per campaign (background refetches never overwrite typing)
  const loadedId = detail.data?.id;
  useEffect(() => {
    if (detail.data && (!draft || (draft as Draft & { __id?: number }).__id !== detail.data.id)) {
      const d = Object.assign(draftFromCampaign(detail.data), { __id: detail.data.id });
      setDraft(d);
      setSaved(JSON.stringify(draftFromCampaign(detail.data)));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loadedId]);

  const patch = (p: Partial<Draft>) => setDraft((d) => (d ? { ...d, ...p } : d));
  const clean = useMemo(() => {
    if (!draft) return null;
    const { __id, ...rest } = draft as Draft & { __id?: number };
    void __id;
    return rest as Draft;
  }, [draft]);
  const dirty = !!clean && JSON.stringify(clean) !== saved;

  const goto = (k: StepKey) => {
    const next = new URLSearchParams(search);
    next.set('step', k);
    setSearch(next);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  /** Save the current step (create on step 1 of a new campaign). Returns true when saved. */
  const saveStep = async (k: StepKey = step, quiet = false): Promise<boolean> => {
    if (!clean) return false;
    setBusy(true);
    setErrors({});
    try {
      if (isNew) {
        const body: Record<string, unknown> = payloadFor('details', clean);
        if (presetSegment) {
          body.audience_source = 'filters';
          body.audience = { ...EMPTY_AUDIENCE, segment_id: presetSegment.id };
        }
        const res = await api.post<Campaign>('whatsapp/campaigns', body);
        toast.success(res.message);
        await refresh();
        navigate(editorPath(res.data.id, 'template', can('whatsapp_campaigns', 'edit')), { replace: true });
        return false;
      }
      const res = await api.put<Campaign>(`whatsapp/campaigns/${id}`, payloadFor(k, clean));
      if (!quiet) toast.success(res.message);
      await refresh();
      const fresh = await detail.refetch();
      if (fresh.data) {
        const server = draftFromCampaign(fresh.data);
        // keep what the user typed on other steps, take the server's view of the saved step (e.g. default mapping)
        const merged: Draft = { ...clean, ...pick(server, k), variable_map: k === 'template' || k === 'personalise' ? server.variable_map : clean.variable_map };
        setDraft(Object.assign(merged, { __id: fresh.data.id }));
        setSaved(JSON.stringify(server));
      }
      return true;
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
      return false;
    } finally {
      setBusy(false);
    }
  };

  const next = async () => {
    const ok = await saveStep(step, true);
    if (ok && stepIndex < STEPS.length - 1) goto(STEPS[stepIndex + 1].key);
  };

  const c = detail.data;
  const editable = isNew || (c && ['draft', 'scheduled'].includes(c.status));
  // same rule as the server: editors change any draft, creators only their own
  const canEdit = can('whatsapp_campaigns', 'edit') || (can('whatsapp_campaigns', 'create') && (isNew || (!!c && c.created_by === session?.user?.id)));
  const canPublish = can('whatsapp_campaigns', 'publish');
  // a publisher without edit rights can still test and send from the Review step
  const locked = !editable || (!canEdit && !(step === 'review' && canPublish));
  const title = isNew ? 'New campaign' : c ? `Edit: ${c.name}` : 'Edit campaign';

  if (!isNew && detail.error && !c) {
    return (
      <div>
        <PageHeader title="Edit campaign" breadcrumbs={[...CMP_BREAD, { label: 'Campaigns', to: '/whatsapp/campaigns' }, { label: 'Edit' }]} />
        <Alert variant="error" title="The campaign could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => detail.refetch()}>Retry</Button>}>
          {(detail.error as Error).message}
        </Alert>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={title}
        description="Five steps: details, an approved template, the audience, personalisation and a final review with a test message."
        breadcrumbs={[...CMP_BREAD, { label: 'Campaigns', to: '/whatsapp/campaigns' }, ...(c ? [{ label: c.name, to: `/whatsapp/campaigns/${c.id}` }] : []), { label: isNew ? 'New' : 'Edit' }]}
        actions={
          c ? (
            <>
              <CampaignStatusBadge status={c.status} />
              <Button variant="secondary" icon={Eye} to={`/whatsapp/campaigns/${c.id}`}>
                Campaign page
              </Button>
            </>
          ) : undefined
        }
      />
      <ModeBanner />

      {!isNew && c && !editable && (
        <Alert variant="warning" title="This campaign can no longer be edited" className="mb-4" action={<Button size="sm" variant="secondary" to={`/whatsapp/campaigns/${c.id}`}>View report</Button>}>
          It is {c.status}. Duplicate it from the campaign page to send it again with changes.
        </Alert>
      )}
      {c?.status === 'scheduled' && (
        <Alert variant="info" className="mb-4">
          Scheduled for {c.scheduled_at}. Saving any change moves it back to draft — schedule it again on the Review step.
        </Alert>
      )}
      {!canEdit && editable && (
        <Alert variant="warning" className="mb-4">
          {canPublish ? 'You can review, test and send this campaign but not change it.' : 'You can view this campaign but not change it.'}
        </Alert>
      )}
      {isNew && segmentParam > 0 && meta.data && (
        presetSegment ? (
          <Alert variant="info" className="mb-4" title={`Audience: saved segment “${presetSegment.name}”`}>
            {presetSegment.contacts_count.toLocaleString('en-IN')} contacts ({presetSegment.opted_in_count.toLocaleString('en-IN')} opted in). The segment is pre-selected on the Audience step — you can add filters or change it there.
          </Alert>
        ) : (
          <Alert variant="warning" className="mb-4">
            The saved segment from the link no longer exists. Choose the audience on the Audience step.
          </Alert>
        )
      )}

      {/* stepper */}
      <nav aria-label="Campaign steps" className="mb-5">
        <ol className="grid grid-cols-5 gap-1 sm:gap-2">
          {STEPS.map((s, i) => {
            const active = s.key === step;
            const done = i < stepIndex;
            const disabled = isNew && i > 0;
            return (
              <li key={s.key} className="min-w-0">
                <button
                  type="button"
                  disabled={disabled || busy}
                  onClick={() => goto(s.key)}
                  aria-current={active ? 'step' : undefined}
                  className={clsx(
                    'group flex w-full flex-col items-center gap-1.5 rounded-xl px-1 py-2 text-center transition sm:flex-row sm:gap-3 sm:px-3 sm:text-left',
                    active ? 'bg-white shadow-soft ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700' : 'hover:bg-white/70 dark:hover:bg-slate-900/60',
                    disabled && 'cursor-not-allowed opacity-50 hover:bg-transparent',
                  )}
                >
                  <span
                    className={clsx(
                      'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                      done ? 'bg-accent-600 text-white' : active ? 'bg-brand-900 text-white ring-4 ring-brand-100 dark:bg-brand-500 dark:ring-brand-500/20' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                    )}
                  >
                    {done ? <Check className="h-4 w-4" aria-hidden /> : i + 1}
                  </span>
                  <span className="min-w-0">
                    <span className={clsx('block truncate text-[11px] font-semibold sm:text-sm', active ? 'text-brand-900 dark:text-white' : 'hidden text-slate-600 dark:text-slate-300 sm:block')}>
                      <span className="sm:hidden">{s.label.split(' ')[0]}</span>
                      <span className="hidden sm:inline">{s.label}</span>
                    </span>
                    <span className="hidden truncate text-xs text-slate-500 dark:text-slate-400 lg:block">{s.hint}</span>
                  </span>
                </button>
              </li>
            );
          })}
        </ol>
      </nav>

      {!draft || !meta.data || (!isNew && !c) ? (
        meta.error ? (
          <Alert variant="error" title="Campaign settings could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => meta.refetch()}>Retry</Button>}>
            {(meta.error as Error).message}
          </Alert>
        ) : (
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="lg:col-span-2">
              <CardSkeleton lines={8} />
            </div>
            <CardSkeleton lines={5} />
          </div>
        )
      ) : (
        <fieldset disabled={locked} className="min-w-0">
          {step === 'details' && <StepDetails draft={draft} patch={patch} errors={errors} meta={meta.data} />}
          {step === 'template' && c && <StepTemplate draft={draft} patch={patch} errors={errors} meta={meta.data} />}
          {step === 'audience' && c && <StepAudience draft={draft} patch={patch} errors={errors} meta={meta.data} campaign={c} />}
          {step === 'personalise' && c && <StepPersonalise draft={draft} patch={patch} errors={errors} meta={meta.data} campaign={c} onGoto={goto} />}
          {step === 'review' && c && (
            <StepReview draft={draft} patch={patch} errors={errors} meta={meta.data} campaign={c} onGoto={goto} saveStep={saveStep} dirty={dirty} canEditDraft={!!editable && canEdit} />
          )}
        </fieldset>
      )}
      {draft && meta.data && (isNew || c) && (
        <>
          {/* footer */}
          <Card className="sticky bottom-3 z-10 mt-5 flex flex-wrap items-center gap-2 px-4 py-3 shadow-soft">
            {stepIndex > 0 ? (
              <Button variant="ghost" icon={ArrowLeft} onClick={() => goto(STEPS[stepIndex - 1].key)} disabled={busy}>
                <span className="hidden sm:inline">Back</span>
              </Button>
            ) : (
              <Button variant="ghost" icon={ArrowLeft} to="/whatsapp/campaigns">
                <span className="hidden sm:inline">Campaigns</span>
              </Button>
            )}
            <span className="ml-auto hidden text-xs text-slate-500 dark:text-slate-400 sm:inline">{dirty ? 'Unsaved changes' : isNew ? '' : 'All changes saved'}</span>
            {!isNew && editable && canEdit && (
              <Button variant="secondary" icon={Save} loading={busy} disabled={!dirty} onClick={() => saveStep()}>
                Save draft
              </Button>
            )}
            {step !== 'review' && editable && canEdit && (
              <Button iconRight={ArrowRight} loading={busy} onClick={next} className="ml-auto sm:ml-0">
                {isNew ? 'Create & continue' : 'Save & continue'}
              </Button>
            )}
            {step !== 'review' && (!editable || !canEdit) && c && (
              <Button variant="secondary" iconRight={ArrowRight} onClick={() => goto(STEPS[stepIndex + 1].key)} className="ml-auto sm:ml-0">
                Next
              </Button>
            )}
          </Card>
        </>
      )}
      {!isNew && c && (
        <p className="mt-4 text-center text-xs text-slate-500 dark:text-slate-400">
          Drafts are saved on the server — <Link to="/whatsapp/campaigns?status=draft" className="font-medium text-brand-700 hover:underline dark:text-brand-300">find them under Drafts</Link>.
        </p>
      )}
    </div>
  );
}

function pick(d: Draft, step: StepKey): Partial<Draft> {
  switch (step) {
    case 'details':
      return { name: d.name, type: d.type, description: d.description };
    case 'template':
      return { template_id: d.template_id };
    case 'audience':
      return { audience_source: d.audience_source, audience: d.audience };
    case 'personalise':
      return {};
    default:
      return { rate_per_minute: d.rate_per_minute, respect_business_hours: d.respect_business_hours };
  }
}
