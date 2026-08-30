# ADR 0004: Application consent and the approval gate

Status: accepted
Date: 2026-08-30

## Context

An application sent to an employer cannot be recalled. It carries the
candidate's name. The cost of a wrong submission is not a failed request; it is
a real person's reputation with a real company.

## Decision

- There is exactly one submission path: approval gate → short-lived approval
  token bound to the application-pack hash → adapter.
- Before submitting, the UI shows the exact CV, cover letter, answers,
  destination, and an explicit consent checkbox.
- A missing required answer moves the application to `NEEDS_INPUT`. CAPTCHA or
  OTP moves it to `NEEDS_USER_ACTION`. Neither can be answered by the system.
- Submission is never retried automatically unless the prior outcome is
  conclusively known. The state machine has no edge from `SUBMITTED` back to a
  submitting state.
- Autonomy level 3 is off by default and enabled per source.

## Consequences

- Every other surface, chat included, can only reach the gate — never past it.
- Automation stops at the boundary where a mistake becomes irreversible, and the
  human is asked at exactly that point rather than at every step.
