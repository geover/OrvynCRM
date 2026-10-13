<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { MailCheck } from 'lucide-vue-next';
import AuthShell from '@/Components/AuthShell.vue';
import { useRecaptcha } from '@/lib/recaptcha';

// Ask for a workspace: who you are and what the company is called. Nothing is created until the e-mail is confirmed.
const props = defineProps({ mode: String, email: { type: String, default: '' }, approval: Boolean });
const page = usePage();

const form = useForm({
    company: '', first_name: '', last_name: '', email: '', phone: '', accept_terms: false, marketing: false,
    recaptcha_token: '',
    website: '',                                                                         // hidden: only a bot fills it
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? '',
});
const { active: protectedByRecaptcha, token: recaptchaToken } = useRecaptcha();
const submit = async () => {
    form.recaptcha_token = await recaptchaToken('signup');
    form.post('/signup', { preserveScroll: true });
};
const resend = () => router.post('/signup/resend', {}, { preserveScroll: true });
const footer = computed(() => page.props.app.footer ?? {});
const reviewed = computed(() => props.mode === 'approval');
</script>

<template>
    <Head :title="mode === 'sent' ? 'Check your e-mail' : 'Create your account'" />
    <AuthShell>
        <!-- the platform has not opened sign-ups -->
        <div v-if="mode === 'closed'" class="relative w-full max-w-sm space-y-4 rounded-2xl border border-slate-200/80 bg-white p-7 text-center shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="signup-closed">
            <h2 class="page-title">Sign-ups are closed</h2>
            <p class="text-sm text-slate-500">New workspaces are not being created right now. If you would like one, get in touch and we will set it up for you.</p>
            <a v-if="footer.contact_url" :href="footer.contact_url" class="btn-primary w-full">Contact us</a>
            <p class="text-sm"><Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link></p>
        </div>

        <!-- asked: now go and click the link -->
        <div v-else-if="mode === 'sent'" class="relative w-full max-w-sm space-y-4 rounded-2xl border border-slate-200/80 bg-white p-7 text-center shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="signup-sent">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600"><MailCheck class="size-6" /></span>
            <h2 class="page-title">Check your e-mail</h2>
            <p class="text-sm text-slate-600">We sent a link to <strong class="break-all text-slate-900">{{ email }}</strong>. Open it to confirm your address{{ approval ? '. Your request is then reviewed, and we e-mail you when it is approved' : ' and choose your password' }}.</p>
            <p v-if="page.props.flash.success" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ page.props.flash.success }}</p>
            <p class="text-xs text-slate-400">Nothing there after a few minutes? Look in your spam folder, or <button type="button" class="text-brand-700 hover:underline" data-testid="signup-resend" @click="resend">send it again</button>.</p>
            <p class="text-sm"><Link href="/signup" class="text-slate-500 hover:underline">Use a different address</Link></p>
        </div>

        <form v-else class="relative w-full max-w-md space-y-4 rounded-2xl border border-slate-200/80 bg-white p-7 shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="signup-form" @submit.prevent="submit">
            <div>
                <h2 class="page-title">Create your account</h2>
                <p class="mt-1 text-sm text-slate-500">{{ reviewed ? 'Tell us about your company. We review each request, then e-mail you.' : 'Set up a workspace for your company in a minute.' }}</p>
            </div>

            <div>
                <label class="label" for="su-company">Company name</label>
                <input id="su-company" v-model="form.company" class="input" autocomplete="organization" maxlength="120" required autofocus data-testid="signup-company" />
                <p v-if="form.errors.company" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.company }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="su-first">First name</label><input id="su-first" v-model="form.first_name" class="input" autocomplete="given-name" maxlength="80" required data-testid="signup-first" /><p v-if="form.errors.first_name" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.first_name }}</p></div>
                <div><label class="label" for="su-last">Last name</label><input id="su-last" v-model="form.last_name" class="input" autocomplete="family-name" maxlength="80" required data-testid="signup-last" /><p v-if="form.errors.last_name" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.last_name }}</p></div>
            </div>
            <div>
                <label class="label" for="su-email">Work e-mail</label>
                <input id="su-email" v-model="form.email" type="email" class="input" autocomplete="email" maxlength="190" required data-testid="signup-email" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.email }}</p>
            </div>
            <div>
                <label class="label" for="su-phone">Phone <span class="normal-case text-slate-400">(optional)</span></label>
                <input id="su-phone" v-model="form.phone" type="tel" class="input" autocomplete="tel" placeholder="+15551234567" maxlength="40" data-testid="signup-phone" />
                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.phone }}</p>
            </div>

            <!-- a trap for bots: people never see or fill this -->
            <div class="absolute -left-[9999px] top-0" aria-hidden="true"><label>Website<input v-model="form.website" type="text" tabindex="-1" autocomplete="off" /></label></div>

            <div class="space-y-2 text-sm text-slate-600">
                <label class="flex items-start gap-2"><input v-model="form.accept_terms" type="checkbox" class="mt-0.5 rounded" required data-testid="signup-terms" />
                    <span>I agree to the
                        <a v-if="footer.terms_url" :href="footer.terms_url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">Terms of Use</a><template v-else>Terms of Use</template>
                        and the
                        <a v-if="footer.privacy_url" :href="footer.privacy_url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">Privacy Policy</a><template v-else>Privacy Policy</template>.</span></label>
                <p v-if="form.errors.accept_terms" class="text-xs text-red-600" role="alert">{{ form.errors.accept_terms }}</p>
                <label class="flex items-start gap-2 text-slate-500"><input v-model="form.marketing" type="checkbox" class="mt-0.5 rounded" /> <span>Send me product news and tips (optional).</span></label>
            </div>

            <p v-if="form.errors.recaptcha" class="text-xs text-red-600" role="alert" data-testid="recaptcha-error">{{ form.errors.recaptcha }}</p>
            <button class="btn-primary w-full" :disabled="form.processing" data-testid="signup-submit">{{ form.processing ? 'Sending…' : 'Create my account' }}</button>
            <p v-if="protectedByRecaptcha" class="text-center text-[11px] leading-relaxed text-slate-400" data-testid="recaptcha-note">Protected by reCAPTCHA. Google's <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" class="underline">Privacy Policy</a> and <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer" class="underline">Terms of Service</a> apply.</p>
            <p class="border-t border-slate-100 pt-4 text-center text-sm text-slate-600">Already have an account? <Link href="/login" class="font-medium text-brand-700 hover:underline">Sign in</Link></p>
        </form>
    </AuthShell>
</template>
