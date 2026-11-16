<script setup lang="ts">
import type { ProductCard } from '~/types/api'

/**
 * Full-width banners built from the store's own products: bold type on a colour field with a
 * collage of product photos. Auto-advances; swipe/scroll on phones.
 */
const props = defineProps<{ products: ProductCard[] }>()
const localePath = useLocalePath()
const { country } = useApi()
const currency = computed(() => currencyOf(country.value))
const index = ref(0)
const track = ref<HTMLElement | null>(null)

const slides = computed(() => [
  { key: 'new', bg: 'bg-[#f3e6dc]', fg: 'text-ink-900', kicker: 'banner.new_kicker', title: 'banner.new_title', cta: 'banner.shop_now', to: { path: '/products', query: { sort: 'newest' } }, imgs: props.products.slice(0, 3) },
  { key: 'tech', bg: 'bg-ink-900', fg: 'text-white', kicker: 'banner.tech_kicker', title: 'banner.tech_title', cta: 'banner.discover', to: { path: '/products', query: { category: 'electronics' } }, imgs: props.products.slice(3, 6) },
  { key: 'value', bg: 'bg-brand-600', fg: 'text-white', kicker: 'banner.value_kicker', title: 'banner.value_title', cta: 'banner.shop_now', to: { path: '/products', query: { price_max: '100', sort: 'price_asc' } }, imgs: props.products.slice(6, 9) },
])

function go(i: number) {
  index.value = (i + slides.value.length) % slides.value.length
  const el = track.value
  if (el) el.scrollTo({ left: (el.scrollWidth / slides.value.length) * index.value * (document.dir === 'rtl' ? -1 : 1), behavior: 'smooth' })
}
function onScroll() {
  const el = track.value
  if (el) index.value = Math.round(Math.abs(el.scrollLeft) / el.clientWidth)
}
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => (timer = setInterval(() => go(index.value + 1), 6000)))
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <section class="relative" data-test="hero">
    <div ref="track" class="no-scrollbar flex snap-x snap-mandatory overflow-x-auto" @scroll.passive="onScroll">
      <NuxtLink
        v-for="s in slides" :key="s.key" :to="localePath(s.to)"
        class="relative flex h-[300px] w-full shrink-0 snap-start items-center overflow-hidden md:h-[380px]" :class="[s.bg, s.fg]"
      >
        <div class="relative z-10 w-1/2 px-5 md:w-2/5 md:px-14">
          <p class="text-[11px] font-bold uppercase tracking-[0.2em] opacity-80 md:text-xs">{{ $t(s.kicker) }}</p>
          <h2 class="mt-2 text-3xl font-black leading-[1.05] md:text-6xl">{{ $t(s.title, { amount: `100 ${currency}` }) }}</h2>
          <span class="mt-5 inline-flex items-center gap-2 border-b-2 border-current pb-0.5 text-sm font-bold">{{ $t(s.cta) }} <AppIcon name="chevron" :size="14" class="rtl:rotate-180" /></span>
        </div>
        <!-- collage of real product photos -->
        <div class="absolute inset-y-0 end-0 flex w-1/2 items-center justify-center gap-2 pe-3 md:w-3/5 md:gap-4 md:pe-10">
          <div v-for="(p, i) in s.imgs" :key="p.id" class="overflow-hidden rounded-md bg-white/20 shadow-xl" :class="i === 1 ? 'h-[78%] w-[38%] md:w-[30%]' : 'hidden h-[62%] w-[30%] sm:block md:w-[24%]'">
            <img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover">
          </div>
        </div>
      </NuxtLink>
    </div>
    <div class="absolute inset-x-0 bottom-3 flex justify-center gap-1.5">
      <button v-for="(s, i) in slides" :key="s.key" type="button" :aria-label="`${i + 1}`" class="h-1.5 rounded-full transition-all" :class="i === index ? 'w-6 bg-white' : 'w-1.5 bg-white/50'" @click="go(i)" />
    </div>
  </section>
</template>
