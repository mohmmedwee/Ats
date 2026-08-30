<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'

import { api } from '@/api/client'
import TierBadge from '@/components/TierBadge.vue'

const { data, isPending, isError } = useQuery({ queryKey: ['chat-tools'], queryFn: api.chatTools })
</script>

<template>
  <section>
    <h1 class="text-xl font-semibold">Chat</h1>
    <p class="mt-1 text-sm text-slate-600">
      The conversation surface lands in phase 8. What already runs is the part that decides what
      chat is allowed to do: the tool registry and its tiers.
    </p>

    <p v-if="isPending" class="mt-6 text-sm text-slate-500">Loading tools…</p>
    <p v-else-if="isError" class="mt-6 text-sm text-red-600">Could not reach the API.</p>

    <template v-else-if="data">
      <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">
        Callable from chat
      </h2>
      <ul v-if="data.tools.length" class="mt-3 space-y-2">
        <li
          v-for="tool in data.tools"
          :key="tool.name"
          class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-3"
        >
          <TierBadge :tier="tool.tier" />
          <div>
            <p class="font-mono text-sm">{{ tool.name }}</p>
            <p class="text-sm text-slate-600">{{ tool.description }}</p>
          </div>
        </li>
      </ul>
      <p v-else class="mt-3 text-sm text-slate-500">
        No tools are wired yet. The registry is live and its rules are enforced; handlers arrive
        with the phases that own them.
      </p>

      <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">
        Never callable from chat
      </h2>
      <ul class="mt-3 space-y-2">
        <li
          v-for="action in data.external_actions"
          :key="action.name"
          class="flex items-start gap-3 rounded-lg border border-dashed border-slate-300 p-3"
        >
          <TierBadge tier="t2_external" />
          <div>
            <p class="font-mono text-sm">{{ action.name }}</p>
            <p class="text-sm text-slate-600">
              Handled at
              <span class="font-mono">{{ action.deep_link }}</span> after explicit review.
            </p>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>
