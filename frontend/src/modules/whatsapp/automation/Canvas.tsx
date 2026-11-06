import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent as ReactPointerEvent } from 'react';
import clsx from 'clsx';
import { AlertTriangle, Maximize2, Minus, Plus, X } from 'lucide-react';
import { bounds, edgePath, inPoint, nodeMap, outPoint, setEdge } from './graph';
import { NODE_H, NODE_SPECS, NODE_W, PORT_LABEL, PORT_TONE, nodeSummary, nodeTitle } from './nodeTypes';
import type { Definition, Issue, Meta, NodeType, Port, WfNode } from './types';

export type Selection = { kind: 'node'; id: string } | { kind: 'edge'; from: string; label: Port } | null;

export interface CanvasHandle {
  fit: () => void;
  zoomBy: (factor: number) => void;
  centerOn: (id: string) => void;
}

export const NODE_MIME = 'application/x-gimt-wf-node';
const GRID = 10;
const snap = (v: number) => Math.round(v / GRID) * GRID;
const clamp = (v: number, a: number, b: number) => Math.min(b, Math.max(a, v));

type Drag =
  | { mode: 'pan'; sx: number; sy: number; vx: number; vy: number; moved: boolean }
  | { mode: 'node'; id: string; sx: number; sy: number; ox: number; oy: number; base: Definition; moved: boolean }
  | { mode: 'connect'; from: string; port: Port; moved: boolean };

interface Props {
  def: Definition;
  meta?: Meta;
  selection: Selection;
  onSelect: (s: Selection) => void;
  /** record = add an undo step; base = the state to record (drag start) */
  onChange: (def: Definition, record: boolean, base?: Definition) => void;
  onDropType: (type: NodeType, pos: { x: number; y: number }) => void;
  issues: Issue[];
  readOnly?: boolean;
  /** step status per node from the last test run */
  trace?: Record<string, string>;
  /** bump to fit the view to the steps after the next render (load, auto-layout) */
  fitSignal?: number;
  className?: string;
}

/** Drag-and-drop workflow canvas: HTML nodes over an SVG edge layer, pan (drag background / wheel), zoom (ctrl + wheel). */
export const Canvas = forwardRef<CanvasHandle, Props>(function Canvas({ def, meta, selection, onSelect, onChange, onDropType, issues, readOnly, trace, fitSignal, className }, ref) {
  const wrapRef = useRef<HTMLDivElement>(null);
  const [view, setView] = useState({ x: 60, y: 40, k: 1 });
  const viewRef = useRef(view);
  viewRef.current = view;
  const defRef = useRef(def);
  defRef.current = def;
  const drag = useRef<Drag | null>(null);
  const [connect, setConnect] = useState<{ from: string; port: Port; x: number; y: number; target: string | null } | null>(null);
  const [dropHint, setDropHint] = useState(false);

  const nodes = useMemo(() => nodeMap(def), [def]);
  const issueMap = useMemo(() => {
    const m: Record<string, { errors: number; warnings: number; first: string }> = {};
    issues.forEach((i) => {
      if (!i.node) return;
      const e = (m[i.node] ??= { errors: 0, warnings: 0, first: i.message });
      if (i.level === 'error') {
        if (!e.errors) e.first = i.message;
        e.errors++;
      } else e.warnings++;
    });
    return m;
  }, [issues]);

  const toCanvas = useCallback((cx: number, cy: number) => {
    const r = wrapRef.current!.getBoundingClientRect();
    const v = viewRef.current;
    return { x: (cx - r.left - v.x) / v.k, y: (cy - r.top - v.y) / v.k };
  }, []);

  /** 'all' = whole workflow on screen; 'top' = readable zoom (≥ 70 %) fitted to the width, starting at the trigger. */
  const fit = useCallback((mode: 'all' | 'top' = 'all') => {
    const el = wrapRef.current;
    if (!el || !el.clientWidth) return;
    const b = bounds(defRef.current.nodes);
    const w = el.clientWidth;
    const h = el.clientHeight;
    const bw = b.maxX - b.minX + 120;
    const bh = b.maxY - b.minY + 100;
    const all = clamp(Math.min(w / bw, h / bh), 0.35, 1.1);
    const k = mode === 'top' ? Math.max(all, clamp(w / bw, 0.7, 1)) : all;
    const tall = (b.maxY - b.minY) * k > h - 60;
    const trigger = defRef.current.nodes.find((n) => n.type === 'trigger');
    // too wide for the view: centre the trigger (the start of the flow) instead of the whole graph
    const x = (b.maxX - b.minX) * k > w - 40 ? (trigger ? w / 2 - (trigger.x + NODE_W / 2) * k : 40 - b.minX * k) : (w - (b.maxX - b.minX) * k) / 2 - b.minX * k;
    setView({ k, x, y: (tall ? 36 : Math.max(30, (h - (b.maxY - b.minY) * k) / 2)) - b.minY * k });
  }, []);

  const zoomAt = useCallback((factor: number, px?: number, py?: number) => {
    const el = wrapRef.current;
    if (!el) return;
    const v = viewRef.current;
    const k = clamp(v.k * factor, 0.3, 1.8);
    const cx = px ?? el.clientWidth / 2;
    const cy = py ?? el.clientHeight / 2;
    setView({ k, x: cx - ((cx - v.x) / v.k) * k, y: cy - ((cy - v.y) / v.k) * k });
  }, []);

  // fit after the definition of this render is on screen (the wrapper may get its size a frame later)
  useEffect(() => {
    if (!fitSignal) return;
    fit('top');
    const t = window.setTimeout(() => fit('top'), 120);
    return () => window.clearTimeout(t);
  }, [fitSignal, fit]);

  useImperativeHandle(ref, () => ({
    fit,
    zoomBy: (f) => zoomAt(f),
    centerOn: (id) => {
      const n = defRef.current.nodes.find((x) => x.id === id);
      const el = wrapRef.current;
      if (!n || !el) return;
      const v = viewRef.current;
      setView({ k: v.k, x: el.clientWidth / 2 - (n.x + NODE_W / 2) * v.k, y: el.clientHeight / 2 - (n.y + NODE_H / 2) * v.k });
    },
  }), [fit, zoomAt]);

  // wheel: pan, ctrl/cmd + wheel (and trackpad pinch) zoom — non-passive so the page does not scroll
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onWheel = (e: WheelEvent) => {
      e.preventDefault();
      const r = el.getBoundingClientRect();
      if (e.ctrlKey || e.metaKey) {
        zoomAt(Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top);
      } else {
        const v = viewRef.current;
        setView({ ...v, x: v.x - e.deltaX, y: v.y - e.deltaY });
      }
    };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, [zoomAt]);

  const hitNode = useCallback((p: { x: number; y: number }, exclude?: string) => {
    const list = defRef.current.nodes;
    for (let i = list.length - 1; i >= 0; i--) {
      const n = list[i];
      if (n.id !== exclude && p.x >= n.x - 8 && p.x <= n.x + NODE_W + 8 && p.y >= n.y - 12 && p.y <= n.y + NODE_H + 8) return n;
    }
    return null;
  }, []);

  // window-level pointer tracking while dragging
  useEffect(() => {
    const move = (e: PointerEvent) => {
      const d = drag.current;
      if (!d) return;
      if (d.mode === 'pan') {
        const dx = e.clientX - d.sx;
        const dy = e.clientY - d.sy;
        if (Math.abs(dx) + Math.abs(dy) > 3) d.moved = true;
        setView((v) => ({ ...v, x: d.vx + dx, y: d.vy + dy }));
      } else if (d.mode === 'node') {
        const k = viewRef.current.k;
        const dx = (e.clientX - d.sx) / k;
        const dy = (e.clientY - d.sy) / k;
        if (!d.moved && Math.abs(dx) + Math.abs(dy) < 3) return;
        d.moved = true;
        const cur = defRef.current;
        onChange({ ...cur, nodes: cur.nodes.map((n) => (n.id === d.id ? { ...n, x: snap(d.ox + dx), y: snap(d.oy + dy) } : n)) }, false);
      } else {
        d.moved = true;
        const p = toCanvas(e.clientX, e.clientY);
        const t = hitNode(p, d.from);
        setConnect({ from: d.from, port: d.port, x: p.x, y: p.y, target: t && t.type !== 'trigger' ? t.id : null });
      }
    };
    const up = (e: PointerEvent) => {
      const d = drag.current;
      if (!d) return;
      drag.current = null;
      if (d.mode === 'pan') {
        if (!d.moved) onSelect(null);
      } else if (d.mode === 'node') {
        if (d.moved) onChange(defRef.current, true, d.base);
      } else {
        const t = hitNode(toCanvas(e.clientX, e.clientY), d.from);
        setConnect(null);
        if (t && t.type !== 'trigger') {
          onChange(setEdge(defRef.current, d.from, d.port, t.id), true);
          onSelect({ kind: 'edge', from: d.from, label: d.port });
        }
      }
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
    return () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      window.removeEventListener('pointercancel', up);
    };
  }, [onChange, onSelect, toCanvas, hitNode]);

  const startPan = (e: ReactPointerEvent) => {
    if (e.button !== 0 && e.button !== 1) return;
    drag.current = { mode: 'pan', sx: e.clientX, sy: e.clientY, vx: viewRef.current.x, vy: viewRef.current.y, moved: false };
  };

  const startNode = (e: ReactPointerEvent, n: WfNode) => {
    if (e.button !== 0) return;
    e.stopPropagation();
    onSelect({ kind: 'node', id: n.id });
    if (readOnly) return;
    drag.current = { mode: 'node', id: n.id, sx: e.clientX, sy: e.clientY, ox: n.x, oy: n.y, base: defRef.current, moved: false };
  };

  const startConnect = (e: ReactPointerEvent, n: WfNode, port: Port) => {
    if (e.button !== 0 || readOnly) return;
    e.stopPropagation();
    e.preventDefault();
    const p = outPoint(n, port);
    drag.current = { mode: 'connect', from: n.id, port, moved: false };
    setConnect({ from: n.id, port, x: p.x, y: p.y + 30, target: null });
  };

  const onNodeKey = (e: KeyboardEvent, n: WfNode) => {
    if (readOnly) return;
    const step = e.shiftKey ? 50 : GRID;
    const delta: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    const dlt = delta[e.key];
    if (dlt) {
      e.preventDefault();
      onChange({ ...def, nodes: def.nodes.map((x) => (x.id === n.id ? { ...x, x: x.x + dlt[0], y: x.y + dlt[1] } : x)) }, true);
    }
  };

  // edges
  const edgeEls = def.edges.map((e) => {
    const a = nodes[e.from];
    const b = nodes[e.to];
    if (!a || !b) return null;
    const { d, mid } = edgePath(outPoint(a, e.label), inPoint(b));
    const sel = selection?.kind === 'edge' && selection.from === e.from && selection.label === e.label;
    const traced = trace && trace[e.from] && trace[e.to];
    return { key: `${e.from}|${e.label}`, e, d, mid, sel, traced };
  });

  // minimap geometry
  const mm = useMemo(() => {
    const el = wrapRef.current;
    const b = bounds(def.nodes);
    const vw = el ? el.clientWidth / view.k : 800;
    const vh = el ? el.clientHeight / view.k : 500;
    const vx = -view.x / view.k;
    const vy = -view.y / view.k;
    const minX = Math.min(b.minX, vx) - 40;
    const minY = Math.min(b.minY, vy) - 40;
    const maxX = Math.max(b.maxX, vx + vw) + 40;
    const maxY = Math.max(b.maxY, vy + vh) + 40;
    const s = Math.min(150 / (maxX - minX), 96 / (maxY - minY));
    return { minX, minY, s, vx, vy, vw, vh };
  }, [def.nodes, view]);

  const onMinimap = (e: ReactPointerEvent<SVGSVGElement>) => {
    e.stopPropagation();
    const r = e.currentTarget.getBoundingClientRect();
    const cx = (e.clientX - r.left) / mm.s + mm.minX;
    const cy = (e.clientY - r.top) / mm.s + mm.minY;
    const el = wrapRef.current!;
    setView((v) => ({ ...v, x: el.clientWidth / 2 - cx * v.k, y: el.clientHeight / 2 - cy * v.k }));
  };

  const connectFrom = connect ? nodes[connect.from] : null;

  return (
    <div
      ref={wrapRef}
      className={clsx(
        'relative h-full w-full touch-none overflow-hidden bg-slate-50 outline-none dark:bg-slate-950',
        '[background-image:radial-gradient(#cbd5e1_1px,transparent_1px)] dark:[background-image:radial-gradient(#1e293b_1px,transparent_1px)]',
        drag.current?.mode === 'pan' ? 'cursor-grabbing' : 'cursor-grab',
        dropHint && 'ring-2 ring-inset ring-accent-400',
        className,
      )}
      style={{ backgroundSize: `${20 * view.k}px ${20 * view.k}px`, backgroundPosition: `${view.x}px ${view.y}px` }}
      onPointerDown={startPan}
      onScroll={(e) => {
        // focusing a step (Tab) or scrollIntoView scrolls this overflow-hidden box: turn that into a pan instead
        const el = e.currentTarget;
        if (el.scrollTop || el.scrollLeft) {
          const dx = el.scrollLeft;
          const dy = el.scrollTop;
          el.scrollTop = 0;
          el.scrollLeft = 0;
          setView((v) => ({ ...v, x: v.x - dx, y: v.y - dy }));
        }
      }}
      onDragOver={(e) => {
        if (readOnly || !e.dataTransfer.types.includes(NODE_MIME)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        setDropHint(true);
      }}
      onDragLeave={() => setDropHint(false)}
      onDrop={(e) => {
        setDropHint(false);
        const type = e.dataTransfer.getData(NODE_MIME) as NodeType;
        if (readOnly || !NODE_SPECS[type]) return;
        e.preventDefault();
        const p = toCanvas(e.clientX, e.clientY);
        onDropType(type, { x: snap(p.x - NODE_W / 2), y: snap(p.y - NODE_H / 2) });
      }}
      aria-label="Workflow canvas"
      role="application"
    >
      <div className="absolute left-0 top-0" style={{ transform: `translate(${view.x}px, ${view.y}px) scale(${view.k})`, transformOrigin: '0 0' }}>
        <svg className="absolute left-0 top-0 overflow-visible" width={1} height={1} style={{ pointerEvents: 'none' }} aria-hidden>
          <defs>
            <marker id="wf-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
              <path d="M 0 0 L 10 5 L 0 10 z" className="fill-slate-400 dark:fill-slate-500" />
            </marker>
            <marker id="wf-arrow-sel" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
              <path d="M 0 0 L 10 5 L 0 10 z" className="fill-accent-600" />
            </marker>
          </defs>
          {edgeEls.map((x) =>
            x ? (
              <g key={x.key}>
                <path
                  d={x.d}
                  fill="none"
                  strokeWidth={x.sel ? 2.5 : 2}
                  markerEnd={x.sel ? 'url(#wf-arrow-sel)' : 'url(#wf-arrow)'}
                  className={clsx(x.sel ? 'stroke-accent-600' : x.traced ? 'stroke-emerald-500' : 'stroke-slate-300 dark:stroke-slate-600')}
                  strokeDasharray={x.e.label === 'false' || x.e.label === 'timeout' ? '6 4' : undefined}
                />
                <path
                  d={x.d}
                  fill="none"
                  stroke="transparent"
                  strokeWidth={16}
                  style={{ pointerEvents: 'stroke', cursor: 'pointer' }}
                  onPointerDown={(ev) => {
                    ev.stopPropagation();
                    onSelect({ kind: 'edge', from: x.e.from, label: x.e.label });
                  }}
                />
              </g>
            ) : null,
          )}
          {connect && connectFrom && (
            <path
              d={edgePath(outPoint(connectFrom, connect.port), connect.target && nodes[connect.target] ? inPoint(nodes[connect.target]) : { x: connect.x, y: connect.y }).d}
              fill="none"
              strokeWidth={2}
              strokeDasharray="5 4"
              className="stroke-accent-500"
            />
          )}
        </svg>

        {/* edge labels + delete button of the selected edge */}
        {edgeEls.map((x) =>
          x && (x.e.label !== 'next' || x.sel) ? (
            <div key={`l-${x.key}`} className="absolute flex -translate-x-1/2 -translate-y-1/2 items-center gap-1" style={{ left: x.mid.x, top: x.mid.y }}>
              {x.e.label !== 'next' && (
                <button
                  type="button"
                  tabIndex={-1}
                  onPointerDown={(ev) => {
                    ev.stopPropagation();
                    onSelect({ kind: 'edge', from: x.e.from, label: x.e.label });
                  }}
                  className={clsx('rounded-full px-2 py-0.5 text-[10px] font-semibold shadow-sm ring-1 ring-white dark:ring-slate-900', PORT_TONE[x.e.label], x.sel && 'ring-2 ring-accent-500')}
                >
                  {PORT_LABEL[x.e.label]}
                </button>
              )}
              {x.sel && !readOnly && (
                <button
                  type="button"
                  aria-label="Delete connection"
                  title="Delete connection (Del)"
                  onPointerDown={(ev) => ev.stopPropagation()}
                  onClick={() => {
                    onChange(setEdge(def, x.e.from, x.e.label, null), true);
                    onSelect(null);
                  }}
                  className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-accent-600 text-white shadow hover:bg-accent-700"
                >
                  <X className="h-3 w-3" />
                </button>
              )}
            </div>
          ) : null,
        )}

        {def.nodes.map((n) => {
          const spec = NODE_SPECS[n.type];
          const Icon = spec.icon;
          const sel = selection?.kind === 'node' && selection.id === n.id;
          const iss = issueMap[n.id];
          const tr = trace?.[n.id];
          const isTarget = connect?.target === n.id;
          return (
            <div
              key={n.id}
              role="button"
              tabIndex={0}
              aria-pressed={sel}
              aria-label={`${spec.label}: ${nodeTitle(n)}. ${nodeSummary(n, meta)}${iss?.errors ? `. ${iss.first}` : ''}`}
              data-node={n.id}
              onPointerDown={(e) => startNode(e, n)}
              onFocus={() => !sel && onSelect({ kind: 'node', id: n.id })}
              onKeyDown={(e) => onNodeKey(e, n)}
              className={clsx(
                'absolute select-none rounded-xl border bg-white text-left shadow-sm outline-none transition-shadow dark:bg-slate-900',
                readOnly ? 'cursor-pointer' : 'cursor-move',
                sel ? 'border-accent-500 shadow-md ring-4 ring-accent-500/20' : isTarget ? 'border-accent-400 ring-4 ring-accent-400/30' : iss?.errors ? 'border-red-300 dark:border-red-500/50' : 'border-slate-200 hover:shadow-md dark:border-slate-700',
                'focus-visible:ring-4 focus-visible:ring-brand-500/30',
                tr === 'ok' && !sel && 'ring-2 ring-emerald-400/70',
                tr === 'failed' && !sel && 'ring-2 ring-red-500/70',
                tr === 'waiting' && !sel && 'ring-2 ring-amber-400/70',
                tr === 'skipped' && !sel && 'ring-2 ring-slate-400/60',
              )}
              style={{ left: n.x, top: n.y, width: NODE_W, height: NODE_H }}
            >
              <span className={clsx('absolute inset-y-3 left-0 w-1 rounded-r-full', spec.bar)} aria-hidden />
              <div className="flex items-start gap-2.5 px-3.5 pt-3">
                <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', spec.chip)}>
                  <Icon className="h-4 w-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1 leading-tight">
                  <p className="truncate text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">{spec.label}</p>
                  {String(n.data.label ?? '').trim() ? (
                    <>
                      <p className="truncate text-[13px] font-semibold text-slate-900 dark:text-white">{nodeTitle(n)}</p>
                      <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">{nodeSummary(n, meta)}</p>
                    </>
                  ) : (
                    <p className="mt-0.5 line-clamp-2 text-[12.5px] font-semibold leading-snug text-slate-900 dark:text-white">{nodeSummary(n, meta)}</p>
                  )}
                </div>
                {iss && (iss.errors || iss.warnings) ? (
                  <span
                    title={iss.first}
                    className={clsx('inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center gap-0.5 rounded-full px-1 text-[10px] font-bold', iss.errors ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200')}
                  >
                    <AlertTriangle className="h-3 w-3" aria-hidden />
                    {iss.errors || iss.warnings}
                  </span>
                ) : null}
              </div>
              {n.type !== 'trigger' && (
                <span className={clsx('absolute -top-[7px] left-1/2 h-3.5 w-3.5 -translate-x-1/2 rounded-full border-2 border-white dark:border-slate-900', isTarget ? 'bg-accent-500' : 'bg-slate-300 dark:bg-slate-600')} aria-hidden />
              )}
              {spec.ports.map((p) => {
                const pt = outPoint(n, p);
                return (
                  <span key={p} className="absolute" style={{ left: pt.x - n.x, top: NODE_H }}>
                    {spec.ports.length > 1 && <span className={clsx('absolute -top-[22px] -translate-x-1/2 whitespace-nowrap rounded px-1 text-[9px] font-bold uppercase', PORT_TONE[p])}>{PORT_LABEL[p]}</span>}
                    <button
                      type="button"
                      tabIndex={-1}
                      aria-label={`Drag to connect ${PORT_LABEL[p]}`}
                      title={readOnly ? PORT_LABEL[p] : `Drag to connect "${PORT_LABEL[p]}"`}
                      onPointerDown={(e) => startConnect(e, n, p)}
                      className={clsx(
                        'absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow transition-transform dark:border-slate-900',
                        readOnly ? 'cursor-default' : 'cursor-crosshair hover:scale-125',
                        p === 'false' ? 'bg-rose-500' : p === 'timeout' ? 'bg-amber-500' : p === 'true' || p === 'replied' ? 'bg-emerald-500' : 'bg-accent-600',
                      )}
                    />
                  </span>
                );
              })}
            </div>
          );
        })}
      </div>

      {/* zoom controls */}
      <div className="absolute bottom-3 left-3 flex items-center gap-0.5 rounded-xl border border-slate-200 bg-white/95 p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900/95" onPointerDown={(e) => e.stopPropagation()}>
        <button type="button" className="btn-icon !h-8 !w-8" onClick={() => zoomAt(1 / 1.2)} aria-label="Zoom out" title="Zoom out">
          <Minus className="h-4 w-4" />
        </button>
        <span className="w-11 text-center text-xs font-semibold tabular-nums text-slate-600 dark:text-slate-300">{Math.round(view.k * 100)}%</span>
        <button type="button" className="btn-icon !h-8 !w-8" onClick={() => zoomAt(1.2)} aria-label="Zoom in" title="Zoom in">
          <Plus className="h-4 w-4" />
        </button>
        <button type="button" className="btn-icon !h-8 !w-8" onClick={() => fit()} aria-label="Fit to screen" title="Fit to screen">
          <Maximize2 className="h-4 w-4" />
        </button>
      </div>

      {/* minimap */}
      {def.nodes.length > 0 && (
        <div className="absolute bottom-3 right-3 hidden overflow-hidden rounded-xl border border-slate-200 bg-white/95 shadow-sm dark:border-slate-700 dark:bg-slate-900/95 2xl:block" onPointerDown={(e) => e.stopPropagation()}>
          <svg width={150} height={96} onPointerDown={onMinimap} className="cursor-pointer" role="img" aria-label="Minimap — click to move the view">
            {def.nodes.map((n) => (
              <rect key={n.id} x={(n.x - mm.minX) * mm.s} y={(n.y - mm.minY) * mm.s} width={Math.max(3, NODE_W * mm.s)} height={Math.max(2, NODE_H * mm.s)} rx={2} fill={NODE_SPECS[n.type].hex} opacity={0.85} />
            ))}
            <rect x={(mm.vx - mm.minX) * mm.s} y={(mm.vy - mm.minY) * mm.s} width={mm.vw * mm.s} height={mm.vh * mm.s} fill="none" strokeWidth={1.5} className="stroke-accent-500" rx={3} />
          </svg>
        </div>
      )}
    </div>
  );
});
