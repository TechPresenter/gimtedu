import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle, ArrowRight, CheckCircle2, GraduationCap, History, Info, ListChecks, PartyPopper, RotateCcw, Rocket, TrendingUp, UserMinus, Users,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardBody, CardHeader, DataTable, EmptyState, Field, Input, PageHeader, Pagination, Reveal, Select, Stagger, StatTile, Stepper,
  Toggle, useConfirm, useToast, type Column,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAcademicSession } from '@/lib/auth';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useAcademicTree, useTreeOptions } from './hooks';
import type { PagedRows, PromotionHistoryRow, PromotionPreview, PromotionResult, PromotionStudent } from './types';

type Action = 'promote' | 'detain' | 'pass_out' | 'skip';
const ACTION_LABEL: Record<Action, string> = { promote: 'Promote', detain: 'Detain', pass_out: 'Pass out', skip: 'Skip' };

export default function PromotionPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { id: currentSessionId } = useAcademicSession();
  const { data: tree } = useAcademicTree();
  const [params] = useSearchParams();
  const [from, setFrom] = useState({ program_id: params.get('program_id') ?? '', semester: params.get('semester') ?? '', section_id: params.get('section_id') ?? '', session_id: params.get('session_id') ?? '' });
  const [actions, setActions] = useState<Record<number, Action>>({});
  const [target, setTarget] = useState({ to_semester: '', to_session_id: '', section_mode: 'same', to_section_id: '', create_sections: true, remarks: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<PromotionResult | null>(null);
  const [historyPage, setHistoryPage] = useState(1);

  useEffect(() => {
    if (currentSessionId && !from.session_id) setFrom((f) => ({ ...f, session_id: String(currentSessionId) }));
  }, [currentSessionId, from.session_id]);

  const opts = useTreeOptions(tree, { program_id: from.program_id, semester: from.semester, session_id: from.session_id });
  const ready = !!(from.program_id && from.semester);
  const preview = useApi<PromotionPreview>(['students', 'promotion', from], 'students/promotion/preview',
    { program_id: from.program_id, semester: from.semester, section_id: from.section_id || undefined, session_id: from.session_id || undefined }, { enabled: ready && !result, staleTime: 0 });
  const history = useApi<PagedRows<PromotionHistoryRow>>(['students', 'promotion-history', historyPage], 'students/promotion/history', { page: historyPage, per_page: 8 });
  const p = preview.data;
  const isFinal = !!p?.target.is_final;

  // reset per-student actions + target defaults when a new preview arrives
  useEffect(() => {
    if (!p) return;
    const a: Record<number, Action> = {};
    p.students.forEach((s) => (a[s.id] = s.suggested_action));
    setActions(a);
    setTarget((t) => ({ ...t, to_semester: p.target.to_semester ? String(p.target.to_semester) : '', to_session_id: p.target.to_session_id ? String(p.target.to_session_id) : '', to_section_id: '' }));
    setErrors({});
  }, [p]);

  const setFromField = (k: keyof typeof from, v: string) => {
    setResult(null);
    setFrom((f) => {
      const n = { ...f, [k]: v };
      if (k === 'program_id') Object.assign(n, { semester: '', section_id: '' });
      if (k === 'semester' || k === 'session_id') n.section_id = '';
      return n;
    });
  };

  const counts = useMemo(() => {
    const c: Record<Action, number> = { promote: 0, detain: 0, pass_out: 0, skip: 0 };
    Object.values(actions).forEach((a) => c[a]++);
    return c;
  }, [actions]);
  const setAll = (a: Action) => setActions((prev) => Object.fromEntries(Object.keys(prev).map((k) => [k, a])) as Record<number, Action>);
  const programOptions = tree?.programs.find((x) => String(x.id) === from.program_id);
  const targetSemesters = programOptions && p ? Array.from({ length: programOptions.total_semesters - p.semester }, (_, i) => ({ value: p.semester + i + 1, label: `Semester ${p.semester + i + 1}` })) : [];
  const targetSections = (tree?.sections ?? []).filter((s) => String(s.program_id) === from.program_id && String(s.semester_no) === target.to_semester && (!target.to_session_id || String(s.academic_session_id) === target.to_session_id));
  const sessionName = (id: string) => tree?.sessions.find((s) => String(s.id) === id)?.name ?? '';
  const sessionOptions = (tree?.sessions ?? []).map((s) => ({ value: s.id, label: s.name }));

  const run = async () => {
    if (!p) return;
    const e: Record<string, string> = {};
    const promoting = counts.promote > 0;
    if (promoting && !target.to_semester) e.to_semester = 'Choose the target semester.';
    if (promoting && target.section_mode === 'fixed' && !target.to_section_id) e.to_section_id = 'Choose the target section.';
    if (counts.promote + counts.detain + counts.pass_out === 0) e.items = 'Every student is set to Skip — nothing to do.';
    setErrors(e);
    if (Object.keys(e).length) return;
    const lines = [
      counts.promote && `${counts.promote} → Semester ${target.to_semester}${target.to_session_id ? ` (${sessionName(target.to_session_id)})` : ''}`,
      counts.pass_out && `${counts.pass_out} marked as passed out`,
      counts.detain && `${counts.detain} detained in semester ${p.semester}`,
      counts.skip && `${counts.skip} skipped`,
    ].filter(Boolean);
    const ok = await confirm({
      title: `Run promotion for ${p.program.short_name} semester ${p.semester}?`,
      message: <div className="space-y-2"><ul className="list-disc space-y-0.5 pl-5">{lines.map((l) => <li key={String(l)}>{l}</li>)}</ul><p className="text-xs text-slate-500">Student records and academic history are updated in one transaction and logged.</p></div>,
      confirmText: 'Run promotion',
    });
    if (!ok) return;
    setRunning(true);
    try {
      const res = await api.post<PromotionResult>('students/promotion', {
        program_id: Number(from.program_id), from_semester: p.semester, from_section_id: from.section_id ? Number(from.section_id) : null, from_session_id: from.session_id ? Number(from.session_id) : null,
        to_semester: target.to_semester ? Number(target.to_semester) : null, to_session_id: target.to_session_id ? Number(target.to_session_id) : null,
        section_mode: target.section_mode, to_section_id: target.to_section_id ? Number(target.to_section_id) : null, create_sections: target.create_sections, remarks: target.remarks || null,
        items: Object.entries(actions).map(([id, action]) => ({ student_id: Number(id), action })),
      });
      toast.success(res.message);
      setResult(res.data);
      await Promise.all([qc.invalidateQueries({ queryKey: ['students'] }), qc.invalidateQueries({ queryKey: ['crud', 'students'] })]);
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      toast.error(ex.message);
    } finally {
      setRunning(false);
    }
  };

  const cols: Column<PromotionStudent>[] = [
    { key: 'name', header: 'Student', render: (s) => (
      <div className="flex items-center gap-3">
        <Avatar name={s.name} src={s.photo} size="sm" />
        <div className="min-w-0 leading-tight"><p className="truncate font-semibold text-slate-900 dark:text-white">{s.name}</p><p className="text-xs text-slate-500">{s.student_uid}{s.roll_no ? ` · ${s.roll_no}` : ''}</p></div>
      </div>
    ) },
    { key: 'section_name', header: 'Section', align: 'center', render: (s) => s.section_name ?? '—' },
    { key: 'attendance_percent', header: 'Attendance', align: 'right', render: (s) => (s.attendance_percent === null ? <span className="text-slate-400">—</span> : <span className={clsx('font-semibold tabular-nums', s.flags.includes('low_attendance') ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-100')}>{s.attendance_percent.toFixed(1)}%</span>) },
    { key: 'cgpa', header: 'CGPA', align: 'right', render: (s) => (s.cgpa === null ? <span className="text-slate-400">—</span> : <span className="font-semibold tabular-nums">{s.cgpa.toFixed(2)}</span>) },
    { key: 'flags', header: 'Flags', render: (s) => (
      <div className="flex flex-wrap gap-1">
        {s.flags.includes('low_attendance') && <Badge color="amber">Low attendance</Badge>}
        {s.flags.includes('backlog') && <Badge color="red">{s.backlogs ? `${s.backlogs} backlog` : s.result_status}</Badge>}
        {!s.flags.length && <span className="text-xs text-slate-400">—</span>}
      </div>
    ) },
    { key: 'action', header: 'Action', render: (s) => (
      <Select inputSize="sm" aria-label={`Action for ${s.name}`} value={actions[s.id] ?? s.suggested_action} onChange={(e) => setActions((a) => ({ ...a, [s.id]: e.target.value as Action }))} className="!w-32"
        options={(isFinal ? ['pass_out', 'detain', 'skip'] : ['promote', 'detain', 'skip']).map((a) => ({ value: a, label: ACTION_LABEL[a as Action] }))} />
    ) },
  ];

  const historyCols: Column<PromotionHistoryRow>[] = [
    { key: 'reference_no', header: 'Reference', render: (r) => <span className="whitespace-nowrap font-mono text-xs font-semibold">{r.reference_no}</span> },
    { key: 'program_name', header: 'Class', render: (r) => <span className="whitespace-nowrap">{r.program_name} · Sem {r.from_semester}{r.section_name ? ` (${r.section_name})` : ''}</span> },
    { key: 'to', header: 'Moved to', render: (r) => (r.to_semester ? <span className="inline-flex items-center gap-1 whitespace-nowrap">Sem {r.to_semester}{r.to_session ? ` · ${r.to_session}` : ''}</span> : <Badge color="blue">Passed out</Badge>) },
    { key: 'counts', header: 'Outcome', render: (r) => (
      <div className="flex flex-wrap gap-1 text-xs">
        {r.promoted_count > 0 && <Badge color="green">{r.promoted_count} promoted</Badge>}
        {r.passed_out_count > 0 && <Badge color="blue">{r.passed_out_count} passed out</Badge>}
        {r.detained_count > 0 && <Badge color="amber">{r.detained_count} detained</Badge>}
        {r.skipped_count > 0 && <Badge color="slate">{r.skipped_count} skipped</Badge>}
      </div>
    ) },
    { key: 'created_at', header: 'When', render: (r) => <span className="whitespace-nowrap text-xs">{formatDateTime(r.created_at)}<span className="block text-slate-500">{r.created_by_name ?? 'System'}</span></span> },
  ];

  const step = result ? 'done' : p ? 'review' : 'select';
  return (
    <>
      <PageHeader
        title="Promote Students"
        description="Move a class to the next semester (or mark the final semester as passed out) in one step. Student records and semester history are updated together."
        breadcrumbs={[{ label: 'Students', to: '/students' }, { label: 'Promote Students' }]}
      />
      <Card className="mb-5 px-5 py-4">
        <Stepper current={step} steps={[{ key: 'select', label: 'Choose class' }, { key: 'review', label: 'Review & set actions' }, { key: 'done', label: 'Promoted' }]} />
      </Card>

      {result ? (
        <Reveal>
          <Card className="mb-5 overflow-hidden">
            <div className="flex flex-col items-center px-6 py-10 text-center">
              <span className="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-accent-50 text-accent-700 ring-8 ring-accent-50/60 motion-safe:animate-slide-up dark:bg-accent-500/15 dark:text-accent-300 dark:ring-accent-500/5">
                <PartyPopper className="h-8 w-8" />
              </span>
              <h2 className="mt-5 font-display text-xl font-bold text-slate-900 dark:text-white">Promotion {result.reference_no} completed</h2>
              <p className="mt-1 text-sm text-slate-500">{p?.program.name} · semester {p?.semester}</p>
              <Stagger className="mt-6 grid w-full max-w-3xl grid-cols-2 gap-3 sm:grid-cols-4" step={70}>
                {[
                  <StatTile key="p" label="Promoted" value={result.promoted} icon={TrendingUp} tone="green" sub={result.to_semester ? `to semester ${result.to_semester}` : undefined} />,
                  <StatTile key="g" label="Passed out" value={result.passed_out} icon={GraduationCap} tone="blue" />,
                  <StatTile key="d" label="Detained" value={result.detained} icon={UserMinus} tone="amber" />,
                  <StatTile key="s" label="Skipped" value={result.skipped} icon={RotateCcw} tone="slate" />,
                ]}
              </Stagger>
              {(result.created_sections.length > 0 || result.without_section > 0) && (
                <div className="mt-5 w-full max-w-3xl space-y-2 text-left">
                  {result.created_sections.length > 0 && <Alert variant="info">Created section{result.created_sections.length > 1 ? 's' : ''} {result.created_sections.join(', ')} for semester {result.to_semester}.</Alert>}
                  {result.without_section > 0 && <Alert variant="warning">{result.without_section} promoted student{result.without_section > 1 ? 's have' : ' has'} no section yet — assign one from the student list.</Alert>}
                </div>
              )}
              <div className="mt-6 flex flex-wrap justify-center gap-2">
                {result.to_semester ? (
                  <Button icon={Users} to={`/students?f.program_id=${from.program_id}&f.semester=${result.to_semester}`}>View semester {result.to_semester}</Button>
                ) : (
                  <Button icon={Users} to={`/students?f.program_id=${from.program_id}&f.status=graduated`}>View passed-out students</Button>
                )}
                <Button variant="secondary" icon={RotateCcw} onClick={() => { setResult(null); setFrom((f) => ({ ...f, semester: '', section_id: '' })); }}>Promote another class</Button>
              </div>
            </div>
          </Card>
        </Reveal>
      ) : (
        <div className="mb-5 grid gap-5 xl:grid-cols-[370px_minmax(0,1fr)]">
          <div className="space-y-5">
            <Card>
              <CardHeader title="Current class" icon={ListChecks} subtitle="Students who are active in this class" />
              <CardBody className="space-y-4">
                <Field label="Program" required htmlFor="pr-program">
                  <Select id="pr-program" value={from.program_id} onChange={(e) => setFromField('program_id', e.target.value)} options={opts.programs} placeholder="Select program" />
                </Field>
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Current semester" required htmlFor="pr-sem">
                    <Select id="pr-sem" value={from.semester} onChange={(e) => setFromField('semester', e.target.value)} options={opts.semesters} placeholder="Select" disabled={!from.program_id} />
                  </Field>
                  <Field label="Session" htmlFor="pr-session">
                    <Select id="pr-session" value={from.session_id} onChange={(e) => setFromField('session_id', e.target.value)} options={sessionOptions} placeholder="Current" />
                  </Field>
                </div>
                <Field label="Section" hint="Leave blank to promote every section together." htmlFor="pr-sec">
                  <Select id="pr-sec" value={from.section_id} onChange={(e) => setFromField('section_id', e.target.value)} options={opts.sections} placeholder="All sections" disabled={!from.semester} />
                </Field>
              </CardBody>
            </Card>

            {p && (
              <Reveal>
                <Card>
                  <CardHeader title={isFinal ? 'Final semester' : 'Promote to'} icon={isFinal ? GraduationCap : ArrowRight} />
                  <CardBody className="space-y-4">
                    {isFinal ? (
                      <Alert variant="info" title="Students will pass out">
                        Semester {p.semester} is the last semester of {p.program.short_name}. Selected students are marked <strong>Passed Out</strong> and become eligible for the alumni network.
                      </Alert>
                    ) : (
                      <>
                        <div className="grid grid-cols-2 gap-3">
                          <Field label="Target semester" required error={errors.to_semester} htmlFor="pr-tsem">
                            <Select id="pr-tsem" value={target.to_semester} onChange={(e) => setTarget((t) => ({ ...t, to_semester: e.target.value, to_section_id: '' }))} options={targetSemesters} placeholder="Select" invalid={!!errors.to_semester} />
                          </Field>
                          <Field label="Target session" error={errors.to_session_id} htmlFor="pr-tsess">
                            <Select id="pr-tsess" value={target.to_session_id} onChange={(e) => setTarget((t) => ({ ...t, to_session_id: e.target.value, to_section_id: '' }))} options={sessionOptions} placeholder="Same session" />
                          </Field>
                        </div>
                        <Field label="Section in the new semester" error={errors.to_section_id}>
                          <div className="space-y-2">
                            {[
                              ['same', 'Keep the same section name', 'A → A, B → B'],
                              ['fixed', 'Move everyone to one section', 'Pick the section below'],
                              ['none', 'Leave unassigned', 'Assign sections later'],
                            ].map(([k, label, hint]) => (
                              <label key={k} className={clsx('flex cursor-pointer gap-2.5 rounded-xl border px-3 py-2 text-sm transition', target.section_mode === k ? 'border-brand-300 bg-brand-50/60 dark:border-brand-500/40 dark:bg-brand-500/10' : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/40')}>
                                <input type="radio" name="section_mode" checked={target.section_mode === k} onChange={() => setTarget((t) => ({ ...t, section_mode: k }))} className="mt-0.5 h-4 w-4 border-slate-300 text-brand-700" />
                                <span><span className="font-medium text-slate-800 dark:text-slate-100">{label}</span><span className="block text-xs text-slate-500">{hint}</span></span>
                              </label>
                            ))}
                          </div>
                        </Field>
                        {target.section_mode === 'same' && (
                          <Toggle checked={target.create_sections} onChange={(v) => setTarget((t) => ({ ...t, create_sections: v }))} label="Create missing sections" description="If the target semester has no matching section yet, create it with the same capacity and class teacher." />
                        )}
                        {target.section_mode === 'fixed' && (
                          <Select aria-label="Target section" value={target.to_section_id} onChange={(e) => setTarget((t) => ({ ...t, to_section_id: e.target.value }))} invalid={!!errors.to_section_id}
                            options={targetSections.map((s) => ({ value: s.id, label: `Section ${s.name} · ${s.strength}/${s.capacity}` }))} placeholder={targetSections.length ? 'Select section' : 'No sections in the target semester'} />
                        )}
                      </>
                    )}
                    <Field label="Remarks" htmlFor="pr-remarks">
                      <Input id="pr-remarks" value={target.remarks} maxLength={255} onChange={(e) => setTarget((t) => ({ ...t, remarks: e.target.value }))} placeholder="e.g. Odd semester results declared" />
                    </Field>
                  </CardBody>
                </Card>
              </Reveal>
            )}
          </div>

          <Card className="min-w-0 overflow-hidden">
            <CardHeader
              title={p ? `${p.program.short_name} · Semester ${p.semester}` : 'Students'}
              subtitle={p ? `${formatNumber(p.students.length)} active student${p.students.length === 1 ? '' : 's'} · minimum attendance ${p.min_attendance}%` : 'Choose a program and semester to load the class'}
              icon={Users}
              actions={p && p.students.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                  <Button size="xs" variant="soft" onClick={() => setAll(isFinal ? 'pass_out' : 'promote')}>All {isFinal ? 'pass out' : 'promote'}</Button>
                  <Button size="xs" variant="ghost" onClick={() => setAll('skip')}>Skip all</Button>
                </div>
              )}
            />
            {!ready ? (
              <EmptyState icon={Rocket} title="Choose the class to promote" description="Pick a program and its current semester on the left. Active students are listed with attendance and result flags so you can decide who moves up." />
            ) : preview.isLoading ? (
              <DataTable columns={cols} rows={[]} loading skeletonRows={6} />
            ) : preview.error ? (
              <div className="p-5"><Alert variant="error" title="Unable to load the class">{(preview.error as ApiError).message}</Alert></div>
            ) : !p?.students.length ? (
              <EmptyState icon={Users} title="No active students in this class" description="Everyone may already have been promoted, or the section is empty. Check the promotion history below." />
            ) : (
              <>
                <div className="max-h-[68vh] overflow-y-auto">
                  <DataTable<PromotionStudent> columns={cols} rows={p.students} dense caption="Students to promote" rowClassName={(s) => (actions[s.id] === 'skip' ? 'opacity-50' : actions[s.id] === 'detain' ? 'bg-amber-50/40 dark:bg-amber-500/5' : undefined)} />
                </div>
                <div className="sticky bottom-0 flex flex-col gap-3 border-t border-slate-100 bg-white/95 px-5 py-3.5 backdrop-blur sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900/95">
                  <div className="flex flex-wrap gap-1.5 text-xs">
                    {!isFinal && <Badge color="green" dot>{counts.promote} promote</Badge>}
                    {isFinal && <Badge color="blue" dot>{counts.pass_out} pass out</Badge>}
                    <Badge color="amber" dot>{counts.detain} detain</Badge>
                    <Badge color="slate" dot>{counts.skip} skip</Badge>
                    {errors.items && <span className="flex items-center gap-1 text-red-600"><AlertTriangle className="h-3.5 w-3.5" />{errors.items}</span>}
                  </div>
                  <Button icon={isFinal ? GraduationCap : CheckCircle2} loading={running} onClick={run}>
                    {isFinal ? `Mark ${counts.pass_out} as passed out` : `Promote ${counts.promote} student${counts.promote === 1 ? '' : 's'}`}
                  </Button>
                </div>
              </>
            )}
          </Card>
        </div>
      )}

      <Reveal>
        <Card className="overflow-hidden">
          <CardHeader title="Promotion history" subtitle="Every bulk promotion and pass-out run" icon={History} />
          {history.isLoading ? (
            <DataTable columns={historyCols} rows={[]} loading skeletonRows={4} />
          ) : history.data && history.data.rows.length ? (
            <>
              <DataTable<PromotionHistoryRow> columns={historyCols} rows={history.data.rows} dense caption="Promotion history" />
              {history.data.pages > 1 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={history.data.page} pages={history.data.pages} total={history.data.total} perPage={history.data.per_page} onPage={setHistoryPage} />}
            </>
          ) : (
            <EmptyState icon={Info} title="No promotions yet" description="Runs appear here with their reference number, outcome and who ran them." />
          )}
        </Card>
      </Reveal>
    </>
  );
}
