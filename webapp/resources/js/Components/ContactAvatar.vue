<script setup>
import { computed, ref } from 'vue';
import { markGravatarMissing, gravatarMissing } from '@/lib/gravatar';

// A contact's picture: the uploaded photo, else the Gravatar of their e-mail address (if the company allows it and one exists), else a person icon
// (male, female or neutral, by the contact's sex, or by their title Mr / Ms when the sex is not set) with the initials underneath.
//
// Cost: the uploaded photo is a small cached file; Gravatar is asked by the browser only for rows on screen (lazy), a "no picture" answer is remembered
// for a week so it is not asked again, and nothing is ever requested from our server. Until a picture arrives, the icon is already showing.
const props = defineProps({
    contact: { type: Object, required: true },        // {avatar_url, gravatar, sex, title, first_name, last_name, full_name}
    size: { type: String, default: 'sm' },            // sm (lists) | md | lg
});

const SIZES = {
    sm: { box: 'size-9', icon: 'size-[22px]', text: 'text-[8px] leading-none', gap: '-mt-0.5' },
    md: { box: 'size-14', icon: 'size-9', text: 'text-[11px] leading-none', gap: '-mt-0.5' },
    lg: { box: 'size-24', icon: 'size-16', text: 'text-sm leading-none', gap: '-mt-1 gap-0.5' },
};
const px = { sm: 72, md: 112, lg: 192 };                // asked for at twice the size, for sharp screens

const sex = computed(() => {
    if (props.contact.sex === 'male' || props.contact.sex === 'female') return props.contact.sex;
    const t = (props.contact.title ?? '').trim().toLowerCase().replace(/\./g, '');
    if (/^(mr|sir|mister)\b/.test(t)) return 'male';
    if (/^(mrs|ms|miss|madam|madame|mme)\b/.test(t)) return 'female';
    return null;
});
const initials = computed(() => {
    const c = props.contact;
    const a = (c.first_name?.[0] ?? '') + (c.last_name?.[0] ?? '');
    return (a || (c.full_name ?? '').split(/\s+/).map((w) => w[0]).slice(0, 2).join('') || '?').toUpperCase();
});
const tone = computed(() => ({ male: 'bg-sky-100 text-sky-700', female: 'bg-pink-100 text-pink-600' })[sex.value] ?? 'bg-brand-100 text-brand-700');

const failed = ref(false);
const loaded = ref(false);
const src = computed(() => {
    if (props.contact.avatar_url) return props.contact.avatar_url;
    const h = props.contact.gravatar;
    return h && !failed.value && !gravatarMissing(h) ? `https://gravatar.com/avatar/${h}?s=${px[props.size]}&d=404` : null;
});
const miss = () => { failed.value = true; if (props.contact.gravatar) markGravatarMissing(props.contact.gravatar); };
const s = computed(() => SIZES[props.size] ?? SIZES.sm);
</script>

<template>
    <span class="relative inline-grid shrink-0 place-items-center overflow-hidden rounded-full" :class="[s.box, tone]" data-testid="contact-avatar" :data-sex="sex ?? 'none'" role="img" :aria-label="contact.full_name || 'Contact'">
        <span class="flex flex-col items-center justify-center font-semibold" :class="s.gap" aria-hidden="true">
            <svg viewBox="0 0 24 24" :class="s.icon" fill="currentColor" data-testid="avatar-icon">
                <template v-if="sex === 'female'">
                    <path opacity=".55" d="M6.5 9.5a5.5 5.5 0 0 1 11 0V16c0 1.2-.8 2-2 2h-7c-1.2 0-2-.8-2-2z" />
                    <circle cx="12" cy="9" r="3.6" />
                    <path d="M8.8 14.6h6.4l3.8 7.4H5z" />
                </template>
                <template v-else-if="sex === 'male'">
                    <circle cx="12" cy="7.5" r="4" />
                    <path d="M3.5 22c0-4.7 3.8-7.6 8.5-7.6s8.5 2.9 8.5 7.6z" />
                </template>
                <template v-else>
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5z" />
                </template>
            </svg>
            <span :class="s.text" data-testid="avatar-initials">{{ initials }}</span>
        </span>
        <img v-if="src" :src="src" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" class="absolute inset-0 size-full object-cover transition-opacity" :class="loaded ? 'opacity-100' : 'opacity-0'" @load="loaded = true" @error="miss" />
    </span>
</template>
