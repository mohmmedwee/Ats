<script setup lang="ts">
const props = defineProps<{ status: string, scope?: 'orders.status' | 'orders.package_status' }>()
const { t, te } = useI18n()
const tone = computed(() => {
  if (['delivered', 'completed', 'paid', 'succeeded', 'purchased', 'published', 'approved', 'refunded', 'accepted', 'found'].includes(props.status)) return 'bg-green-100 text-green-800'
  if (['cancelled', 'payment_failed', 'expired', 'exception', 'failed', 'rejected', 'suspended', 'revoked', 'error'].includes(props.status)) return 'bg-red-100 text-red-800'
  if (['shipped', 'partially_shipped', 'processing', 'preparing', 'in_progress', 'supplier_processing', 'pending_review'].includes(props.status)) return 'bg-blue-100 text-blue-800'
  if (['manual', 'waiting_retry', 'reconciling', 'requested', 'manual_required', 'pending'].includes(props.status)) return 'bg-gold-100 text-ink-900'
  return 'bg-gray-100 text-gray-800'
})
const label = computed(() => {
  if (props.scope) return t(`${props.scope}.${props.status}`)
  const key = `admin.status_labels.${props.status}`
  return te(key) ? t(key) : props.status
})
</script>

<template>
  <span class="inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium" :class="tone">{{ label }}</span>
</template>
