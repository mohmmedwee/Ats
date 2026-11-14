<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface Task {
  id: string
  status: string
  attempts: number
  age_minutes: number
  last_error: Record<string, unknown> | null
  supplier_order: { reference: string, status: string, exception_reason: string | null, expected_cost: string, actual_cost: string | null, currency: string, external_order_id: string | null }
}
const { request, csrf, idempotencyKey } = useApi()
const { t } = useI18n()
const auth = useAuthStore()
const tasks = ref<Task[]>([])
const filter = ref('exception,manual,reconciling,waiting_retry')
const error = ref('')

async function load() {
  tasks.value = (await request<{ data: Task[] }>('/admin/procurement', { query: { status: filter.value || undefined } })).data
}

async function act(task: Task, action: 'retry' | 'approve' | 'cancel') {
  error.value = ''
  let body: Record<string, unknown> = {}
  if (action === 'approve') {
    const external = prompt(t('admin.external_order_id'))
    const cost = external ? prompt(t('admin.actual_cost'), task.supplier_order.expected_cost) : null
    if (!external || !cost) return
    body = { external_order_id: external, actual_cost: cost }
  }
  if (action === 'cancel') body = { refund: true }
  try {
    await csrf()
    await request(`/admin/procurement/${task.id}/${action}`, { method: 'POST', body, headers: { 'Idempotency-Key': idempotencyKey(`proc-${action}`) } })
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

onMounted(load)
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.procurement') }}</h1>
    <select v-model="filter" class="mb-4 rounded border px-2 py-1" @change="load">
      <option value="exception,manual,reconciling,waiting_retry">exception, manual, reconciling, waiting_retry</option>
      <option value="">all</option>
    </select>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <table class="w-full text-sm">
      <thead><tr class="border-b text-start"><th class="p-2 text-start">SO</th><th class="text-start">{{ $t('admin.status') }}</th><th class="text-start">{{ $t('admin.reason') }}</th><th class="text-start">cost</th><th /></tr></thead>
      <tbody>
        <tr v-for="task in tasks" :key="task.id" class="border-b" data-test="procurement-row">
          <td class="p-2" dir="ltr">{{ task.supplier_order.reference }}</td>
          <td><StatusBadge :status="task.status" /> <StatusBadge :status="task.supplier_order.status" /></td>
          <td>{{ task.supplier_order.exception_reason ?? task.last_error?.reason ?? '' }}</td>
          <td dir="ltr">{{ task.supplier_order.expected_cost }} → {{ task.supplier_order.actual_cost ?? '…' }} {{ task.supplier_order.currency }}</td>
          <td v-if="auth.can('procurement.act')" class="space-x-2 whitespace-nowrap">
            <button v-if="task.status === 'exception'" class="underline" @click="act(task, 'retry')">{{ $t('admin.actions.retry') }}</button>
            <button v-if="['exception', 'manual'].includes(task.status)" class="underline" @click="act(task, 'approve')">{{ $t('admin.actions.approve') }}</button>
            <button v-if="['exception', 'manual'].includes(task.status)" class="text-red-700 underline" @click="act(task, 'cancel')">{{ $t('admin.actions.cancel_refund') }}</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!tasks.length" class="mt-4 text-gray-500">{{ $t('common.none') }}</p>
  </section>
</template>
