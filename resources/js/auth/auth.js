import { del, get, patch, post, put, upload } from '../services/api';

// Sign-up choices. Keep in step with OrganizationTypes::selectableLabels() (hidden types are left out).
export const organizationTypes = [
    ['school', 'School / Education'],
    ['fitness', 'Fitness / Gym'],
];

export const currentUser = async () => {
    try {
        return (await get('/api/auth/user')).user;
    } catch (error) {
        if (error.status === 401) return null;
        throw error;
    }
};

export const register = (data) => post('/api/auth/register', data);
export const login = (data) => post('/api/auth/login', data);
export const logout = () => post('/api/auth/logout');
export const updateAccount = (data) => put('/api/auth/account', data);
export const updatePassword = (data) => put('/api/auth/password', data);
export const forgotPassword = (email) => post('/api/auth/forgot-password', { email });
export const resetPassword = (data) => post('/api/auth/reset-password', data);
export const resendVerification = () => post('/api/auth/verification/resend');
export const scanAttendance = (qr_payload, scan_request_id) => post('/api/attendance/scan', { qr_payload, scan_request_id });
export const getAttendanceReport = (params) => get(`/api/attendance/report?${new URLSearchParams(params).toString()}`);

export const phaseTwoApi = { get, post, put, patch, del, upload, scan: scanAttendance, report: getAttendanceReport };
