<script setup lang="ts">
const props = defineProps<{ status: string, scope?: 'orders.status' | 'orders.package_status' }>()
const tone = computed(() => {
  if (['delivered', 'completed', 'paid', 'succeeded', 'purchased'].includes(props.status)) return 'bg-green-100 text-green-800'
  if (['cancelled', 'payment_failed', 'expired', 'exception', 'failed', 'rejected'].includes(props.status)) return 'bg-red-100 text-red-800'
  if (['shipped', 'partially_shipped', 'processing', 'preparing'].includes(props.status)) return 'bg-blue-100 text-blue-800'
  return 'bg-gray-100 text-gray-800'
})
</script>

<template>
  <span class="inline-block rounded px-2 py-0.5 text-xs font-medium" :class="tone">
    {{ scope ? $t(`${scope}.${status}`) : status }}
  </span>
</template>
