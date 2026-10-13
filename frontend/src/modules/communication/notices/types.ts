import type { Row } from '@/lib/types';
import type { AudienceSummary, ChannelsState, QueueProgress } from '../shared';

export interface NoticeSummary {
  total: number;
  live: number;
  scheduled: number;
  draft: number;
  expired: number;
  pinned: number;
  expiring: number;
  this_month: number;
  views: number;
  delivered_30d: number;
  failed_30d: number;
  pending: number;
  pending_notices: { id: number; title: string; pending: number }[];
  by_category: { key: string; label: string; total: number; live: number }[];
  by_audience: { key: string; label: string; total: number }[];
  channels: ChannelsState;
}

export interface NoticeDelivery {
  channels: { channel: string; sent: number; failed: number; queued: number }[];
  pending: number;
  last_sent_at: string | null;
  total_sent: number;
  total_failed: number;
}

export interface NoticeDetail {
  notice: Row;
  delivery: NoticeDelivery;
  audience: AudienceSummary;
}

export interface PublishResult {
  batch: string | null;
  queued: Record<string, number>;
  notified: number;
  progress: QueueProgress | null;
  scheduled: boolean;
}
