import { lazy } from 'react';
import { createBrowserRouter, type RouteObject } from 'react-router-dom';
import { ROUTER_BASE } from '@/lib/config';
import { AdminLayout } from '@/components/layout/AdminLayout';
import { routes as dashboard } from '@/modules/dashboard/routes';
import { routes as system } from '@/modules/system/routes';
import { routes as students } from '@/modules/students/routes';
import { routes as faculty } from '@/modules/faculty/routes';
import { routes as academics } from '@/modules/academics/routes';
import { routes as admissions } from '@/modules/admissions/routes';
import { routes as attendance } from '@/modules/attendance/routes';
import { routes as fees } from '@/modules/fees/routes';
import { routes as exams } from '@/modules/exams/routes';
import { routes as campus } from '@/modules/campus/routes';
import { routes as career } from '@/modules/career/routes';
import { routes as communication } from '@/modules/communication/routes';
import { routes as cms } from '@/modules/cms/routes';
import { routes as reports } from '@/modules/reports/routes';

const LoginPage = lazy(() => import('@/pages/auth/LoginPage'));
const ForgotPasswordPage = lazy(() => import('@/pages/auth/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/ResetPasswordPage'));
const NotFound = lazy(() => import('@/components/layout/NotFound'));

const appRoutes: RouteObject[] = [
  ...dashboard, ...system, ...students, ...faculty, ...academics, ...admissions, ...attendance, ...fees, ...exams, ...campus, ...career, ...communication, ...cms, ...reports,
  { path: '*', element: <NotFound /> },
];

export const router = createBrowserRouter(
  [
    { path: '/login', element: <LoginPage /> },
    { path: '/forgot-password', element: <ForgotPasswordPage /> },
    { path: '/reset-password', element: <ResetPasswordPage /> },
    { path: '/', element: <AdminLayout />, children: appRoutes },
  ],
  { basename: ROUTER_BASE },
);
