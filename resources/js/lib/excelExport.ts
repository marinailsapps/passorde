import { ref } from 'vue';

import { deliverFile, fetchFile } from '@/lib/download';
import { t } from '@/lib/i18n';
import { isStandalone } from '@/offline/pwa';

/**
 * The "Excel" link of a report. In the installed app the download goes
 * through the share sheet (lib/download.ts); in a browser tab, the plain link
 * does it (the click handler lets it through).
 */
export function useExcelExport(url: () => string, filename: () => string) {
    const standalone = isStandalone();
    const exporting = ref(false);
    const exportError = ref<string | null>(null);
    const pendingFile = ref<File | null>(null);

    async function exportExcel(event: MouseEvent): Promise<void> {
        if (!standalone) return;
        event.preventDefault();
        if (exporting.value) return;
        exporting.value = true;
        exportError.value = null;
        pendingFile.value = null;
        try {
            const file = await fetchFile(url(), filename());
            if ((await deliverFile(file)) === 'needs-tap') pendingFile.value = file;
        } catch {
            exportError.value = t('The Excel file could not be downloaded. Check the network and try again.');
        } finally {
            exporting.value = false;
        }
    }

    async function openPending(): Promise<void> {
        const file = pendingFile.value;
        pendingFile.value = null;
        if (file && (await deliverFile(file)) === 'needs-tap') pendingFile.value = file;
    }

    return { exporting, exportError, pendingFile, exportExcel, openPending };
}
