<script setup lang="ts">
const props = defineProps<{ productId: string, floating?: boolean }>()
const wishlist = useWishlistStore()
const localePath = useLocalePath()
const route = useRoute()
const saved = computed(() => wishlist.has(props.productId))
onMounted(() => wishlist.load().catch(() => undefined))

async function onClick() {
  const ok = await wishlist.toggle(props.productId)
  if (!ok) await navigateTo(localePath({ path: '/login', query: { redirect: route.fullPath } }))
}
</script>

<template>
  <button
    type="button"
    :aria-label="saved ? $t('wishlist.remove') : $t('wishlist.add')" :aria-pressed="saved"
    class="grid place-items-center rounded-full transition"
    :class="floating ? 'absolute end-2 top-2 h-8 w-8 bg-white/90 shadow-sm backdrop-blur hover:scale-110' : 'h-11 w-11 border border-ink-300 bg-white hover:border-ink-900'"
    data-test="wishlist-toggle"
    @click.prevent.stop="onClick"
  >
    <svg :width="floating ? 16 : 20" :height="floating ? 16 : 20" viewBox="0 0 24 24" :fill="saved ? '#E0245E' : 'none'" :stroke="saved ? '#E0245E' : 'currentColor'" stroke-width="1.8" aria-hidden="true">
      <path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z" />
    </svg>
  </button>
</template>
