import { lazy, type ComponentType, type LazyExoticComponent, type ReactNode } from 'react';
import type { RouteObject } from 'react-router-dom';
import { Construction } from 'lucide-react';
import { RequirePermission } from '@/components/layout/Guards';
import { Card, EmptyState, PageHeader } from '@/components/ui';
import type { PermissionAction } from '@/lib/auth';

/**
 * Build a permission-guarded, lazily loaded route.
 *   page('students', 'students', () => import('./StudentsPage'))
 *   page('students/new', 'students', () => import('./StudentFormPage'), 'create')
 */
export function page(path: string, perm: string, importer: () => Promise<{ default: ComponentType }>, action: PermissionAction = 'view'): RouteObject {
  const Component: LazyExoticComponent<ComponentType> = lazy(importer);
  return { path, element: guard(perm, <Component />, action) };
}

export function guard(perm: string, node: ReactNode, action: PermissionAction = 'view') {
  return (
    <RequirePermission module={perm} action={action}>
      {node}
    </RequirePermission>
  );
}

/** Temporary placeholder used only while a module is being built. Must not remain in production. */
export function ModuleStub({ title }: { title: string }) {
  return (
    <>
      <PageHeader title={title} />
      <Card>
        <EmptyState icon={Construction} title={`${title} is being set up`} description="This screen will be available shortly." />
      </Card>
    </>
  );
}
