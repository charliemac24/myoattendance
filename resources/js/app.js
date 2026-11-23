import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { currentUser, logout } from './auth/auth';
import { AppPage, ForgotPasswordPage, LoginPage, RegisterPage, ResetPasswordPage, VerifyEmailPage } from './pages/AuthPages';
import { PeoplePage, ResourcePage } from './pages/PhaseTwoPages';
import { ScannerPage } from './pages/ScannerPage';
import { AttendanceReportPage } from './pages/AttendanceReportPage';
import { TerminologyPage } from './pages/TerminologyPage';
import { AccountSettingsPage } from './pages/AccountSettingsPage';
import { terminologyFor } from './terminology';
import { SuperAdminPage } from './pages/SuperAdminPage';
import { SmsTemplatePage } from './pages/SmsTemplatePage';
import { BillingPage } from './pages/BillingPage';

import '../css/app.css';

const h = React.createElement;

function route() {
    return window.location.pathname;
}

function navigate(path) {
    window.history.pushState({}, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
}

function App() {
    const [path, setPath] = useState(route());
    const [user, setUser] = useState(undefined);

    useEffect(() => {
        const onPopState = () => setPath(route());
        window.addEventListener('popstate', onPopState);
        currentUser().then(setUser).catch(() => setUser(null));
        return () => window.removeEventListener('popstate', onPopState);
    }, []);

    const onAuthenticated = (nextUser) => {
        setUser(nextUser);
        if (!nextUser) {
            window.location.replace('/login');
            return;
        }
        navigate(nextUser ? (nextUser.email_verified_at ? '/app' : '/verify-email') : '/login');
    };

    if (user === undefined) return h('main', { className: 'auth-shell' }, h('p', null, 'Loading…'));
    if (path === '/register') return h(RegisterPage, { onAuthenticated });
    if (path === '/forgot-password') return h(ForgotPasswordPage);
    if (path.startsWith('/reset-password/')) return h(ResetPasswordPage, { token: path.split('/').pop() });
    if (user && !user.email_verified_at && path !== '/verify-email') return h(VerifyEmailPage, { user, onAuthenticated });
    if (path === '/verify-email') return user ? h(VerifyEmailPage, { user, onAuthenticated }) : h(LoginPage, { onAuthenticated });
    if (path === '/super-admin') return user ? (user.role === 'super_admin' ? h(SuperAdminPage, { user, onAuthenticated }) : h(AppPage, { user, onAuthenticated })) : h(LoginPage, { onAuthenticated });
    if (path === '/sms/templates') return user ? h(SmsTemplatePage, { user, onLogout: async () => { await logout(); onAuthenticated(null); } }) : h(LoginPage, { onAuthenticated });
    if (['/people', '/groups', '/subgroups', '/schedules', '/scanner', '/attendance', '/billing', '/settings/terminology', '/settings/account'].includes(path) && !user) return h(LoginPage, { onAuthenticated });
    if (path === '/app') return user ? h(AppPage, { user, onAuthenticated }) : h(LoginPage, { onAuthenticated });
    if (path === '/billing') return user ? h(BillingPage, { user, onLogout: async () => { await logout(); onAuthenticated(null); } }) : h(LoginPage, { onAuthenticated });
    const terminology = terminologyFor(user);
    const signOut = async () => { await logout(); onAuthenticated(null); };
    if (path === '/settings/terminology') return h(TerminologyPage, { user, onSave: (next) => setUser({ ...user, organization: { ...user.organization, terminology: next } }), onLogout: signOut });
    if (path === '/settings/account') return h(AccountSettingsPage, { user, onSave: (next) => setUser(next), onLogout: signOut });
    if (path === '/people') return h(PeoplePage, { user, terminology, onLogout: signOut });
    if (path === '/scanner') return h(ScannerPage, { user, onLogout: async () => { await logout(); onAuthenticated(null); } });
    if (path === '/attendance') return h(AttendanceReportPage, { user, terminology, onLogout: () => onAuthenticated(null) });
    if (path === '/groups') return h(ResourcePage, { title: terminology.group_plural, path: '/api/groups', fields: [{ label: 'Name', name: 'name', required: true }], user, terminology, onLogout: signOut });
    if (path === '/subgroups') return h(ResourcePage, { title: terminology.subgroup_plural, path: '/api/subgroups', fields: [{ label: 'Name', name: 'name', required: true }, { label: terminology.group_singular, name: 'group_id', required: true }], user, terminology, onLogout: signOut });
    if (path === '/schedules') return h(ResourcePage, { title: 'Schedules', path: '/api/schedules', fields: [{ label: 'Name', name: 'name', required: true }, { label: terminology.group_singular, name: 'group_id', required: true }, { label: terminology.subgroup_singular, name: 'subgroup_id' }, { label: 'Days', name: 'day_of_week' }, { label: 'Start (HH:MM)', name: 'start_time', required: true }, { label: 'Late after (HH:MM)', name: 'late_after', required: true }, { label: 'End (HH:MM, optional)', name: 'end_time' }], user, terminology, onLogout: signOut });
    if (user?.email_verified_at) return h(AppPage, { user, onAuthenticated });
    return h(LoginPage, { onAuthenticated });
}

createRoot(document.getElementById('app')).render(h(App));
