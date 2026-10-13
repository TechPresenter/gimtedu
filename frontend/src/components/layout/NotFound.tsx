import { Compass } from 'lucide-react';
import { Button, EmptyState } from '@/components/ui';
import { useDocumentTitle } from '@/lib/hooks';

export default function NotFound() {
  useDocumentTitle('Page not found');
  return (
    <div className="card mx-auto mt-8 max-w-xl">
      <EmptyState icon={Compass} title="Page not found" description="The page you are looking for does not exist or has been moved." action={<Button to="/">Go to dashboard</Button>} />
    </div>
  );
}
