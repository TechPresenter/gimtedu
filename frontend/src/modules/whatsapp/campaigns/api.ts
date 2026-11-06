import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useWaRefresh } from '../shared';
import { fromServerDate } from './lib';
import type { CampaignDetail, CampaignListResponse, CampaignMeta, RecipientsResponse } from './types';

/** Campaign API hooks. Every key starts with ['wa', 'campaigns'] so useCampaignRefresh() invalidates the area. */
export const cmpKeys = {
  list: (params: Record<string, unknown>) => ['wa', 'campaigns', 'list', params] as const,
  meta: ['wa', 'campaigns', 'meta'] as const,
  detail: (id: number) => ['wa', 'campaigns', 'detail', id] as const,
  recipients: (id: number, params: Record<string, unknown>) => ['wa', 'campaigns', 'recipients', id, params] as const,
};

export function useCampaignRefresh() {
  const refresh = useWaRefresh();
  return () => refresh('campaigns');
}

/** Campaign list + KPI summary; refreshes every 10 s while a campaign is sending. */
export function useCampaignList(params: Record<string, string | number | undefined>) {
  return useQuery({
    queryKey: cmpKeys.list(params),
    queryFn: ({ signal }) => api.get<CampaignListResponse>('whatsapp/campaigns', params, { signal, background: true }),
    placeholderData: keepPreviousData,
    refetchInterval: (q) => ((q.state.data?.summary.by_status.sending ?? 0) > 0 ? 10_000 : false),
    refetchIntervalInBackground: false,
  });
}

export function useCampaignMeta() {
  return useQuery({
    queryKey: cmpKeys.meta,
    queryFn: ({ signal }) => api.get<CampaignMeta>('whatsapp/campaigns/meta', undefined, { signal }),
    staleTime: 60_000,
  });
}

/** Campaign detail; polls every 5 s while the campaign is sending (or scheduled within the hour). */
export function useCampaign(id: number, opts: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: cmpKeys.detail(id),
    queryFn: ({ signal }) => api.get<CampaignDetail>(`whatsapp/campaigns/${id}`, undefined, { signal, background: true }),
    enabled: (opts.enabled ?? true) && id > 0,
    refetchInterval: (q) => {
      const c = q.state.data;
      if (!c) return false;
      if (c.status === 'sending') return 5_000;
      const scheduled = fromServerDate(c.scheduled_at);
      if (c.status === 'scheduled' && scheduled && scheduled.getTime() - Date.now() < 3_600_000) return 20_000;
      const completed = fromServerDate(c.completed_at);
      if (c.status === 'completed' && completed && Date.now() - completed.getTime() < 3_600_000) return 30_000;
      return false;
    },
    refetchIntervalInBackground: false,
  });
}

export function useRecipients(id: number, params: Record<string, string | number | undefined>, poll: boolean) {
  return useQuery({
    queryKey: cmpKeys.recipients(id, params),
    queryFn: ({ signal }) => api.get<RecipientsResponse>(`whatsapp/campaigns/${id}/recipients`, params, { signal, background: poll }),
    enabled: id > 0,
    placeholderData: keepPreviousData,
    refetchInterval: poll ? 8_000 : false,
    refetchIntervalInBackground: false,
  });
}
