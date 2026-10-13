/** Maps common status words to badge colours (kept in sync with app/components.php status_badge()). */
export type BadgeColor = 'green' | 'amber' | 'red' | 'blue' | 'purple' | 'cyan' | 'slate' | 'navy';

const groups: Record<BadgeColor, string[]> = {
  green: ['active', 'paid', 'present', 'approved', 'published', 'success', 'confirmed', 'completed', 'pass', 'issued', 'verified', 'available', 'selected', 'accepted', 'joined', 'resolved', 'subscribed', 'sent', 'open', 'converted', 'returned', 'placed', 'online', 'yes', 'cleared', 'enabled', 'promoted', 'received', 'processed', 'attended', 'studying', 'registered'],
  amber: ['pending', 'partial', 'late', 'draft', 'scheduled', 'in_progress', 'on_leave', 'leave', 'review', 'shortlisted', 'interested', 'contacted', 'upcoming', 'marks_entry', 'submitted', 'on_hold', 'maintenance', 'queued', 'reserved', 'half_day', 'processing', 'withheld', 'offered', 'medium', 'pledged', 'sending', 'probation'],
  red: ['inactive', 'overdue', 'absent', 'rejected', 'failed', 'fail', 'cancelled', 'revoked', 'blocked', 'locked', 'suspended', 'dropped', 'lost', 'damaged', 'not_interested', 'declined', 'urgent', 'high', 'unpaid', 'backlog', 'expired', 'malpractice', 'no', 'bounced', 'detained', 'critical', 'error'],
  blue: ['new', 'ongoing', 'applied', 'issued_book', 'application', 'enquiry', 'graduated', 'alumni', 'read', 'replied', 'aptitude', 'technical', 'hr', 'full', 'occupied', 'low', 'info', 'logout'],
  purple: ['document_verification', 'entrance_interview', 'approval', 'fee_payment', 'archived', 'withdrawn', 'vacated', 'refunded', 'waived', 'unsubscribed', 'closed'],
  cyan: ['login', 'mock_test', 'training', 'workshop'],
  slate: [],
  navy: [],
};

export function statusColor(status: string | null | undefined): BadgeColor {
  const s = String(status ?? '').toLowerCase();
  for (const [color, words] of Object.entries(groups) as [BadgeColor, string[]][]) {
    if (words.includes(s)) return color;
  }
  return 'slate';
}

export const badgeClass: Record<BadgeColor, string> = {
  green: 'badge-green', amber: 'badge-amber', red: 'badge-red', blue: 'badge-blue', purple: 'badge-purple', cyan: 'badge-cyan', slate: 'badge-slate', navy: 'badge-navy',
};

/** Admission pipeline stages in order. */
export const ADMISSION_STAGES = [
  { key: 'enquiry', label: 'Enquiry' },
  { key: 'application', label: 'Application' },
  { key: 'document_verification', label: 'Document Verification' },
  { key: 'entrance_interview', label: 'Entrance / Interview' },
  { key: 'approval', label: 'Approval' },
  { key: 'fee_payment', label: 'Fee Payment' },
  { key: 'confirmed', label: 'Admission Confirmed' },
] as const;
