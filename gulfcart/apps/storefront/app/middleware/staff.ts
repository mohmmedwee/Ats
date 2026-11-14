export default defineNuxtRouteMiddleware(async () => {
  // Session cookies live in the browser; auth checks run client-side.
  if (import.meta.server) return
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const localePath = useLocalePath()
  if (!auth.user) return navigateTo(localePath('/login'))
  if (!auth.isStaff) return navigateTo(localePath('/'))
  if (auth.mfaPending) return navigateTo(localePath('/mfa'))
})
