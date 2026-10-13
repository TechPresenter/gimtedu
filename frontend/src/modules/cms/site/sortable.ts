import { useState, type DragEvent } from 'react';

/** Move an element of an array (immutable). */
export function moveItem<T>(arr: T[], from: number, to: number): T[] {
  if (from === to || from < 0 || to < 0 || from >= arr.length || to >= arr.length) return arr;
  const next = arr.slice();
  const [it] = next.splice(from, 1);
  next.splice(to, 0, it);
  return next;
}

/**
 * Minimal HTML5 drag & drop reordering for vertical lists (no dependencies). Spread `dragProps(i)` on the draggable
 * element (row or handle area) and `dropProps(i)` on the row; `overIndex` / `dragIndex` drive the drop indicator.
 * Keyboard users reorder with the up/down buttons each list also renders.
 */
export function useSortable(onMove: (from: number, to: number) => void, group = 'list') {
  const [dragIndex, setDragIndex] = useState<number | null>(null);
  const [overIndex, setOverIndex] = useState<number | null>(null);
  const mime = `application/x-gimt-${group}`;
  const reset = () => {
    setDragIndex(null);
    setOverIndex(null);
  };
  return {
    dragIndex,
    overIndex,
    dragProps: (i: number) => ({
      draggable: true,
      onDragStart: (e: DragEvent) => {
        e.stopPropagation();
        setDragIndex(i);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData(mime, String(i));
        e.dataTransfer.setData('text/plain', String(i));
      },
      onDragEnd: reset,
    }),
    dropProps: (i: number) => ({
      onDragOver: (e: DragEvent) => {
        if (dragIndex === null || !e.dataTransfer.types.includes(mime)) return;
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'move';
        if (overIndex !== i) setOverIndex(i);
      },
      onDrop: (e: DragEvent) => {
        if (dragIndex === null || !e.dataTransfer.types.includes(mime)) return;
        e.preventDefault();
        e.stopPropagation();
        if (dragIndex !== i) onMove(dragIndex, i);
        reset();
      },
    }),
  };
}
