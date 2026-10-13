import { usePage } from '@inertiajs/vue3';
import { toast } from '@/lib/ui';

/** Options for an Inertia visit that keeps the scroll position and toasts the server's success message (or `fallback`) when it worked. */
export const saved = (fallback = 'Saved', extra = {}) => ({
    preserveScroll: true,
    onSuccess: () => {
        toast(usePage().props.flash.success ?? fallback);
        extra.onSuccess?.();
    },
});
