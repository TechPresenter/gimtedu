import { Fragment, type ReactNode } from 'react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import type { LucideIcon } from 'lucide-react';

export interface DropdownItem {
  label: string;
  icon?: LucideIcon;
  onClick?: () => void;
  to?: string;
  href?: string;
  target?: string;
  danger?: boolean;
  disabled?: boolean;
  divider?: boolean;
  hint?: string;
}

interface DropdownProps {
  /** Content of the trigger button */
  trigger: ReactNode;
  items: (DropdownItem | false | null | undefined)[];
  align?: 'left' | 'right';
  triggerClassName?: string;
  header?: ReactNode;
  width?: string;
  label?: string;
}

export function Dropdown({ trigger, items, align = 'right', triggerClassName, header, width = 'w-56', label }: DropdownProps) {
  const list = items.filter(Boolean) as DropdownItem[];
  return (
    <Menu as="div" className="relative inline-block text-left">
      <MenuButton className={triggerClassName ?? 'btn btn-secondary btn-sm'} aria-label={label}>
        {trigger}
      </MenuButton>
      <MenuItems anchor={align === 'right' ? 'bottom end' : 'bottom start'} className={clsx('menu-panel [--anchor-gap:6px]', width)} modal={false}>
        {header && <div className="border-b border-slate-100 px-3 py-2 dark:border-slate-800">{header}</div>}
        {list.map((item, i) =>
          item.divider ? (
            <div key={`d${i}`} className="my-1 border-t border-slate-100 dark:border-slate-800" />
          ) : (
            <MenuItem key={`${item.label}${i}`} disabled={item.disabled} as={Fragment}>
              {({ focus }) => {
                const cls = clsx('menu-item', item.danger && '!text-red-600 dark:!text-red-400', item.disabled && 'opacity-50', focus && 'bg-slate-100 dark:bg-slate-800');
                const inner = (
                  <>
                    {item.icon && <item.icon className="h-4 w-4 shrink-0 opacity-70" />}
                    <span className="flex-1">{item.label}</span>
                    {item.hint && <span className="text-xs text-slate-400">{item.hint}</span>}
                  </>
                );
                if (item.to) return <Link to={item.to} className={cls}>{inner}</Link>;
                if (item.href) return <a href={item.href} target={item.target} rel="noopener noreferrer" className={cls}>{inner}</a>;
                return <button type="button" onClick={item.onClick} className={cls}>{inner}</button>;
              }}
            </MenuItem>
          ),
        )}
      </MenuItems>
    </Menu>
  );
}
