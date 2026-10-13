import clsx from 'clsx';
import { ChevronDown, CirclePlay, Quote, Star } from 'lucide-react';
import { formatNumber } from '@/lib/format';
import { appUrl } from '@/lib/config';
import { CmsIcon } from '../icons';
import type { Block, Data } from '../types';
import { Band, Eyebrow, Heading, Img, Pill, ProgramCard, RecruiterLogo, hasLink, paragraphs, statText, tintFor, useCols, usePreview } from './kit';

const arr = (v: unknown): Data[] => (Array.isArray(v) ? (v as Data[]) : []);

/** Live approximation of one page-builder block on the public website. */
export function BlockPreview({ block }: { block: Block }) {
  const { settings } = block;
  const d = block.data ?? {};
  const bg = settings?.background ?? 'white';
  const dark = bg === 'dark';
  const pad = settings?.padding ?? 'normal';
  switch (block.type) {
    case 'hero':
      return <HeroBlock d={d} bg={bg} pad={pad} />;
    case 'text':
      return (
        <Band bg={bg} padding={pad}>
          <div className={clsx('mx-auto', d.width === 'narrow' ? 'max-w-2xl' : d.width === 'wide' ? 'max-w-none' : 'max-w-3xl', d.align === 'center' && 'text-center')}>
            {!!d.heading && <h2 className={clsx('mb-4 font-display text-[32px] font-extrabold tracking-[-0.02em]', dark ? 'text-white' : 'text-[#0B2A5B]')}>{d.heading}</h2>}
            <div className={clsx('cms-prose space-y-3 text-[15px] leading-relaxed [&_a]:text-[#D03E16] [&_a]:underline [&_h2]:mt-5 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-bold [&_h3]:mt-4 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-bold [&_li]:ml-5 [&_ol]:list-decimal [&_table]:w-full [&_td]:border [&_td]:border-[#E5DED6] [&_td]:p-2 [&_th]:border [&_th]:border-[#E5DED6] [&_th]:bg-[#FAF8F5] [&_th]:p-2 [&_th]:text-left [&_ul]:list-disc',
              dark ? 'text-white/80 [&_h2]:text-white [&_h3]:text-white' : '[&_h2]:text-[#0B2A5B] [&_h3]:text-[#0B2A5B] [&_strong]:text-[#0B2A5B]')}
              dangerouslySetInnerHTML={{ __html: String(d.html || '<p class="text-slate-400">Start writing…</p>') }} />
          </div>
        </Band>
      );
    case 'image':
      return (
        <Band bg={bg} padding={pad}>
          <figure className={clsx('mx-auto', d.width === 'normal' ? 'max-w-3xl' : '')}>
            <Img src={d.image} alt={d.alt} className={clsx('w-full', d.width === 'full' ? 'aspect-[21/9]' : 'aspect-[16/9] rounded-[24px]')} />
            {!!d.caption && <figcaption className={clsx('mt-3 text-center text-sm', dark ? 'text-white/60' : 'text-[#64748B]')}>{d.caption}</figcaption>}
          </figure>
        </Band>
      );
    case 'video': {
      const aspect = d.aspect === '4:3' ? 'aspect-[4/3]' : d.aspect === '1:1' ? 'aspect-square max-w-xl' : 'aspect-video';
      return (
        <Band bg={bg} padding={pad}>
          {!!d.title && <Heading title={d.title} dark={dark} center />}
          <div className={clsx('relative mx-auto max-w-4xl overflow-hidden rounded-[24px] bg-[#0B2A5B]', aspect)}>
            {d.poster && <Img src={d.poster} className="absolute inset-0 h-full w-full opacity-80" />}
            <span className="absolute left-1/2 top-1/2 inline-flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-[#D03E16] shadow-xl">
              <CirclePlay className="h-9 w-9" />
            </span>
            {!d.url && <span className="absolute bottom-3 left-3 rounded-full bg-amber-300 px-3 py-1 text-xs font-semibold text-[#0B2A5B]">Add a video URL</span>}
          </div>
          {!!d.caption && <p className={clsx('mt-3 text-center text-sm', dark ? 'text-white/60' : 'text-[#64748B]')}>{d.caption}</p>}
        </Band>
      );
    }
    case 'cards':
      return <CardsBlock d={d} bg={bg} pad={pad} />;
    case 'statistics':
      return <StatsBlock d={d} bg={bg} pad={pad} />;
    case 'testimonials':
      return <TestimonialsBlock d={d} bg={bg} pad={pad} />;
    case 'faq':
      return <FaqBlock d={d} bg={bg} pad={pad} />;
    case 'cta':
      return <CtaBlock d={d} />;
    case 'gallery':
      return <GalleryBlock d={d} bg={bg} pad={pad} />;
    case 'course_listing':
      return <CourseBlock d={d} bg={bg} pad={pad} />;
    case 'faculty_listing':
      return <FacultyBlock d={d} bg={bg} pad={pad} />;
    case 'placement_section':
      return <PlacementBlock d={d} bg={bg} pad={pad} />;
    default:
      return <Band><p className="text-sm text-slate-400">Unknown block “{block.type}”.</p></Band>;
  }
}

function HeroBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { mobile } = usePreview();
  const center = d.align === 'center';
  const dark = bg === 'dark';
  return (
    <Band bg={bg} padding={pad === 'normal' ? 'normal' : pad} className={clsx(bg === 'white' && '!bg-[#FAF8F5]', !dark && 'bg-[linear-gradient(#0000000a_1px,transparent_1px),linear-gradient(90deg,#0000000a_1px,transparent_1px)] [background-size:28px_28px]')}>
      <div className={clsx('grid items-center gap-10', mobile || center || !d.image ? 'grid-cols-1' : 'grid-cols-2')}>
        <div className={clsx(center && 'mx-auto max-w-3xl text-center')}>
          {!!d.eyebrow && <Eyebrow dark={dark}>{d.eyebrow}</Eyebrow>}
          <h1 className={clsx('font-display font-extrabold leading-[1.04] tracking-[-0.035em]', d.size === 'lg' ? 'text-6xl' : 'text-[52px]', mobile && '!text-4xl', dark ? 'text-white' : 'text-[#0B2A5B]')}>
            {d.title || 'Untitled'}
            {!!d.highlight && (
              <span className="relative mt-1 block text-[#F05A28]">
                {d.highlight}
                <svg className="absolute -bottom-2 left-0 h-3 w-48 text-[#F05A28]/70" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden><path d="M2 9 C 50 2, 120 2, 198 7" stroke="currentColor" strokeWidth="3" fill="none" strokeLinecap="round" /></svg>
              </span>
            )}
          </h1>
          {!!d.text && <p className={clsx('mt-5 text-base leading-relaxed', dark ? 'text-white/75' : 'text-[#4A5568]')}>{d.text}</p>}
          {(hasLink(d.primary_cta) || hasLink(d.secondary_cta)) && (
            <div className={clsx('mt-6 flex flex-wrap gap-3', center && 'justify-center')}>
              <Pill link={d.primary_cta} />
              <Pill link={d.secondary_cta} variant={dark ? 'light' : 'outline'} />
            </div>
          )}
          {arr(d.chips).length > 0 && (
            <div className={clsx('mt-6 flex flex-wrap gap-2', center && 'justify-center')}>
              {arr(d.chips).map((c, i) => (
                <span key={i} className="inline-flex items-center gap-1.5 rounded-full border border-[#E5DED6] bg-white px-3 py-1.5 text-xs font-medium text-[#0B2A5B] shadow-sm">
                  <CmsIcon name={c.icon} className="h-3.5 w-3.5 text-[#D03E16]" />
                  {c.label}
                </span>
              ))}
            </div>
          )}
        </div>
        {!!d.image && !center && <Img src={d.image} alt={d.image_alt} className="aspect-[4/3] w-full rounded-[28px] shadow-xl" />}
      </div>
    </Band>
  );
}

function CardsBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const items = arr(d.items);
  const cols = useCols(Number(d.columns) || 3, d.style === 'person' ? 1 : 1);
  const dark = bg === 'dark';
  return (
    <Band bg={bg} padding={pad}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={dark} center={d.style === 'person'} />
      <div className={clsx('grid gap-5', cols)}>
        {items.map((c, i) => {
          if (d.style === 'person') {
            return (
              <div key={i} className="overflow-hidden rounded-[22px] bg-white text-center shadow-[0_10px_30px_-12px_rgba(0,0,0,.18)]">
                <Img src={c.image} alt={c.title} className="aspect-[4/3.4] w-full object-top" />
                <div className="p-5">
                  <h3 className="font-display text-xl font-bold text-[#0B2A5B]">{c.title}</h3>
                  {!!c.subtitle && <p className="mt-0.5 text-sm font-semibold text-[#D03E16]">{c.subtitle}</p>}
                  {!!c.text && <p className="mt-2 text-sm leading-relaxed text-[#4A5568]">{c.text}</p>}
                </div>
              </div>
            );
          }
          if (d.style === 'image') {
            return (
              <div key={i} className="overflow-hidden rounded-[20px] bg-[#FAF8F5]">
                <Img src={c.image} alt={c.title} className="aspect-[16/10] w-full" />
                <div className="p-4">
                  <h3 className="font-display text-lg font-bold text-[#0B2A5B]">{c.title}</h3>
                  {!!c.text && <p className="mt-1 text-sm text-[#4A5568]">{c.text}</p>}
                  {!!c.link_label && <p className="mt-3 text-sm font-semibold text-[#D03E16]">{c.link_label} →</p>}
                </div>
              </div>
            );
          }
          const pastel = d.style === 'pastel';
          return (
            <div key={i} className={clsx('rounded-[20px] p-5', pastel ? tintFor(c.tint, i) : dark ? 'bg-white/5 ring-1 ring-white/10' : 'border border-[#EDE8E2] bg-[#FAF8F5]')}>
              <span className={clsx('inline-flex h-11 w-11 items-center justify-center rounded-full', pastel ? 'bg-white text-[#0B2A5B]' : 'bg-[#D03E16]/10 text-[#D03E16]')}>
                <CmsIcon name={c.icon} className="h-5 w-5" />
              </span>
              <h3 className={clsx('mt-4 font-display text-lg font-bold', dark && !pastel ? 'text-white' : 'text-[#0B2A5B]')}>{c.title}</h3>
              {!!c.subtitle && <p className="text-xs font-semibold text-[#D03E16]">{c.subtitle}</p>}
              {!!c.text && <p className={clsx('mt-1.5 text-sm leading-relaxed', dark && !pastel ? 'text-white/70' : 'text-[#4A5568]')}>{c.text}</p>}
              {!!c.link_label && <span className="mt-3 inline-flex rounded-full bg-white px-3 py-1 text-xs font-semibold text-[#0B2A5B]">{c.link_label} →</span>}
            </div>
          );
        })}
      </div>
      {!items.length && <p className="text-sm text-slate-400">Add cards to this block.</p>}
    </Band>
  );
}

export function StatsRow({ items, variant, dark }: { items: Data[]; variant?: string; dark?: boolean }) {
  const { data, mobile } = usePreview();
  const cols = mobile ? 'grid-cols-2' : (({ 1: 'grid-cols-1', 2: 'grid-cols-2', 3: 'grid-cols-3' } as Record<number, string>)[items.length] ?? 'grid-cols-4');
  if (variant === 'band' || dark) {
    return (
      <div className={clsx('grid divide-x divide-white/15', cols)}>
        {items.map((s, i) => (
          <div key={i} className="px-4 text-center">
            <p className="font-display text-5xl font-extrabold text-white">{statText(s, data?.stats)}</p>
            <p className="mt-1 text-sm text-white/60">{s.label}</p>
          </div>
        ))}
      </div>
    );
  }
  return (
    <div className={clsx('grid gap-4', cols)}>
      {items.map((s, i) => (
        <div key={i} className={clsx('flex items-center gap-3', variant === 'inline' ? 'p-2' : 'rounded-[20px] border border-[#EDE8E2] bg-[#FAF8F5] p-5')}>
          {!!s.icon && (
            <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#D03E16]/10 text-[#D03E16]">
              <CmsIcon name={s.icon} className="h-5 w-5" />
            </span>
          )}
          <div>
            <p className="font-display text-3xl font-bold text-[#0B2A5B]">{statText(s, data?.stats)}</p>
            <p className="text-xs text-[#64748B]">{s.label}</p>
          </div>
        </div>
      ))}
    </div>
  );
}

function StatsBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const band = d.variant === 'band';
  const effBg = band ? 'dark' : bg;
  return (
    <Band bg={effBg} padding={pad} grid={band}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={effBg === 'dark'} center={band} />
      <StatsRow items={arr(d.items)} variant={d.variant} dark={effBg === 'dark'} />
    </Band>
  );
}

function TestimonialsBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { data } = usePreview();
  const cols = useCols(3);
  const types: string[] = Array.isArray(d.types) ? d.types : [];
  const items = d.source === 'manual'
    ? arr(d.items).map((t) => ({ name: t.name, quote: t.quote, role: t.role, photo: t.photo, rating: t.rating ?? 5 }))
    : (data?.testimonials ?? []).filter((t) => !types.length || types.includes(t.type)).slice(0, Number(d.limit) || 6)
        .map((t) => ({ name: t.name, quote: t.content, role: [t.designation, t.company].filter(Boolean).join(' · ') || t.program, photo: t.photo, rating: t.rating }));
  return (
    <Band bg={bg} padding={pad}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={bg === 'dark'} />
      <div className={clsx('grid gap-5', cols)}>
        {items.slice(0, 3).map((t, i) => (
          <div key={i} className="rounded-[20px] border border-[#EDE8E2] bg-white p-5 shadow-sm">
            <Quote className="h-6 w-6 text-[#D03E16]" />
            <p className="mt-2 line-clamp-5 text-sm leading-relaxed text-[#4A5568]">{t.quote}</p>
            <div className="mt-4 flex items-center gap-3">
              <Img src={t.photo} alt="" className="h-10 w-10 rounded-full" />
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-[#0B2A5B]">{t.name}</p>
                <p className="truncate text-xs text-[#64748B]">{t.role}</p>
              </div>
              <span className="ml-auto flex text-[#FDB022]">{Array.from({ length: Number(t.rating) || 5 }).map((_, k) => <Star key={k} className="h-3 w-3 fill-current" />)}</span>
            </div>
          </div>
        ))}
      </div>
      {!items.length && <p className="text-sm text-slate-400">{d.source === 'manual' ? 'Add stories to this block.' : 'No active testimonials match these settings yet.'}</p>}
      {items.length > 3 && <p className="mt-3 text-center text-xs text-[#64748B]">+ {items.length - 3} more in the slider</p>}
    </Band>
  );
}

function FaqBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { data, mobile } = usePreview();
  const items = d.source === 'manual'
    ? arr(d.items).map((f) => ({ q: f.q, a: f.a }))
    : (data?.faqs ?? []).filter((f) => !d.category || f.category === d.category).slice(0, Number(d.limit) || 8).map((f) => ({ q: f.question, a: f.answer }));
  const two = String(d.columns) === '2' && !mobile;
  return (
    <Band bg={bg} padding={pad}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={bg === 'dark'} />
      <div className={clsx('grid gap-3', two ? 'grid-cols-2' : 'mx-auto max-w-3xl grid-cols-1')}>
        {items.map((f, i) => (
          <div key={i} className="rounded-2xl border border-[#E5DED6] bg-white px-4 py-3">
            <p className="flex items-center justify-between gap-3 text-sm font-semibold text-[#0B2A5B]">
              {f.q || 'Question'}
              <ChevronDown className={clsx('h-4 w-4 shrink-0 text-[#0B2A5B]', i === 0 && 'rotate-180')} />
            </p>
            {i === 0 && <p className="mt-2 text-sm leading-relaxed text-[#4A5568]">{paragraphs(f.a)[0]}</p>}
          </div>
        ))}
      </div>
      {!items.length && <p className="text-sm text-slate-400">{d.source === 'manual' ? 'Add questions to this block.' : 'No active FAQs match these settings yet.'}</p>}
    </Band>
  );
}

export function CtaBlock({ d }: { d: Data }) {
  const { mobile } = usePreview();
  const theme = d.theme ?? 'dark';
  const cls = theme === 'orange' ? 'bg-[#D03E16] text-white' : theme === 'light' ? 'bg-[#F5F2EE] text-[#0B2A5B]' : 'bg-[#0B2A5B] text-white';
  const darkText = theme !== 'light';
  return (
    <section className="px-6 py-8">
      <div className={clsx('relative mx-auto grid max-w-[1160px] items-center gap-6 overflow-hidden rounded-[28px] p-10', cls, mobile || !d.image ? 'grid-cols-1' : 'grid-cols-[1fr_280px]')}>
        {theme === 'dark' && <div className="pointer-events-none absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)', backgroundSize: '36px 36px' }} aria-hidden />}
        <div className="relative">
          {!!d.eyebrow && <Eyebrow dark={darkText}>{d.eyebrow}</Eyebrow>}
          <h2 className="font-display text-[34px] font-extrabold leading-tight tracking-[-0.02em]">{d.title || 'Call to action'}</h2>
          {!!d.text && <p className={clsx('mt-2 text-[15px]', darkText ? 'text-white/75' : 'text-[#4A5568]')}>{d.text}</p>}
          <div className="mt-5 flex flex-wrap gap-3">
            <Pill link={d.primary} variant={theme === 'orange' ? 'ink' : 'primary'} />
            <Pill link={d.secondary} variant={darkText ? 'light' : 'outline'} />
          </div>
        </div>
        {!!d.image && !mobile && <Img src={d.image} className="relative aspect-square w-full rounded-[22px]" />}
      </div>
    </section>
  );
}

function GalleryBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { data, mobile } = usePreview();
  const fromAlbum = d.source === 'gallery';
  const album = fromAlbum ? data?.galleries.find((g) => g.id === Number(d.gallery_id)) : undefined;
  const photos: { image: string; caption?: string }[] = fromAlbum ? (album?.photos ?? []).map((p) => ({ image: p })) : arr(d.items).map((p) => ({ image: p.image, caption: p.caption }));
  const n = Number(d.columns) || 3;
  const bento = d.layout === 'bento' && !mobile;
  return (
    <Band bg={bg} padding={pad}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={bg === 'dark'} />
      <div className={clsx('grid gap-3', mobile ? 'grid-cols-2' : bento ? 'grid-cols-4' : ({ 2: 'grid-cols-2', 3: 'grid-cols-3', 4: 'grid-cols-4' } as Record<number, string>)[n])}>
        {photos.slice(0, mobile ? 6 : 9).map((p, i) => (
          <div key={i} className={clsx('relative overflow-hidden rounded-[18px]', bento && i === 0 && 'col-span-2 row-span-2')}>
            <Img src={p.image} className={clsx('h-full w-full', bento && i === 0 ? 'aspect-square' : d.layout === 'masonry' && i % 2 ? 'aspect-[3/4]' : 'aspect-[4/3]')} />
            {!!p.caption && <span className="absolute bottom-2 left-2 rounded-full bg-white/90 px-2.5 py-0.5 text-[11px] font-medium text-[#0B2A5B]">{p.caption}</span>}
          </div>
        ))}
      </div>
      {!photos.length && <p className="text-sm text-slate-400">{fromAlbum ? (album ? 'This album has no photos yet.' : 'Choose a gallery album.') : 'Add photos to this gallery.'}</p>}
    </Band>
  );
}

function CourseBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { data } = usePreview();
  const cols = useCols(3);
  let list = data?.programs ?? [];
  if (d.mode === 'featured') list = list.filter((p) => p.is_featured);
  if (d.mode === 'level') list = list.filter((p) => p.level === d.level);
  if (d.mode === 'department') list = list.filter((p) => p.department_id === Number(d.department_id));
  if (d.mode === 'selected') {
    const ids: number[] = Array.isArray(d.program_ids) ? d.program_ids.map(Number) : [];
    list = ids.map((id) => (data?.programs ?? []).find((p) => p.id === id)).filter(Boolean) as typeof list;
  }
  list = list.slice(0, Number(d.limit) || 6);
  return (
    <Band bg={bg} padding={pad}>
      <div className="flex items-end justify-between gap-4">
        <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={bg === 'dark'} />
        {hasLink(d.cta) && <div className="mb-7 shrink-0"><Pill link={d.cta} variant="outline" small /></div>}
      </div>
      {!!d.show_filters && (
        <div className="mb-5 flex flex-wrap gap-2">
          {['All Programs', 'Undergraduate', 'Postgraduate', 'Management', 'Technology'].map((c, i) => (
            <span key={c} className={clsx('rounded-full px-3.5 py-1.5 text-xs font-semibold', i === 0 ? 'bg-[#0B2A5B] text-white' : 'border border-black/15 text-[#0B2A5B]')}>{c}</span>
          ))}
        </div>
      )}
      <div className={clsx('grid gap-5', cols)}>{list.map((p) => <ProgramCard key={p.id} p={p} />)}</div>
      {!list.length && <p className="text-sm text-slate-400">No programs match these settings.</p>}
    </Band>
  );
}

function FacultyBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { data } = usePreview();
  const cols = useCols(4, 2);
  const list = (data?.faculty ?? []).filter((f) => !d.department_id || f.department_id === Number(d.department_id)).slice(0, Number(d.limit) || 8);
  return (
    <Band bg={bg} padding={pad}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={bg === 'dark'} />
      <div className={clsx('grid gap-4', cols)}>
        {list.map((f) => (
          <div key={f.id} className="overflow-hidden rounded-[18px] border border-[#EDE8E2] bg-[#FAF8F5]">
            {f.photo ? <img src={appUrl(f.photo)} alt="" className="aspect-square w-full object-cover" /> : (
              <div className="flex aspect-square w-full items-center justify-center bg-[#E7E1FF] font-display text-3xl font-extrabold text-[#0B2A5B]">{f.name.replace(/^(Dr|Prof|Mr|Ms|Mrs)\.?\s+/i, '').split(' ').slice(0, 2).map((x) => x[0]).join('')}</div>
            )}
            <div className="p-3">
              <p className="truncate text-sm font-semibold text-[#0B2A5B]">{f.name}</p>
              <p className="truncate text-xs text-[#D03E16]">{f.designation}</p>
              {!!d.show_qualification && !!f.qualification && <p className="mt-0.5 truncate text-[11px] text-[#64748B]">{f.qualification}</p>}
              {!!d.show_bio && !!f.bio && <p className="mt-1 line-clamp-2 text-[11px] text-[#64748B]">{f.bio}</p>}
            </div>
          </div>
        ))}
      </div>
      {!list.length && <p className="text-sm text-slate-400">No faculty marked "show on website" for this department.</p>}
      {hasLink(d.cta) && <div className="mt-6 text-center"><Pill link={d.cta} variant="outline" small /></div>}
    </Band>
  );
}

export function PlacementBlock({ d, bg, pad }: { d: Data; bg: string; pad: string }) {
  const { data, mobile } = usePreview();
  const offers = (data?.offers ?? []).slice(0, Number(d.offers_limit) || 6);
  const logos = (data?.recruiters ?? []).slice(0, Number(d.recruiters_limit) || 16);
  const stats = arr(d.stats);
  return (
    <Band bg={bg} padding={pad}>
      <Heading eyebrow={d.eyebrow} title={d.heading} sub={d.subheading} dark={bg === 'dark'} />
      {stats.length > 0 && (
        <div className="mb-8 rounded-[24px] bg-gradient-to-br from-[#071A3B] to-[#0B2A5B] px-6 py-8">
          <StatsRow items={stats} variant="band" dark />
        </div>
      )}
      {d.show_offers !== false && offers.length > 0 && (
        <div className={clsx('mb-8 grid gap-3', mobile ? 'grid-cols-1' : 'grid-cols-3')}>
          {offers.map((o) => (
            <div key={o.id} className="flex items-center gap-3 rounded-2xl border border-[#EDE8E2] bg-[#FAF8F5] p-3">
              <Img src={o.photo} className="h-11 w-11 rounded-full" />
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-[#0B2A5B]">{o.student}</p>
                <p className="truncate text-xs text-[#64748B]">{o.program} · {o.company}</p>
              </div>
              <span className="ml-auto whitespace-nowrap rounded-full bg-[#FDEBE4] px-2.5 py-1 text-[11px] font-bold text-[#C93C14]">₹{formatNumber(Number(o.package_lpa), 1)} LPA</span>
            </div>
          ))}
        </div>
      )}
      {d.show_recruiters !== false && logos.length > 0 && (
        <div className="flex flex-wrap justify-center gap-2 rounded-[22px] border border-[#EDE8E2] bg-[#FAF8F5] p-4">
          {logos.map((c) => <RecruiterLogo key={c.id} name={c.name} logo={c.logo} />)}
        </div>
      )}
      {hasLink(d.cta) && <div className="mt-6 text-center"><Pill link={d.cta} /></div>}
    </Band>
  );
}
