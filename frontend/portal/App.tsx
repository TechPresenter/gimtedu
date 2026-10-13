import { Suspense, useEffect } from 'react';
import { RouterProvider } from 'react-router-dom';
import { QueryCache, QueryClient, QueryClientProvider, useQueryClient } from '@tanstack/react-query';
import { ConfirmProvider, ToastProvider } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { ThemeProvider } from '@/lib/theme';
import { Splash } from './components/Gates';
import { areaFromPath } from './lib/config';
import { isPasswordChangeRequired } from './lib/errors';
import { sessionKey, useMarkSignedOut } from './lib/session';
import { router } from './router';

const queryClient: QueryClient = new QueryClient({
  queryCache: new QueryCache({
    onError: (error) => {
      // A temporary password is still pending: reload the session so the forced "set a new password" screen shows.
      if (isPasswordChangeRequired(error)) {
        const area = areaFromPath(window.location.pathname);
        if (area) void queryClient.invalidateQueries({ queryKey: sessionKey(area) });
      }
    },
  }),
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
    },
  },
});

/** Server answered 401 (session expired / signed out elsewhere): mark the current area signed out -> sign-in screen. */
function ExpiryListener() {
  const markSignedOut = useMarkSignedOut();
  const qc = useQueryClient();
  useEffect(() => {
    const onExpired = () => {
      const area = areaFromPath(window.location.pathname);
      if (!area) return;
      const current = qc.getQueryData<{ authenticated?: boolean }>(sessionKey(area));
      if (current?.authenticated) markSignedOut(area, 'expired');
    };
    window.addEventListener('auth:expired', onExpired);
    return () => window.removeEventListener('auth:expired', onExpired);
  }, [markSignedOut, qc]);
  return null;
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <ToastProvider>
          <ConfirmProvider>
            <ExpiryListener />
            <Suspense fallback={<Splash />}>
              <RouterProvider router={router} />
            </Suspense>
          </ConfirmProvider>
        </ToastProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
}
