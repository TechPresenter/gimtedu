import { useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { History, ShieldCheck, UserRound } from 'lucide-react';
import { Alert, Button, CardSkeleton, PageHeader, Reveal, Tabs } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { ProfileCard } from './profile/ProfileCard';
import { AccountTab } from './profile/AccountTab';
import { SecurityTab } from './profile/SecurityTab';
import { ActivityTab } from './profile/ActivityTab';
import type { ProfileData, ProfileUser } from './profile/types';

const TABS = [
  { key: 'account', label: 'Account', icon: UserRound },
  { key: 'security', label: 'Password & Security', icon: ShieldCheck },
  { key: 'activity', label: 'My Activity', icon: History },
];

export default function ProfilePage() {
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : 'account';
  const { session, patch } = useAuth();
  const qc = useQueryClient();
  const { data, isLoading, error, refetch } = useApi<ProfileData>(['profile'], 'profile');

  /** Keep the cached profile and the top-bar identity in sync after an edit. */
  const onUpdated = (u: ProfileUser) => {
    qc.setQueryData<ProfileData>(['profile'], (old) => (old ? { ...old, user: u } : old));
    if (session?.user) {
      patch({ user: { ...session.user, name: u.name, email: u.email, phone: u.phone, designation: u.designation, avatar: u.avatar } });
    }
    void qc.invalidateQueries({ queryKey: ['profile-activity'] });
  };
  const reload = () => {
    void refetch();
    void qc.invalidateQueries({ queryKey: ['profile-logins'] });
    void qc.invalidateQueries({ queryKey: ['profile-activity'] });
  };

  return (
    <>
      <PageHeader
        title="My Profile"
        description="Manage your personal details, password, appearance and account security."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'My Profile' }]}
      />
      {error ? (
        <Alert variant="error" title="Unable to load your profile" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : isLoading || !data ? (
        <div className="grid gap-6 lg:grid-cols-12">
          <div className="lg:col-span-4">
            <CardSkeleton lines={10} />
          </div>
          <div className="space-y-6 lg:col-span-8">
            <CardSkeleton lines={6} />
            <CardSkeleton lines={4} />
          </div>
        </div>
      ) : (
        <div className="grid items-start gap-6 lg:grid-cols-12">
          <Reveal className="lg:sticky lg:top-20 lg:col-span-4">
            <ProfileCard data={data} onUpdated={onUpdated} />
          </Reveal>
          <div className="min-w-0 space-y-5 lg:col-span-8">
            {data.user.must_change_password && (
              <Alert variant="warning" title="Please change your password">
                Your administrator asked you to set a new password. Use the Password &amp; Security tab.
              </Alert>
            )}
            <Tabs tabs={TABS} value={tab} onChange={(k) => setParams(k === 'account' ? {} : { tab: k }, { replace: true })} />
            <Reveal key={tab}>
              {tab === 'account' && <AccountTab user={data.user} onUpdated={onUpdated} />}
              {tab === 'security' && <SecurityTab data={data} onChanged={reload} />}
              {tab === 'activity' && <ActivityTab />}
            </Reveal>
          </div>
        </div>
      )}
    </>
  );
}
