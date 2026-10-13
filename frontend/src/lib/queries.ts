import { keepPreviousData, useQuery, useQueryClient, type UseQueryOptions } from '@tanstack/react-query';
import { api, type Query } from './api';
import type { Option, Paginated, Row } from './types';
import type { CrudMeta } from '@/components/crud/types';

/** Module meta (fields, columns, filters, permissions) - cached for the session. */
export function useCrudMeta(module: string) {
  return useQuery({
    queryKey: ['crud-meta', module],
    queryFn: () => api.get<CrudMeta>(`crud/${module}/meta`),
    staleTime: 5 * 60 * 1000,
  });
}

export function useCrudList<T extends Row = Row>(module: string, params: Query, enabled = true) {
  return useQuery({
    queryKey: ['crud', module, params],
    queryFn: ({ signal }) => api.get<Paginated<T>>(`crud/${module}`, params, { signal }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

/** Named lookup source (GET /api/lookup/{source}). */
export function useLookup(source: string | null | undefined, params: Query = {}, enabled = true) {
  return useQuery({
    queryKey: ['lookup', source, params],
    queryFn: ({ signal }) => api.get<Option[]>(`lookup/${source}`, params, { signal }),
    enabled: !!source && enabled,
    staleTime: 60 * 1000,
  });
}

/** Generic GET hook: useApi<Summary>(['fees-summary', sessionId], 'fees/summary', { session_id }) */
export function useApi<T>(key: unknown[], path: string, query?: Query, opts: Partial<UseQueryOptions<T>> = {}) {
  return useQuery<T>({
    queryKey: key,
    queryFn: ({ signal }) => api.get<T>(path, query, { signal }),
    ...opts,
  });
}

/** Invalidate all cached lists of a module (after custom actions). */
export function useInvalidate() {
  const qc = useQueryClient();
  return (...keys: string[]) => Promise.all(keys.map((k) => qc.invalidateQueries({ queryKey: [k] })));
}

export function useInvalidateCrud() {
  const qc = useQueryClient();
  return (module: string) => qc.invalidateQueries({ queryKey: ['crud', module] });
}
