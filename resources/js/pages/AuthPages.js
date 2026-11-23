import React, { useState } from 'react';
import { forgotPassword, login, logout, organizationTypes, register, resendVerification, resetPassword } from '../auth/auth';

const h = React.createElement;

function ErrorText({ error }) {
    if (!error) return null;
    const fields = Object.values(error.errors || {}).flat();
    return h('div', { className: 'auth-error', role: 'alert' }, [...fields, error.message].filter(Boolean).map((message, index) => h('p', { key: `${message}-${index}` }, message)));
}

function Form({ title, children, onSubmit, submitLabel, error, message, footer }) {
    return h('main', { className: 'auth-shell' }, h('section', { className: 'auth-card' },
        h('h1', null, title),
        message && h('p', { role: 'status' }, message),
        h('form', { onSubmit, noValidate: true }, children, h('button', { type: 'submit' }, submitLabel)),
        h(ErrorText, { error }),
        footer && h('nav', { className: 'auth-links' }, footer),
    ));
}

const field = (label, name, type = 'text', value, setValue, extra = {}) => h('label', { key: name }, label, h('input', { name, type, value, onChange: (event) => setValue(event.target.value), required: true, ...extra }));
const link = (label, path) => h('a', { href: path, onClick: (event) => { event.preventDefault(); window.history.pushState({}, '', path); window.dispatchEvent(new PopStateEvent('popstate')); } }, label);

export function LoginPage({ onAuthenticated }) {
    const [email, setEmail] = useState(''); const [password, setPassword] = useState(''); const [error, setError] = useState(null);
    const submit = async (event) => { event.preventDefault(); setError(null); try { const result = await login({ email, password }); onAuthenticated(result.user); } catch (caught) { setError(caught); } };
    return h(Form, { title: 'Sign in', onSubmit: submit, submitLabel: 'Sign in', error, footer: [link('Forgot password', '/forgot-password'), link('Create account', '/register')] }, field('Email', 'email', 'email', email, setEmail), field('Password', 'password', 'password', password, setPassword));
}

export function RegisterPage({ onAuthenticated }) {
    const [values, setValues] = useState({ name: '', organization_name: '', organization_type: 'company', email: '', password: '', password_confirmation: '' }); const [error, setError] = useState(null);
    const set = (name) => (value) => setValues((old) => ({ ...old, [name]: value }));
    const submit = async (event) => { event.preventDefault(); setError(null); try { const result = await register(values); onAuthenticated(result.user); } catch (caught) { setError(caught); } };
    return h(Form, { title: 'Create account', onSubmit: submit, submitLabel: 'Create account', error, footer: [link('Already have an account', '/login')] },
        h('p', { key: 'trial-copy' }, 'Start with a 30-day free trial. After the trial, MYO Attendance is ₱2,999/month. SMS Notifications are an optional add-on priced separately.'),
        field('Full name', 'name', 'text', values.name, set('name')),
        field('Organization name', 'organization_name', 'text', values.organization_name, set('organization_name')),
        h('label', { key: 'organization_type' }, 'Organization type', h('select', { value: values.organization_type, onChange: (event) => set('organization_type')(event.target.value) }, organizationTypes.map(([value, label]) => h('option', { value, key: value }, label)))),
        field('Email', 'email', 'email', values.email, set('email')),
        field('Password', 'password', 'password', values.password, set('password')),
        field('Confirm password', 'password_confirmation', 'password', values.password_confirmation, set('password_confirmation')),
    );
}

export function VerifyEmailPage({ user, onAuthenticated }) {
    const [error, setError] = useState(null); const [message, setMessage] = useState('Please verify your email before entering the application.');
    const resend = async () => { setError(null); try { const result = await resendVerification(); setMessage(result.message); } catch (caught) { setError(caught); } };
    return h(Form, { title: 'Verify your email', onSubmit: (event) => { event.preventDefault(); resend(); }, submitLabel: 'Resend verification email', error, message, footer: [h('button', { type: 'button', onClick: async () => { await logout(); onAuthenticated(null); } }, 'Log out')] }, h('p', null, `Signed in as ${user.email}`));
}

export function ForgotPasswordPage() {
    const [email, setEmail] = useState(''); const [error, setError] = useState(null); const [message, setMessage] = useState(null);
    const submit = async (event) => { event.preventDefault(); setError(null); try { setMessage((await forgotPassword(email)).message); } catch (caught) { setError(caught); } };
    return h(Form, { title: 'Forgot password', onSubmit: submit, submitLabel: 'Send reset link', error, message, footer: [link('Back to sign in', '/login')] }, field('Email', 'email', 'email', email, setEmail));
}

export function ResetPasswordPage({ token }) {
    const queryEmail = new URLSearchParams(window.location.search).get('email') || ''; const [values, setValues] = useState({ email: queryEmail, password: '', password_confirmation: '' }); const [error, setError] = useState(null); const [message, setMessage] = useState(null);
    const set = (name) => (value) => setValues((old) => ({ ...old, [name]: value }));
    const submit = async (event) => { event.preventDefault(); setError(null); try { setMessage((await resetPassword({ ...values, token })).message); } catch (caught) { setError(caught); } };
    return h(Form, { title: 'Reset password', onSubmit: submit, submitLabel: 'Reset password', error, message, footer: [link('Back to sign in', '/login')] }, field('Email', 'email', 'email', values.email, set('email')), field('New password', 'password', 'password', values.password, set('password')), field('Confirm password', 'password_confirmation', 'password', values.password_confirmation, set('password_confirmation')));
}

export function AppPage({ user, onAuthenticated }) {
    const labels = user.organization?.terminology || {};
   return h('main', { className: 'auth-shell' }, h('section', { className: 'auth-card' }, h('h1', null, 'MYO Attendance'), h('p', null, `Signed in as ${user.name}`), h('p', null, `Organization: ${user.organization?.name || 'Unavailable'}`), h('p', null, 'Daily attendance reporting is ready.'), h('nav', { className: 'auth-links' }, [link('Attendance', '/attendance'), link('Scanner', '/scanner'), link('Billing', '/billing'), link(labels.person_plural || 'People', '/people'), link(labels.group_plural || 'Groups', '/groups'), link(labels.subgroup_plural || 'Subgroups', '/subgroups'), link('Schedules', '/schedules'), link('Manage labels', '/settings/terminology')]), h('button', { onClick: async () => { await logout(); onAuthenticated(null); } }, 'Log out')));
    return h('main', { className: 'auth-shell' }, h('section', { className: 'auth-card' }, h('h1', null, 'MYO Attendance'), h('p', null, `Signed in as ${user.name}`), h('p', null, `Organization: ${user.organization?.name || 'Unavailable'}`), h('p', null, 'Daily attendance reporting is ready.'), h('nav', { className: 'auth-links' }, [link('Attendance', '/attendance'), link('Scanner', '/scanner'), link('Billing', '/billing'), link(labels.person_plural || 'People', '/people'), link(labels.group_plural || 'Groups', '/groups'), link(labels.subgroup_plural || 'Subgroups', '/subgroups'), link('Schedules', '/schedules'), link('SMS templates', '/sms/templates'), link('Manage labels', '/settings/terminology')]), h('button', { onClick: async () => { await logout(); onAuthenticated(null); } }, 'Log out')));
}
