import clsx from 'clsx';
import { ArrowRight, CalendarDays, ChevronDown, CircleCheck, CirclePlay, Quote, Star } from 'lucide-react';
import { formatDate, formatNumber } from '@/lib/format';
import { CmsIcon } from '../icons';
import type { Data, HomeSection } from '../types';
import { CtaBlock, PlacementBlock, StatsRow } from './BlockPreview';
import { Band, Heading, Img, Pill, ProgramCard, RecruiterLogo, hasLink, paragraphs, spotlightSlides, tintFor, useCols, usePreview } from './kit';

const arr = (v: unknown): Data[] => (Array.isArray(v) ? (v as Data[]) : []);
const categoryLabel: Record<string, string> = { all: 'All Programs', ug: 'Undergraduate', pg: 'Postgraduate', management: 'Management', technology: 'Technology', commerce: 'Commerce', diploma: 'Diploma', certificate: 'Certificate' };

/** Approximation of one home page section on the public website (Design v3 — includes/partials/home.php). */
export function SectionPreview({ section }: { section: Pick<HomeSection, 'key' | 'title' | 'subtitle' | 'content'> }) {
  const { key, title, subtitle } = section;
  const c = section.content ?? {};
  switch (key) {
    case 'hero':
      return <HeroSection title={title} subtitle={subtitle} c={c} />;
    case 'stats':
      return <StatsSection title={title} subtitle={subtitle} c={c} />;
    case 'features':
      return <FeaturesSection c={c} />;
    case 'programs':
      return <ProgramsSection title={title} subtitle={subtitle} c={c} />;
    case 'why':
      return <WhySection title={title} subtitle={subtitle} c={c} />;
    case 'academic_excellence':
      return <AboutSection title={title} subtitle={subtitle} c={c} />;
    case 'admission_process':
      return <JourneySection title={title} subtitle={subtitle} c={c} />;
    case 'campus_life':
      return <CampusSection title={title} subtitle={subtitle} c={c} />;
    case 'placements':
      return <PlacementBlock d={{ ...c, heading: title, subheading: subtitle, show_offers: c.show_top_offers, show_recruiters: false }} bg="light" pad="normal" />;
    case 'recruiters':
      return <RecruitersSection title={title} subtitle={subtitle} c={c} />;
    case 'testimonials':
      return <TestimonialsSection title={title} subtitle={subtitle} c={c} />;
    case 'events_news':
      return <EventsSection title={title} subtitle={subtitle} c={c} />;
    case 'faqs':
      return <FaqsSection title={title} subtitle={subtitle} c={c} />;
    case 'cta':
      return <CtaSection title={title} subtitle={subtitle} c={c} />;
    default:
      return <Band><p className="text-sm text-slate-400">Unknown section.</p></Band>;
  }
}

type P = { title?: string | null; subtitle?: string | null; c: Data };

function HeroSection({ title, subtitle, c }: P) {
  const { mobile, data } = usePreview();
  // Same rules as the website (includes/partials/home.php): live "home hero" banners with a button rotate in the
  // spotlight card next to the hero copy (max 4); a banner repeating the hero headline is skipped.
  const spot = c.use_banners ? spotlightSlides(data?.banners ?? [], title) : [];
  const s = spot[0];
  const words: string[] = Array.isArray(c.tagline_words) ? c.tagline_words : [];
  return (
    <section className="relative overflow-hidden bg-[#FAF8F5] bg-[linear-gradient(#0B2A5B0a_1px,transparent_1px),linear-gradient(90deg,#0B2A5B0a_1px,transparent_1px)] [background-size:32px_32px]">
      <div className={clsx('relative mx-auto grid items-center gap-12', mobile ? 'grid-cols-1 px-5 pb-16 pt-10' : 'max-w-[1160px] grid-cols-[1.15fr_1fr] px-10 pb-20 pt-14')}>
        <div>
          {!!c.eyebrow && (
            <p className="inline-flex items-center gap-2 rounded-full border border-[#EDE8E2] bg-white py-1 pl-1 pr-3.5 text-[11px] font-bold uppercase tracking-[0.14em] text-[#0B2A5B] shadow-sm">
              <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-[#0B2A5B] text-white"><CmsIcon name="sparkles" className="h-3 w-3" /></span>
              {c.eyebrow}
            </p>
          )}
          <h1 className={clsx('mt-5 font-display font-extrabold leading-[1.04] tracking-[-0.035em] text-[#0B2A5B]', mobile ? 'text-[40px]' : 'text-[62px]')}>
            <span className="block">{title || 'Headline'}</span>
            {!!c.highlight && <span className="block bg-gradient-to-r from-[#D03E16] via-[#F05A28] to-[#F37A52] bg-clip-text text-transparent">{c.highlight}</span>}
          </h1>
          {!!subtitle && <p className="mt-5 max-w-xl text-[17px] leading-relaxed text-[#4A5568]">{subtitle}</p>}
          {!!c.badge && (
            <p className="mt-6 inline-flex items-center gap-3 rounded-2xl border border-[#F8C9B8] bg-white px-4 py-2.5 text-sm font-semibold text-[#0B2A5B] shadow-sm">
              <span className="h-2.5 w-2.5 rounded-full bg-[#D03E16]" />
              {c.badge}
              <ArrowRight className="h-4 w-4 text-[#D03E16]" />
            </p>
          )}
          <div className="mt-7 flex flex-wrap gap-3">
            <Pill link={c.primary_cta} />
            <Pill link={c.secondary_cta} variant="ink" />
            {!!c.video_url && (
              <span className="inline-flex items-center gap-2 rounded-full border border-[#0B2A5B]/20 bg-white px-4 py-2.5 text-sm font-semibold text-[#0B2A5B]">
                <CirclePlay className="h-4 w-4 text-[#D03E16]" />
                {c.video_label || 'Watch Our Campus Tour'}
              </span>
            )}
          </div>
          {words.length > 0 && (
            <p className="mt-6 flex flex-wrap items-center gap-x-2.5 text-[12px] font-bold uppercase tracking-[0.18em] text-[#64748B]">
              {words.map((w, i) => (
                <span key={i} className="inline-flex items-center gap-2.5">
                  {i > 0 && <span className="h-1 w-1 rounded-full bg-[#F05A28]" />}
                  {w}
                </span>
              ))}
            </p>
          )}
        </div>
        <div className="relative">
          <Img src={c.image} alt={c.image_alt} className="aspect-[5/4.4] w-full rounded-[32px] shadow-[0_30px_60px_-30px_rgba(11,42,91,.55)]" />
          {arr(c.chips).slice(0, 2).map((ch, i) => (
            <span key={i} className={clsx('absolute inline-flex items-center gap-2 rounded-full bg-white/90 py-1.5 pl-1.5 pr-3.5 text-xs font-bold text-[#0B2A5B] shadow-lg', i === 0 ? 'right-4 top-5' : 'left-[-12px] top-1/3')}>
              <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[#D03E16] text-white"><CmsIcon name={ch.icon} className="h-3.5 w-3.5" /></span>
              {ch.label}
            </span>
          ))}
          {s && (
            <div className={clsx('absolute rounded-2xl bg-white p-3.5 shadow-[0_20px_50px_-20px_rgba(11,42,91,.45)]', mobile ? '-bottom-10 left-3 right-3' : '-bottom-10 -left-10 w-[330px]')}>
              <div className="flex items-center gap-3">
                <Img src={s.mobile_image || s.desktop_image} alt={s.image_alt ?? ''} className="h-14 w-14 shrink-0 rounded-xl" />
                <div className="min-w-0">
                  {!!s.eyebrow && <p className="truncate text-[10px] font-bold uppercase tracking-[0.14em] text-[#C93C14]">{s.eyebrow}</p>}
                  <p className="truncate font-display text-[15px] font-bold text-[#0B2A5B]">{s.title}</p>
                  <p className="text-xs font-semibold text-[#4A5568]">{s.cta_text || s.cta2_text} →</p>
                </div>
              </div>
              <p className="mt-2.5 flex items-center justify-between text-[10px] font-bold uppercase tracking-[0.16em] text-[#64748B]">
                {spot.length} highlight{spot.length === 1 ? '' : 's'}
                <span className="flex gap-1">
                  <span className="h-5 w-5 rounded-full border border-[#EDE8E2]" />
                  <span className="h-5 w-5 rounded-full border border-[#0B2A5B]/30" />
                </span>
              </p>
            </div>
          )}
        </div>
      </div>
      {!!c.use_banners && (
        <p className="absolute right-3 top-3 rounded-full bg-white px-2.5 py-1 text-[10px] font-semibold text-[#0B2A5B] shadow">
          {spot.length ? `Spotlight · ${spot.length} live banner${spot.length === 1 ? '' : 's'}` : 'No live hero banner with a button'}
        </p>
      )}
    </section>
  );
}

function StatsSection({ title, subtitle, c }: P) {
  const items = arr(c.items);
  if (c.variant === 'band') {
    return (
      <Band bg="dark" grid>
        <Heading title={title} sub={subtitle} dark center />
        <StatsRow items={items} variant="band" dark />
      </Band>
    );
  }
  return (
    <section className="relative bg-white px-6 py-8">
      <div className={clsx('mx-auto max-w-[1060px]', c.variant === 'overlap' && 'rounded-[26px] border border-[#EDE8E2] bg-white p-6 shadow-[0_20px_50px_-20px_rgba(0,0,0,.25)]')}>
        {!!title && c.variant !== 'overlap' && <Heading title={title} sub={subtitle} />}
        <StatsRow items={items} variant={c.variant === 'cards' ? 'cards' : 'inline'} />
      </div>
    </section>
  );
}

function FeaturesSection({ c }: { c: Data }) {
  const { mobile } = usePreview();
  const items = arr(c.items);
  return (
    <section className="border-y border-[#EDE8E2] bg-white px-6 py-6">
      <div className={clsx('mx-auto grid max-w-[1160px] gap-4', mobile ? 'grid-cols-2' : items.length > 4 ? 'grid-cols-6' : 'grid-cols-4')}>
        {items.map((f, i) => (
          <div key={i} className="flex items-center gap-2.5">
            <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#D03E16]/10 text-[#D03E16]"><CmsIcon name={f.icon} className="h-5 w-5" /></span>
            <span className="text-[13px] font-semibold leading-tight text-[#0B2A5B]">{f.title}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

function ProgramsSection({ title, subtitle, c }: P) {
  const { data } = usePreview();
  const cols = useCols(3);
  let list = data?.programs ?? [];
  if (c.mode === 'featured') list = list.filter((p) => p.is_featured);
  if (c.mode === 'selected') {
    const ids: number[] = Array.isArray(c.program_ids) ? c.program_ids.map(Number) : [];
    list = ids.map((id) => (data?.programs ?? []).find((p) => p.id === id)).filter(Boolean) as typeof list;
  }
  list = list.slice(0, Number(c.limit) || 6);
  const cats: string[] = Array.isArray(c.categories) ? c.categories : [];
  return (
    <Band>
      <div className="flex items-end justify-between gap-4">
        <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} />
        {hasLink(c.view_all) && <div className="mb-7 shrink-0"><Pill link={c.view_all} variant="outline" small /></div>}
      </div>
      {cats.length > 0 && (
        <div className="mb-6 flex flex-wrap gap-2">
          {cats.map((k, i) => <span key={k} className={clsx('rounded-full px-3.5 py-1.5 text-xs font-semibold', i === 0 ? 'bg-[#0B2A5B] text-white' : 'border border-black/15 text-[#0B2A5B]')}>{categoryLabel[k] ?? k}</span>)}
        </div>
      )}
      <div className={clsx('grid gap-5', cols)}>{list.map((p) => <ProgramCard key={p.id} p={p} />)}</div>
      {!list.length && <p className="text-sm text-slate-400">No programs match these settings.</p>}
    </Band>
  );
}

function WhySection({ title, subtitle, c }: P) {
  const { mobile } = usePreview();
  return (
    <Band>
      <div className={clsx('grid gap-10', mobile ? 'grid-cols-1' : 'grid-cols-[minmax(0,1fr)_minmax(0,2fr)] items-start')}>
        <div>
          <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} />
          {!!c.image && <Img src={c.image} className="aspect-[4/3] w-full rounded-[24px]" />}
        </div>
        <div className={clsx('grid gap-4', mobile ? 'grid-cols-1' : 'grid-cols-2')}>
          {arr(c.items).map((it, i) => (
            <div key={i} className="relative rounded-[20px] border border-[#EDE8E2] bg-[#FAF8F5] p-5">
              <span className="absolute right-4 top-3 text-[11px] font-bold text-[#0B2A5B]/25">{String(i + 1).padStart(2, '0')}</span>
              <span className={clsx('inline-flex h-11 w-11 items-center justify-center rounded-xl text-[#0B2A5B]', tintFor(it.tint, i))}><CmsIcon name={it.icon} className="h-5 w-5" /></span>
              <h3 className="mt-4 font-display text-[17px] font-bold leading-snug text-[#0B2A5B]">{it.title}</h3>
              {!!it.text && <p className="mt-1 text-[13px] leading-relaxed text-[#4A5568]">{it.text}</p>}
            </div>
          ))}
        </div>
      </div>
    </Band>
  );
}

function AboutSection({ title, subtitle, c }: P) {
  const { mobile } = usePreview();
  return (
    <Band>
      <div className={clsx('grid items-center gap-12', mobile ? 'grid-cols-1' : 'grid-cols-2')}>
        <div>
          <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} />
          <div className="space-y-3 text-[15px] leading-relaxed text-[#4A5568]">{paragraphs(c.text).map((p, i) => <p key={i}>{p}</p>)}</div>
          {arr(c.bullets).length > 0 && (
            <ul className="mt-5 grid grid-cols-1 gap-2">
              {(c.bullets as string[]).map((b, i) => <li key={i} className="flex items-center gap-2 text-sm font-medium text-[#0B2A5B]"><CircleCheck className="h-4 w-4 text-[#D03E16]" />{b}</li>)}
            </ul>
          )}
          <div className="mt-6"><Pill link={c.cta} variant="ink" /></div>
        </div>
        <div className="relative pb-16">
          <Img src={c.image} alt={c.image_alt} className="aspect-[4/3] w-full rounded-[28px]" />
          {!!c.video_url && !!c.video_label && (
            <span className="absolute left-4 top-4 inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-[#0B2A5B] shadow"><CirclePlay className="h-4 w-4 text-[#D03E16]" />{c.video_label}</span>
          )}
          {arr(c.cards).length > 0 && (
            <div className={clsx('absolute bottom-0 left-6 right-6 grid gap-3', arr(c.cards).length > 1 ? 'grid-cols-2' : 'grid-cols-1')}>
              {arr(c.cards).slice(0, 2).map((card, i) => (
                <div key={i} className="rounded-2xl bg-white p-4 shadow-xl">
                  <p className="flex items-center gap-2 font-display text-base font-bold text-[#0B2A5B]"><CmsIcon name={card.icon} className="h-4 w-4 text-[#D03E16]" />{card.title}</p>
                  <p className="mt-1 line-clamp-3 text-[11px] leading-relaxed text-[#4A5568]">{card.text}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </Band>
  );
}

function JourneySection({ title, subtitle, c }: P) {
  const { mobile } = usePreview();
  const steps = arr(c.steps);
  return (
    <Band bg="light">
      <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} center />
      <div className={clsx('grid gap-4', mobile ? 'grid-cols-1' : ({ 3: 'grid-cols-3', 4: 'grid-cols-4', 5: 'grid-cols-5', 6: 'grid-cols-6' } as Record<number, string>)[steps.length] ?? 'grid-cols-5')}>
        {steps.map((s, i) => (
          <div key={i} className="relative rounded-[20px] border border-[#EDE8E2] bg-white p-4 text-center">
            <span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-[#0B2A5B] text-white"><CmsIcon name={s.icon} className="h-5 w-5" /></span>
            <p className="mt-2 text-[11px] font-bold uppercase tracking-wider text-[#D03E16]">Step {i + 1}</p>
            <h3 className="mt-1 font-display text-base font-bold leading-snug text-[#0B2A5B]">{s.title}</h3>
            {!!s.text && <p className="mt-1 text-xs leading-relaxed text-[#64748B]">{s.text}</p>}
          </div>
        ))}
      </div>
      {hasLink(c.cta) && <div className="mt-8 text-center"><Pill link={c.cta} /></div>}
    </Band>
  );
}

function CampusSection({ title, subtitle, c }: P) {
  const cols = useCols(Math.min(3, Math.max(2, arr(c.cards).length)));
  return (
    <Band>
      <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} />
      <div className={clsx('grid gap-5', cols)}>
        {arr(c.cards).map((card, i) => (
          <div key={i} className="relative overflow-hidden rounded-[24px]">
            <Img src={card.image} className="aspect-[4/5] w-full" />
            <div className="absolute inset-0 bg-gradient-to-t from-[#0B2A5B]/90 via-[#0B2A5B]/30 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-5 text-white">
              <h3 className="font-display text-2xl font-bold">{card.title}</h3>
              {!!card.text && <p className="mt-1 text-sm text-white/80">{card.text}</p>}
              {!!card.link_label && <span className="mt-3 inline-flex rounded-full bg-white px-3 py-1 text-xs font-semibold text-[#0B2A5B]">{card.link_label} →</span>}
            </div>
          </div>
        ))}
      </div>
    </Band>
  );
}

function RecruitersSection({ title, subtitle, c }: P) {
  const { data } = usePreview();
  // Official logos when set (companies.logo / manual logo), the company name as a wordmark otherwise.
  const companies: { name: string; logo: string | null }[] = c.source === 'manual'
    ? arr(c.items).map((i) => ({ name: String(i.name ?? ''), logo: (i.logo as string) || null }))
    : (data?.recruiters ?? []).map((r) => ({ name: r.name, logo: r.logo }));
  const list = companies.filter((x) => x.name).slice(0, Number(c.limit) || 24);
  const rows = String(c.rows) === '2' && list.length >= 8 ? [list.filter((_, i) => i % 2 === 0), list.filter((_, i) => i % 2 === 1)] : [list];
  return (
    <Band>
      <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} center />
      <div className="space-y-3 overflow-hidden rounded-[24px] border border-[#EDE8E2] bg-[#FAF8F5] p-4">
        {rows.map((row, r) => (
          <div key={r} className={clsx('flex gap-3', r === 1 && 'translate-x-10')}>
            {row.map((co, i) => <RecruiterLogo key={i} name={co.name} logo={co.logo} />)}
          </div>
        ))}
        {!list.length && <p className="text-center text-sm text-slate-400">No recruiters to show yet.</p>}
      </div>
      {hasLink(c.cta) && <div className="mt-6 text-center"><Pill link={c.cta} variant="outline" small /></div>}
    </Band>
  );
}

function TestimonialsSection({ title, subtitle, c }: P) {
  const { data, mobile } = usePreview();
  const types: string[] = Array.isArray(c.types) ? c.types : [];
  const list = (data?.testimonials ?? []).filter((t) => !types.length || types.includes(t.type)).slice(0, Number(c.limit) || 8);
  return (
    <Band bg="light">
      <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} />
      <div className={clsx('grid gap-5', mobile ? 'grid-cols-1' : 'grid-cols-3')}>
        {list.slice(0, mobile ? 1 : 3).map((t) => (
          <div key={t.id} className="rounded-[22px] bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <Img src={t.photo} className="h-12 w-12 rounded-full" />
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-[#0B2A5B]">{t.name}</p>
                <p className="truncate text-xs text-[#64748B]">{[t.program, t.designation, t.company].filter(Boolean).join(' · ')}</p>
              </div>
            </div>
            <p className="mt-3 line-clamp-5 text-sm leading-relaxed text-[#4A5568]"><Quote className="mr-1 inline h-4 w-4 text-[#D03E16]" />{t.content}</p>
            <span className="mt-3 flex text-[#FDB022]">{Array.from({ length: t.rating || 5 }).map((_, k) => <Star key={k} className="h-3 w-3 fill-current" />)}</span>
          </div>
        ))}
      </div>
      {!list.length && <p className="text-sm text-slate-400">No active testimonials for these types yet — add some in Website CMS › Testimonials.</p>}
      <p className="mt-4 text-center text-xs text-[#64748B]">{list.length} stories in the slider{Number(c.autoplay) ? ` · autoplay every ${Number(c.autoplay) / 1000}s` : ' · autoplay off'}</p>
    </Band>
  );
}

function EventsSection({ title, subtitle, c }: P) {
  const { data, mobile } = usePreview();
  const events = c.show_events ? (data?.events ?? []).slice(0, Number(c.events_limit) || 3) : [];
  const news = c.show_news ? (data?.news ?? []).slice(0, Number(c.news_limit) || 3) : [];
  const notices = c.show_notices ? (data?.notices ?? []).slice(0, Number(c.notices_limit) || 5) : [];
  return (
    <Band>
      <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} />
      <div className={clsx('grid gap-6', mobile ? 'grid-cols-1' : 'grid-cols-3')}>
        {c.show_events && (
          <div>
            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-[#0B2A5B]">Upcoming events</p>
            <div className="space-y-3">
              {events.map((e) => (
                <div key={e.id} className="flex gap-3 rounded-2xl bg-[#FAF8F5] p-3">
                  <span className="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-[#0B2A5B] text-white">
                    <span className="text-base font-bold leading-none">{new Date(e.start_datetime.replace(' ', 'T')).getDate()}</span>
                    <span className="text-[9px] uppercase">{new Date(e.start_datetime.replace(' ', 'T')).toLocaleString('en', { month: 'short' })}</span>
                  </span>
                  <div className="min-w-0"><p className="line-clamp-2 text-sm font-semibold text-[#0B2A5B]">{e.title}</p><p className="truncate text-xs text-[#64748B]">{e.venue}</p></div>
                </div>
              ))}
              {!events.length && <p className="text-xs text-slate-400">No upcoming website events.</p>}
            </div>
          </div>
        )}
        {c.show_news && (
          <div>
            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-[#0B2A5B]">Latest news</p>
            <div className="space-y-3">
              {news.map((n) => (
                <div key={n.id} className="flex gap-3">
                  <Img src={n.thumbnail} className="h-14 w-20 shrink-0 rounded-xl" />
                  <div className="min-w-0"><p className="line-clamp-2 text-sm font-semibold text-[#0B2A5B]">{n.title}</p><p className="text-xs text-[#64748B]">{formatDate(n.published_at)}</p></div>
                </div>
              ))}
              {!news.length && <p className="text-xs text-slate-400">No published news yet.</p>}
            </div>
          </div>
        )}
        {c.show_notices && (
          <div className="rounded-[20px] bg-[#F5F2EE]/70 p-4">
            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-[#0B2A5B]">Notice board</p>
            <ul className="space-y-2.5">
              {notices.map((n) => (
                <li key={n.id} className="flex gap-2 text-sm text-[#0B2A5B]"><CalendarDays className="mt-0.5 h-4 w-4 shrink-0 text-[#D03E16]" /><span className="line-clamp-2">{n.title}</span></li>
              ))}
              {!notices.length && <li className="text-xs text-slate-400">No published notices.</li>}
            </ul>
          </div>
        )}
      </div>
    </Band>
  );
}

function FaqsSection({ title, subtitle, c }: P) {
  const { data, mobile } = usePreview();
  const cats: string[] = Array.isArray(c.categories) ? c.categories : [];
  const list = (data?.faqs ?? []).filter((f) => (!cats.length || cats.includes(f.category)) && (!c.home_only || Number(f.show_on_home) === 1)).slice(0, Number(c.limit) || 8);
  const two = String(c.columns) === '2' && !mobile;
  return (
    <Band bg="light">
      <Heading eyebrow={c.eyebrow} title={title} sub={subtitle} center />
      <div className={clsx('grid gap-3', two ? 'grid-cols-2' : 'mx-auto max-w-3xl grid-cols-1')}>
        {list.map((f, i) => (
          <div key={f.id} className="rounded-2xl border border-[#E5DED6] bg-white px-4 py-3">
            <p className="flex items-center justify-between gap-3 text-sm font-semibold text-[#0B2A5B]">{f.question}<ChevronDown className={clsx('h-4 w-4 shrink-0 text-[#D03E16]', i === 0 && 'rotate-180')} /></p>
            {i === 0 && <p className="mt-2 line-clamp-3 text-sm text-[#4A5568]">{f.answer}</p>}
          </div>
        ))}
      </div>
      {!list.length && <p className="text-center text-sm text-slate-400">No FAQs match — mark FAQs “Show on home” in Website CMS › FAQs.</p>}
      {hasLink(c.view_all) && <div className="mt-6 text-center"><Pill link={c.view_all} variant="outline" small /></div>}
    </Band>
  );
}

function CtaSection({ title, subtitle, c }: P) {
  const { mobile } = usePreview();
  return (
    <div>
      <CtaBlock d={{ eyebrow: c.eyebrow, title, text: subtitle, primary: c.primary, secondary: c.secondary, image: c.show_enquiry_form ? '' : c.image, theme: c.theme }} />
      {!!c.show_enquiry_form && (
        <div className={clsx('mx-auto -mt-4 mb-8 rounded-[22px] border border-[#E5DED6] bg-white p-5 shadow-lg', mobile ? 'mx-6' : 'max-w-[760px]')}>
          <p className="font-display text-lg font-bold text-[#0B2A5B]">Request a call back</p>
          <div className={clsx('mt-3 grid gap-2', mobile ? 'grid-cols-1' : 'grid-cols-4')}>
            {['Full name', 'Phone', 'Email', 'Program'].map((p) => <span key={p} className="rounded-xl border border-[#E5DED6] px-3 py-2 text-xs text-[#64748B]">{p}</span>)}
          </div>
          <span className="mt-3 inline-flex rounded-full bg-[#D03E16] px-4 py-2 text-xs font-semibold text-white">Get free counselling →</span>
        </div>
      )}
    </div>
  );
}

/** Live value helper exported for forms that show stat sources. */
export function liveLabel(v: number | null | undefined): string {
  return v === null || v === undefined ? 'no data yet' : formatNumber(v, Number.isInteger(v) ? 0 : 1);
}
