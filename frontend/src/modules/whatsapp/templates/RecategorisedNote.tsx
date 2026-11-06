import { Badge } from '@/components/ui';
import { formatDateTime } from '@/lib/format';
import { CATEGORY_TONE } from './lib';
import type { TplRecategorised } from './types';

const label = (c: string) => CATEGORY_TONE[c as keyof typeof CATEGORY_TONE]?.label ?? c;

/** What Meta's re-categorisation means for sending (shared by the library badge and the detail drawer). */
export function recategorisedText(r: TplRecategorised, name?: string): string {
  const what = `Meta re-categorised ${name ? `"${name}"` : 'this template'} from ${label(r.from)} to ${label(r.to)} (Interakt sync, ${formatDateTime(r.at)}).`;
  if (r.to === 'MARKETING') {
    return `${what} ${r.from === 'UTILITY' ? 'Utility sends would now fail at Interakt, so GIMT' : 'GIMT'} sends it as Marketing: a marketing opt-in is required — automated messages to contacts without one are skipped, including those already queued. For transactional messages, create a utility version without promotional wording.`;
  }
  if (r.to === 'UTILITY') return `${what} GIMT now sends it as Utility; a marketing opt-in is no longer required for it.`;
  return `${what} Check the events, workflows and campaigns that use it.`;
}

/** Small badge on library cards / rows; the full explanation is in the tooltip. */
export function RecategorisedBadge({ r }: { r: TplRecategorised }) {
  return (
    <span title={recategorisedText(r)} className="inline-flex">
      <Badge color={r.to === 'MARKETING' ? 'red' : 'navy'}>Re-categorised by Meta</Badge>
      <span className="sr-only">{recategorisedText(r)}</span>
    </span>
  );
}
