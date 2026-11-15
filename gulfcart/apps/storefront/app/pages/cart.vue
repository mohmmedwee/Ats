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
    <h1 class="mb-4 text-lg font-extrabold uppercase tracking-wide md:text-xl">{{ $t('cart.title') }} <span v-if="cart.count" class="text-sm font-medium normal-case text-ink-500">({{ cart.count }})</span></h1>

    <div v-if="!cart.cart?.items.length" class="flex flex-col items-center gap-4 py-16 text-center">
      <span class="flex h-20 w-20 items-center justify-center rounded-full bg-ink-100"><AppIcon name="bag" :size="34" /></span>
      <p class="text-ink-500">{{ $t('cart.empty') }}</p>
      <NuxtLink :to="localePath('/products')" class="btn-primary min-w-48">{{ $t('hero.cta') }}</NuxtLink>
    </div>

    <div v-else class="grid gap-5 md:grid-cols-[minmax(0,1fr)_360px] md:items-start md:gap-8">
      <div class="space-y-3">
        <div v-if="cart.cart.free_shipping" class="rounded-md bg-ink-100/70 p-3.5" data-test="free-shipping">
          <p class="mb-2 flex items-center gap-2 text-sm">
            <AppIcon name="truck" :size="18" />
            <span v-if="cart.cart.free_shipping.reached" class="font-semibold text-green-700">{{ $t('cart.free_reached') }}</span>
            <span v-else>{{ $t('cart.free_remaining', { amount: formatMoney(cart.cart.free_shipping.remaining, locale) }) }}</span>
          </p>
          <div class="h-1.5 overflow-hidden rounded-full bg-white"><div class="h-full rounded-full bg-ink-900 transition-all" :style="{ width: `${progress}%` }" /></div>
        </div>

        <ul class="divide-y divide-ink-100 border-y border-ink-100">
          <li v-for="line in cart.cart.items" :key="line.id" class="flex gap-3 py-4" data-test="cart-line">
            <NuxtLink v-if="line.product" :to="localePath(`/products/${line.product.slug}`)" class="aspect-[3/4] w-[88px] shrink-0 overflow-hidden rounded-md bg-ink-100 md:w-[104px]">
              <img v-if="line.product.image" :src="line.product.image" alt="" class="h-full w-full object-cover">
            </NuxtLink>
            <div class="flex min-w-0 flex-1 flex-col">
              <div class="flex items-start gap-2">
                <p class="line-clamp-2 flex-1 text-sm text-ink-700">{{ line.product?.title }}</p>
                <button class="text-ink-500 hover:text-ink-900" :aria-label="$t('common.remove')" @click="cart.remove(line.id)"><AppIcon name="trash" :size="17" /></button>
              </div>
              <p v-if="Object.keys(line.attributes).length" class="mt-1.5 inline-flex w-fit rounded bg-ink-100 px-2 py-0.5 text-xs text-ink-700" dir="auto">{{ Object.values(line.attributes).join(' / ') }}</p>
              <p v-if="issueFor(line.id)" class="mt-1 text-xs font-medium text-gold-600" role="alert">
                {{ $t(`cart.issues.${issueFor(line.id)!.code}`, { old: formatMoney(issueFor(line.id)!.old_price, locale), new: formatMoney(issueFor(line.id)!.new_price, locale) }) }}
              </p>
              <div class="mt-auto flex items-center justify-between pt-2">
                <p class="text-base font-extrabold">{{ formatMoney(line.line_total, locale) }}</p>
                <div class="flex items-center rounded-md border border-ink-300 text-sm">
                  <button class="h-8 w-8" :disabled="line.quantity <= 1" @click="cart.update(line.id, line.quantity - 1)">−</button>
                  <span class="w-6 text-center font-semibold">{{ line.quantity }}</span>
                  <button class="h-8 w-8" :disabled="line.quantity >= 20" @click="cart.update(line.id, line.quantity + 1)">+</button>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </div>

      <aside class="space-y-3 rounded-md border border-ink-100 p-5 md:sticky md:top-36">
        <p class="text-sm font-extrabold uppercase tracking-wide">{{ $t('checkout.summary') }}</p>
        <div class="flex justify-between text-base"><span>{{ $t('cart.subtotal') }}</span><strong class="font-extrabold" data-test="cart-subtotal">{{ formatMoney(cart.cart.subtotal, locale) }}</strong></div>
        <p class="text-xs text-ink-500">{{ $t('cart.summary_note') }}</p>
        <button v-if="cart.cart.issues.length" class="btn-outline w-full" @click="cart.validate()">{{ $t('cart.review') }}</button>
        <NuxtLink v-else-if="auth.user" :to="localePath('/checkout')" class="btn-primary h-12 w-full font-bold uppercase tracking-wide" data-test="go-checkout">{{ $t('cart.checkout') }}</NuxtLink>
        <NuxtLink v-else :to="localePath('/login?redirect=/checkout')" class="btn-primary h-12 w-full font-bold">{{ $t('cart.login_to_checkout') }}</NuxtLink>
        <p class="flex items-center gap-2 text-xs text-ink-500"><AppIcon name="shield" :size="16" /> {{ $t('footer.secure') }}</p>
      </aside>
    </div>
  </section>
</template>
