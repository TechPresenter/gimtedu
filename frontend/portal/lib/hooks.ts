import { useEffect } from 'react';
import { keepPreviousData, useQuery, type UseQueryOptions } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import { AREA_LABEL, type Area } from './config';

/** document.title = "<title> · Student Portal · GIMT" */
export function usePortalTitle(title: string | undefined, area?: Area | null) {
  useEffect(() => {
    if (!title) return;
    document.title = [title, area ? AREA_LABEL[area] : 'Portal', 'GIMT'].join(' · ');
  }, [title, area]);
}

/**
 * GET an area endpoint: usePortalQuery('student', ['dashboard'], 'dashboard') -> GET /api/portal/student/dashboard.
 * Cached under ['portal', area, ...key] so signing out (useMarkSignedOut) drops it.
 */
export function usePortalQuery<T>(area: Area, key: unknown[], path: string, query?: Query, opts: Partial<UseQueryOptions<T>> = {}) {
  return useQuery<T>({
    queryKey: ['portal', area, ...key, query ?? null],
    queryFn: ({ signal }) => api.get<T>(`portal/${area}/${path.replace(/^\/+/, '')}`, query, { signal }),
    placeholderData: keepPreviousData,
    ...opts,
  });
}
