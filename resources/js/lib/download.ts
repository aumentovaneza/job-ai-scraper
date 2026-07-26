import { api } from '@/lib/api';

/**
 * Downloads a file from an authenticated API endpoint as a real browser
 * download. A plain `<a href>` won't carry the Sanctum session/XSRF cookies
 * the way the shared `api` client does, so this fetches the file as a blob
 * through `api` (which sends credentials) and triggers the save client-side
 * via a temporary object URL.
 */
export async function downloadFile(url: string, fallbackFilename: string): Promise<void> {
    const response = await api.get<Blob>(url, { responseType: 'blob' });
    const filename = filenameFromDisposition(response.headers['content-disposition']) ?? fallbackFilename;

    const objectUrl = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(objectUrl);
}

function filenameFromDisposition(disposition: unknown): string | null {
    if (typeof disposition !== 'string') return null;
    const match = /filename="?([^"]+)"?/.exec(disposition);
    return match?.[1] ?? null;
}
