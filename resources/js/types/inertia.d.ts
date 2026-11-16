import type { SharedProps } from './index';

declare module '@inertiajs/core' {
    // Module augmentation needs an interface; it only re-exports SharedProps.
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type
    interface PageProps extends SharedProps {}
}
