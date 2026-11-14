<script setup lang="ts">
/**
 * Renders the small page markup used by content pages, without v-html:
 * "## " headings, "- " bullets, "1. " numbered items, blank lines between paragraphs.
 */
const props = defineProps<{ text: string }>()
type Block = { kind: 'h' | 'p', text: string } | { kind: 'ul' | 'ol', items: string[] }
const blocks = computed<Block[]>(() => {
  const out: Block[] = []
  for (const chunk of props.text.replace(/\r/g, '').split(/\n{2,}/)) {
    const lines = chunk.split('\n').map(l => l.trim()).filter(Boolean)
    if (!lines.length) continue
    if (lines.length === 1 && lines[0]!.startsWith('## ')) {
      out.push({ kind: 'h', text: lines[0]!.slice(3) })
    }
    else if (lines.every(l => l.startsWith('- '))) {
      out.push({ kind: 'ul', items: lines.map(l => l.slice(2)) })
    }
    else if (lines.every(l => /^\d+\.\s/.test(l))) {
      out.push({ kind: 'ol', items: lines.map(l => l.replace(/^\d+\.\s/, '')) })
    }
    else {
      for (const l of lines) out.push(l.startsWith('## ') ? { kind: 'h', text: l.slice(3) } : { kind: 'p', text: l })
    }
  }
  return out
})
</script>

<template>
  <div class="space-y-4 leading-relaxed text-ink-700">
    <template v-for="(b, i) in blocks" :key="i">
      <h2 v-if="b.kind === 'h'" class="pt-2 text-lg font-bold text-ink-900">{{ b.text }}</h2>
      <p v-else-if="b.kind === 'p'">{{ b.text }}</p>
      <ul v-else-if="b.kind === 'ul'" class="list-disc space-y-1 ps-6"><li v-for="(it, j) in b.items" :key="j">{{ it }}</li></ul>
      <ol v-else-if="b.kind === 'ol'" class="list-decimal space-y-1 ps-6"><li v-for="(it, j) in b.items" :key="j">{{ it }}</li></ol>
    </template>
  </div>
</template>
