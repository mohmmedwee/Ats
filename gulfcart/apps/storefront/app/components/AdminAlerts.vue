<script setup lang="ts">
defineProps<{ error?: string, notice?: string }>()
</script>

<template>
  <div>
    <p v-if="error" role="alert" class="mb-4 flex items-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"><AppIcon name="alert" :size="18" /> {{ error }}</p>
    <p v-if="notice" role="status" data-test="admin-notice" class="mb-4 flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800"><AppIcon name="check" :size="18" /> {{ notice }}</p>
  </div>
</template>
