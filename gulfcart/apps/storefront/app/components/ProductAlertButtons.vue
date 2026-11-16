<script setup lang="ts">
/** "Tell me when it's back" (out of stock) and "Tell me if the price drops". Sign-in needed to know whom to tell. */
const props = defineProps<{ productId: string, variantId: string | null, outOfStock: boolean }>()
const auth = useAuthStore()
const { request, csrf } = useApi()
const localePath = useLocalePath()
const route = useRoute()
const { t } = useI18n()
const done = ref<Record<string, boolean>>({})
const error = ref('')

async function subscribe(type: 'back_in_stock' | 'price_drop') {
  error.value = ''
  if (!auth.user) {
    await navigateTo(localePath({ path: '/login', query: { redirect: route.fullPath } }))
    return
  }
  try {
    await csrf()
    await request(`/products/${props.productId}/alerts`, { method: 'POST', body: { type, variant_id: type === 'back_in_stock' ? props.variantId : null } })
    done.value[type] = true
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
watch(() => props.variantId, () => (done.value.back_in_stock = false))
</script>

<template>
  <div class="space-y-2" data-test="product-alerts">
    <button v-if="outOfStock" type="button" class="btn-primary h-12 w-full font-bold" :disabled="done.back_in_stock" data-test="alert-stock" @click="subscribe('back_in_stock')">
      <AppIcon :name="done.back_in_stock ? 'check' : 'clock'" :size="17" /> {{ done.back_in_stock ? $t('alerts.stock_done') : $t('alerts.stock') }}
    </button>
    <button type="button" class="flex items-center gap-1.5 text-xs font-semibold text-ink-700 underline underline-offset-4 disabled:no-underline" :disabled="done.price_drop" data-test="alert-price" @click="subscribe('price_drop')">
      <AppIcon :name="done.price_drop ? 'check' : 'tag'" :size="14" /> {{ done.price_drop ? $t('alerts.price_done') : $t('alerts.price') }}
    </button>
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
  </div>
</template>
