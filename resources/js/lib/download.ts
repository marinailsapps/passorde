/**
 * Files from an installed app (PWA). Following a download link there leaves
 * the app on the browser's file viewer, with no way back on iOS; so the file
 * is fetched here and handed to the share sheet ("Enregistrer dans Fichiers",
 * Excel, Mail…) or, where sharing files is not supported, saved through a
 * temporary link. The page stays where it is.
 */

/** A file name from a French text, as Laravel's Str::slug() makes it ("Stock par catégorie" → "stock-par-categorie"). */
export function slug(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export async function fetchFile(url: string, fallbackName: string): Promise<File> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
    const blob = await response.blob();
    return new File([blob], name, { type: blob.type || 'application/octet-stream' });
}

/**
 * 'done' when the share sheet opened (or was closed by the user) or the file
 * was saved; 'needs-tap' when the browser wants a fresh tap before sharing
 * (Safari, when fetching took too long after the first one).
 */
export async function deliverFile(file: File): Promise<'done' | 'needs-tap'> {
    if (typeof navigator.canShare === 'function' && navigator.canShare({ files: [file] })) {
        try {
            await navigator.share({ files: [file], title: file.name });
            return 'done';
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return 'done';
            if (error instanceof DOMException && error.name === 'NotAllowedError') return 'needs-tap';
            throw error;
        }
    }

    saveFile(file);
    return 'done';
}

/** Saves through a temporary link: a browser tab's normal download. */
export function saveFile(file: File): void {
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
