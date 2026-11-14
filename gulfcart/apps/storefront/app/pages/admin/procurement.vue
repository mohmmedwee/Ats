<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Task {
  id: string
  status: string
  attempts: number
  age_minutes: number
  next_attempt_at: string | null
  last_error: Record<string, unknown> | null
  supplier_order: { order_id: string, reference: string, status: string, procurement_mode: string, exception_reason: string | null, exception_details: Record<string, unknown> | null, expected_cost: string, actual_cost: string | null, currency: string, external_order_id: string | null }
  attempt_log?: { attempt_number: number, action: string, outcome: string, error_class: string | null, duration_ms: number | null, at: string }[]
}
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const localePath = useLocalePath()
const auth = useAuthStore()
const tasks = ref<Task[]>([])
const counts = ref<Record<string, number>>({})
const filter = ref('exception,manual,reconciling,waiting_retry')
const detail = ref<Task | null>(null)
const approve = reactive({ open: false, task: null as Task | null, external_order_id: '', actual_cost: '' })
const tabs = [
  { value: 'exception,manual,reconciling,waiting_retry', label: 'admin.procurement_page.needs_attention' },
  { value: 'exception', label: 'admin.procurement_page.status.exception' },
  { value: 'manual', label: 'admin.procurement_page.status.manual' },
  { value: 'queued,in_progress', label: 'admin.procurement_page.in_progress' },
  { value: 'succeeded', label: 'admin.procurement_page.status.succeeded' },
  { value: '', label: 'admin.common.all' },
]

async function load() {
  await run(async () => {
    const res = await request<{ data: Task[], meta: { counts: Record<string, number> } }>('/admin/procurement', { query: { status: filter.value || undefined, per_page: 100 } })
    tasks.value = res.data
    counts.value = res.meta.counts ?? {}
  })
}
async function open(task: Task) {
  detail.value = (await run(() => request<{ data: Task }>(`/admin/procurement/${task.id}`)))?.data ?? null
}
async function act(task: Task, action: 'retry' | 'cancel', body: Record<string, unknown> = {}) {
  if (action === 'cancel' && !confirm(t('admin.procurement_page.cancel_confirm'))) return
  await run(() => send('POST', `/admin/procurement/${task.id}/${action}`, action === 'cancel' ? { refund: true } : body, `proc-${action}`), t('admin.common.done'))
  detail.value = null
  await load()
}
function startApprove(task: Task) {
  Object.assign(approve, { open: true, task, external_order_id: task.supplier_order.external_order_id ?? '', actual_cost: task.supplier_order.expected_cost })
}
async function submitApprove() {
  if (!approve.task) return
  const ok = await run(() => send('POST', `/admin/procurement/${approve.task!.id}/approve`, { external_order_id: approve.external_order_id, actual_cost: approve.actual_cost }, 'proc-approve'), t('admin.common.done'))
  if (ok !== undefined) {
    approve.open = false
    detail.value = null
    await load()
  }
}
const attention = computed(() => ['exception', 'manual', 'reconciling', 'waiting_retry'].reduce((n, s) => n + (counts.value[s] ?? 0), 0))
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.procurement')" :subtitle="$t('admin.procurement_page.subtitle')">
      <button class="btn-outline btn-sm" @click="load"><AppIcon name="refresh" :size="14" /> {{ $t('admin.common.refresh') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="no-scrollbar mb-4 flex gap-2 overflow-x-auto">
      <button v-for="tab in tabs" :key="tab.value" class="chip whitespace-nowrap" :class="filter === tab.value ? 'chip-active' : ''" @click="filter = tab.value; load()">
        {{ $t(tab.label) }}<span v-if="tab.value === tabs[0]!.value && attention" class="ms-1 rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ attention }}</span>
      </button>
    </div>
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.procurement_page.reference') }}</th><th>{{ $t('admin.status') }}</th><th>{{ $t('admin.reason') }}</th><th>{{ $t('admin.procurement_page.cost') }}</th><th>{{ $t('admin.procurement_page.age') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="task in tasks" :key="task.id" data-test="procurement-row">
            <td><button class="link" dir="ltr" @click="open(task)">{{ task.supplier_order.reference }}</button></td>
            <td class="whitespace-nowrap"><StatusBadge :status="task.status" /> <StatusBadge :status="task.supplier_order.status" /></td>
            <td class="max-w-xs text-xs">{{ task.supplier_order.exception_reason ?? task.last_error?.reason ?? '' }}<span v-if="task.last_error?.message" class="block truncate text-ink-500" :title="String(task.last_error.message)">{{ task.last_error.message }}</span></td>
            <td class="whitespace-nowrap" dir="ltr">{{ task.supplier_order.expected_cost }} → {{ task.supplier_order.actual_cost ?? '…' }} {{ task.supplier_order.currency }}</td>
            <td class="whitespace-nowrap text-ink-500">{{ task.age_minutes < 120 ? $t('admin.procurement_page.minutes', { n: task.age_minutes }) : $t('admin.procurement_page.hours', { n: Math.round(task.age_minutes / 60) }) }}</td>
            <td v-if="auth.can('procurement.act')" class="whitespace-nowrap text-end">
              <button v-if="task.status === 'exception'" class="btn-outline btn-sm" @click="act(task, 'retry')">{{ $t('admin.actions.retry') }}</button>
              <button v-if="['exception', 'manual'].includes(task.status)" class="btn-primary btn-sm ms-2" @click="startApprove(task)">{{ $t('admin.actions.approve') }}</button>
              <button v-if="['exception', 'manual'].includes(task.status)" class="btn-sm ms-2 text-red-700 hover:underline" @click="act(task, 'cancel')">{{ $t('admin.actions.cancel_refund') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!tasks.length" class="p-6 text-center text-ink-500">{{ $t('admin.procurement_page.all_clear') }}</p>
    </div>

    <AdminModal :open="!!detail" :title="detail?.supplier_order.reference ?? ''" wide @close="detail = null">
      <div v-if="detail" class="space-y-4 text-sm">
        <div class="flex flex-wrap gap-2"><StatusBadge :status="detail.status" /><StatusBadge :status="detail.supplier_order.status" /><span class="text-ink-500">{{ detail.supplier_order.procurement_mode }} · {{ $t('admin.procurement_page.attempts', { n: detail.attempts }) }}</span></div>
        <p><NuxtLink :to="localePath(`/admin/orders/${detail.supplier_order.order_id}`)" class="link">{{ $t('admin.procurement_page.view_order') }} →</NuxtLink></p>
        <pre v-if="detail.supplier_order.exception_details" class="overflow-x-auto rounded-xl bg-ink-100 p-3 text-xs" dir="ltr">{{ JSON.stringify(detail.supplier_order.exception_details, null, 2) }}</pre>
        <h3 class="font-semibold">{{ $t('admin.procurement_page.log') }}</h3>
        <table class="admin-table">
          <tbody>
            <tr v-for="a in detail.attempt_log" :key="`${a.attempt_number}-${a.action}-${a.at}`">
              <td class="whitespace-nowrap text-ink-500">{{ formatDateTime(a.at, locale) }}</td><td>#{{ a.attempt_number }} {{ a.action }}</td><td><StatusBadge :status="a.outcome" /></td><td class="text-xs text-ink-500">{{ a.error_class }}</td><td class="text-xs">{{ a.duration_ms }} ms</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AdminModal>

    <AdminModal :open="approve.open" :title="$t('admin.actions.approve')" @close="approve.open = false">
      <form class="space-y-3" @submit.prevent="submitApprove">
        <p class="text-sm text-ink-500">{{ $t('admin.procurement_page.approve_hint') }}</p>
        <label class="block"><span class="label">{{ $t('admin.external_order_id') }}</span><input v-model="approve.external_order_id" class="input" required dir="ltr"></label>
        <label class="block"><span class="label">{{ $t('admin.actual_cost') }} ({{ approve.task?.supplier_order.currency }})</span><input v-model="approve.actual_cost" class="input" inputmode="decimal" required dir="ltr"></label>
        <div class="flex justify-end gap-2"><button type="button" class="btn-outline" @click="approve.open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
