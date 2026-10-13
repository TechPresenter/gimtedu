import { useEffect, useId, useRef } from 'react';
import { prefersReducedMotion } from '@/components/ui';

/**
 * Original, code-native hero illustration: a floating graduation cap inside tilted orbits, a small star
 * constellation and a stylised campus skyline (clock tower, columned hall, library dome) on a glowing horizon.
 * White/sky strokes at low opacity so it sits quietly on the navy mesh. Orbiting dots use SMIL motion that is
 * paused with the rest of the hero when it is off-screen, and is not rendered at all under reduced motion.
 */

const CX = 210;
const CY = 118;

/** A dot that travels along an ellipse around the cap (static under reduced motion). */
function Orbiter({ rx, ry, dur, begin, r, color, angle, still }: { rx: number; ry: number; dur: number; begin: number; r: number; color: string; angle: number; still: boolean }) {
  if (still) {
    const a = (angle * Math.PI) / 180;
    return <circle cx={CX + rx * Math.cos(a)} cy={CY + ry * Math.sin(a)} r={r} fill={color} />;
  }
  const path = `M${CX - rx} ${CY} a${rx} ${ry} 0 1 0 ${rx * 2} 0 a${rx} ${ry} 0 1 0 ${-rx * 2} 0`;
  return (
    <circle r={r} fill={color}>
      <animateMotion dur={`${dur}s`} begin={`${-begin}s`} repeatCount="indefinite" path={path} />
    </circle>
  );
}

const STARS: [number, number, number, number][] = [
  // x, y, radius, twinkle delay (s)
  [96, 74, 2.2, 0], [56, 98, 1.6, 1.2], [324, 66, 2.4, 0.6], [368, 90, 1.6, 2.1], [228, 36, 2, 1.6], [190, 18, 1.5, 0.3],
  [40, 46, 1.3, 2.6], [384, 34, 1.4, 1], [300, 26, 1.2, 3], [128, 30, 1.2, 1.9],
];
const LINKS: [number, number, number, number][] = [
  [138, 108, 96, 74], [96, 74, 56, 98], [282, 108, 324, 66], [324, 66, 368, 90], [210, 80, 228, 36], [228, 36, 190, 18],
];
const WINDOWS: [number, number, boolean][] = [
  // x, y, lit
  [128, 236, true], [140, 236, false], [152, 236, true], [128, 252, false], [140, 252, true], [152, 252, false],
  [268, 232, true], [280, 232, false], [292, 232, true], [304, 232, false], [268, 248, false], [280, 248, true], [292, 248, false], [304, 248, true],
  [268, 264, true], [280, 264, false], [292, 264, true], [304, 264, false],
];

export function HeroArt({ className, paused = false }: { className?: string; paused?: boolean }) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, '');
  const id = (s: string) => `dh-${uid}-${s}`;
  const svgRef = useRef<SVGSVGElement | null>(null);
  const still = prefersReducedMotion();

  useEffect(() => {
    const svg = svgRef.current;
    if (!svg || still || typeof svg.pauseAnimations !== 'function') return;
    if (paused) svg.pauseAnimations();
    else svg.unpauseAnimations();
  }, [paused, still]);

  return (
    <svg ref={svgRef} viewBox="0 0 420 300" className={className} aria-hidden focusable="false">
      <defs>
        <radialGradient id={id('glow')} cx="50%" cy="50%" r="50%">
          <stop offset="0" stopColor="#38BDF8" stopOpacity=".38" />
          <stop offset=".55" stopColor="#3B82F6" stopOpacity=".12" />
          <stop offset="1" stopColor="#3B82F6" stopOpacity="0" />
        </radialGradient>
        <linearGradient id={id('board')} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#FFFFFF" stopOpacity=".42" />
          <stop offset="1" stopColor="#93C5FD" stopOpacity=".1" />
        </linearGradient>
        <linearGradient id={id('body')} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#FFFFFF" stopOpacity=".26" />
          <stop offset="1" stopColor="#FFFFFF" stopOpacity=".05" />
        </linearGradient>
        <linearGradient id={id('bld')} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#FFFFFF" stopOpacity=".2" />
          <stop offset="1" stopColor="#FFFFFF" stopOpacity=".04" />
        </linearGradient>
        <linearGradient id={id('horizon')} x1="0" x2="1">
          <stop offset="0" stopColor="#7DD3FC" stopOpacity="0" />
          <stop offset=".5" stopColor="#7DD3FC" stopOpacity=".8" />
          <stop offset="1" stopColor="#7DD3FC" stopOpacity="0" />
        </linearGradient>
        <linearGradient id={id('orbit')} x1="0" x2="1">
          <stop offset="0" stopColor="#FFFFFF" stopOpacity=".04" />
          <stop offset=".5" stopColor="#BAE6FD" stopOpacity=".45" />
          <stop offset="1" stopColor="#FFFFFF" stopOpacity=".04" />
        </linearGradient>
      </defs>

      {/* glow behind the cap */}
      <circle cx={CX} cy={CY} r="128" fill={`url(#${id('glow')})`} />

      {/* constellation */}
      <g stroke="#E0F2FE" strokeOpacity=".22" strokeWidth=".9">
        {LINKS.map(([x1, y1, x2, y2], i) => <line key={i} x1={x1} y1={y1} x2={x2} y2={y2} />)}
      </g>
      {STARS.map(([x, y, r, d], i) => (
        <circle key={i} cx={x} cy={y} r={r} fill="#FFFFFF" className="dh-twinkle" style={{ animationDelay: `${d}s` }} />
      ))}
      <path d="M0 -7 L1.6 -1.6 L7 0 L1.6 1.6 L0 7 L-1.6 1.6 L-7 0 L-1.6 -1.6 Z" transform="translate(352 150)" fill="#FDE68A" className="dh-twinkle" style={{ animationDelay: '1.4s' }} />
      <path d="M0 -5 L1.2 -1.2 L5 0 L1.2 1.2 L0 5 L-1.2 1.2 L-5 0 L-1.2 -1.2 Z" transform="translate(70 152)" fill="#BAE6FD" className="dh-twinkle" style={{ animationDelay: '.4s' }} />

      {/* orbits */}
      <g transform={`rotate(-10 ${CX} ${CY})`}>
        <ellipse cx={CX} cy={CY} rx="178" ry="50" fill="none" stroke={`url(#${id('orbit')})`} strokeWidth="1.2" />
        <ellipse cx={CX} cy={CY} rx="126" ry="34" fill="none" stroke="#FFFFFF" strokeOpacity=".16" strokeWidth="1" strokeDasharray="3 7" />
        <Orbiter rx={178} ry={50} dur={22} begin={4} r={3.6} color="#7DD3FC" angle={20} still={still} />
        <Orbiter rx={178} ry={50} dur={22} begin={15} r={2.4} color="#FDE68A" angle={200} still={still} />
        <Orbiter rx={126} ry={34} dur={14} begin={2} r={2.8} color="#86EFAC" angle={140} still={still} />
      </g>

      {/* graduation cap (floats gently) */}
      <ellipse cx={CX} cy="170" rx="50" ry="6" fill="#020617" fillOpacity=".22" />
      <g className="dh-float">
        <path d={`M170 118 L170 142 C170 158 250 158 250 142 L250 118 L${CX} 134 Z`} fill={`url(#${id('body')})`} stroke="#FFFFFF" strokeOpacity=".45" strokeWidth="1.2" strokeLinejoin="round" />
        <polygon points={`${CX},80 282,108 ${CX},136 138,108`} fill={`url(#${id('board')})`} stroke="#FFFFFF" strokeOpacity=".7" strokeWidth="1.4" strokeLinejoin="round" />
        <polyline points={`138,108 ${CX},80 282,108`} fill="none" stroke="#FFFFFF" strokeOpacity=".95" strokeWidth="1.6" strokeLinejoin="round" strokeLinecap="round" />
        <circle cx={CX} cy="108" r="3.6" fill="#FFFFFF" />
        <path d={`M${CX} 108 L262 116 L262 144`} fill="none" stroke="#FBBF24" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        <g className="dh-sway" style={{ transformOrigin: '262px 144px' }}>
          <path d="M257.5 146 L266.5 146 L269 164 L255 164 Z" fill="#FBBF24" />
          <circle cx="262" cy="145" r="3.4" fill="#FDE68A" />
        </g>
      </g>

      {/* skyline: back layer */}
      <g fill="#FFFFFF" fillOpacity=".055">
        <rect x="24" y="238" width="38" height="44" rx="2" />
        <rect x="58" y="224" width="30" height="58" rx="2" />
        <rect x="336" y="228" width="34" height="54" rx="2" />
        <rect x="368" y="242" width="30" height="40" rx="2" />
      </g>
      {/* clock tower */}
      <rect x="90" y="182" width="30" height="100" fill={`url(#${id('bld')})`} />
      <polygon points="90,182 105,158 120,182" fill="#FFFFFF" fillOpacity=".18" />
      <circle cx="105" cy="199" r="8" fill="none" stroke="#FFFFFF" strokeOpacity=".6" strokeWidth="1.3" />
      <path d="M105 199 L105 194 M105 199 L108.5 201" stroke="#FFFFFF" strokeOpacity=".8" strokeWidth="1.2" strokeLinecap="round" />
      {/* left wing */}
      <rect x="120" y="228" width="44" height="54" fill={`url(#${id('bld')})`} />
      {/* columned hall */}
      <polygon points={`150,214 ${CX},186 270,214`} fill="#FFFFFF" fillOpacity=".16" stroke="#FFFFFF" strokeOpacity=".35" strokeWidth="1" strokeLinejoin="round" />
      <rect x="156" y="214" width="108" height="6" fill="#FFFFFF" fillOpacity=".2" />
      <g fill="#FFFFFF" fillOpacity=".2">
        {[164, 181, 198, 215, 232, 249].map((x) => <rect key={x} x={x} y="222" width="7" height="52" rx="1.5" />)}
      </g>
      <rect x="152" y="274" width="116" height="8" fill="#FFFFFF" fillOpacity=".14" />
      {/* right wing + library dome */}
      <rect x="262" y="224" width="52" height="58" fill={`url(#${id('bld')})`} />
      <path d="M318 246 a17 17 0 0 1 34 0 Z" fill="#FFFFFF" fillOpacity=".16" />
      <rect x="318" y="246" width="34" height="36" fill={`url(#${id('bld')})`} />
      {/* windows */}
      {WINDOWS.map(([x, y, lit], i) => (
        <rect
          key={i}
          x={x}
          y={y}
          width="6"
          height="7"
          rx="1"
          fill={lit ? '#FDE68A' : '#FFFFFF'}
          fillOpacity={lit ? 0.75 : 0.16}
          className={lit ? 'dh-twinkle' : undefined}
          style={lit ? { animationDelay: `${(i % 5) * 0.7}s`, animationDuration: '5.5s' } : undefined}
        />
      ))}
      {/* trees */}
      <g fill="#FFFFFF" fillOpacity=".08">
        <circle cx="74" cy="272" r="11" />
        <circle cx="140" cy="274" r="8" />
        <circle cx="300" cy="274" r="9" />
        <circle cx="388" cy="272" r="10" />
      </g>
      {/* horizon */}
      <rect x="6" y="281" width="408" height="1.6" fill={`url(#${id('horizon')})`} />
    </svg>
  );
}
