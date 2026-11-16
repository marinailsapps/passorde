import { usePage } from '@inertiajs/vue3';

/** True whenever Evoliz does not run (switched off, keys or licence): approved is the order's last status (App\Support\SyncMode). */
export const isFileMode = (): boolean => usePage().props.syncProvider === 'file';
