import { useEffect, useState, type ReactNode } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import type { Paged } from '../lib';

/** Paged list from the admin log endpoints (rows + pagination + extra summary keys). */
export function usePaged<T, X = Record<string, unknown>>(name: string, path: string, query: Query) {
  return useQuery({
    queryKey: ['wa', 'admin', name, query],
    queryFn: ({ signal }) => api.get<Paged<T> & X>(path, query, { signal }),
    placeholderData: keepPreviousData,
  });
}

/** Responsive filter row (stacks on phones). */
export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="flex flex-col gap-2 border-b border-slate-100 p-3 sm:flex-row sm:flex-wrap sm:items-center dark:border-slate-800">{children}</div>;
}

/** Debounced copy of a value (search boxes). */
export function useDebounced<T>(value: T, ms = 350): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = window.setTimeout(() => setV(value), ms);
    return () => window.clearTimeout(t);
  }, [value, ms]);
  return v;
}

/** Drop empty values from a filter object. */
export function clean(q: Record<string, string | number | undefined | null>): Query {
  const out: Query = {};
  Object.entries(q).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') out[k] = v;
  });
  return out;
}
