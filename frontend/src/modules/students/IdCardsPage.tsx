import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { CheckSquare, CreditCard, FlipHorizontal2, IdCard, Printer, Search, Square, UsersRound, X } from 'lucide-react';
import { Alert, Button, Card, CardBody, CardHeader, Combobox, EmptyState, Field, PageHeader, Reveal, Select, Skeleton, Tabs } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { printUrl } from '@/lib/config';
import { formatNumber } from '@/lib/format';
import type { Option } from '@/lib/types';
import { STUDENT_STATUSES } from './constants';
import { useAcademicTree, useTreeOptions } from './hooks';
import { IdCardBack, IdCardFront } from './components/IdCard';
import type { IdCardRow, InstituteInfo } from './types';

type Side = 'both' | 'front' | 'back';
const PAGE = 24;

export default function IdCardsPage() {
  const [params] = useSearchParams();
  const [mode, setMode] = useState<'class' | 'search'>(params.get('ids') ? 'search' : 'class');
  const [sel, setSel] = useState({ program_id: params.get('program_id') ?? '', semester: params.get('semester') ?? '', section_id: params.get('section_id') ?? '', batch_id: params.get('batch_id') ?? '', status: params.get('status') ?? 'active' });
  const [picked, setPicked] = useState<Option[]>(() => (params.get('ids') ?? '').split(',').filter(Boolean).map((v) => ({ value: Number(v), label: `#${v}` })));
  const [side, setSide] = useState<Side>('both');
  const [excluded, setExcluded] = useState<Set<number>>(new Set());
  const [shown, setShown] = useState(PAGE);
  const { data: tree } = useAcademicTree();
  const opts = useTreeOptions(tree, { program_id: sel.program_id, semester: sel.semester });

  const query = mode === 'class'
    ? { program_id: sel.program_id || undefined, semester: sel.semester || undefined, section_id: sel.section_id || undefined, batch_id: sel.batch_id || undefined, status: sel.status || 'all' }
    : { ids: picked.map((p) => p.value).join(',') || undefined };
  const enabled = mode === 'class' ? !!(sel.program_id || sel.section_id || sel.batch_id) : picked.length > 0;
  const q = useApi<{ rows: IdCardRow[]; total: number; limit: number; institute?: InstituteInfo }>(['students', 'id-cards', query], 'students/id-cards', query, { enabled, staleTime: 30_000 });
  const rows = useMemo(() => (enabled ? q.data?.rows ?? [] : []), [enabled, q.data]);

  useEffect(() => {
    setExcluded(new Set());
    setShown(PAGE);
  }, [q.data]);

  const included = rows.filter((r) => !excluded.has(r.id));
  const toggle = (id: number) => setExcluded((s) => {
    const n = new Set(s);
    if (n.has(id)) n.delete(id);
    else n.add(id);
    return n;
  });
  const setField = (k: keyof typeof sel, v: string) => setSel((s) => {
    const next = { ...s, [k]: v };
    if (k === 'program_id') Object.assign(next, { semester: '', section_id: '', batch_id: '' });
    if (k === 'semester') next.section_id = '';
    return next;
  });
  const print = () => window.open(printUrl('id-cards.php', { ids: included.map((r) => r.id).join(','), side }), '_blank');
  const perPage = side === 'both' ? 4 : 8;
  const sheets = Math.ceil(included.length / perPage);

  return (
    <>
      <PageHeader
        title="ID Cards"
        description="Generate printable student identity cards (CR80 size) — front with photo and details, back with address and a QR code of the student ID."
        breadcrumbs={[{ label: 'Students', to: '/students' }, { label: 'ID Cards' }]}
        actions={
          <Button icon={Printer} onClick={print} disabled={!included.length}>
            Print {included.length ? `${formatNumber(included.length)} card${included.length === 1 ? '' : 's'}` : 'sheet'}
          </Button>
        }
      />

      <div className="grid gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
        <div className="space-y-5">
          <Card>
            <CardHeader title="Choose students" icon={UsersRound} />
            <CardBody className="space-y-4">
              <Tabs variant="pills" value={mode} onChange={(m) => setMode(m as 'class' | 'search')} tabs={[{ key: 'class', label: 'By class' }, { key: 'search', label: 'Pick students' }]} className="w-full [&>button]:flex-1" />
              {mode === 'class' ? (
                <>
                  <Field label="Program" required htmlFor="ic-program">
                    <Select id="ic-program" value={sel.program_id} onChange={(e) => setField('program_id', e.target.value)} options={opts.programs} placeholder="Select program" />
                  </Field>
                  <div className="grid grid-cols-2 gap-3">
                    <Field label="Semester" htmlFor="ic-sem">
                      <Select id="ic-sem" value={sel.semester} onChange={(e) => setField('semester', e.target.value)} options={opts.semesters} placeholder="All" disabled={!sel.program_id} />
                    </Field>
                    <Field label="Section" htmlFor="ic-sec">
                      <Select id="ic-sec" value={sel.section_id} onChange={(e) => setField('section_id', e.target.value)} options={opts.sections} placeholder="All" disabled={!sel.program_id} />
                    </Field>
                  </div>
                  <Field label="Batch" htmlFor="ic-batch">
                    <Select id="ic-batch" value={sel.batch_id} onChange={(e) => setField('batch_id', e.target.value)} options={opts.batches} placeholder="All batches" disabled={!sel.program_id} />
                  </Field>
                  <Field label="Status" htmlFor="ic-status">
                    <Select id="ic-status" value={sel.status} onChange={(e) => setField('status', e.target.value)} options={STUDENT_STATUSES} placeholder="Any status" />
                  </Field>
                </>
              ) : (
                <>
                  <Field label="Search students" hint="Name, student ID, roll or admission number.">
                    <Combobox multiple source="students" value={picked.map((p) => p.value)} onChange={(vals) => setPicked((prev) => vals.map((v) => prev.find((p) => String(p.value) === String(v)) ?? { value: v, label: String(v) }))} placeholder="Type to search…" />
                  </Field>
                  {picked.length > 0 && (
                    <button type="button" onClick={() => setPicked([])} className="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-red-600">
                      <X className="h-3.5 w-3.5" /> Clear {picked.length} selected
                    </button>
                  )}
                </>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Print layout" icon={FlipHorizontal2} />
            <CardBody className="space-y-3">
              {([
                ['both', 'Front + back', 'Each row shows a card front and its back — cut and laminate together. 4 students (8 faces) per A4 sheet.'],
                ['front', 'Fronts only', '8 card fronts per A4 sheet (print backs separately for duplex).'],
                ['back', 'Backs only', '8 card backs per A4 sheet, mirrored for duplex printing.'],
              ] as [Side, string, string][]).map(([k, label, hint]) => (
                <label key={k} className={clsx('flex cursor-pointer gap-3 rounded-xl border p-3 transition', side === k ? 'border-brand-300 bg-brand-50/60 dark:border-brand-500/40 dark:bg-brand-500/10' : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50')}>
                  <input type="radio" name="side" value={k} checked={side === k} onChange={() => setSide(k)} className="mt-0.5 h-4 w-4 border-slate-300 text-brand-700" />
                  <span className="text-sm">
                    <span className="font-semibold text-slate-800 dark:text-slate-100">{label}</span>
                    <span className="block text-xs text-slate-500 dark:text-slate-400">{hint}</span>
                  </span>
                </label>
              ))}
              {included.length > 0 && (
                <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
                  {formatNumber(included.length)} card{included.length === 1 ? '' : 's'} → <strong>{sheets}</strong> A4 sheet{sheets === 1 ? '' : 's'} with crop marks
                </p>
              )}
            </CardBody>
          </Card>
        </div>

        <div className="min-w-0">
          <Card className="min-h-[420px]">
            <CardHeader
              title="Preview"
              subtitle={rows.length ? `${formatNumber(included.length)} of ${formatNumber(rows.length)} selected${q.data && q.data.total > rows.length ? ` · showing first ${q.data.limit} of ${formatNumber(q.data.total)}` : ''}` : 'Cards appear here as you choose students'}
              icon={IdCard}
              actions={rows.length > 0 && (
                <div className="flex gap-1.5">
                  <Button size="sm" variant="ghost" icon={CheckSquare} onClick={() => setExcluded(new Set())}>All</Button>
                  <Button size="sm" variant="ghost" icon={Square} onClick={() => setExcluded(new Set(rows.map((r) => r.id)))}>None</Button>
                </div>
              )}
            />
            <CardBody>
              {!enabled ? (
                <EmptyState icon={CreditCard} title="Choose a class or pick students" description="Select a program (optionally semester, section or batch), or search for individual students to preview their ID cards." />
              ) : q.isLoading ? (
                <div className="grid gap-5 xl:grid-cols-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="aspect-[85.6/54] w-full rounded-xl" />)}</div>
              ) : q.error ? (
                <Alert variant="error" title="Unable to load students">{(q.error as Error).message}</Alert>
              ) : !rows.length ? (
                <EmptyState icon={Search} title="No students match" description="Try another section, batch or status." />
              ) : (
                <>
                  <ul className="grid gap-x-6 gap-y-7 2xl:grid-cols-2">
                    {rows.slice(0, shown).map((r, i) => {
                      const on = !excluded.has(r.id);
                      return (
                        <Reveal as="li" key={r.id} delay={Math.min(i % PAGE, 8) * 40}>
                          <label className="mb-2 flex cursor-pointer items-center gap-2 text-sm">
                            <input type="checkbox" className="form-checkbox" checked={on} onChange={() => toggle(r.id)} />
                            <span className={clsx('font-medium', on ? 'text-slate-800 dark:text-slate-100' : 'text-slate-400 line-through')}>{r.full_name}</span>
                            <span className="text-xs text-slate-500">{r.student_uid}</span>
                          </label>
                          <div className={clsx('grid gap-3 transition duration-300 sm:grid-cols-2', !on && 'opacity-40 grayscale', side !== 'both' && '!grid-cols-1 sm:max-w-sm')}>
                            {side !== 'back' && <div className="transition duration-300 hover:-translate-y-0.5"><IdCardFront s={r} institute={q.data?.institute} /></div>}
                            {side !== 'front' && <div className="transition duration-300 hover:-translate-y-0.5"><IdCardBack s={r} institute={q.data?.institute} /></div>}
                          </div>
                        </Reveal>
                      );
                    })}
                  </ul>
                  {rows.length > shown && (
                    <div className="mt-6 text-center">
                      <Button variant="secondary" onClick={() => setShown((n) => n + PAGE)}>Show more ({formatNumber(rows.length - shown)} remaining)</Button>
                    </div>
                  )}
                </>
              )}
            </CardBody>
          </Card>
        </div>
      </div>
    </>
  );
}
