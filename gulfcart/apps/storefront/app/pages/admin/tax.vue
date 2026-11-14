<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface TaxRule { id: string, country: string, tax_type: string, category_id: string | null, rate_percent: string, is_recoverable: boolean, applies_to_shipping: boolean, effective_from: string, effective_to: string | null }
interface CustomsRule { id: string, country: string, hs_code_prefix: string | null, duty_percent: string, valuation_basis: string, de_minimis_amount: string | null, de_minimis_currency: string | null, effective_from: string, effective_to: string | null }
const { request, send, run, error, notice } = useAdmin()
const { t } = useI18n()
const tax = ref<TaxRule[]>([])
const customs = ref<CustomsRule[]>([])
const today = new Date().toISOString().slice(0, 10)
const taxOpen = ref(false)
const customsOpen = ref(false)
const tf = reactive({ country: 'AE', tax_type: 'vat', rate_percent: '5', applies_to_shipping: true, effective_from: today })
const cf = reactive({ country: 'AE', hs_code_prefix: '', duty_percent: '5', valuation_basis: 'CIF', de_minimis_amount: '1000', de_minimis_currency: 'AED', effective_from: today })

async function load() {
  await run(async () => {
    const [a, b] = await Promise.all([request<{ data: TaxRule[] }>('/admin/tax-rules'), request<{ data: CustomsRule[] }>('/admin/customs-rules')])
    tax.value = a.data
    customs.value = b.data
  })
}
async function addTax() {
  const ok = await run(() => send('POST', '/admin/tax-rules', tf), t('admin.common.saved'))
  if (ok !== undefined) {
    taxOpen.value = false
    await load()
  }
}
async function addCustoms() {
  const ok = await run(() => send('POST', '/admin/customs-rules', { ...cf, hs_code_prefix: cf.hs_code_prefix || null, de_minimis_amount: cf.de_minimis_amount || null, de_minimis_currency: cf.de_minimis_amount ? cf.de_minimis_currency : null }), t('admin.common.saved'))
  if (ok !== undefined) {
    customsOpen.value = false
    await load()
  }
}
async function endRule(kind: 'tax-rules' | 'customs-rules', id: string) {
  if (!confirm(t('admin.tax.end_confirm'))) return
  await run(() => send('PATCH', `/admin/${kind}/${id}`, { effective_to: today }), t('admin.common.saved'))
  await load()
}
const current = (r: { effective_from: string, effective_to: string | null }) => r.effective_from <= today && (!r.effective_to || r.effective_to >= today)
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.tax.title')" :subtitle="$t('admin.tax.subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />
    <p class="mb-4 rounded-xl bg-gold-100 px-4 py-3 text-sm text-ink-700">{{ $t('admin.tax.advisor') }}</p>

    <div class="card mb-6 overflow-x-auto">
      <div class="flex items-center justify-between px-4 pt-4"><h2 class="font-bold">{{ $t('admin.tax.vat') }}</h2><button class="btn-primary btn-sm" @click="taxOpen = true"><AppIcon name="plus" :size="14" /> {{ $t('admin.tax.add') }}</button></div>
      <table class="admin-table mt-2">
        <thead><tr><th>{{ $t('admin.common.country') }}</th><th>{{ $t('admin.tax.type') }}</th><th>{{ $t('admin.tax.rate') }}</th><th>{{ $t('admin.tax.shipping') }}</th><th>{{ $t('admin.tax.period') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in tax" :key="r.id" :class="current(r) ? '' : 'opacity-50'">
            <td>{{ r.country }}</td><td>{{ $t(`admin.tax.types.${r.tax_type}`) }}</td><td class="font-semibold" dir="ltr">{{ Number(r.rate_percent) }}%</td>
            <td>{{ r.applies_to_shipping ? '✓' : '—' }}</td><td class="text-xs" dir="ltr">{{ r.effective_from }} → {{ r.effective_to ?? '∞' }}</td>
            <td class="text-end"><button v-if="current(r) && !r.effective_to" class="btn-outline btn-sm" @click="endRule('tax-rules', r.id)">{{ $t('admin.tax.end') }}</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card overflow-x-auto">
      <div class="flex items-center justify-between px-4 pt-4"><h2 class="font-bold">{{ $t('admin.tax.customs') }}</h2><button class="btn-primary btn-sm" @click="customsOpen = true"><AppIcon name="plus" :size="14" /> {{ $t('admin.tax.add_customs') }}</button></div>
      <table class="admin-table mt-2">
        <thead><tr><th>{{ $t('admin.common.country') }}</th><th>{{ $t('admin.tax.hs') }}</th><th>{{ $t('admin.tax.duty') }}</th><th>{{ $t('admin.tax.basis') }}</th><th>{{ $t('admin.tax.de_minimis') }}</th><th>{{ $t('admin.tax.period') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in customs" :key="r.id" :class="current(r) ? '' : 'opacity-50'">
            <td>{{ r.country }}</td><td class="font-mono" dir="ltr">{{ r.hs_code_prefix ?? $t('admin.tax.default') }}</td><td class="font-semibold" dir="ltr">{{ Number(r.duty_percent) }}%</td>
            <td>{{ r.valuation_basis }}</td><td dir="ltr">{{ r.de_minimis_amount ? `${r.de_minimis_amount} ${r.de_minimis_currency}` : '—' }}</td>
            <td class="text-xs" dir="ltr">{{ r.effective_from }} → {{ r.effective_to ?? '∞' }}</td>
            <td class="text-end"><button v-if="current(r) && !r.effective_to" class="btn-outline btn-sm" @click="endRule('customs-rules', r.id)">{{ $t('admin.tax.end') }}</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <AdminModal :open="taxOpen" :title="$t('admin.tax.add')" @close="taxOpen = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="addTax">
        <label><span class="label">{{ $t('admin.common.country') }}</span><select v-model="tf.country" class="input"><option>AE</option><option>SA</option></select></label>
        <label><span class="label">{{ $t('admin.tax.type') }}</span><select v-model="tf.tax_type" class="input"><option value="vat">{{ $t('admin.tax.types.vat') }}</option><option value="import_vat">{{ $t('admin.tax.types.import_vat') }}</option></select></label>
        <label><span class="label">{{ $t('admin.tax.rate') }} %</span><input v-model="tf.rate_percent" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('admin.common.from') }}</span><input v-model="tf.effective_from" type="date" class="input" required></label>
        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="tf.applies_to_shipping" type="checkbox"> {{ $t('admin.tax.shipping') }}</label>
        <p class="text-xs text-ink-500 sm:col-span-2">{{ $t('admin.tax.effective_hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="taxOpen = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
    <AdminModal :open="customsOpen" :title="$t('admin.tax.add_customs')" @close="customsOpen = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="addCustoms">
        <label><span class="label">{{ $t('admin.common.country') }}</span><select v-model="cf.country" class="input" @change="cf.de_minimis_currency = cf.country === 'SA' ? 'SAR' : 'AED'"><option>AE</option><option>SA</option></select></label>
        <label><span class="label">{{ $t('admin.tax.hs') }}</span><input v-model="cf.hs_code_prefix" class="input" dir="ltr" :placeholder="$t('admin.tax.default')"></label>
        <label><span class="label">{{ $t('admin.tax.duty') }} %</span><input v-model="cf.duty_percent" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('admin.tax.basis') }}</span><select v-model="cf.valuation_basis" class="input"><option>CIF</option><option>FOB</option></select></label>
        <label><span class="label">{{ $t('admin.tax.de_minimis') }} ({{ cf.de_minimis_currency }})</span><input v-model="cf.de_minimis_amount" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.common.from') }}</span><input v-model="cf.effective_from" type="date" class="input" required></label>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="customsOpen = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
