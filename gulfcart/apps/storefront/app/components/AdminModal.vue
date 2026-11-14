<script setup lang="ts">
const props = defineProps<{ open: boolean, title: string, wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.open) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center" @click.self="emit('close')">
      <div role="dialog" aria-modal="true" :aria-label="title" class="card w-full p-6" :class="wide ? 'max-w-3xl' : 'max-w-lg'">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-lg font-bold">{{ title }}</h2>
          <button :aria-label="$t('admin.nav.close')" class="text-ink-500 hover:text-ink-900" @click="emit('close')"><AppIcon name="x" /></button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>
