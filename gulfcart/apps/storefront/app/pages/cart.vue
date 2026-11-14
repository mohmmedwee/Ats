<script setup lang="ts">
const cart = useCartStore()
const auth = useAuthStore()
const { locale } = useI18n()
const localePath = useLocalePath()
const { country } = useApi()

onMounted(() => cart.load())
watch(country, () => cart.load())

const issueFor = (id: string) => cart.cart?.issues.find(i => i.item_id === id)
</script>

<template>
  <section>
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('cart.title') }}</h1>
    <p v-if="!cart.cart?.items.length" class="text-gray-500">{{ $t('cart.empty') }}</p>
    <ul v-else class="divide-y rounded border">
      <li v-for="line in cart.cart.items" :key="line.id" class="flex flex-wrap items-center gap-4 p-3" data-test="cart-line">
        <img v-if="line.product?.image" :src="line.product.image" alt="" class="h-16 w-16 rounded object-cover">
        <div class="min-w-40 flex-1">
          <NuxtLink v-if="line.product" :to="localePath(`/products/${line.product.slug}`)" class="font-medium">{{ line.product.title }}</NuxtLink>
          <p class="text-xs text-gray-500">{{ Object.values(line.attributes).join(' / ') }}</p>
          <p v-if="issueFor(line.id)" class="text-sm text-amber-700" role="alert">
            {{ $t(`cart.issues.${issueFor(line.id)!.code}`, { old: formatMoney(issueFor(line.id)!.old_price, locale), new: formatMoney(issueFor(line.id)!.new_price, locale) }) }}
          </p>
        </div>
        <select :value="line.quantity" class="rounded border px-2 py-1" :aria-label="$t('common.quantity')" @change="cart.update(line.id, Number(($event.target as HTMLSelectElement).value))">
          <option v-for="n in 20" :key="n" :value="n">{{ n }}</option>
        </select>
        <span class="w-28 text-end font-medium">{{ formatMoney(line.line_total, locale) }}</span>
        <button class="text-sm underline" @click="cart.remove(line.id)">{{ $t('common.remove') }}</button>
      </li>
    </ul>

    <div v-if="cart.cart?.items.length" class="mt-6 flex flex-col items-end gap-2">
      <p class="text-lg">{{ $t('cart.subtotal') }}: <strong data-test="cart-subtotal">{{ formatMoney(cart.cart.subtotal, locale) }}</strong></p>
      <p class="text-xs text-gray-500">{{ $t('common.prices_include_vat') }}</p>
      <button v-if="cart.cart.issues.length" class="rounded border px-4 py-2" @click="cart.validate()">{{ $t('cart.review') }}</button>
      <NuxtLink v-else-if="auth.user" :to="localePath('/checkout')" class="rounded bg-gray-900 px-6 py-2 text-white" data-test="go-checkout">{{ $t('cart.checkout') }}</NuxtLink>
      <NuxtLink v-else :to="localePath('/login?redirect=/checkout')" class="rounded bg-gray-900 px-6 py-2 text-white">{{ $t('cart.login_to_checkout') }}</NuxtLink>
    </div>
  </section>
</template>
