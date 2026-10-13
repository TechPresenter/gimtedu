import clsx from 'clsx';
import { ArrowLeft, ArrowRight, BadgePercent, Check, ChevronDown, Info, Phone, RotateCcw, Sparkles } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';
import { inr, openEnquiry, type Option } from './home/shared';

interface Rules {
  minPct?: number;
  maxPct?: number;
  incomeMax?: number;
  specials?: string[];
  categories?: string[];
}

interface Scheme {
  id: number;
  name: string;
  type: 'percentage' | 'fixed';
  value: number;
  max: number | null;
  category: string;
  criteria: string;
  rules: Rules;
  benefit: string;
}

interface ProgramOption {
  value: string;
  label: string;
  level: string;
  fee: number;
  per: 'year' | 'course';
}

interface IncomeBand extends Option {
  max: number | null;
}

export interface ScholarshipAssistantProps extends IslandBaseProps {
  schemes: Scheme[];
  levels: Option[];
  programs: ProgramOption[];
  /** Score bands derived from the merit thresholds (value = band floor, "0" = below the lowest). */
  percentBands: Option[];
  /** Income bands derived from the income limits (max = exclusive upper bound; null = above / undisclosed). */
  incomeBands: IncomeBand[];
  specials: Option[];
  /** Social categories — only when a scheme's criteria mention SC / ST / OBC. */
  categories: string[];
  currency?: string;
  callbackUrl: string;
  applyUrl: string;
  detailsUrl?: string | null;
  maxPercent?: number | null;
}

type StepKey = 'course' | 'score' | 'category' | 'income' | 'special';

interface Answers {
  level: string;
  program: string;
  pct: string;
  category: string;
  income: string;
  specials: string[];
}

const EMPTY: Answers = { level: '', program: '', pct: '', category: '', income: '', specials: [] };

/**
 * Scholarship Assistant: 3–5 eligibility questions built from the real scholarships table (score bands from the
 * merit thresholds, income bands from the income limits, special categories from the criteria) → matching schemes with
 * their benefit, cap and an indicative saving on the chosen program, plus "Request a Callback" / "Apply" CTAs.
 * Indicative only — the admissions office confirms eligibility.
 */
export default function ScholarshipAssistant({
  schemes,
  levels,
  programs,
  percentBands,
  incomeBands,
  specials,
  categories,
  currency = '₹',
  callbackUrl,
  applyUrl,
  detailsUrl,
  maxPercent,
}: ScholarshipAssistantProps) {
  const uid = useId();
  const isStatic = useStaticMode();
  const steps = useMemo<StepKey[]>(
    () => ['course', ...(percentBands.length ? (['score'] as StepKey[]) : []), ...(categories.length ? (['category'] as StepKey[]) : []), ...(incomeBands.length ? (['income'] as StepKey[]) : []), ...(specials.length ? (['special'] as StepKey[]) : [])],
    [percentBands.length, categories.length, incomeBands.length, specials.length],
  );
  const [step, setStep] = useState(0); // steps.length = results
  const [a, setA] = useState<Answers>(EMPTY);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    headingRef.current?.focus({ preventScroll: true });
  }, [step]);

  const done = step >= steps.length;
  const key = steps[step];
  const program = programs.find((p) => p.value === a.program) || null;

  const canNext = key === 'course' ? !!a.level : key === 'score' ? !!a.pct : key === 'category' ? !!a.category : key === 'income' ? !!a.income : true;

  const eligible = (r: Rules): boolean => {
    const keys = Object.keys(r);
    if (!keys.length) return false;
    if (r.minPct !== undefined) {
      if (a.pct === '') return false;
      const p = parseFloat(a.pct);
      if (p < r.minPct || (r.maxPct !== undefined && p > r.maxPct)) return false;
    }
    if (r.incomeMax !== undefined) {
      const band = incomeBands.find((b) => b.value === a.income);
      if (!band || band.max === null || band.max > r.incomeMax) return false;
    }
    if (r.specials?.length && !r.specials.some((s) => a.specials.includes(s))) return false;
    if (r.categories?.length && !r.categories.includes(a.category)) return false;
    return true;
  };

  const estimate = (s: Scheme): number | null => {
    if (!program || program.fee <= 0) return null;
    if (s.type === 'fixed') return null;
    const v = (program.fee * s.value) / 100;
    return s.max !== null ? Math.min(v, s.max) : v;
  };

  const matched = done
    ? schemes
        .filter((s) => eligible(s.rules))
        .map((s) => ({ s, est: estimate(s) }))
        .sort((x, y) => (y.est ?? y.s.value) - (x.est ?? x.s.value))
    : [];
  const askAbout = done ? schemes.filter((s) => !Object.keys(s.rules).length) : [];

  const go = (n: number) => setStep(Math.max(0, Math.min(steps.length, n)));
  const restart = () => {
    setA(EMPTY);
    setStep(0);
  };

  const radio = (name: keyof Answers, list: Option[], cols = 'sm:grid-cols-2') => (
    <div className={clsx('grid gap-2.5', cols)}>
      {list.map((o) => {
        const checked = a[name] === o.value;
        return (
          <label
            key={o.value}
            className={clsx(
              'relative flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3.5 text-[14.5px] font-semibold transition focus-within:ring-2 focus-within:ring-accent-500 focus-within:ring-offset-2',
              checked ? 'border-brand-900 bg-brand-900 text-white' : 'border-warm-2 bg-white text-brand-900 hover:border-[#E2DAD0] hover:bg-[#FAF8F5]',
            )}
          >
            <input
              type="radio"
              name={`${uid}-${name}`}
              value={o.value}
              checked={checked}
              onChange={() => setA((prev) => ({ ...prev, [name]: o.value, ...(name === 'level' && prev.level !== o.value ? { program: '' } : {}) }))}
              className="sr-only"
            />
            <span className={clsx('inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2', checked ? 'border-white bg-white text-brand-900' : 'border-slate-300')} aria-hidden="true">
              {checked && <Check className="h-3 w-3" strokeWidth={3} />}
            </span>
            {o.label}
          </label>
        );
      })}
    </div>
  );

  const titles: Record<StepKey, string> = {
    course: 'Which course are you applying for?',
    score: 'What did you score in your last qualifying exam?',
    category: 'Which category do you belong to?',
    income: 'What is your annual family income?',
    special: 'Do any of these apply to you?',
  };
  const hints: Record<StepKey, string> = {
    course: 'Pick the level; choosing a program lets us estimate your saving.',
    score: 'Class XII for undergraduate programs, graduation for postgraduate programs.',
    category: 'Only used to match category-based schemes.',
    income: 'Income-based fee support depends on the family income certificate.',
    special: 'Select all that apply — or none.',
  };

  return (
    <div className="grid grid-cols-1 overflow-hidden rounded-[30px] border border-warm-2 bg-white shadow-[0_24px_60px_-44px_rgba(7,26,59,.5)] lg:grid-cols-12">
      <div className="relative isolate overflow-hidden bg-brand-900 p-7 text-white sm:p-9 lg:col-span-5">
        <span className="pointer-events-none absolute -right-16 -top-16 -z-10 h-56 w-56 rounded-full bg-accent-500/25 blur-3xl" aria-hidden="true" />
        <span className="pointer-events-none absolute -bottom-10 -left-10 -z-10 h-40 w-40 rounded-full border-[18px] border-white/[.06]" aria-hidden="true" />
        <p className="eyebrow !text-accent-300">Scholarship Assistant</p>
        <h3 className="mt-3 font-display text-[1.7rem] font-extrabold leading-tight text-white">Find the scholarships you may qualify for</h3>
        <p className="mt-3 text-[15px] leading-relaxed text-white/75">
          Answer {steps.length} quick {steps.length === 1 ? 'question' : 'questions'} — your course{steps.includes('score') ? ', last exam score' : ''}
          {steps.includes('income') ? ', family income' : ''}
          {steps.includes('special') ? ' and any special category' : ''} — to see matching schemes.
        </p>
        {maxPercent ? (
          <div className="mt-6 flex items-end gap-3">
            <p className="font-display text-[2.6rem] font-extrabold leading-none text-white">{maxPercent}%</p>
            <p className="pb-1 text-[14px] leading-snug text-white/70">highest merit scholarship on tuition</p>
          </div>
        ) : null}
        <ol className="mt-7 hidden space-y-2.5 lg:block" aria-label="Questions">
          {steps.map((k, i) => (
            <li key={k} className={clsx('flex items-center gap-3 text-[14px] transition-colors', i === step ? 'font-semibold text-white' : i < step || done ? 'text-white/70' : 'text-white/45')}>
              <span
                className={clsx(
                  'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[12px] font-bold',
                  i < step || done ? 'bg-accent-500 text-white' : i === step ? 'bg-white text-brand-900' : 'bg-white/10 text-white/60',
                )}
                aria-hidden="true"
              >
                {i < step || done ? <Check className="h-3.5 w-3.5" strokeWidth={3} /> : i + 1}
              </span>
              {{ course: 'Your course', score: 'Exam score', category: 'Category', income: 'Family income', special: 'Special categories' }[k]}
            </li>
          ))}
        </ol>
        <p className="mt-7 flex gap-2 text-[12.5px] leading-relaxed text-white/60">
          <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          Indicative only — eligibility and the final concession are confirmed by the admissions office after document verification.
        </p>
      </div>

      <div className="flex min-h-[460px] flex-col p-6 sm:p-8 lg:col-span-7">
        {!done ? (
          <>
            <div className="flex items-center justify-between gap-3">
              <p className="text-[12.5px] font-bold uppercase tracking-[0.12em] text-accent-700">
                Question {step + 1} of {steps.length}
              </p>
              <div className="h-1.5 w-32 overflow-hidden rounded-full bg-warm" aria-hidden="true">
                <span className="block h-full origin-left rounded-full bg-accent-600 transition-transform duration-500" style={{ transform: `scaleX(${(step + 1) / steps.length})` }} />
              </div>
            </div>
            <fieldset className="mt-4 flex-1" key={key}>
              <legend className="contents">
                <h4 ref={headingRef} tabIndex={-1} className="font-display text-[1.3rem] font-extrabold leading-snug text-brand-900 outline-none">
                  {titles[key]}
                </h4>
              </legend>
              <p className="mt-1.5 text-[14px] text-slate-500">{hints[key]}</p>
              <div className={clsx('mt-5', !isStatic && 'animate-fade-up')}>
                {key === 'course' && (
                  <>
                    {radio('level', levels)}
                    {a.level && programs.some((p) => p.level === a.level) && (
                      <label className="relative mt-4 block">
                        <span className="form-label">Program (optional)</span>
                        <select
                          value={a.program}
                          onChange={(e) => setA((prev) => ({ ...prev, program: e.target.value }))}
                          className="h-12 w-full appearance-none rounded-xl border border-warm-2 bg-white pl-4 pr-10 text-[14.5px] font-semibold text-brand-900 focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10"
                        >
                          <option value="">Not decided yet</option>
                          {programs
                            .filter((p) => p.level === a.level)
                            .map((p) => (
                              <option key={p.value} value={p.value}>
                                {p.label}
                              </option>
                            ))}
                        </select>
                        <ChevronDown className="pointer-events-none absolute bottom-4 right-4 h-4 w-4 text-slate-400" aria-hidden="true" />
                      </label>
                    )}
                  </>
                )}
                {key === 'score' && radio('pct', percentBands, 'sm:grid-cols-3')}
                {key === 'category' && radio('category', categories.map((c) => ({ value: c, label: c })), 'grid-cols-2 sm:grid-cols-4')}
                {key === 'income' && radio('income', incomeBands)}
                {key === 'special' && (
                  <div className="grid gap-2.5 sm:grid-cols-2">
                    {specials.map((o) => {
                      const checked = a.specials.includes(o.value);
                      return (
                        <label
                          key={o.value}
                          className={clsx(
                            'flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3.5 text-[14.5px] font-semibold transition focus-within:ring-2 focus-within:ring-accent-500 focus-within:ring-offset-2',
                            checked ? 'border-brand-900 bg-brand-900 text-white' : 'border-warm-2 bg-white text-brand-900 hover:border-[#E2DAD0] hover:bg-[#FAF8F5]',
                          )}
                        >
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => setA((prev) => ({ ...prev, specials: checked ? prev.specials.filter((s) => s !== o.value) : [...prev.specials, o.value] }))}
                            className="sr-only"
                          />
                          <span className={clsx('inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2', checked ? 'border-white bg-white text-brand-900' : 'border-slate-300')} aria-hidden="true">
                            {checked && <Check className="h-3 w-3" strokeWidth={3} />}
                          </span>
                          {o.label}
                        </label>
                      );
                    })}
                  </div>
                )}
              </div>
            </fieldset>
            <div className="mt-8 flex items-center justify-between gap-3">
              <button type="button" onClick={() => go(step - 1)} disabled={step === 0} className="btn btn-ghost">
                <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                Back
              </button>
              <button type="button" onClick={() => go(step + 1)} disabled={!canNext} className="btn btn-apply">
                {step === steps.length - 1 ? 'See my scholarships' : 'Next'}
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          </>
        ) : (
          <div className={clsx('flex flex-1 flex-col', !isStatic && 'animate-fade-up')} aria-live="polite">
            <p className="text-[12.5px] font-bold uppercase tracking-[0.12em] text-accent-700">Your results</p>
            <h4 ref={headingRef} tabIndex={-1} className="mt-1 font-display text-[1.35rem] font-extrabold leading-snug text-brand-900 outline-none">
              {matched.length
                ? `You may be eligible for ${matched.length} ${matched.length === 1 ? 'scholarship' : 'scholarships'}`
                : 'No scheme matches your answers right now'}
            </h4>
            {matched.length > 0 ? (
              <ul className="mt-5 space-y-3">
                {matched.map(({ s, est }, i) => (
                  <li key={s.id} className={clsx('rounded-2xl border p-4', i === 0 ? 'border-accent-200 bg-accent-50/60' : 'border-warm-2 bg-white')}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="font-display text-[16px] font-bold text-brand-900">
                          {i === 0 && <Sparkles className="mr-1.5 inline h-4 w-4 -translate-y-px text-accent-600" aria-hidden="true" />}
                          {s.name}
                        </p>
                        <p className="mt-1 text-[13.5px] leading-relaxed text-slate-600">{s.criteria}</p>
                      </div>
                      <div className="shrink-0 text-right">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-900 px-3 py-1 text-[13px] font-bold text-white">
                          <BadgePercent className="h-3.5 w-3.5" aria-hidden="true" />
                          {s.benefit}
                        </span>
                        {s.max !== null && <p className="mt-1 text-[12px] font-semibold text-slate-500">up to {inr(s.max, currency)}</p>}
                      </div>
                    </div>
                    {est !== null && program && (
                      <p className="mt-3 rounded-xl bg-white px-3 py-2 text-[13.5px] text-brand-900 ring-1 ring-inset ring-warm-2">
                        Indicative saving on {program.label}: <strong className="font-display font-extrabold text-accent-700">≈ {inr(est, currency)}</strong> per {program.per}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-3 text-[14.5px] leading-relaxed text-slate-600">
                That doesn’t rule you out — schemes are reviewed every session, and instalment options are available. Talk to a counsellor for options that fit your situation.
              </p>
            )}
            {askAbout.length > 0 && (
              <p className="mt-4 text-[13.5px] text-slate-600">
                Also ask the admissions office about: <span className="font-semibold text-brand-900">{askAbout.map((s) => s.name).join(', ')}</span>.
              </p>
            )}
            <p className="mt-4 flex gap-2 text-[12.5px] leading-relaxed text-slate-500">
              <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              Indicative only — confirmed by the admissions office after document verification.
            </p>
            <div className="mt-auto flex flex-wrap items-center gap-3 pt-6">
              <button
                type="button"
                className="btn btn-callback"
                onClick={() =>
                  openEnquiry(callbackUrl, {
                    level: a.level || undefined,
                    program: a.program || undefined,
                    note: matched.length ? `Scholarship enquiry: ${matched.map((m) => m.s.name).join(', ')}` : 'Scholarship enquiry',
                  })
                }
              >
                <Phone className="h-4 w-4" aria-hidden="true" />
                Request a Callback
              </button>
              <a href={a.program ? `${applyUrl}?program=${encodeURIComponent(a.program)}` : applyUrl} className="btn btn-apply">
                Apply Now
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
              </a>
              <button type="button" className="btn btn-ghost btn-sm" onClick={restart}>
                <RotateCcw className="h-4 w-4" aria-hidden="true" />
                Start over
              </button>
              {detailsUrl && (
                <a href={detailsUrl} className="link-arrow ml-auto text-[14px]">
                  All scholarship rules
                  <ArrowRight className="h-4 w-4" aria-hidden="true" />
                </a>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
