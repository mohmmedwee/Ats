# ADR-003: Nuxt storefront

**Status:** Proposed (decision D1)

**Context.** The storefront needs SSR for SEO, first-class i18n with Arabic RTL, and
TypeScript. The draft specified Nuxt 3. Nuxt 4 was released in mid-2025 and Nuxt 3's
maintenance was scheduled to end in January 2026; starting a new project on an
end-of-life major version would force an early migration. Confirm the current support
status before starting Milestone 1.

**Decision.** Use the current supported Nuxt major (Nuxt 4), Vue 3, TypeScript strict,
Tailwind CSS, `@nuxtjs/i18n` with `ar` and `en` locales and `dir` switching, Pinia for state.
The admin dashboard is a layout inside the same Nuxt app for the MVP.

**Consequences.** Nuxt 4's `app/` directory convention is used. Admin can be split into its
own app later without API changes. If the team decides to stay on Nuxt 3, record that
in a superseding ADR together with the migration plan.
