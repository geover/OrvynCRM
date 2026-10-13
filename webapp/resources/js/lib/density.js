import { useStored } from '@/lib/stored';

/** How the message lists and conversations are drawn ('comfortable' | 'compact'): one choice for every messaging page, kept in this browser. */
export const messageDensity = useStored('messages-density', 'comfortable');
