import { afterEach, describe, expect, it, vi } from 'vitest';

import { deliverFile, fetchFile, slug } from './download';

const file = new File(['x'], 'stock-par-essence-2026-10-15.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });

function share(result: () => Promise<void>) {
    const spy = vi.fn(result);
    vi.stubGlobal('navigator', { canShare: () => true, share: spy });
    return spy;
}

afterEach(() => vi.unstubAllGlobals());

describe('deliverFile', () => {
    it('hands the file to the share sheet', async () => {
        const spy = share(() => Promise.resolve());
        expect(await deliverFile(file)).toBe('done');
        expect(spy).toHaveBeenCalledWith({ files: [file], title: file.name });
    });

    it('treats a closed share sheet as done', async () => {
        share(() => Promise.reject(new DOMException('cancelled', 'AbortError')));
        expect(await deliverFile(file)).toBe('done');
    });

    it('asks for a fresh tap when the browser refuses to share without one', async () => {
        share(() => Promise.reject(new DOMException('no gesture', 'NotAllowedError')));
        expect(await deliverFile(file)).toBe('needs-tap');
    });

    it('saves through a temporary link where files cannot be shared', async () => {
        const link = { href: '', download: '', click: vi.fn(), remove: vi.fn() };
        vi.stubGlobal('navigator', {});
        vi.stubGlobal('document', { createElement: () => link, body: { append: vi.fn() } });
        vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:report');

        expect(await deliverFile(file)).toBe('done');
        expect(link).toMatchObject({ href: 'blob:report', download: file.name });
        expect(link.click).toHaveBeenCalled();
    });
});

describe('fetchFile', () => {
    it('names the file after the server', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('x', { headers: { 'Content-Disposition': 'attachment; filename=stock-par-essence-2026-10-15.xlsx' } }))));
        expect((await fetchFile('/reports/stock.xlsx', 'fallback.xlsx')).name).toBe('stock-par-essence-2026-10-15.xlsx');
    });

    it('fails on an error answer', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('', { status: 500 }))));
        await expect(fetchFile('/reports/stock.xlsx', 'fallback.xlsx')).rejects.toThrow('HTTP 500');
    });
});

describe('slug', () => {
    it('makes a file name as Str::slug() does', () => {
        expect(slug('stock-par-catégorie')).toBe('stock-par-categorie');
        expect(slug('Casse et cadeaux')).toBe('casse-et-cadeaux');
        expect(slug('stock-par-essence')).toBe('stock-par-essence');
    });
});
