<script setup>
// A faint picture of progress behind the sign-in form: a dotted line whose dots grow as it climbs, over
// rising bars. Drawn in the brand colour at low strength so the form stays the focus; it draws itself once on arrival and
// stays still for visitors who ask their device for less motion.
</script>

<template>
    <svg viewBox="0 0 600 800" preserveAspectRatio="xMidYMax slice" class="login-progress absolute inset-0 size-full" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <mask id="lp-draw" maskUnits="userSpaceOnUse" x="0" y="0" width="600" height="800">
                <path class="lp-reveal" pathLength="1" d="M-20 730 C 70 700, 120 650, 190 622 S 290 548, 350 500 S 450 392, 505 322 S 560 230, 566 190" fill="none" stroke="#fff" stroke-width="14" stroke-linecap="round" />
            </mask>
        </defs>

        <!-- rising bars -->
        <g class="fill-brand-500/[0.018]">
            <rect x="40" y="690" width="46" height="110" rx="10" /><rect x="112" y="650" width="46" height="150" rx="10" /><rect x="184" y="610" width="46" height="190" rx="10" />
            <rect x="256" y="560" width="46" height="240" rx="10" /><rect x="328" y="500" width="46" height="300" rx="10" /><rect x="400" y="430" width="46" height="370" rx="10" />
            <rect x="472" y="350" width="46" height="450" rx="10" />
        </g>

        <!-- the dotted climb, drawn in by the mask. The dots grow along the way: progress that picks up pace -->
        <g mask="url(#lp-draw)" fill="none" class="stroke-brand-500/[0.11]" stroke-linecap="round" stroke-dasharray="1 11">
            <path d="M-20 730 C 70 700, 120 650, 190 622" stroke-width="2.5" />
            <path d="M190 622 C 260 594, 290 548, 350 500" stroke-width="3.5" />
            <path d="M350 500 C 410 452, 450 392, 505 322" stroke-width="4.5" />
            <path d="M505 322 C 560 252, 560 230, 566 190" stroke-width="5.5" />
        </g>
    </svg>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .lp-reveal { stroke-dasharray: 1; stroke-dashoffset: 1; animation: lp-draw 2.6s cubic-bezier(0.4, 0, 0.2, 1) 0.3s forwards; }
}
@media (prefers-reduced-motion: reduce) {
    .lp-reveal { stroke-dasharray: none; }
}
@keyframes lp-draw { to { stroke-dashoffset: 0; } }
</style>
