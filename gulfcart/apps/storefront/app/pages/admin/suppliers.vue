<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Authorization { id: string, agreement_reference: string, allows_resale: boolean, allows_api_purchasing: boolean, allows_direct_shipping: boolean, allows_image_use: boolean, countries: string[], valid_from: string, valid_until: string | null }
interface Supplier { id: string, name: string, code: string, connector_type: string, base_currency: string, has_credentials: boolean, commercial_authorization_status: string, direct_shipping_enabled: boolean, is_active: boolean, metadata: Record<string, unknown>, authorizations: Authorization[] }
const { request, send, run, error, notice } = useAdmin()
const { t } = useI18n()
const suppliers = ref<Supplier[]>([])
const createOpen = ref(false)
const create = reactive({ name: '', code: '', connector_type: 'manual', base_currency: 'USD', shipping_cost_per_unit: '0', delivery_min_days: 7, delivery_max_days: 14 })
const authFor = ref<Supplier | null>(null)
const today = new Date().toISOString().slice(0, 10)
const auth = reactive({ agreement_reference: '', allows_resale: true, allows_api_purchasing: false, allows_direct_shipping: true, allows_image_use: true, countries: ['AE', 'SA'], valid_from: today, valid_until: '' })

async function load() {
  await run(async () => {
    suppliers.value = (await request<{ data: Supplier[] }>('/admin/suppliers')).data
  })
}
async function status(s: Supplier, action: 'activate' | 'deactivate') {
  await run(() => send('POST', `/admin/suppliers/${s.id}/status`, { action }), t('admin.common.done'))
  await load()
}
async function submitCreate() {
  const ok = await run(() => send('POST', '/admin/suppliers', {
    name: create.name, code: create.code, connector_type: create.connector_type, base_currency: create.base_currency.toUpperCase(),
    direct_shipping_enabled: true,
    credentials_ref: create.connector_type === 'cj' ? 'config:gulfcart.cj.api_key' : undefined,
    metadata: { shipping_cost_per_unit: Number(create.shipping_cost_per_unit), delivery_min_days: create.delivery_min_days, delivery_max_days: create.delivery_max_days },
  }), t('admin.common.saved'))
  if (ok !== undefined) {
    createOpen.value = false
    await load()
  }
}
function openAuth(s: Supplier) {
  authFor.value = s
  Object.assign(auth, { agreement_reference: '', allows_api_purchasing: s.connector_type !== 'manual', valid_from: today, valid_until: '' })
}
async function submitAuth() {
  if (!authFor.value) return
  const ok = await run(() => send('POST', `/admin/suppliers/${authFor.value!.id}/authorizations`, { ...auth, valid_until: auth.valid_until || null }), t('admin.common.saved'))
  if (ok !== undefined) {
    authFor.value = null
    await load()
  }
}
const flags = ['allows_resale', 'allows_api_purchasing', 'allows_direct_shipping', 'allows_image_use'] as const
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.suppliers')" :subtitle="$t('admin.suppliers_page.subtitle')">
      <button class="btn-primary btn-sm" @click="createOpen = true"><AppIcon name="plus" :size="14" /> {{ $t('admin.suppliers_page.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="grid gap-4 md:grid-cols-2">
      <div v-for="s in suppliers" :key="s.id" class="card p-5" data-test="supplier-card">
        <div class="mb-2 flex items-start justify-between gap-2">
          <div>
            <h2 class="text-lg font-bold">{{ s.name }}</h2>
            <p class="text-xs text-ink-500" dir="ltr">{{ s.code }} · {{ s.connector_type }} · {{ s.base_currency }}</p>
          </div>
          <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="s.is_active ? 'bg-green-100 text-green-800' : 'bg-ink-100 text-ink-700'">{{ s.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</span>
        </div>
        <p class="mb-3 text-sm">{{ $t('admin.suppliers_page.authorization') }}: <StatusBadge :status="s.commercial_authorization_status" /></p>
        <div v-for="a in s.authorizations" :key="a.id" class="mb-2 rounded-xl bg-ink-100/60 p-3 text-xs">
          <p class="font-semibold">{{ a.agreement_reference }} · {{ a.countries.join(' / ') }}</p>
          <p class="text-ink-500">{{ a.valid_from }} → {{ a.valid_until ?? '∞' }}</p>
          <p class="mt-1 flex flex-wrap gap-2"><span v-for="f in flags" :key="f" :class="a[f] ? 'text-green-700' : 'text-ink-300 line-through'">{{ $t(`admin.suppliers_page.flags.${f}`) }}</span></p>
        </div>
        <p v-if="s.connector_type === 'cj' && !s.has_credentials" class="mb-2 text-xs text-gold-600">{{ $t('admin.suppliers_page.cj_key') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button class="btn-outline btn-sm" @click="openAuth(s)">{{ $t('admin.suppliers_page.add_authorization') }}</button>
          <button v-if="!s.is_active" class="btn-primary btn-sm" :disabled="s.commercial_authorization_status !== 'approved'" @click="status(s, 'activate')">{{ $t('admin.activate') }}</button>
          <button v-else class="btn-outline btn-sm" @click="status(s, 'deactivate')">{{ $t('admin.deactivate') }}</button>
        </div>
      </div>
    </div>
    <p v-if="!suppliers.length" class="card p-6 text-center text-ink-500">{{ $t('common.none') }}</p>

    <AdminModal :open="createOpen" :title="$t('admin.suppliers_page.add')" @close="createOpen = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
        <label class="block sm:col-span-2"><span class="label">{{ $t('admin.common.name') }}</span><input v-model="create.name" class="input" required></label>
        <label class="block"><span class="label">{{ $t('admin.common.code') }}</span><input v-model="create.code" class="input" required pattern="[a-z0-9_-]+" dir="ltr"></label>
        <label class="block"><span class="label">{{ $t('admin.suppliers_page.connector') }}</span>
          <select v-model="create.connector_type" class="input"><option value="manual">{{ $t('admin.suppliers_page.connectors.manual') }}</option><option value="cj">CJdropshipping</option><option value="mock">{{ $t('admin.suppliers_page.connectors.mock') }}</option></select>
        </label>
        <label class="block"><span class="label">{{ $t('admin.suppliers_page.currency') }}</span><input v-model="create.base_currency" class="input" maxlength="3" required dir="ltr"></label>
        <label class="block"><span class="label">{{ $t('admin.suppliers_page.shipping_per_unit') }}</span><input v-model="create.shipping_cost_per_unit" class="input" inputmode="decimal" dir="ltr"></label>
        <label class="block"><span class="label">{{ $t('admin.suppliers_page.delivery_days') }}</span><span class="flex gap-2"><input v-model.number="create.delivery_min_days" class="input" dir="ltr"><input v-model.number="create.delivery_max_days" class="input" dir="ltr"></span></label>
        <p class="text-xs text-ink-500 sm:col-span-2">{{ $t('admin.suppliers_page.create_hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="createOpen = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>

    <AdminModal :open="!!authFor" :title="`${$t('admin.suppliers_page.add_authorization')} · ${authFor?.name ?? ''}`" @close="authFor = null">
      <form class="space-y-3" @submit.prevent="submitAuth">
        <label class="block"><span class="label">{{ $t('admin.suppliers_page.agreement') }}</span><input v-model="auth.agreement_reference" class="input" required :placeholder="$t('admin.suppliers_page.agreement_hint')"></label>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <label v-for="f in flags" :key="f" class="flex items-center gap-2"><input v-model="auth[f]" type="checkbox"> {{ $t(`admin.suppliers_page.flags.${f}`) }}</label>
        </div>
        <div class="flex gap-4 text-sm">
          <label v-for="c in ['AE', 'SA']" :key="c" class="flex items-center gap-2"><input v-model="auth.countries" type="checkbox" :value="c"> {{ $t(`country.${c}`) }}</label>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <label class="block"><span class="label">{{ $t('admin.common.from') }}</span><input v-model="auth.valid_from" type="date" class="input" required></label>
          <label class="block"><span class="label">{{ $t('admin.common.until') }}</span><input v-model="auth.valid_until" type="date" class="input"></label>
        </div>
        <div class="flex justify-end gap-2"><button type="button" class="btn-outline" @click="authFor = null">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
