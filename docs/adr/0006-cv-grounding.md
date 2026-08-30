# ADR 0006: Every extracted claim is checked against the CV

Status: accepted
Date: 2026-08-30

## Context

Phase 1 uses a model to turn a CV into structured facts. Models are good at
that and also, occasionally, at helping: asked for skills, one will happily add
the framework that usually accompanies the ones it found. A CV that lists
FastAPI and Kubernetes comes back listing Docker too.

That is not a formatting problem. Those facts are what the match engine scores
against and what the pack generator writes into a cover letter. A skill the
model added becomes a claim the candidate makes to an employer.

## Decision

The model is used for structure, not for truth.

Facts are split into two groups:

- **Verbatim kinds** — skill, employer, role, achievement, certification,
  education, language, link, location. Each value must appear in the extracted
  CV text after case, accent, and punctuation folding. Anything that does not is
  discarded and returned to the user in the parse report's `rejected` list.
- **Draft kinds** — headline, summary, years of experience. These are
  necessarily the model's own wording, so they are stored with provenance
  `generated_draft` and never as `cv_derived`.

Composed values are verified by their parts: "Engineering Lead at Northwind
Systems" is stored, while "Engineering Lead" and "Northwind Systems" are what
must actually be present in the document.

No parse ever writes `user_confirmed`. That provenance has exactly one source:
a person acting in the UI.

## Consequences

- A hallucinated skill cannot reach an application, because it is never stored.
- Rejections are visible rather than silent. A pattern of them means the prompt
  or the model needs attention, and the user can see it happening.
- Legitimate values are occasionally rejected — a CV written in Arabic with an
  English skills list, an achievement the model lightly rephrased. The user adds
  those by hand, which marks them `user_confirmed`, which is correct.
- Reprocessing is safe: confirmed facts and edited profile fields are preserved,
  so a re-parse cannot undo a correction.
