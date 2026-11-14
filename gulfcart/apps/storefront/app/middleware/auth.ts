export default defineNuxtRouteMiddleware(async () => {
  // Session cookies live in the browser; auth checks run client-side.
  if (import.meta.server) return
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  if (!auth.user) return navigateTo(useLocalePath()('/login'))
})
