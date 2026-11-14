<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Coupon { id: string, code: string, description: string | null, type: 'percent' | 'fixed' | 'free_shipping', value: string, max_discount: string | null, min_subtotal: string | null, countries: string[], starts_at: string | null, ends_at: string | null, usage_limit: number | null, per_customer_limit: number, is_active: boolean, used: number, discount_given: string }
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const coupons = ref<Coupon[]>([])
const open = ref(false)
const editing = ref<Coupon | null>(null)
const f = reactive({ code: '', description: '', type: 'percent' as Coupon['type'], value: '10', max_discount: '', min_subtotal: '', countries: [] as string[], starts_at: '', ends_at: '', usage_limit: '', per_customer_limit: 1, is_active: true })

async function load() {
  await run(async () => {
    coupons.value = (await request<{ data: Coupon[] }>('/admin/coupons')).data
  })
}
const day = (iso: string | null) => (iso ? iso.slice(0, 10) : '')
function edit(c: Coupon | null) {
  editing.value = c
  Object.assign(f, c
    ? { code: c.code, description: c.description ?? '', type: c.type, value: String(Number(c.value)), max_discount: c.max_discount ?? '', min_subtotal: c.min_subtotal ?? '', countries: [...c.countries], starts_at: day(c.starts_at), ends_at: day(c.ends_at), usage_limit: c.usage_limit ? String(c.usage_limit) : '', per_customer_limit: c.per_customer_limit, is_active: c.is_active }
    : { code: '', description: '', type: 'percent', value: '10', max_discount: '', min_subtotal: '', countries: [], starts_at: '', ends_at: '', usage_limit: '', per_customer_limit: 1, is_active: true })
  open.value = true
}
async function save() {
  const n = (v: string) => (v === '' ? null : Number(v))
  const body = {
    code: f.code, description: f.description || null, type: f.type, value: f.type === 'free_shipping' ? 0 : Number(f.value),
    max_discount: n(f.max_discount), min_subtotal: n(f.min_subtotal), countries: f.countries,
    starts_at: f.starts_at || null, ends_at: f.ends_at ? `${f.ends_at}T23:59:59` : null, usage_limit: n(f.usage_limit),
    per_customer_limit: Number(f.per_customer_limit), is_active: f.is_active,
  }
  const ok = await run(() => editing.value ? send('PATCH', `/admin/coupons/${editing.value.id}`, body) : send('POST', '/admin/coupons', body), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
async function toggle(c: Coupon) {
  await run(() => send('PATCH', `/admin/coupons/${c.id}`, { is_active: !c.is_active }), t('admin.common.saved'))
  await load()
}
const label = (c: Coupon) => c.type === 'percent' ? `${Number(c.value)}%` : c.type === 'fixed' ? `${Number(c.value)}` : t('admin.coupons.free_shipping')
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.coupons.title')" :subtitle="$t('admin.coupons.subtitle')">
      <button class="btn-primary btn-sm" @click="edit(null)"><AppIcon name="plus" :size="14" /> {{ $t('admin.coupons.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.code') }}</th><th>{{ $t('admin.coupons.offer') }}</th><th>{{ $t('admin.coupons.conditions') }}</th><th>{{ $t('admin.coupons.validity') }}</th><th>{{ $t('admin.coupons.used') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="c in coupons" :key="c.id">
            <td><b class="font-mono" dir="ltr">{{ c.code }}</b><span v-if="c.description" class="block text-xs text-ink-500">{{ c.description }}</span></td>
            <td class="font-semibold">{{ label(c) }}<span v-if="c.max_discount" class="block text-xs font-normal text-ink-500">{{ $t('admin.coupons.max', { n: Number(c.max_discount) }) }}</span></td>
            <td class="text-xs">
              <span v-if="c.min_subtotal" class="block">{{ $t('admin.coupons.min', { n: Number(c.min_subtotal) }) }}</span>
              <span class="block">{{ c.countries.length ? c.countries.join(' / ') : $t('admin.common.all') }}</span>
              <span class="block text-ink-500">{{ $t('admin.coupons.per_customer', { n: c.per_customer_limit || '∞' }) }}</span>
            </td>
            <td class="text-xs" dir="ltr">{{ day(c.starts_at) || '…' }} → {{ day(c.ends_at) || '∞' }}</td>
            <td>{{ c.used }}<span v-if="c.usage_limit"> / {{ c.usage_limit }}</span><span class="block text-xs text-ink-500">−{{ Number(c.discount_given).toLocaleString(locale) }}</span></td>
            <td><button class="chip text-xs" :class="c.is_active ? 'chip-active' : ''" @click="toggle(c)">{{ c.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</button></td>
            <td class="text-end"><button class="btn-outline btn-sm" @click="edit(c)"><AppIcon name="edit" :size="14" /></button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!coupons.length" class="p-6 text-center text-ink-500">{{ $t('admin.coupons.empty') }}</p>
    </div>

    <AdminModal :open="open" :title="editing ? editing.code : $t('admin.coupons.add')" wide @close="open = false">
      <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="save">
        <label><span class="label">{{ $t('admin.common.code') }}</span><input v-model="f.code" class="input font-mono uppercase" required pattern="[A-Za-z0-9_\-]{3,40}" dir="ltr" placeholder="WELCOME10"></label>
        <label class="sm:col-span-2"><span class="label">{{ $t('admin.coupons.description') }}</span><input v-model="f.description" class="input"></label>
        <label><span class="label">{{ $t('admin.coupons.type') }}</span>
          <select v-model="f.type" class="input"><option value="percent">{{ $t('admin.coupons.types.percent') }}</option><option value="fixed">{{ $t('admin.coupons.types.fixed') }}</option><option value="free_shipping">{{ $t('admin.coupons.free_shipping') }}</option></select>
        </label>
        <label v-if="f.type !== 'free_shipping'"><span class="label">{{ f.type === 'percent' ? '%' : $t('admin.coupons.amount') }}</span><input v-model="f.value" class="input" dir="ltr" required></label>
        <label v-if="f.type === 'percent'"><span class="label">{{ $t('admin.coupons.max_label') }}</span><input v-model="f.max_discount" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.coupons.min_label') }}</span><input v-model="f.min_subtotal" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.coupons.usage_limit') }}</span><input v-model="f.usage_limit" class="input" dir="ltr" :placeholder="'∞'"></label>
        <label><span class="label">{{ $t('admin.coupons.per_customer_label') }}</span><input v-model.number="f.per_customer_limit" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.common.from') }}</span><input v-model="f.starts_at" type="date" class="input"></label>
        <label><span class="label">{{ $t('admin.common.until') }}</span><input v-model="f.ends_at" type="date" class="input"></label>
        <div class="flex items-end gap-4 text-sm">
          <label v-for="c in ['AE', 'SA']" :key="c" class="flex items-center gap-1"><input v-model="f.countries" type="checkbox" :value="c"> {{ c }}</label>
          <label class="flex items-center gap-1"><input v-model="f.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
        </div>
        <p class="text-xs text-ink-500 sm:col-span-3">{{ $t('admin.coupons.hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-3"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
