/** Downloads a CSV from an authenticated GET endpoint, keeping the server's file name. */
export async function downloadCsv(path, fallbackName) {
    const response = await fetch(path, { credentials: 'same-origin', headers: { Accept: 'text/csv' } });
    if (!response.ok) throw new Error('Download failed');

    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^";]+)"?/);
    const url = window.URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = match ? match[1] : fallbackName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
}
