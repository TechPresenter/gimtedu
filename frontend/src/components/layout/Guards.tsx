import type { ReactNode } from 'react';
import { ShieldX } from 'lucide-react';
import { useAuth, type PermissionAction } from '@/lib/auth';
import { Button, EmptyState } from '@/components/ui';
import { useDocumentTitle } from '@/lib/hooks';
import { labelize } from '@/lib/format';

/** Renders children only when the user has the permission; otherwise a friendly 403 state. */
export function RequirePermission({ module, action = 'view', children }: { module: string; action?: PermissionAction; children: ReactNode }) {
  const { can } = useAuth();
  if (!can(module, action)) return <Forbidden module={module} action={action} />;
  return <>{children}</>;
}

/** Readable names for permission modules whose key does not labelize well (mirrors app/registry.php labels). */
const MODULE_LABELS: Record<string, string> = {
  cms: 'Website CMS',
  seo: 'SEO Management',
  blog: 'Blog / News',
  media: 'Media Library & Gallery',
  faculty: 'Faculty & Staff',
  results: 'Results & Marksheets',
  fees: 'Fees & Accounts',
  notices: 'Notices & Circulars',
  communication: 'Newsletter / Email / SMS',
  feedback: 'Feedback & Complaints',
  reports: 'Reports & Analytics',
  roles: 'Roles & Permissions',
  settings: 'System Settings',
  backup: 'Backup & Restore',
};

export function Forbidden({ module, action }: { module?: string; action?: string }) {
  useDocumentTitle('Access denied');
  const label = module ? (MODULE_LABELS[module] ?? labelize(module)) : '';
  return (
    <div className="card mx-auto mt-8 max-w-xl">
      <EmptyState
        icon={ShieldX}
        title="You don't have access to this page"
        description={module ? `Your role does not include the "${action}" permission for ${label}. Contact a Super Admin if you need access.` : 'Contact a Super Admin if you need access.'}
        action={<Button to="/">Back to dashboard</Button>}
      />
    </div>
  );
}
