<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface User { id: string, name: string, email: string | null, phone: string | null, type: string, status: string, roles: string[], mfa_enabled: boolean }
const { request, send, run, error, notice } = useAdmin()
const { t } = useI18n()
const auth = useAuthStore()
const staffRoles = ['support', 'operations', 'catalog_manager', 'finance', 'admin', 'super_admin']
const users = ref<User[]>([])
const filters = reactive({ type: 'staff', q: '', page: 1 })
const pager = ref({ page: 1, last_page: 1, total: 0 })
const rolesFor = ref<User | null>(null)
const roles = ref<string[]>([])
const inviteOpen = ref(false)
const invite = reactive({ name: '', email: '', roles: ['support'] as string[] })

async function load() {
  await run(async () => {
    const res = await request<{ data: User[], meta: { pagination: typeof pager.value } }>('/admin/users', { query: { type: filters.type || undefined, q: filters.q || undefined, page: filters.page } })
    users.value = res.data
    pager.value = res.meta.pagination
  })
}
function editRoles(u: User) {
  rolesFor.value = u
  roles.value = [...u.roles]
}
async function saveRoles() {
  if (!rolesFor.value) return
  const ok = await run(() => send('PUT', `/admin/users/${rolesFor.value!.id}/roles`, { roles: rolesFor.value!.type === 'customer' ? ['customer', ...roles.value.filter(r => r === 'customer')] : roles.value }), t('admin.common.saved'))
  if (ok !== undefined) {
    rolesFor.value = null
    await load()
  }
}
async function toggleStatus(u: User) {
  if (!confirm(t('admin.common.confirm'))) return
  await run(() => send('PATCH', `/admin/users/${u.id}/status`, { status: u.status === 'active' ? 'suspended' : 'active' }), t('admin.common.saved'))
  await load()
}
async function submitInvite() {
  const ok = await run(() => send('POST', '/admin/users', invite), t('admin.users_page.invited'))
  if (ok !== undefined) {
    inviteOpen.value = false
    Object.assign(invite, { name: '', email: '', roles: ['support'] })
    await load()
  }
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.users_page.title')" :subtitle="$t('admin.users_page.subtitle')">
      <button v-if="auth.can('roles.manage')" class="btn-primary btn-sm" @click="inviteOpen = true"><AppIcon name="plus" :size="14" /> {{ $t('admin.users_page.invite') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="filters.page = 1; load()">
      <div class="flex gap-2">
        <button v-for="k in ['staff', 'customer']" :key="k" type="button" class="chip" :class="filters.type === k ? 'chip-active' : ''" @click="filters.type = k; filters.page = 1; load()">{{ $t(`admin.users_page.types.${k}`) }}</button>
      </div>
      <label class="min-w-48 flex-1"><span class="label">{{ $t('admin.common.search') }}</span><input v-model="filters.q" class="input" :placeholder="$t('admin.users_page.search_hint')"></label>
      <button class="btn-dark">{{ $t('admin.common.filter') }}</button>
    </form>
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.name') }}</th><th>{{ $t('admin.users_page.contact') }}</th><th>{{ $t('admin.roles') }}</th><th>MFA</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td class="font-medium">{{ u.name }}</td>
            <td class="text-xs text-ink-500" dir="ltr">{{ u.email }}<span v-if="u.phone" class="block">{{ u.phone }}</span></td>
            <td><span v-for="r in u.roles" :key="r" class="me-1 inline-block rounded-full bg-ink-100 px-2 py-0.5 text-xs">{{ $t(`admin.users_page.roles.${r}`) }}</span></td>
            <td>{{ u.type === 'staff' ? (u.mfa_enabled ? '✓' : '—') : '' }}</td>
            <td><span :class="u.status === 'active' ? 'text-green-700' : 'text-red-600'">{{ $t(`admin.users_page.status.${u.status}`) }}</span></td>
            <td class="whitespace-nowrap text-end">
              <button v-if="auth.can('roles.manage') && u.type === 'staff'" class="btn-outline btn-sm" @click="editRoles(u)">{{ $t('admin.users_page.edit_roles') }}</button>
              <button v-if="u.id !== auth.user?.id" class="btn-sm ms-2" :class="u.status === 'active' ? 'text-red-600 hover:underline' : 'link'" @click="toggleStatus(u)">{{ u.status === 'active' ? $t('admin.users_page.disable') : $t('admin.users_page.enable') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!users.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
    <AdminPager :page="pager.page" :last-page="pager.last_page" :total="pager.total" @go="p => { filters.page = p; load() }" />

    <AdminModal :open="!!rolesFor" :title="`${$t('admin.users_page.edit_roles')} · ${rolesFor?.name ?? ''}`" @close="rolesFor = null">
      <form class="space-y-3" @submit.prevent="saveRoles">
        <label v-for="r in staffRoles" :key="r" class="flex items-start gap-2 text-sm">
          <input v-model="roles" type="checkbox" :value="r" class="mt-1">
          <span><b>{{ $t(`admin.users_page.roles.${r}`) }}</b><span class="block text-xs text-ink-500">{{ $t(`admin.users_page.role_hints.${r}`) }}</span></span>
        </label>
        <div class="flex justify-end gap-2"><button type="button" class="btn-outline" @click="rolesFor = null">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>

    <AdminModal :open="inviteOpen" :title="$t('admin.users_page.invite')" @close="inviteOpen = false">
      <form class="space-y-3" @submit.prevent="submitInvite">
        <label class="block"><span class="label">{{ $t('admin.common.name') }}</span><input v-model="invite.name" class="input" required></label>
        <label class="block"><span class="label">{{ $t('auth.email') }}</span><input v-model="invite.email" type="email" class="input" required dir="ltr"></label>
        <div>
          <span class="label">{{ $t('admin.roles') }}</span>
          <label v-for="r in staffRoles" :key="r" class="flex items-start gap-2 text-sm">
            <input v-model="invite.roles" type="checkbox" :value="r" class="mt-1">
            <span><b>{{ $t(`admin.users_page.roles.${r}`) }}</b><span class="block text-xs text-ink-500">{{ $t(`admin.users_page.role_hints.${r}`) }}</span></span>
          </label>
        </div>
        <p class="text-xs text-ink-500">{{ $t('admin.users_page.invite_hint') }}</p>
        <div class="flex justify-end gap-2"><button type="button" class="btn-outline" @click="inviteOpen = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('admin.users_page.send_invite') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
