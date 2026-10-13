import { Fragment } from 'react';
import { Link } from 'react-router-dom';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import clsx from 'clsx';
import { ChevronDown, Globe, KeyRound, LogOut, Monitor, Moon, Sun, UserRound } from 'lucide-react';
import { areaPath } from '../lib/config';
import { usePortal } from '../lib/session';
import { usePortalTheme, type ThemeChoice } from '../lib/theme';
import { PortalAvatar } from './Ui';

const THEMES: { key: ThemeChoice; label: string; icon: typeof Sun }[] = [
  { key: 'light', label: 'Light', icon: Sun },
  { key: 'dark', label: 'Dark', icon: Moon },
  { key: 'system', label: 'System', icon: Monitor },
];

/** Light / dark / system segmented control. */
export function ThemeSwitch({ className }: { className?: string }) {
  const { area } = usePortal();
  const { theme, choose } = usePortalTheme(area);
  return (
    <div className={clsx('grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800', className)} role="radiogroup" aria-label="Colour theme">
      {THEMES.map((t) => (
        <button
          key={t.key}
          type="button"
          role="radio"
          aria-checked={theme === t.key}
          onClick={() => choose(t.key)}
          className={clsx(
            'inline-flex items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-semibold transition',
            theme === t.key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-950 dark:text-white' : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white',
          )}
        >
          <t.icon className="h-3.5 w-3.5" aria-hidden />
          {t.label}
        </button>
      ))}
    </div>
  );
}

/** Avatar menu in the top bar: profile, change password, theme, website, sign out. */
export function UserMenu({ onSignOut, siteUrl }: { onSignOut: () => void; siteUrl: string }) {
  const { area, identity } = usePortal();
  const item = 'menu-item';
  return (
    <Menu as="div" className="relative">
      <MenuButton className="flex items-center gap-2 rounded-full py-1 pl-1 pr-1 transition hover:bg-slate-100 sm:pr-2.5 dark:hover:bg-slate-800" aria-label="Account menu">
        <PortalAvatar name={identity.name} src={identity.photo} size="sm" />
        <span className="hidden max-w-[10rem] truncate text-left text-[13px] font-semibold leading-tight text-slate-800 sm:block dark:text-slate-100">
          {identity.firstName}
          <span className="block truncate text-[11px] font-medium text-slate-500 dark:text-slate-400">{identity.idLabel}</span>
        </span>
        <ChevronDown className="hidden h-4 w-4 text-slate-400 sm:block" aria-hidden />
      </MenuButton>
      <MenuItems anchor="bottom end" className="menu-panel w-72 [--anchor-gap:8px]" modal={false}>
        <div className="flex items-center gap-3 px-3 pb-3 pt-2">
          <PortalAvatar name={identity.name} src={identity.photo} size="md" />
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-slate-900 dark:text-white">{identity.name}</p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{identity.idLabel}</p>
          </div>
        </div>
        <div className="my-1 border-t border-slate-100 dark:border-slate-800" />
        <MenuItem as={Fragment}>
          <Link to={areaPath(area, 'profile')} className={item}>
            <UserRound className="h-4 w-4 opacity-70" aria-hidden /> My profile
          </Link>
        </MenuItem>
        <MenuItem as={Fragment}>
          <Link to={areaPath(area, 'change-password')} className={item}>
            <KeyRound className="h-4 w-4 opacity-70" aria-hidden /> Change password
          </Link>
        </MenuItem>
        <MenuItem as={Fragment}>
          <a href={siteUrl} className={item}>
            <Globe className="h-4 w-4 opacity-70" aria-hidden /> Visit website
          </a>
        </MenuItem>
        <div className="my-1 border-t border-slate-100 dark:border-slate-800" />
        <div className="px-2 pb-1 pt-1.5">
          <p className="mb-1.5 px-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Appearance</p>
          <ThemeSwitch />
        </div>
        <div className="my-1 border-t border-slate-100 dark:border-slate-800" />
        <MenuItem as={Fragment}>
          <button type="button" onClick={onSignOut} className={clsx(item, '!text-red-600 dark:!text-red-400')}>
            <LogOut className="h-4 w-4" aria-hidden /> Sign out
          </button>
        </MenuItem>
      </MenuItems>
    </Menu>
  );
}
