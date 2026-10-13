import { Component, type ErrorInfo, type ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';

interface State {
  error: Error | null;
}

/** Catches render errors in a page so the rest of the shell keeps working. */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Page crashed:', error, info.componentStack);
  }

  render() {
    if (this.state.error) {
      const chunk = /Loading chunk|Failed to fetch dynamically imported module|Importing a module script failed/i.test(this.state.error.message);
      return (
        <div className="card mx-auto mt-10 max-w-lg p-8 text-center">
          <span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
            <TriangleAlert className="h-6 w-6" />
          </span>
          <h2 className="mt-4 text-lg font-semibold">{chunk ? 'A new version is available' : 'Something went wrong on this page'}</h2>
          <p className="mt-1 text-sm text-slate-500">{chunk ? 'Please reload to get the latest version of SmartCampus.' : this.state.error.message}</p>
          <button type="button" className="btn btn-primary mt-5" onClick={() => window.location.reload()}>
            Reload page
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}
