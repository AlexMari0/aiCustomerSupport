<script setup lang="ts">
import { onMounted, ref } from "vue"
import { getJson } from "./services/api"
import type { ApiSuccessResponse } from "./types/api"

interface HealthData {
  service: string
  status: string
}

const apiMessage = ref("Checking backend API...")

onMounted(async () => {
  try {
    const result = await getJson<HealthData>("/health")

    if (!result.success) {
      apiMessage.value = `API error: ${result.message}`
      return
    }

    const payload = result as ApiSuccessResponse<HealthData>
    apiMessage.value = `API status: ${payload.data.status}`
  } catch {
    apiMessage.value = "API unreachable. Start Laravel server at http://127.0.0.1:8000"
  }
})
</script>

<template>
  <main class="mx-auto flex min-h-screen w-full max-w-6xl items-center px-6 py-14 lg:px-10">
    <section class="w-full rounded-3xl border border-blue-100 bg-white/80 p-8 shadow-xl shadow-blue-100/70 backdrop-blur lg:p-12">
      <p class="mb-3 inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold tracking-wide text-blue-700">
        AI Customer Support Platform
      </p>
      <h1 class="text-3xl font-extrabold text-slate-900 lg:text-5xl">
        Frontend foundation is ready.
      </h1>
      <p class="mt-4 max-w-3xl text-base leading-7 text-slate-600 lg:text-lg">
        This Vue 3 + TypeScript app is connected for API-first development and ready to evolve into support dashboards, ticket workflows, and AI-assisted agent tools.
      </p>

      <div class="mt-8 grid gap-4 md:grid-cols-3">
        <article class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <h2 class="text-sm font-semibold text-slate-900">Framework</h2>
          <p class="mt-2 text-sm text-slate-600">Vue 3 + TypeScript + Vite</p>
        </article>
        <article class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <h2 class="text-sm font-semibold text-slate-900">Styling</h2>
          <p class="mt-2 text-sm text-slate-600">Tailwind CSS v4 configured</p>
        </article>
        <article class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <h2 class="text-sm font-semibold text-slate-900">Backend API</h2>
          <p class="mt-2 text-sm text-slate-600">
            <code class="rounded bg-slate-200 px-1 py-0.5">/api/v1/health</code>
          </p>
          <p class="mt-2 text-xs text-slate-500">{{ apiMessage }}</p>
        </article>
      </div>
    </section>
  </main>
</template>
