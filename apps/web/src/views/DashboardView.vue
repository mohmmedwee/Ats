<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'

import { api } from '@/api/client'

const { data: policy, isPending, isError } = useQuery({ queryKey: ['policy'], queryFn: api.policy })
</script>

<template>
  <section>
    <h1 class="text-xl font-semibold">Dashboard</h1>
    <p class="mt-1 text-sm text-slate-600">
      Discovery, matching, and the review queue arrive in phases 2 to 4. This page shows the policy
      the running stack is enforcing right now.
    </p>

    <p v-if="isPending" class="mt-6 text-sm text-slate-500">Loading policy…</p>
    <p v-else-if="isError" class="mt-6 text-sm text-red-600">
      Could not reach the API. Is it running on port 8000?
    </p>

    <dl v-else-if="policy" class="mt-6 grid gap-4 sm:grid-cols-2">
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <dt class="text-xs uppercase tracking-wide text-slate-500">Autonomy level</dt>
        <dd class="mt-1 text-lg font-medium">
          {{ policy.autonomy_level }} · {{ policy.autonomy_name.replace('_', ' ') }}
        </dd>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <dt class="text-xs uppercase tracking-wide text-slate-500">Automatic submission</dt>
        <dd class="mt-1 text-lg font-medium">
          {{ policy.auto_submit_enabled ? 'Enabled' : 'Disabled' }}
        </dd>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <dt class="text-xs uppercase tracking-wide text-slate-500">Daily application cap</dt>
        <dd class="mt-1 text-lg font-medium">{{ policy.max_applications_per_day }}</dd>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <dt class="text-xs uppercase tracking-wide text-slate-500">Discovery schedule</dt>
        <dd class="mt-1 text-lg font-medium">
          {{ policy.discovery_cron }}
          <span class="text-sm text-slate-500">{{ policy.discovery_timezone }}</span>
        </dd>
      </div>
    </dl>
  </section>
</template>
