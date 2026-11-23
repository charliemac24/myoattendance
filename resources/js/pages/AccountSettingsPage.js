import React, { useState } from 'react';
import { updateAccount, updatePassword } from '../auth/auth';

const h = React.createElement;

function ErrorText({ error }) {
    if (!error) return null;
    return h('div', { className: 'auth-error', role: 'alert' }, [...Object.values(error.errors || {}).flat(), error.message].filter(Boolean).map((message, index) => h('p', { key: `${message}-${index}` }, message)));
}

const field = (label, name, type, value, onChange) => h('label', { key: name }, label, h('input', { name, type, value, required: true, onChange: (event) => onChange(event.target.value) }));

export function AccountSettingsPage({ user, onSave, onLogout }) {
    const [name, setName] = useState(user.name || '');
    const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [profileError, setProfileError] = useState(null); const [passwordError, setPasswordError] = useState(null);
    const [profileMessage, setProfileMessage] = useState(''); const [passwordMessage, setPasswordMessage] = useState('');
    const setPassword = (key) => (value) => setPasswords((current) => ({ ...current, [key]: value }));
    const saveProfile = async (event) => { event.preventDefault(); setProfileError(null); setProfileMessage(''); try { const result = await updateAccount({ name }); onSave(result.user); setProfileMessage(result.message); } catch (error) { setProfileError(error); } };
    const savePassword = async (event) => { event.preventDefault(); setPasswordError(null); setPasswordMessage(''); try { const result = await updatePassword(passwords); setPasswords({ current_password: '', password: '', password_confirmation: '' }); setPasswordMessage(result.message); } catch (error) { setPasswordError(error); } };
    return h('main', { className: 'app-shell' }, h('header', { className: 'app-header' }, h('strong', null, 'MYO Attendance'), h('nav', null, [h('a', { href: '/app' }, 'Home'), h('button', { type: 'button', onClick: onLogout }, 'Log out')])), h('section', { className: 'app-card' }, h('h1', null, 'Account Settings'), h('p', null, 'Manage your personal account details and password.'), h('form', { onSubmit: saveProfile }, h('h2', null, 'Profile'), field('Full name', 'name', 'text', name, setName), h('label', null, 'Email', h('input', { value: user.email || '', readOnly: true }), h('small', null, 'Email changes require a separate verification flow and are not available here yet.')), h('button', { type: 'submit' }, 'Save profile'), profileMessage && h('p', { role: 'status' }, profileMessage), h(ErrorText, { error: profileError })), h('form', { onSubmit: savePassword }, h('h2', null, 'Change password'), field('Current password', 'current_password', 'password', passwords.current_password, setPassword('current_password')), field('New password', 'password', 'password', passwords.password, setPassword('password')), field('Confirm new password', 'password_confirmation', 'password', passwords.password_confirmation, setPassword('password_confirmation')), h('button', { type: 'submit' }, 'Update password'), passwordMessage && h('p', { role: 'status' }, passwordMessage), h(ErrorText, { error: passwordError }))));
}
