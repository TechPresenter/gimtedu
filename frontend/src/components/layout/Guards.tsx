import type { ReactNode } from 'react';
import { ShieldX } from 'lucide-react';
import { useAuth, type PermissionAction } from '@/lib/auth';
import { Button, EmptyState } from '@/components/ui';
import { useDocumentTitle } from '@/lib/hooks';

/** Renders children only when the user has the permission; otherwise a friendly 403 state. */
export function RequirePermission({ module, action = 'view', children }: { module: string; action?: PermissionAction; children: ReactNode }) {
  const { can } = useAuth();
  if (!can(module, action)) return <Forbidden module={module} action={action} />;
  return <>{children}</>;
}

export function Forbidden({ module, action }: { module?: string; action?: string }) {
  useDocumentTitle('Access denied');
  return (
    <div className="card mx-auto mt-8 max-w-xl">
      <EmptyState
        icon={ShieldX}
        title="You don't have access to this page"
        description={module ? `Your role does not include the "${action}" permission for ${module.replace(/_/g, ' ')}. Contact a Super Admin if you need access.` : 'Contact a Super Admin if you need access.'}
        action={<Button to="/">Back to dashboard</Button>}
      />
    </div>
  );
}
