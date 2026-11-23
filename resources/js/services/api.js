const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

export async function request(path, options = {}) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken() || '',
        ...(options.headers || {}),
    };
    if (options.body instanceof FormData) delete headers['Content-Type'];
    const response = await fetch(path, {
        credentials: 'same-origin',
        headers,
        ...options,
    });
    const payload = await response.json().catch(() => ({}));
    if (payload.csrf_token) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', payload.csrf_token);

    if (!response.ok) {
        const fallback = {
            413: 'The upload is too large. Use a smaller file, or submit without one.',
            419: 'Your session expired. Refresh the page and try again.',
        }[response.status];
        const message = response.status === 419 ? fallback : (payload.message || fallback || 'The request could not be completed.');
        const error = new Error(message);
        error.status = response.status;
        error.errors = payload.errors || {};
        throw error;
    }

    return payload;
}

export const get = (path) => request(path);
export const post = (path, body) => request(path, { method: 'POST', body: JSON.stringify(body) });
export const put = (path, body) => request(path, { method: 'PUT', body: JSON.stringify(body) });
export const patch = (path, body) => request(path, { method: 'PATCH', body: JSON.stringify(body) });
export const del = (path) => request(path, { method: 'DELETE' });
export const upload = (path, file, fields = {}, fileField = 'file') => request(path, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() || '' }, body: (() => { const form = new FormData(); if (file) form.append(fileField, file); Object.entries(fields).forEach(([key, value]) => form.append(key, value ?? '')); return form; })() });
