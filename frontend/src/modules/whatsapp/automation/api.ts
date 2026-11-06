import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import type { Meta, RunDetail, RunList, Workflow, WorkflowList, WorkflowStats } from './types';

/** Query keys of the automation area: ['wa', 'workflows', …]. */
export const WF_KEY = ['wa', 'workflows'] as const;

export function useWorkflowMeta() {
  return useQuery({
    queryKey: [...WF_KEY, 'meta'],
    queryFn: ({ signal }) => api.get<Meta>('whatsapp/workflows/meta', undefined, { signal }),
    staleTime: 60_000,
  });
}

export function useWorkflowList(params: Query) {
  return useQuery({
    queryKey: [...WF_KEY, 'list', params],
    queryFn: ({ signal }) => api.get<WorkflowList>('whatsapp/workflows', params, { signal }),
    placeholderData: keepPreviousData,
  });
}

export function useWorkflow(id: number | null) {
  return useQuery({
    queryKey: [...WF_KEY, 'one', id],
    queryFn: ({ signal }) => api.get<Workflow>(`whatsapp/workflows/${id}`, undefined, { signal }),
    enabled: !!id,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
}

export function useWorkflowStats(id: number | null, days = 30) {
  return useQuery({
    queryKey: [...WF_KEY, 'stats', id, days],
    queryFn: ({ signal }) => api.get<WorkflowStats>(`whatsapp/workflows/${id}/stats`, { days }, { signal }),
    enabled: !!id,
    staleTime: 30_000,
  });
}

export function useRunList(params: Query, poll = false) {
  return useQuery({
    queryKey: [...WF_KEY, 'runs', params],
    queryFn: ({ signal }) => api.get<RunList>('whatsapp/workflows/runs', params, { signal, background: poll }),
    placeholderData: keepPreviousData,
    refetchInterval: poll ? 30_000 : false,
    refetchIntervalInBackground: false,
  });
}

export function useRunDetail(id: number | null) {
  return useQuery({
    queryKey: [...WF_KEY, 'run', id],
    queryFn: ({ signal }) => api.get<RunDetail>(`whatsapp/workflows/runs/${id}`, undefined, { signal }),
    enabled: !!id,
  });
}

/** Refresh every automation query (lists, runs, KPIs). */
export function useWorkflowRefresh() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: [...WF_KEY] });
}
