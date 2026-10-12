<script setup>
import { computed } from 'vue';

// Renders an announcement's text safely (nothing is ever inserted as HTML): paragraphs separated by a blank line,
// "- " bullet lists, and **bold**.
const props = defineProps({ text: { type: String, default: '' } });

const inline = (line) => line.split(/(\*\*[^*]+\*\*)/g).filter(Boolean).map((part) => (part.startsWith('**') && part.endsWith('**') && part.length > 4 ? { bold: true, text: part.slice(2, -2) } : { bold: false, text: part }));

const blocks = computed(() => props.text.replace(/\r\n/g, '\n').trim().split(/\n{2,}/).filter(Boolean).map((chunk) => {
    const lines = chunk.split('\n');
    if (lines.every((l) => /^\s*[-*]\s+/.test(l))) return { list: true, items: lines.map((l) => inline(l.replace(/^\s*[-*]\s+/, ''))) };
    return { list: false, lines: lines.map(inline) };
}));
</script>

<template>
    <div class="space-y-2 text-sm leading-relaxed">
        <template v-for="(b, i) in blocks" :key="i">
            <ul v-if="b.list" class="list-disc space-y-1 pl-5">
                <li v-for="(item, j) in b.items" :key="j"><template v-for="(part, k) in item" :key="k"><strong v-if="part.bold">{{ part.text }}</strong><template v-else>{{ part.text }}</template></template></li>
            </ul>
            <p v-else><template v-for="(line, j) in b.lines" :key="j"><br v-if="j > 0" /><template v-for="(part, k) in line" :key="k"><strong v-if="part.bold">{{ part.text }}</strong><template v-else>{{ part.text }}</template></template></template></p>
        </template>
    </div>
</template>
