<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Offer { id: string, name: { en: string, ar: string | null }, buy_qty: number, get_qty: number, get_percent: string, scope: 'all' | 'category' | 'product', category_id: string | null, product_id: string | null, is_active: boolean, starts_at: string | null, ends_at: string | null }
interface Cat { id: string, name: { en?: string, ar?: string }, parent_id: string | null }
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const offers = ref<Offer[]>([])
const cats = ref<Cat[]>([])
const open = ref(false)
const f = reactive({ name_en: '', name_ar: '', buy_qty: 2, get_qty: 1, get_percent: 100, scope: 'all' as Offer['scope'], category_id: '', ends_at: '' })
async function load() {
  await run(async () => {
    offers.value = (await request<{ data: Offer[] }>('/admin/offers')).data
    cats.value = (await request<{ data: Cat[] }>('/admin/categories').catch(() => ({ data: [] }))).data
  })
}
async function save() {
  const ok = await run(() => send('POST', '/admin/offers', {
    name: { en: f.name_en, ar: f.name_ar || null }, buy_qty: f.buy_qty, get_qty: f.get_qty, get_percent: f.get_percent, scope: f.scope,
    category_id: f.scope === 'category' ? f.category_id : null, ends_at: f.ends_at ? new Date(f.ends_at).toISOString() : null,
  }), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
async function toggle(o: Offer) {
  await run(() => send('PATCH', `/admin/offers/${o.id}`, { is_active: !o.is_active }), t('admin.common.saved'))
  await load()
}
const catName = (id: string | null) => { const c = cats.value.find(x => x.id === id); return c ? ((locale.value === 'ar' && c.name.ar) || c.name.en) : '' }
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('offers.admin_title')" :subtitle="$t('offers.admin_subtitle')">
      <button class="btn-primary btn-sm" @click="open = true"><AppIcon name="plus" :size="14" /> {{ $t('offers.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('flash.name') }}</th><th>{{ $t('offers.rule') }}</th><th>{{ $t('flash.applies_to') }}</th><th>{{ $t('admin.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="o in offers" :key="o.id">
            <td><b>{{ locale === 'ar' && o.name.ar ? o.name.ar : o.name.en }}</b></td>
            <td class="text-sm">{{ Number(o.get_percent) >= 100 ? $t('offers.free', { buy: o.buy_qty, get: o.get_qty }) : $t('offers.percent', { buy: o.buy_qty, get: o.get_qty, n: Number(o.get_percent) }) }}</td>
            <td class="text-sm">{{ o.scope === 'category' ? catName(o.category_id) : $t(`flash.scope.${o.scope}`) }}</td>
            <td><button class="chip text-xs" :class="o.is_active ? 'chip-active' : ''" @click="toggle(o)">{{ o.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!offers.length" class="p-6 text-center text-ink-500">{{ $t('offers.empty') }}</p>
    </div>
    <AdminModal :open="open" :title="$t('offers.add')" @close="open = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
        <label><span class="label">{{ $t('flash.name') }} (EN)</span><input v-model="f.name_en" class="input" required dir="ltr" placeholder="Buy 2 get 1 free"></label>
        <label><span class="label">{{ $t('flash.name') }} (AR)</span><input v-model="f.name_ar" class="input" dir="rtl" placeholder="اشترِ 2 والثالثة مجاناً"></label>
        <label><span class="label">{{ $t('offers.buy') }}</span><input v-model.number="f.buy_qty" type="number" min="1" max="20" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('offers.get') }}</span><input v-model.number="f.get_qty" type="number" min="1" max="20" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('offers.get_percent') }}</span><input v-model.number="f.get_percent" type="number" min="1" max="100" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('flash.applies_to') }}</span><select v-model="f.scope" class="input"><option value="all">{{ $t('flash.scope.all') }}</option><option value="category">{{ $t('flash.scope.category') }}</option></select></label>
        <label v-if="f.scope === 'category'" class="sm:col-span-2"><span class="label">{{ $t('flash.scope.category') }}</span><select v-model="f.category_id" class="input" required><option v-for="c in cats" :key="c.id" :value="c.id">{{ c.parent_id ? '— ' : '' }}{{ catName(c.id) }}</option></select></label>
        <label><span class="label">{{ $t('admin.common.until') }}</span><input v-model="f.ends_at" type="datetime-local" class="input"></label>
        <p class="rounded-md bg-ink-100 p-3 text-xs text-ink-700 sm:col-span-2">{{ $t('offers.hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
