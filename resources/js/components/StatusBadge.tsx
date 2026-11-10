import { Badge } from 'react-bootstrap';

export function StatusBadge({ value }: { value: string }) {
  const tone = ({ ACTIVE: 'success', PAID: 'success', APPROVED: 'success', RECEIVED: 'success', PARTIALLY_PAID:'warning', SUBMITTED: 'warning', REJECTED: 'danger', CLOSED: 'neutral', INACTIVE: 'neutral', UNPAID: 'neutral', DRAFT: 'neutral' } as Record<string, string>)[value] ?? 'primary';
  const bootstrapTone = tone === 'neutral' ? 'secondary' : tone;
  return <Badge pill bg={bootstrapTone} className={`status-badge status-${tone}`}><i/>{value.replaceAll('_', ' ')}</Badge>;
}
