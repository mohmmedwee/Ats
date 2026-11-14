<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface ReturnRow { id: string, rma_number: string, order_id: string, status: string, reason_code: string, customer_note: string | null, items: { quantity: number }[], refund: { amount: string, currency: string } | null, created_at?: string }
type Action = 'approve' | 'reject' | 'receive' | 'refund'
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const localePath = useLocalePath()
const auth = useAuthStore()
const rows = ref<ReturnRow[]>([])
const status = ref('')
async function load() {
  await run(async () => {
    rows.value = (await request<{ data: ReturnRow[] }>('/admin/returns', { query: { status: status.value || undefined, per_page: 100 } })).data
  })
}
async function act(r: ReturnRow, action: Action) {
  if (action === 'reject' && !confirm(t('admin.common.confirm'))) return
  await run(() => send('POST', `/admin/returns/${r.id}/${action}`, {}, `ret-${action}`), t('admin.common.done'))
  await load()
}
const next: Record<string, Action[]> = { requested: ['approve', 'reject'], approved: ['receive'], received: ['refund', 'reject'] }
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.returns')" :subtitle="$t('admin.returns_page.subtitle')">
      <select v-model="status" class="input w-auto" @change="load">
        <option value="">{{ $t('admin.common.all') }}</option>
        <option v-for="s in ['requested', 'approved', 'received', 'refunded', 'rejected']" :key="s" :value="s">{{ $t(`admin.returns_page.status.${s}`) }}</option>
      </select>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>RMA</th><th>{{ $t('admin.status') }}</th><th>{{ $t('admin.reason') }}</th><th>{{ $t('admin.orders_page.items') }}</th><th class="text-end">{{ $t('admin.refund') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td><NuxtLink :to="localePath(`/admin/orders/${r.order_id}`)" class="link" dir="ltr">{{ r.rma_number }}</NuxtLink></td>
            <td><StatusBadge :status="r.status" /></td>
            <td>{{ $t(`orders.reasons.${r.reason_code}`) }}<span v-if="r.customer_note" class="block text-xs text-ink-500">“{{ r.customer_note }}”</span></td>
            <td>{{ r.items.reduce((n, i) => n + i.quantity, 0) }}</td>
            <td class="text-end">{{ r.refund ? formatMoney(r.refund, locale) : '' }}</td>
            <td class="whitespace-nowrap text-end">
              <template v-if="auth.can('refunds.request')">
                <button v-for="a in next[r.status] ?? []" :key="a" class="btn-sm ms-2" :class="a === 'reject' ? 'btn-outline' : 'btn-primary'" @click="act(r, a)">
                  {{ a === 'approve' ? $t('admin.actions.approve_refund') : a === 'reject' ? $t('admin.actions.reject_refund') : $t(`admin.${a}`) }}
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!rows.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
  </section>
</template>
