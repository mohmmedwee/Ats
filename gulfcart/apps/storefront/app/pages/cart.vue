<script setup lang="ts">
const cart = useCartStore()
const auth = useAuthStore()
const { locale } = useI18n()
const localePath = useLocalePath()
const { country } = useApi()

onMounted(() => cart.load())
watch(country, () => cart.load())

const issueFor = (id: string) => cart.cart?.issues.find(i => i.item_id === id)
const progress = computed(() => {
  const fs = cart.cart?.free_shipping
  if (!fs) return 100
  return Math.min(100, Math.round((Number(cart.cart?.subtotal?.amount ?? 0) / Number(fs.threshold.amount)) * 100))
})
</script>

<template>
  <section>
    <h1 class="mb-4 text-xl font-bold">{{ $t('cart.title') }} <span v-if="cart.count" class="text-base font-normal text-ink-500">({{ cart.count }})</span></h1>

    <div v-if="!cart.cart?.items.length" class="card flex flex-col items-center gap-4 p-12 text-center">
      <span class="flex h-16 w-16 items-center justify-center rounded-full bg-brand-50 text-brand-600"><AppIcon name="cart" :size="30" /></span>
      <p class="text-ink-500">{{ $t('cart.empty') }}</p>
      <NuxtLink :to="localePath('/products')" class="btn-primary">{{ $t('hero.cta') }}</NuxtLink>
    </div>

    <div v-else class="grid gap-4 md:grid-cols-[1fr_340px] md:items-start">
      <div class="space-y-3">
        <div v-if="cart.cart.free_shipping" class="card p-4" data-test="free-shipping">
          <p class="mb-2 flex items-center gap-2 text-sm">
            <AppIcon name="truck" :size="18" class="text-brand-600" />
            <span v-if="cart.cart.free_shipping.reached" class="font-semibold text-green-700">{{ $t('cart.free_reached') }}</span>
            <span v-else>{{ $t('cart.free_remaining', { amount: formatMoney(cart.cart.free_shipping.remaining, locale) }) }}</span>
          </p>
          <div class="h-2 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-brand-600 transition-all" :style="{ width: `${progress}%` }" /></div>
        </div>

        <ul class="card divide-y">
          <li v-for="line in cart.cart.items" :key="line.id" class="flex gap-3 p-3" data-test="cart-line">
            <NuxtLink v-if="line.product" :to="localePath(`/products/${line.product.slug}`)" class="h-28 w-21 shrink-0 overflow-hidden rounded-lg bg-ink-100" style="width: 5.25rem">
              <img v-if="line.product.image" :src="line.product.image" alt="" class="h-full w-full object-cover">
            </NuxtLink>
            <div class="flex min-w-0 flex-1 flex-col">
              <p class="line-clamp-2 text-sm">{{ line.product?.title }}</p>
              <p class="mt-1 text-xs text-ink-500">{{ Object.values(line.attributes).join(' / ') }}</p>
              <p v-if="issueFor(line.id)" class="mt-1 text-xs font-medium text-gold-600" role="alert">
                {{ $t(`cart.issues.${issueFor(line.id)!.code}`, { old: formatMoney(issueFor(line.id)!.old_price, locale), new: formatMoney(issueFor(line.id)!.new_price, locale) }) }}
              </p>
              <div class="mt-auto flex items-center justify-between pt-2">
                <p class="font-bold text-brand-600">{{ formatMoney(line.line_total, locale) }}</p>
                <div class="flex items-center gap-3">
                  <div class="flex items-center rounded-full border border-ink-300 text-sm">
                    <button class="h-8 w-8" :disabled="line.quantity <= 1" @click="cart.update(line.id, line.quantity - 1)">−</button>
                    <span class="w-6 text-center">{{ line.quantity }}</span>
                    <button class="h-8 w-8" :disabled="line.quantity >= 20" @click="cart.update(line.id, line.quantity + 1)">+</button>
                  </div>
                  <button class="text-xs text-ink-500 underline" @click="cart.remove(line.id)">{{ $t('common.remove') }}</button>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </div>

      <aside class="card space-y-3 p-4 md:sticky md:top-36">
        <p class="font-bold">{{ $t('checkout.summary') }}</p>
        <div class="flex justify-between text-sm"><span>{{ $t('cart.subtotal') }}</span><strong data-test="cart-subtotal">{{ formatMoney(cart.cart.subtotal, locale) }}</strong></div>
        <p class="text-xs text-ink-500">{{ $t('cart.summary_note') }}</p>
        <button v-if="cart.cart.issues.length" class="btn-outline w-full" @click="cart.validate()">{{ $t('cart.review') }}</button>
        <NuxtLink v-else-if="auth.user" :to="localePath('/checkout')" class="btn-primary w-full py-3" data-test="go-checkout">{{ $t('cart.checkout') }}</NuxtLink>
        <NuxtLink v-else :to="localePath('/login?redirect=/checkout')" class="btn-primary w-full py-3">{{ $t('cart.login_to_checkout') }}</NuxtLink>
        <p class="flex items-center gap-2 text-xs text-ink-500"><AppIcon name="shield" :size="16" /> {{ $t('footer.secure') }}</p>
      </aside>
    </div>
  </section>
</template>
