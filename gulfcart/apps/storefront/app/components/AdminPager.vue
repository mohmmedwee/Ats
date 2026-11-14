<script setup lang="ts">
const props = defineProps<{ page: number, lastPage: number, total?: number }>()
const emit = defineEmits<{ go: [page: number] }>()
</script>

<template>
  <div v-if="props.lastPage > 1 || props.total !== undefined" class="mt-4 flex items-center justify-between text-sm text-ink-500">
    <span v-if="props.total !== undefined">{{ $t('admin.common.total', { n: props.total }) }}</span>
    <div v-if="props.lastPage > 1" class="flex items-center gap-2">
      <button class="btn-outline btn-sm" :disabled="props.page <= 1" @click="emit('go', props.page - 1)">{{ $t('admin.common.prev') }}</button>
      <span>{{ props.page }} / {{ props.lastPage }}</span>
      <button class="btn-outline btn-sm" :disabled="props.page >= props.lastPage" @click="emit('go', props.page + 1)">{{ $t('admin.common.next') }}</button>
    </div>
  </div>
</template>
