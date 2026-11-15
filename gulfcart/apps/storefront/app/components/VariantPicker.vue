<script setup lang="ts">
import type { Money, Variant } from '~/types/api'

/**
 * One row per option (colour first, then size, then anything else). A value is greyed out when no
 * purchasable variant has it together with the other choices already made. When options are priced
 * differently, each choice shows its own price.
 */
const props = defineProps<{
  variants: Variant[]
  images: { url: string, variant_id: string | null }[]
  modelValue: string | null
}>()
const emit = defineEmits<{ 'update:modelValue': [id: string | null], 'image': [url: string] }>()
const { locale, t, te } = useI18n()

const ORDER = ['color', 'colour', 'size']
const keys = computed(() => {
  const all = new Set<string>()
  props.variants.forEach(v => Object.keys(v.attributes).forEach(k => all.add(k)))
  return [...all].sort((a, b) => (ORDER.indexOf(a) === -1 ? 99 : ORDER.indexOf(a)) - (ORDER.indexOf(b) === -1 ? 99 : ORDER.indexOf(b)))
})
const valuesOf = (key: string) => [...new Set(props.variants.map(v => v.attributes[key]).filter((x): x is string => !!x))]

const choice = reactive<Record<string, string>>({})
function syncFrom(id: string | null) {
  const v = props.variants.find(x => x.id === id)
  if (v) Object.assign(choice, v.attributes)
}
watch(() => props.modelValue, syncFrom, { immediate: true })

const matches = (v: Variant, sel: Record<string, string>) => Object.entries(sel).every(([k, val]) => v.attributes[k] === val)
const isColour = (key: string) => key === 'color' || key === 'colour'

/** A value is available if some purchasable variant has it plus the other current choices. */
function available(key: string, value: string) {
  const others = Object.fromEntries(Object.entries(choice).filter(([k]) => k !== key))
  return props.variants.some(v => v.purchasable && v.attributes[key] === value && matches(v, others))
}

function pick(key: string, value: string) {
  choice[key] = value
  let v = props.variants.find(x => x.purchasable && matches(x, choice))
  if (!v) {
    // Keep this choice and move the others to the first combination that can be bought.
    v = props.variants.find(x => x.purchasable && x.attributes[key] === value)
    if (v) Object.assign(choice, v.attributes)
  }
  emit('update:modelValue', v?.id ?? null)
  if (isColour(key)) {
    const ids = props.variants.filter(x => x.attributes[key] === value).map(x => x.id)
    const img = props.images.find(i => i.variant_id && ids.includes(i.variant_id))
    if (img) emit('image', img.url)
  }
}

/** Price shown under a choice when options of this row are priced differently. */
function priceFor(key: string, value: string): Money | null {
  const others = Object.fromEntries(Object.entries(choice).filter(([k]) => k !== key))
  const vs = props.variants.filter(v => v.price && v.attributes[key] === value && matches(v, others))
  const cheapest = vs.sort((a, b) => Number(a.price!.amount) - Number(b.price!.amount))[0]
  return cheapest?.price ?? null
}
const varies = (key: string) => new Set(valuesOf(key).map(val => priceFor(key, val)?.amount).filter(Boolean)).size > 1

const keyLabel = (key: string) => (te(`product.option.${key}`) ? t(`product.option.${key}`) : key)
const valueLabel = (key: string, value: string) => (isColour(key) && te(`colors.${value.toLowerCase()}`) ? t(`colors.${value.toLowerCase()}`) : value)

/** CSS colour for common names (English and Arabic); unknown names fall back to a text chip. */
const SWATCH: Record<string, string> = {
  black: '#1f1f23', white: '#ffffff', beige: '#e7d7bd', cream: '#f3ead7', navy: '#24325f', blue: '#2f6fd6', 'light blue': '#9cc3ef',
  red: '#d22b2b', burgundy: '#7a1f33', pink: '#f2a6bf', green: '#2f8a4d', olive: '#6f7340', khaki: '#b9a77a', brown: '#7b4d2a',
  grey: '#9a9aa2', gray: '#9a9aa2', yellow: '#f2c230', orange: '#f08a2b', purple: '#7a4fb3', gold: '#d4af37', silver: '#c0c0c8',
  أسود: '#1f1f23', أبيض: '#ffffff', بيج: '#e7d7bd', كحلي: '#24325f', أزرق: '#2f6fd6', أحمر: '#d22b2b', وردي: '#f2a6bf', أخضر: '#2f8a4d', بني: '#7b4d2a', رمادي: '#9a9aa2',
}
const swatch = (value: string) => SWATCH[value.trim().toLowerCase()]
const colourImage = (key: string, value: string) => {
  const ids = props.variants.filter(x => x.attributes[key] === value).map(x => x.id)
  return props.images.find(i => i.variant_id && ids.includes(i.variant_id))?.url
}
</script>

<template>
  <div class="space-y-4" data-test="variant-picker">
    <div v-for="key in keys" :key="key">
      <div class="mb-2 flex items-center justify-between">
        <p class="text-sm font-semibold">{{ keyLabel(key) }}: <span class="font-normal text-ink-700" dir="auto" data-test="option-selected">{{ choice[key] ? valueLabel(key, choice[key]!) : '' }}</span></p>
        <slot :name="`after-${key}`" />
      </div>

      <div v-if="isColour(key)" class="flex flex-wrap gap-2" :data-test="`option-${key}`">
        <button
          v-for="val in valuesOf(key)" :key="val" type="button"
          :aria-label="valueLabel(key, val)" :aria-pressed="choice[key] === val" :title="valueLabel(key, val)"
          class="relative grid h-11 w-11 place-items-center overflow-hidden rounded-full border-2 transition"
          :class="[choice[key] === val ? 'border-ink-900' : 'border-ink-300 hover:border-ink-700', available(key, val) ? '' : 'opacity-40']"
          @click="pick(key, val)"
        >
          <img v-if="colourImage(key, val)" :src="colourImage(key, val)" alt="" class="h-full w-full object-cover">
          <span v-else-if="swatch(val)" class="h-full w-full" :style="{ background: swatch(val) }" />
          <span v-else class="px-1 text-[10px] leading-tight">{{ valueLabel(key, val) }}</span>
          <span v-if="!available(key, val)" class="absolute inset-0 m-auto h-px w-14 rotate-45 bg-ink-700" />
        </button>
      </div>

      <div v-else class="flex flex-wrap gap-2" :data-test="`option-${key}`">
        <button
          v-for="val in valuesOf(key)" :key="val" type="button" class="chip flex-col leading-tight"
          :class="{ 'chip-active': choice[key] === val, 'cursor-not-allowed text-ink-300 line-through': !available(key, val) }"
          :disabled="!available(key, val)" :aria-pressed="choice[key] === val"
          @click="pick(key, val)"
        >
          <span dir="auto">{{ valueLabel(key, val) }}</span>
          <span v-if="varies(key) && priceFor(key, val)" class="text-[10px] font-normal opacity-80">{{ formatMoney(priceFor(key, val), locale) }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
