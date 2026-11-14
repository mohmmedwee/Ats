<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Band { max_grams: number, amount: string | number }
interface Method { id: string, country: string, code: string, name: { en: string, ar: string }, carrier: string | null, pricing_model: 'flat' | 'weight_band', config: { amount?: string | number, bands?: Band[], above_amount?: string | number | null }, free_shipping_threshold: string | null, extra_min_days: number, extra_max_days: number, sort_order: number, is_active: boolean }
const { request, send, run, error, notice, tr } = useAdmin()
const { t } = useI18n()
const methods = ref<Method[]>([])
const open = ref(false)
const editing = ref<Method | null>(null)
const f = reactive({ country: 'AE', code: '', name_en: '', name_ar: '', carrier: '', pricing_model: 'flat' as 'flat' | 'weight_band', amount: '15', bands: [{ max_grams: 500, amount: '15' }] as Band[], above_amount: '', free_shipping_threshold: '200', extra_min_days: 0, extra_max_days: 0, sort_order: 0, is_active: true })
const currency = (c: string) => (c === 'SA' ? 'SAR' : 'AED')

async function load() {
  await run(async () => {
    methods.value = (await request<{ data: Method[] }>('/admin/shipping-methods')).data
  })
}
function edit(m: Method | null) {
  editing.value = m
  Object.assign(f, m
    ? { country: m.country, code: m.code, name_en: m.name.en, name_ar: m.name.ar, carrier: m.carrier ?? '', pricing_model: m.pricing_model, amount: String(m.config.amount ?? ''), bands: (m.config.bands ?? []).map(b => ({ ...b })), above_amount: String(m.config.above_amount ?? ''), free_shipping_threshold: m.free_shipping_threshold ?? '', extra_min_days: m.extra_min_days, extra_max_days: m.extra_max_days, sort_order: m.sort_order, is_active: m.is_active }
    : { country: 'AE', code: '', name_en: '', name_ar: '', carrier: '', pricing_model: 'flat', amount: '15', bands: [{ max_grams: 500, amount: '15' }], above_amount: '', free_shipping_threshold: '200', extra_min_days: 0, extra_max_days: 0, sort_order: 0, is_active: true })
  open.value = true
}
async function save() {
  const config = f.pricing_model === 'flat'
    ? { amount: Number(f.amount) }
    : { bands: f.bands.map(b => ({ max_grams: Number(b.max_grams), amount: Number(b.amount) })), above_amount: f.above_amount === '' ? null : Number(f.above_amount) }
  const body: Record<string, unknown> = {
    name: { en: f.name_en, ar: f.name_ar }, carrier: f.carrier || null, pricing_model: f.pricing_model, config,
    free_shipping_threshold: f.free_shipping_threshold === '' ? null : Number(f.free_shipping_threshold),
    extra_min_days: Number(f.extra_min_days), extra_max_days: Number(f.extra_max_days), sort_order: Number(f.sort_order), is_active: f.is_active,
  }
  if (!editing.value) Object.assign(body, { country: f.country, code: f.code })
  const ok = await run(() => editing.value ? send('PATCH', `/admin/shipping-methods/${editing.value.id}`, body) : send('POST', '/admin/shipping-methods', body), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
const price = (m: Method) => m.pricing_model === 'flat'
  ? `${m.config.amount} ${currency(m.country)}`
  : (m.config.bands ?? []).map(b => `≤${b.max_grams}g: ${b.amount}`).join(' · ') + (m.config.above_amount ? ` · +${m.config.above_amount}` : '')
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.shipping.title')" :subtitle="$t('admin.shipping.subtitle')">
      <button class="btn-primary btn-sm" @click="edit(null)"><AppIcon name="plus" :size="14" /> {{ $t('admin.shipping.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div v-for="c in ['AE', 'SA']" :key="c" class="card mb-6 overflow-x-auto">
      <h2 class="px-4 pt-4 font-bold">{{ $t(`country.${c}`) }} <span class="text-sm font-normal text-ink-500">({{ currency(c) }})</span></h2>
      <table class="admin-table mt-2">
        <thead><tr><th>{{ $t('admin.common.name') }}</th><th>{{ $t('admin.shipping.carrier') }}</th><th>{{ $t('admin.shipping.price') }}</th><th>{{ $t('admin.shipping.free_over') }}</th><th>{{ $t('admin.shipping.extra_days') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="m in methods.filter(x => x.country === c)" :key="m.id">
            <td><b>{{ tr(m.name as never) }}</b> <span class="block font-mono text-xs text-ink-500" dir="ltr">{{ m.code }}</span></td>
            <td>{{ m.carrier ?? '—' }}</td>
            <td class="text-xs" dir="ltr">{{ price(m) }}</td>
            <td>{{ m.free_shipping_threshold ? `${m.free_shipping_threshold} ${currency(c)}` : '—' }}</td>
            <td dir="ltr">+{{ m.extra_min_days }} / +{{ m.extra_max_days }}</td>
            <td><span :class="m.is_active ? 'text-green-700' : 'text-ink-500'">{{ m.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</span></td>
            <td class="text-end"><button class="btn-outline btn-sm" @click="edit(m)"><AppIcon name="edit" :size="14" /></button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <AdminModal :open="open" :title="editing ? $t('admin.shipping.edit') : $t('admin.shipping.add')" wide @close="open = false">
      <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="save">
        <template v-if="!editing">
          <label><span class="label">{{ $t('admin.common.country') }}</span><select v-model="f.country" class="input"><option value="AE">AE</option><option value="SA">SA</option></select></label>
          <label class="sm:col-span-2"><span class="label">{{ $t('admin.common.code') }}</span><input v-model="f.code" class="input" required pattern="[a-z0-9_-]+" dir="ltr"></label>
        </template>
        <label><span class="label">{{ $t('admin.common.name_en') }}</span><input v-model="f.name_en" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('admin.common.name_ar') }}</span><input v-model="f.name_ar" class="input" dir="rtl" required></label>
        <label><span class="label">{{ $t('admin.shipping.carrier') }}</span><input v-model="f.carrier" class="input"></label>
        <label><span class="label">{{ $t('admin.shipping.model') }}</span><select v-model="f.pricing_model" class="input"><option value="flat">{{ $t('admin.shipping.models.flat') }}</option><option value="weight_band">{{ $t('admin.shipping.models.weight_band') }}</option></select></label>
        <label v-if="f.pricing_model === 'flat'" class="sm:col-span-2"><span class="label">{{ $t('admin.shipping.price') }} ({{ currency(f.country) }})</span><input v-model="f.amount" class="input" dir="ltr" required></label>
        <div v-else class="space-y-2 sm:col-span-3">
          <div v-for="(b, i) in f.bands" :key="i" class="flex items-center gap-2 text-sm">
            ≤ <input v-model.number="b.max_grams" class="input w-28" dir="ltr"> g → <input v-model="b.amount" class="input w-28" dir="ltr"> {{ currency(f.country) }}
            <button type="button" class="text-red-600" @click="f.bands.splice(i, 1)"><AppIcon name="trash" :size="14" /></button>
          </div>
          <button type="button" class="link text-sm" @click="f.bands.push({ max_grams: (f.bands.at(-1)?.max_grams ?? 0) + 1000, amount: '' })">+ {{ $t('admin.shipping.add_band') }}</button>
          <label class="block w-60"><span class="label">{{ $t('admin.shipping.above') }}</span><input v-model="f.above_amount" class="input" dir="ltr"></label>
        </div>
        <label><span class="label">{{ $t('admin.shipping.free_over') }}</span><input v-model="f.free_shipping_threshold" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.shipping.extra_days') }}</span><span class="flex gap-2"><input v-model.number="f.extra_min_days" class="input" dir="ltr"><input v-model.number="f.extra_max_days" class="input" dir="ltr"></span></label>
        <label><span class="label">{{ $t('admin.categories.order') }}</span><input v-model.number="f.sort_order" class="input" dir="ltr"></label>
        <label class="flex items-center gap-2 text-sm"><input v-model="f.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
        <div class="flex justify-end gap-2 sm:col-span-3"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
