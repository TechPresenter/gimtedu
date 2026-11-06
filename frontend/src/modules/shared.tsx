import { lazy, useEffect, useRef, type ComponentType, type LazyExoticComponent, type ReactNode } from 'react';
import type { RouteObject } from 'react-router-dom';
import { ShieldX } from 'lucide-react';
import { RequirePermission } from '@/components/layout/Guards';
import { Button, EmptyState, useToast } from '@/components/ui';
import { setDownloadErrorReporter } from '@/lib/api';
import { useAuth, type PermissionAction } from '@/lib/auth';
import { labelize } from '@/lib/format';
import { useDocumentTitle } from '@/lib/hooks';
import { permAllowed, type PermSpec } from '@/navigation';

/**
 * Build a permission-guarded, lazily loaded route.
 *   page('students', 'students', () => import('./StudentsPage'))
 *   page('students/new', 'students', () => import('./StudentFormPage'), 'create')
 *   page('whatsapp', ['whatsapp', 'whatsapp_reports'], () => import('./DashboardPage'))   // any of these modules
 */
export function page(path: string, perm: PermSpec, importer: () => Promise<{ default: ComponentType }>, action: PermissionAction = 'view'): RouteObject {
  const Component: LazyExoticComponent<ComponentType> = lazy(importer);
  return { path, element: guard(perm, <Component />, action) };
}

/** `perm` is one permission module, or a list of modules of which the user needs `action` on at least one. */
export function guard(perm: PermSpec, node: ReactNode, action: PermissionAction = 'view') {
  return (
    <>
      <DownloadErrorToasts />
      {typeof perm === 'string' ? (
        <RequirePermission module={perm} action={action}>
          {node}
        </RequirePermission>
      ) : (
        <RequireAnyPermission modules={perm} action={action}>
          {node}
        </RequireAnyPermission>
      )}
    </>
  );
}

/** Renders children when the user has `action` on any of `modules`; otherwise the friendly 403 state. */
function RequireAnyPermission({ modules, action, children }: { modules: readonly string[]; action: PermissionAction; children: ReactNode }) {
  const { can } = useAuth();
  if (!permAllowed(can, modules, action)) return <ForbiddenAny modules={modules} action={action} />;
  return <>{children}</>;
}

const moduleLabel = (m: string) => labelize(m).replace(/\bWhatsapp\b/g, 'WhatsApp');

function ForbiddenAny({ modules, action }: { modules: readonly string[]; action: PermissionAction }) {
  useDocumentTitle('Access denied');
  const labels = modules.map(moduleLabel);
  const list = labels.length > 1 ? `${labels.slice(0, -1).join(', ')} or ${labels[labels.length - 1]}` : (labels[0] ?? '');
  return (
    <div className="card mx-auto mt-8 max-w-xl">
      <EmptyState
        icon={ShieldX}
        title="You don't have access to this page"
        description={`Your role does not include the "${action}" permission for ${list}. Contact a Super Admin if you need access.`}
        action={<Button to="/">Back to dashboard</Button>}
      />
    </div>
  );
}

/**
 * Lets downloadFile() (lib/api) show its failures (403 export not allowed, 404, server errors) as the app's toast. Every
 * route goes through guard(), so a page that offers a download always has this mounted.
 */
function DownloadErrorToasts() {
  const toast = useToast();
  const latest = useRef(toast);
  latest.current = toast;
  useEffect(() => setDownloadErrorReporter((message) => latest.current.error(message)), []);
  return null;
}
