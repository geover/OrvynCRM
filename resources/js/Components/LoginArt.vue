<script setup>
// The sign-in page's default background: a bright, moving scene of a team meeting around a live dashboard. Drawn in code
// so it is sharp at any size and weighs almost nothing; the base takes the platform colour (--color-brand-*), the
// accents (pink, amber, cyan, emerald) stay lively whatever the colour is. Motion stops for people who prefer none.
</script>

<template>
    <svg class="la absolute inset-0 size-full" viewBox="0 0 800 1000" preserveAspectRatio="xMidYMax slice" role="img" aria-label="A team in a meeting looking at a live dashboard of growing sales, tasks and new contacts" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <linearGradient id="la-bg" x1="0" y1="0" x2="0.7" y2="1">
                <stop offset="0" style="stop-color: var(--color-brand-900)" />
                <stop offset="0.55" style="stop-color: var(--color-brand-700)" />
                <stop offset="1" style="stop-color: var(--color-brand-500)" />
            </linearGradient>
            <linearGradient id="la-pink" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#ec4899" /><stop offset="1" stop-color="#f9a8d4" /></linearGradient>
            <linearGradient id="la-amber" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#f59e0b" /><stop offset="1" stop-color="#fde68a" /></linearGradient>
            <linearGradient id="la-cyan" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#06b6d4" /><stop offset="1" stop-color="#a5f3fc" /></linearGradient>
            <linearGradient id="la-green" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#10b981" /><stop offset="1" stop-color="#a7f3d0" /></linearGradient>
            <linearGradient id="la-violet" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#8b5cf6" /><stop offset="1" stop-color="#ddd6fe" /></linearGradient>
            <linearGradient id="la-avatar" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f472b6" /><stop offset="1" stop-color="#fb923c" /></linearGradient>
            <linearGradient id="la-floor" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#000" stop-opacity="0.3" /><stop offset="1" stop-color="#000" stop-opacity="0" /></linearGradient>
            <filter id="la-blur" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="46" /></filter>
            <filter id="la-shadow" x="-20%" y="-20%" width="140%" height="150%"><feDropShadow dx="0" dy="14" stdDeviation="14" flood-color="#0b1020" flood-opacity="0.35" /></filter>
            <pattern id="la-dots" width="28" height="28" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.4" fill="#fff" fill-opacity="0.1" /></pattern>
        </defs>

        <rect width="800" height="1000" fill="url(#la-bg)" />

        <!-- colour glows that drift slowly -->
        <g filter="url(#la-blur)">
            <circle class="la-drift" cx="120" cy="430" r="150" fill="#ec4899" fill-opacity="0.5" />
            <circle class="la-drift la-slow" cx="700" cy="520" r="170" fill="#22d3ee" fill-opacity="0.42" />
            <circle class="la-drift la-late" cx="560" cy="170" r="130" fill="#fbbf24" fill-opacity="0.32" />
            <circle class="la-drift la-slow la-late" cx="260" cy="880" r="190" fill="#8b5cf6" fill-opacity="0.5" />
        </g>
        <rect width="800" height="1000" fill="url(#la-dots)" />

        <!-- twinkling sparkles -->
        <g fill="#fff">
            <path class="la-twinkle" d="M90 390l5 14 14 5-14 5-5 14-5-14-14-5 14-5z" />
            <path class="la-twinkle la-late" d="M730 360l4 11 11 4-11 4-4 11-4-11-11-4 11-4z" />
            <path class="la-twinkle la-slow" d="M650 770l4 11 11 4-11 4-4 11-4-11-11-4 11-4z" />
            <circle class="la-twinkle la-late" cx="40" cy="700" r="4" /><circle class="la-twinkle" cx="770" cy="620" r="3.5" /><circle class="la-twinkle la-slow" cx="410" cy="360" r="3" />
        </g>

        <!-- the dashboard on the wall -->
        <g filter="url(#la-shadow)">
            <rect x="70" y="430" width="660" height="290" rx="20" fill="#fff" fill-opacity="0.95" />
        </g>
        <rect x="70" y="430" width="660" height="40" rx="20" fill="#eef2ff" />
        <rect x="70" y="450" width="660" height="20" fill="#eef2ff" />
        <circle cx="98" cy="451" r="6" fill="#f87171" /><circle cx="118" cy="451" r="6" fill="#fbbf24" /><circle cx="138" cy="451" r="6" fill="#34d399" />
        <rect x="170" y="444" width="150" height="14" rx="7" fill="#c7d2fe" />

        <!-- KPI tiles -->
        <g>
            <rect x="94" y="490" width="140" height="64" rx="12" fill="#fdf2f8" /><rect x="108" y="504" width="56" height="9" rx="4.5" fill="#f9a8d4" /><rect x="108" y="522" width="86" height="18" rx="6" fill="#ec4899" />
            <rect x="248" y="490" width="140" height="64" rx="12" fill="#ecfeff" /><rect x="262" y="504" width="56" height="9" rx="4.5" fill="#a5f3fc" /><rect x="262" y="522" width="70" height="18" rx="6" fill="#06b6d4" />
            <rect x="402" y="490" width="140" height="64" rx="12" fill="#fffbeb" /><rect x="416" y="504" width="56" height="9" rx="4.5" fill="#fde68a" /><rect x="416" y="522" width="94" height="18" rx="6" fill="#f59e0b" />
        </g>

        <!-- growing bars -->
        <g class="la-bars">
            <rect class="la-bar" style="--d: 0.1s" x="106" y="650" width="34" height="42" rx="7" fill="url(#la-violet)" />
            <rect class="la-bar" style="--d: 0.25s" x="156" y="626" width="34" height="66" rx="7" fill="url(#la-cyan)" />
            <rect class="la-bar" style="--d: 0.4s" x="206" y="636" width="34" height="56" rx="7" fill="url(#la-green)" />
            <rect class="la-bar" style="--d: 0.55s" x="256" y="600" width="34" height="92" rx="7" fill="url(#la-amber)" />
            <rect class="la-bar" style="--d: 0.7s" x="306" y="578" width="34" height="114" rx="7" fill="url(#la-pink)" />
            <rect class="la-bar" style="--d: 0.85s" x="356" y="556" width="34" height="136" rx="7" fill="url(#la-violet)" />
            <rect class="la-bar" style="--d: 1s" x="406" y="590" width="34" height="102" rx="7" fill="url(#la-cyan)" />
            <rect class="la-bar" style="--d: 1.15s" x="456" y="548" width="34" height="144" rx="7" fill="url(#la-green)" />
        </g>
        <path d="M94 692h410" stroke="#c7d2fe" stroke-width="2" />
        <polyline class="la-line" points="123,630 173,606 223,616 273,580 323,558 373,536 423,570 473,528" fill="none" stroke="#6366f1" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" />
        <circle class="la-pulse" cx="473" cy="528" r="9" fill="#6366f1" />
        <circle cx="473" cy="528" r="4" fill="#fff" />

        <!-- goal ring -->
        <circle cx="632" cy="590" r="54" fill="none" stroke="#e0e7ff" stroke-width="16" />
        <circle class="la-ring" cx="632" cy="590" r="54" fill="none" stroke="#ec4899" stroke-width="16" stroke-linecap="round" stroke-dasharray="254 340" transform="rotate(-90 632 590)" />
        <path d="M613 592l14 14 26-30" fill="none" stroke="#10b981" stroke-width="7" stroke-linecap="round" stroke-linejoin="round" />

        <!-- floating cards -->
        <g class="la-float" filter="url(#la-shadow)">
            <rect x="44" y="352" width="236" height="84" rx="18" fill="#fff" />
            <circle cx="86" cy="394" r="24" fill="url(#la-avatar)" /><circle cx="86" cy="388" r="8" fill="#fff" fill-opacity="0.9" /><path d="M71 408q15-16 30 0z" fill="#fff" fill-opacity="0.9" />
            <rect x="122" y="376" width="104" height="11" rx="5.5" fill="#1e1b4b" fill-opacity="0.85" />
            <rect x="122" y="396" width="64" height="9" rx="4.5" fill="#a5b4fc" />
            <rect x="192" y="394" width="64" height="14" rx="7" fill="#dcfce7" /><rect x="202" y="399" width="44" height="4" rx="2" fill="#16a34a" />
        </g>
        <g class="la-float la-b" filter="url(#la-shadow)">
            <rect x="524" y="368" width="236" height="72" rx="18" fill="#fff" />
            <circle cx="562" cy="404" r="20" fill="#d1fae5" /><path d="M552 405l8 8 14-17" fill="none" stroke="#10b981" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
            <rect x="594" y="390" width="96" height="11" rx="5.5" fill="#1e1b4b" fill-opacity="0.85" />
            <rect x="594" y="408" width="62" height="9" rx="4.5" fill="#a5b4fc" />
            <rect x="696" y="392" width="46" height="24" rx="12" fill="#fbbf24" /><rect x="706" y="401" width="26" height="6" rx="3" fill="#fff" />
        </g>
        <g class="la-float la-c" filter="url(#la-shadow)">
            <rect x="560" y="706" width="196" height="76" rx="18" fill="#fff" />
            <rect x="580" y="726" width="16" height="16" rx="5" fill="#8b5cf6" /><path d="M583 734l4 4 7-8" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            <rect x="606" y="728" width="110" height="9" rx="4.5" fill="#1e1b4b" fill-opacity="0.8" />
            <rect x="580" y="752" width="16" height="16" rx="5" fill="#e0e7ff" /><rect x="606" y="754" width="80" height="9" rx="4.5" fill="#c7d2fe" />
        </g>

        <!-- floor -->
        <rect y="920" width="800" height="80" fill="url(#la-floor)" />

        <!-- presenter -->
        <g class="la-wave">
            <rect x="106" y="868" width="12" height="54" rx="6" fill="#312e81" /><rect x="126" y="868" width="12" height="54" rx="6" fill="#312e81" />
            <rect x="94" y="790" width="56" height="86" rx="20" fill="#f472b6" />
            <path class="la-arm" d="M150 812L196 748" stroke="#f472b6" stroke-width="14" stroke-linecap="round" />
            <circle class="la-hand" cx="198" cy="744" r="8" fill="#e8b894" />
            <circle cx="122" cy="764" r="24" fill="#f1c9a5" />
            <path d="M98 760a24 24 0 0 1 48 0c-6-12-14-17-24-17s-18 5-24 17z" fill="#7c2d12" />
        </g>

        <!-- people seated around the table -->
        <g>
            <g transform="translate(250 0)"><path d="M-42 880v-30q0-34 42-34t42 34v30z" fill="#34d399" /><circle cy="790" r="24" fill="#f5d0b5" /><path d="M-25 786a25 25 0 0 1 50 0c-6-10-14-14-25-14s-19 4-25 14z" fill="#b4793a" /></g>
            <g transform="translate(370 0)"><path d="M-42 880v-30q0-34 42-34t42 34v30z" fill="#fbbf24" /><circle cy="790" r="24" fill="#8d5a3b" /><path d="M-24 790a24 24 0 0 1 48 0c0-16-10-24-24-24s-24 8-24 24z" fill="#1a1210" /></g>
            <g transform="translate(490 0)"><path d="M-42 880v-30q0-34 42-34t42 34v30z" fill="#22d3ee" /><circle cy="790" r="24" fill="#d9a27c" /><path d="M-26 792q-2-34 26-30t26 30q-8-14-26-14t-26 14z" fill="#5a3a22" /></g>
            <g transform="translate(610 0)"><path d="M-42 880v-30q0-34 42-34t42 34v30z" fill="#a78bfa" /><circle cy="790" r="24" fill="#f1c9a5" /><path d="M-24 786a24 24 0 0 1 48 0c-10-7-34-9-48 0z" fill="#2c2018" /></g>
        </g>

        <!-- table and laptops -->
        <rect x="190" y="868" width="480" height="24" rx="12" fill="#fff" fill-opacity="0.9" />
        <rect x="190" y="892" width="480" height="12" rx="6" fill="#000" fill-opacity="0.2" />
        <g fill="#fff"><rect x="228" y="846" width="44" height="22" rx="3" /><rect x="470" y="846" width="44" height="22" rx="3" /></g>
        <g><rect x="232" y="850" width="36" height="14" rx="2" fill="#6366f1" /><rect x="474" y="850" width="36" height="14" rx="2" fill="#ec4899" /></g>
        <g fill="#fbbf24"><rect x="352" y="856" width="30" height="12" rx="2" /><rect x="590" y="854" width="26" height="14" rx="2" /></g>

        <!-- plant -->
        <g>
            <rect x="710" y="918" width="46" height="46" rx="8" fill="#fff" fill-opacity="0.45" />
            <g fill="#34d399"><ellipse cx="733" cy="880" rx="12" ry="42" /><ellipse cx="712" cy="894" rx="10" ry="32" transform="rotate(-28 712 894)" /><ellipse cx="754" cy="894" rx="10" ry="32" transform="rotate(28 754 894)" /></g>
        </g>
    </svg>
</template>

<style scoped>
.la * { transform-box: fill-box; }
.la-drift { animation: la-drift 14s ease-in-out infinite alternate; }
.la-slow { animation-duration: 20s; }
.la-late { animation-delay: -6s; }
.la-float { animation: la-float 6s ease-in-out infinite; }
.la-float.la-b { animation-delay: -2s; animation-duration: 7s; }
.la-float.la-c { animation-delay: -4s; animation-duration: 8s; }
.la-twinkle { animation: la-twinkle 3.2s ease-in-out infinite; transform-origin: center; }
.la-bar { transform-origin: center bottom; animation: la-grow 1.1s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: var(--d, 0s); }
.la-line { stroke-dasharray: 700; stroke-dashoffset: 700; animation: la-draw 1.8s ease-out 0.6s forwards; }
.la-ring { stroke-dashoffset: 254; animation: la-ring 1.6s ease-out 0.3s forwards; }
.la-pulse { transform-origin: center; animation: la-pulse 2.2s ease-out infinite; }
.la-arm { transform-box: view-box; transform-origin: 150px 812px; animation: la-arm 2.4s ease-in-out infinite; }
.la-hand { animation: la-hand 2.4s ease-in-out infinite; }

@keyframes la-drift { from { transform: translate(0, 0); } to { transform: translate(34px, -28px); } }
@keyframes la-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
@keyframes la-twinkle { 0%, 100% { opacity: 0.15; transform: scale(0.7); } 50% { opacity: 1; transform: scale(1.15); } }
@keyframes la-grow { from { transform: scaleY(0); } to { transform: scaleY(1); } }
@keyframes la-draw { to { stroke-dashoffset: 0; } }
@keyframes la-ring { to { stroke-dashoffset: 0; } }
@keyframes la-pulse { 0% { opacity: 0.55; transform: scale(1); } 100% { opacity: 0; transform: scale(2.6); } }
@keyframes la-arm { 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(-9deg); } }
@keyframes la-hand { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-6px, -7px); } }

@media (prefers-reduced-motion: reduce) {
    .la * { animation: none !important; }
    .la-line { stroke-dashoffset: 0; }
    .la-ring { stroke-dashoffset: 0; }
}
</style>
