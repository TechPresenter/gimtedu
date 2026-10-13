import type { Row } from '@/lib/types';
import type { AudienceSummary, ChannelsState, QueueProgress } from '../shared';

export interface NewsletterSummary {
  subscribers: { total: number; subscribed: number; unsubscribed: number; bounced: number; new_month: number; lost_month: number };
  campaigns: { sent: number; scheduled: number; drafts: number; sending: number; delivered: number; failed: number };
  last30: { sent: number; failed: number };
  growth: { labels: string[]; joined: number[]; left: number[] };
  by_source: { key: string; label: string; count: number }[];
  active: { id: number; title: string; recipients_count: number; sent_count: number; failed_count: number; status: string; progress: QueueProgress }[];
  next_scheduled: { id: number; title: string; scheduled_at: string } | null;
  recent: { id: number; title: string; sent_at: string; recipients: number; sent: number; failed: number }[];
  started: number[];
  channels: ChannelsState;
  /** url / command / windows only for users who can manage communication */
  cron: { url?: string; command?: string; windows?: string; last_run: string | null };
}

export interface CampaignDetail {
  campaign: Row;
  progress: QueueProgress;
  errors: { error: string; count: number }[];
  audience: AudienceSummary | null;
}

export interface CampaignRef {
  id: number;
  title: string;
  channel?: string;
  recipients?: number;
}
