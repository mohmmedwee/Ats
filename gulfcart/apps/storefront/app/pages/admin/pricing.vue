<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Rule { id: string, scope: string, country: string | null, supplier_id: string | null, category_id: string | null, product_id: string | null, rule_type: string, value: string, min_margin_percent: string | null, max_selling_price: string | null, priority: number, is_active: boolean }
interface Rate { id: string, base: string, target: string, rate: string, source: string, effective_at: string, stale: boolean }
const { request, send, run, error, notice, tr } = useAdmin()
const { t, locale } = useI18n()
const tab = ref<'rules' | 'fx' | 'simulate'>('rules')
const rules = ref<Rule[]>([])
const rates = ref<Rate[]>([])
const categories = ref<{ id: string, name: Record<string, string> }[]>([])
const suppliers = ref<{ id: string, name: string }[]>([])
const ruleOpen = ref(false)
const editing = ref<Rule | null>(null)
const rf = reactive({ scope: 'global', country: '', supplier_id: '', category_id: '', rule_type: 'margin_percent', value: '35', min_margin_percent: '15', max_selling_price: '', priority: 100, is_active: true })
const fx = reactive({ base: 'USD', target: 'AED', rate: '' })
const sim = reactive({ unit_cost: '10', shipping_cost: '5', cost_currency: 'USD', sale_currency: 'AED', exchange_rate: '3.6725', vat_percent: '5', rule_type: 'margin_percent', value: '35', min_margin_percent: '15', payment_fee_percent: '2.9', rounding: 'ceil_whole' })
const simResult = ref<Record<string, unknown> | null>(null)

async function load() {
  await run(async () => {
    const [r, x] = await Promise.all([request<{ data: Rule[] }>('/admin/pricing-rules'), request<{ data: Rate[] }>('/admin/exchange-rates')])
    rules.value = r.data
    rates.value = x.data
  })
}
function editRule(r: Rule | null) {
  editing.value = r
  Object.assign(rf, r
    ? { scope: r.scope, country: r.country ?? '', supplier_id: r.supplier_id ?? '', category_id: r.category_id ?? '', rule_type: r.rule_type, value: r.value, min_margin_percent: r.min_margin_percent ?? '', max_selling_price: r.max_selling_price ?? '', priority: r.priority, is_active: r.is_active }
    : { scope: 'global', country: '', supplier_id: '', category_id: '', rule_type: 'margin_percent', value: '35', min_margin_percent: '15', max_selling_price: '', priority: 100, is_active: true })
  ruleOpen.value = true
}
async function saveRule() {
  const body: Record<string, unknown> = {
    rule_type: rf.rule_type, value: rf.value, min_margin_percent: rf.min_margin_percent || null, max_selling_price: rf.max_selling_price || null, priority: Number(rf.priority), is_active: rf.is_active,
  }
  if (!editing.value) {
    Object.assign(body, { scope: rf.scope, country: rf.country || null, supplier_id: rf.scope === 'supplier' ? rf.supplier_id : null, category_id: rf.scope === 'category' ? rf.category_id : null })
  }
  const ok = await run(() => editing.value ? send('PATCH', `/admin/pricing-rules/${editing.value.id}`, body) : send('POST', '/admin/pricing-rules', body), t('admin.pricing.saved'))
  if (ok !== undefined) {
    ruleOpen.value = false
    await load()
  }
}
async function saveRate() {
  const ok = await run(() => send('POST', '/admin/exchange-rates', { base: fx.base.toUpperCase(), target: fx.target, rate: fx.rate }), t('admin.pricing.saved'))
  if (ok !== undefined) {
    fx.rate = ''
    await load()
  }
}
async function simulate() {
  simResult.value = (await run(() => request<{ data: Record<string, unknown> }>('/admin/pricing/simulate', { method: 'POST', body: { ...sim, min_margin_percent: sim.min_margin_percent || null } })))?.data ?? null
}
const target = (r: Rule) => {
  if (r.scope === 'category') return tr(categories.value.find(c => c.id === r.category_id)?.name as never) || r.category_id
  if (r.scope === 'supplier') return suppliers.value.find(s => s.id === r.supplier_id)?.name ?? r.supplier_id
  if (r.scope === 'product') return r.product_id
  return ''
}
onMounted(async () => {
  await load()
  categories.value = (await request<{ data: typeof categories.value }>('/admin/categories').catch(() => ({ data: [] }))).data
  suppliers.value = (await request<{ data: typeof suppliers.value }>('/admin/suppliers').catch(() => ({ data: [] }))).data
})
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.pricing.title')" :subtitle="$t('admin.pricing.subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />
    <div class="mb-4 flex gap-2">
      <button v-for="k in (['rules', 'fx', 'simulate'] as const)" :key="k" class="chip" :class="tab === k ? 'chip-active' : ''" @click="tab = k">{{ $t(`admin.pricing.tabs.${k}`) }}</button>
    </div>

    <template v-if="tab === 'rules'">
      <div class="card overflow-x-auto">
        <div class="flex items-center justify-between px-4 pt-4">
          <p class="text-sm text-ink-500">{{ $t('admin.pricing.rules_hint') }}</p>
          <button class="btn-primary btn-sm" @click="editRule(null)"><AppIcon name="plus" :size="14" /> {{ $t('admin.pricing.add_rule') }}</button>
        </div>
        <table class="admin-table mt-3">
          <thead><tr><th>{{ $t('admin.pricing.scope') }}</th><th>{{ $t('admin.common.country') }}</th><th>{{ $t('admin.pricing.type') }}</th><th>{{ $t('admin.pricing.value') }}</th><th>{{ $t('admin.pricing.min_margin') }}</th><th>{{ $t('admin.pricing.priority') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in rules" :key="r.id">
              <td>{{ $t(`admin.pricing.scopes.${r.scope}`) }} <span class="block text-xs text-ink-500">{{ target(r) }}</span></td>
              <td>{{ r.country ?? $t('admin.common.all') }}</td>
              <td>{{ $t(`admin.pricing.types.${r.rule_type}`) }}</td>
              <td class="font-semibold" dir="ltr">{{ Number(r.value) }}{{ r.rule_type === 'fixed_markup' ? '' : '%' }}</td>
              <td dir="ltr">{{ r.min_margin_percent ? `${Number(r.min_margin_percent)}%` : '—' }}</td>
              <td>{{ r.priority }}</td>
              <td><span :class="r.is_active ? 'text-green-700' : 'text-ink-500'">{{ r.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</span></td>
              <td class="text-end"><button class="btn-outline btn-sm" @click="editRule(r)"><AppIcon name="edit" :size="14" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template v-if="tab === 'fx'">
      <form class="card mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="saveRate">
        <label class="w-24"><span class="label">{{ $t('admin.pricing.base') }}</span><input v-model="fx.base" class="input" maxlength="3" dir="ltr" required></label>
        <label class="w-28"><span class="label">{{ $t('admin.pricing.target') }}</span><select v-model="fx.target" class="input"><option>AED</option><option>SAR</option></select></label>
        <label class="w-36"><span class="label">{{ $t('admin.pricing.rate') }}</span><input v-model="fx.rate" class="input" inputmode="decimal" dir="ltr" required></label>
        <button class="btn-dark">{{ $t('admin.pricing.set_rate') }}</button>
        <p class="w-full text-xs text-ink-500">{{ $t('admin.pricing.fx_hint') }}</p>
      </form>
      <div class="card overflow-x-auto">
        <table class="admin-table">
          <thead><tr><th>{{ $t('admin.pricing.pair') }}</th><th>{{ $t('admin.pricing.rate') }}</th><th>{{ $t('admin.pricing.source') }}</th><th>{{ $t('admin.common.date') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in rates" :key="r.id">
              <td class="font-semibold" dir="ltr">{{ r.base }} → {{ r.target }}</td>
              <td dir="ltr">{{ r.rate }}</td>
              <td>{{ r.source }}</td>
              <td :class="r.stale ? 'font-semibold text-red-600' : 'text-ink-500'">{{ formatDateTime(r.effective_at, locale) }} <span v-if="r.stale">· {{ $t('admin.pricing.stale') }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template v-if="tab === 'simulate'">
      <div class="grid gap-6 lg:grid-cols-2">
        <form class="card grid grid-cols-2 gap-3 p-5" @submit.prevent="simulate">
          <label><span class="label">{{ $t('admin.pricing.sim.unit_cost') }}</span><input v-model="sim.unit_cost" class="input" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.sim.shipping_cost') }}</span><input v-model="sim.shipping_cost" class="input" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.sim.cost_currency') }}</span><input v-model="sim.cost_currency" class="input" maxlength="3" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.sim.sale_currency') }}</span><select v-model="sim.sale_currency" class="input" @change="sim.vat_percent = sim.sale_currency === 'SAR' ? '15' : '5'; sim.exchange_rate = sim.sale_currency === 'SAR' ? '3.75' : '3.6725'"><option>AED</option><option>SAR</option></select></label>
          <label><span class="label">{{ $t('admin.pricing.rate') }}</span><input v-model="sim.exchange_rate" class="input" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.sim.vat') }}</span><input v-model="sim.vat_percent" class="input" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.type') }}</span><select v-model="sim.rule_type" class="input"><option v-for="k in ['margin_percent', 'markup_percent', 'fixed_markup']" :key="k" :value="k">{{ $t(`admin.pricing.types.${k}`) }}</option></select></label>
          <label><span class="label">{{ $t('admin.pricing.value') }}</span><input v-model="sim.value" class="input" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.min_margin') }}</span><input v-model="sim.min_margin_percent" class="input" dir="ltr"></label>
          <label><span class="label">{{ $t('admin.pricing.sim.fee') }}</span><input v-model="sim.payment_fee_percent" class="input" dir="ltr"></label>
          <button class="btn-primary col-span-2">{{ $t('admin.pricing.sim.run') }}</button>
        </form>
        <div class="card p-5">
          <h2 class="mb-3 font-bold">{{ $t('admin.pricing.sim.result') }}</h2>
          <dl v-if="simResult" class="grid grid-cols-2 gap-y-2 text-sm">
            <template v-for="(v, k) in simResult" :key="k">
              <dt class="text-ink-500">{{ String(k).replace(/_/g, ' ') }}</dt><dd class="text-end font-medium" dir="ltr">{{ typeof v === 'object' ? JSON.stringify(v) : v }}</dd>
            </template>
          </dl>
          <p v-else class="text-sm text-ink-500">{{ $t('admin.pricing.sim.hint') }}</p>
        </div>
      </div>
    </template>

    <AdminModal :open="ruleOpen" :title="editing ? $t('admin.pricing.edit_rule') : $t('admin.pricing.add_rule')" @close="ruleOpen = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveRule">
        <template v-if="!editing">
          <label><span class="label">{{ $t('admin.pricing.scope') }}</span><select v-model="rf.scope" class="input"><option v-for="s in ['global', 'country', 'supplier', 'category']" :key="s" :value="s">{{ $t(`admin.pricing.scopes.${s}`) }}</option></select></label>
          <label><span class="label">{{ $t('admin.common.country') }}</span><select v-model="rf.country" class="input"><option value="">{{ $t('admin.common.all') }}</option><option value="AE">AE</option><option value="SA">SA</option></select></label>
          <label v-if="rf.scope === 'supplier'" class="sm:col-span-2"><span class="label">{{ $t('admin.supplier') }}</span><select v-model="rf.supplier_id" class="input" required><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
          <label v-if="rf.scope === 'category'" class="sm:col-span-2"><span class="label">{{ $t('admin.categories.title') }}</span><select v-model="rf.category_id" class="input" required><option v-for="c in categories" :key="c.id" :value="c.id">{{ tr(c.name as never) }}</option></select></label>
        </template>
        <label><span class="label">{{ $t('admin.pricing.type') }}</span><select v-model="rf.rule_type" class="input"><option v-for="k in ['margin_percent', 'markup_percent', 'fixed_markup']" :key="k" :value="k">{{ $t(`admin.pricing.types.${k}`) }}</option></select></label>
        <label><span class="label">{{ $t('admin.pricing.value') }}</span><input v-model="rf.value" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('admin.pricing.min_margin') }}</span><input v-model="rf.min_margin_percent" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.pricing.max_price') }}</span><input v-model="rf.max_selling_price" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.pricing.priority') }}</span><input v-model.number="rf.priority" class="input" dir="ltr"></label>
        <label class="mt-6 flex items-center gap-2 text-sm"><input v-model="rf.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
        <p class="text-xs text-ink-500 sm:col-span-2">{{ $t('admin.pricing.reprice_hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="ruleOpen = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
