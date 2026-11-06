import { useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, ExternalLink, GraduationCap, ShieldCheck, ShieldOff } from 'lucide-react';
import { Alert, Button, Card, CardBody, CardHeader, DataTable, EmptyState, Field, Input, Modal, SearchInput, Select, Toggle, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { useWaRefresh } from '../shared';
import { useBotCourses } from './api';
import type { BotCourse } from './types';

/**
 * Course information the bot quotes (website programs: duration, seats, fee, eligibility, specialisations). A program
 * marked "not verified" never has its fee or eligibility quoted — the bot hands those questions to a counsellor.
 * The data itself is edited in Academics › Programs.
 */
export function CoursesTab() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const q = useBotCourses();
  const [search, setSearch] = useState('');
  const [level, setLevel] = useState('');
  const [filter, setFilter] = useState('');
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [busy, setBusy] = useState<number | 'bulk' | null>(null);
  const [noteFor, setNoteFor] = useState<BotCourse | null>(null);
  const [note, setNote] = useState('');
  const canEdit = can('whatsapp_automation', 'edit');
  const rows = q.data?.rows ?? [];
  const levels = useMemo(() => Object.fromEntries([...new Set(rows.map((r) => r.level))].map((l) => [l, l])), [rows]);
  const visible = rows.filter(
    (r) =>
      (!search || `${r.name} ${r.short_name} ${r.code ?? ''}`.toLowerCase().includes(search.toLowerCase())) &&
      (!level || r.level === level) &&
      (!filter || (filter === 'verified' ? r.verified : filter === 'unverified' ? !r.verified : r.issues.length > 0)),
  );
  const unverified = rows.filter((r) => !r.verified).length;
  const withIssues = rows.filter((r) => r.issues.length).length;

  const setVerified = async (c: BotCourse, verified: boolean, withNote?: string) => {
    setBusy(c.id);
    try {
      const r = await api.post(`whatsapp/bot/courses/${c.id}`, { verified, note: withNote ?? c.note ?? '' });
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const bulk = async (verified: boolean) => {
    setBusy('bulk');
    try {
      const r = await api.post('whatsapp/bot/courses/bulk', { ids: [...selected], verified });
      toast.success(r.message);
      setSelected(new Set());
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const columns: Column<BotCourse>[] = [
    {
      key: 'name',
      header: 'Program',
      render: (r) => (
        <div className="min-w-[13rem]">
          <p className="font-semibold text-slate-900 dark:text-white">{r.short_name}</p>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {r.name} · {r.level}
          </p>
        </div>
      ),
    },
    { key: 'duration', header: 'Duration', render: (r) => (r.duration ? <span className="whitespace-nowrap">{r.duration}</span> : <span className="text-slate-400">—</span>) },
    { key: 'fee', header: 'Fee', render: (r) => (r.fee ? <span className="whitespace-nowrap font-medium">{r.fee}</span> : <span className="text-slate-400">Not set</span>) },
    {
      key: 'eligibility',
      header: 'Eligibility',
      className: 'max-w-xs',
      render: (r) => (r.eligibility ? <span className="line-clamp-2 text-xs text-slate-600 dark:text-slate-300">{r.eligibility}</span> : <span className="text-slate-400">Not set</span>),
    },
    {
      key: 'checks',
      header: 'Data checks',
      render: (r) =>
        r.issues.length ? (
          <span className="inline-flex items-start gap-1 text-xs text-amber-700 dark:text-amber-400">
            <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden /> {r.issues.join(' · ')}
          </span>
        ) : (
          <span className="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
            <CheckCircle2 className="h-3.5 w-3.5" aria-hidden /> Complete
          </span>
        ),
    },
    {
      key: 'verified',
      header: 'Verified for WhatsApp',
      render: (r) => (
        <div className="min-w-[11rem]">
          <Toggle
            checked={r.verified}
            disabled={!canEdit || busy === r.id}
            onChange={(v) => {
              if (!v) {
                setNote(r.note ?? '');
                setNoteFor(r);
              } else void setVerified(r, true, '');
            }}
            label={r.verified ? (r.fee ? 'Quotes fee & eligibility' : 'Quotes eligibility · no fee on record') : 'Counsellor confirms'}
          />
          {(r.note || r.checked_at) && (
            <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
              {r.note ? `${r.note} · ` : ''}
              {r.checked_by_name ? `${r.checked_by_name}, ` : ''}
              {r.checked_at ? formatDate(r.checked_at) : ''}
            </p>
          )}
        </div>
      ),
    },
  ];

  return (
    <Card>
      <CardHeader
        title="Course information"
        subtitle={q.data ? `${rows.length} website programs · ${unverified} not verified · ${withIssues} with missing data` : 'Programs, fees, eligibility and duration the bot quotes'}
        icon={GraduationCap}
        actions={
          q.data?.can_edit_programs ? (
            <Button size="sm" variant="secondary" iconRight={ExternalLink} to="/academics?tab=programs">
              Edit programs
            </Button>
          ) : undefined
        }
      />
      <CardBody className="space-y-4">
        <Alert variant="info">
          The bot quotes fees, eligibility, duration and seats straight from the program records. Switch a program to <strong>Counsellor confirms</strong> when its published fee or eligibility is not final — the bot then never quotes them and offers a counsellor instead.
        </Alert>
        <div className="flex flex-wrap items-center gap-2">
          <SearchInput value={search} onChange={setSearch} placeholder="Search programs…" className="min-w-[12rem] flex-1" />
          <Select aria-label="Level" value={level} onChange={(e) => setLevel(e.target.value)} options={levels} placeholder="All levels" className="w-full sm:w-44" />
          <Select aria-label="Show" value={filter} onChange={(e) => setFilter(e.target.value)} options={{ verified: 'Verified', unverified: 'Counsellor confirms', issues: 'Missing data' }} placeholder="All programs" className="w-full sm:w-52" />
          {canEdit && selected.size > 0 && (
            <div className="flex items-center gap-2">
              <Button size="sm" icon={ShieldCheck} loading={busy === 'bulk'} onClick={() => bulk(true)}>
                Verify {selected.size}
              </Button>
              <Button size="sm" variant="secondary" icon={ShieldOff} disabled={busy === 'bulk'} onClick={() => bulk(false)}>
                Counsellor confirms
              </Button>
            </div>
          )}
        </div>
        {q.error ? (
          <Alert variant="error" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
            {(q.error as ApiError).message}
          </Alert>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            loading={q.isLoading}
            selectable={canEdit}
            selected={selected}
            onSelectedChange={setSelected}
            empty={<EmptyState icon={GraduationCap} title={rows.length ? 'No programs match' : 'No website programs'} description={rows.length ? 'Change the filters.' : 'Programs shown on the website appear here.'} />}
            rowClassName={(r) => (!r.verified ? 'bg-amber-50/40 dark:bg-amber-500/5' : undefined)}
          />
        )}
      </CardBody>
      <Modal
        open={!!noteFor}
        onClose={() => setNoteFor(null)}
        size="sm"
        title={`Counsellor confirms ${noteFor?.short_name ?? ''}`}
        description="The bot will not quote this program's fee or eligibility and will offer a counsellor instead."
        footer={
          <>
            <Button variant="secondary" onClick={() => setNoteFor(null)}>
              Cancel
            </Button>
            <Button
              loading={busy === noteFor?.id}
              onClick={async () => {
                if (noteFor) await setVerified(noteFor, false, note);
                setNoteFor(null);
              }}
            >
              Save
            </Button>
          </>
        }
      >
        <Field label="Reason (optional)" htmlFor="course-note" hint="Shown to the team, e.g. Fee for 2027 not approved yet.">
          <Input id="course-note" value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} />
        </Field>
      </Modal>
    </Card>
  );
}
