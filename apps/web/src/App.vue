<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
      <nav class="mx-auto flex max-w-5xl items-center gap-6 px-6 py-4">
        <span class="font-semibold tracking-tight">Job Agent</span>
        <RouterLink
          to="/"
          class="text-sm text-slate-600 hover:text-slate-900"
          active-class="text-slate-900 font-medium"
        >
          Dashboard
        </RouterLink>
        <RouterLink
          to="/chat"
          class="text-sm text-slate-600 hover:text-slate-900"
          active-class="text-slate-900 font-medium"
        >
          Chat
        </RouterLink>
      </nav>
    </header>
    <main class="mx-auto max-w-5xl px-6 py-8">
      <RouterView />
    </main>
  </div>
</template>
