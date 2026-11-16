<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Card { id: string, code: string, currency: string, initial_amount: string, balance: string, expires_at: string | null, is_active: boolean, note: string | null, created_at: string }
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const cards = ref<Card[]>([])
const open = ref(false)
const issued = ref<string[]>([])
const f = reactive({ amount: 100, currency: 'AED', quantity: 1, expires_at: '', note: '' })
async function load() {
  await run(async () => {
    cards.value = (await request<{ data: Card[] }>('/admin/gift-cards')).data
  })
}
async function issue() {
  const res = await run(() => send<{ data: { codes: string[] } }>('POST', '/admin/gift-cards', { ...f, expires_at: f.expires_at || null, note: f.note || null }), t('admin.common.saved'))
  if (res) {
    issued.value = res.data.codes
    await load()
  }
}
async function toggle(c: Card) {
  await run(() => send('PATCH', `/admin/gift-cards/${c.id}`, { is_active: !c.is_active }), t('admin.common.saved'))
  await load()
}
const money = (amount: string, currency: string) => formatMoney({ amount, currency }, locale.value)
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('gift.admin_title')" :subtitle="$t('gift.admin_subtitle')">
      <button class="btn-primary btn-sm" @click="issued = []; open = true"><AppIcon name="plus" :size="14" /> {{ $t('gift.issue') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.code') }}</th><th>{{ $t('gift.balance') }}</th><th>{{ $t('gift.note') }}</th><th>{{ $t('admin.common.until') }}</th><th>{{ $t('admin.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="c in cards" :key="c.id">
            <td class="font-mono text-xs" dir="ltr">{{ c.code }}</td>
            <td><b>{{ money(c.balance, c.currency) }}</b><span class="block text-xs text-ink-500">/ {{ money(c.initial_amount, c.currency) }}</span></td>
            <td class="text-xs">{{ c.note }}</td>
            <td class="text-xs" dir="ltr">{{ c.expires_at ? c.expires_at.slice(0, 10) : '∞' }}</td>
            <td><button class="chip text-xs" :class="c.is_active ? 'chip-active' : ''" @click="toggle(c)">{{ c.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!cards.length" class="p-6 text-center text-ink-500">{{ $t('gift.empty') }}</p>
    </div>
    <AdminModal :open="open" :title="$t('gift.issue')" @close="open = false">
      <div v-if="issued.length" class="space-y-2">
        <p class="text-sm">{{ $t('gift.issued') }}</p>
        <pre class="max-h-60 overflow-auto rounded-md bg-ink-100 p-3 text-xs" dir="ltr">{{ issued.join('\n') }}</pre>
      </div>
      <form v-else class="grid gap-3 sm:grid-cols-2" @submit.prevent="issue">
        <label><span class="label">{{ $t('gift.amount') }}</span><input v-model.number="f.amount" type="number" min="1" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('gift.currency') }}</span><select v-model="f.currency" class="input"><option v-for="c in ['AED', 'SAR', 'KWD', 'QAR', 'BHD', 'OMR']" :key="c">{{ c }}</option></select></label>
        <label><span class="label">{{ $t('gift.quantity') }}</span><input v-model.number="f.quantity" type="number" min="1" max="100" class="input" dir="ltr"></label>
        <label><span class="label">{{ $t('admin.common.until') }}</span><input v-model="f.expires_at" type="date" class="input"></label>
        <label class="sm:col-span-2"><span class="label">{{ $t('gift.note') }}</span><input v-model="f.note" class="input" maxlength="255"></label>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('gift.issue') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
