"""Application workflow transitions (plan section 7.6)."""

from __future__ import annotations

from job_agent_domain.enums import (
    APPLICATION_TRANSITIONS,
    BLOCKING_STATES,
    ApplicationStatus,
    AutonomyLevel,
)


def test_every_status_has_a_transition_entry() -> None:
    assert set(APPLICATION_TRANSITIONS) == set(ApplicationStatus)


def test_terminal_states_have_no_successors() -> None:
    assert APPLICATION_TRANSITIONS[ApplicationStatus.CONFIRMED] == frozenset()
    assert APPLICATION_TRANSITIONS[ApplicationStatus.WITHDRAWN] == frozenset()


def test_submission_requires_passing_through_approval() -> None:
    """No path reaches SUBMITTED without APPROVED first."""
    for status, allowed in APPLICATION_TRANSITIONS.items():
        if ApplicationStatus.SUBMITTED in allowed:
            assert status is ApplicationStatus.READY_TO_SUBMIT

    predecessors = {
        status
        for status, allowed in APPLICATION_TRANSITIONS.items()
        if ApplicationStatus.READY_TO_SUBMIT in allowed
    }
    assert predecessors == {ApplicationStatus.FORM_STARTED}
    assert ApplicationStatus.FORM_STARTED in APPLICATION_TRANSITIONS[ApplicationStatus.APPROVED]


def test_a_missing_answer_and_a_captcha_both_pause() -> None:
    from_form = APPLICATION_TRANSITIONS[ApplicationStatus.FORM_STARTED]
    assert ApplicationStatus.NEEDS_INPUT in from_form
    assert ApplicationStatus.NEEDS_USER_ACTION in from_form
    assert ApplicationStatus.NEEDS_INPUT in BLOCKING_STATES
    assert ApplicationStatus.NEEDS_USER_ACTION in BLOCKING_STATES


def test_submitted_never_returns_to_a_submitting_state() -> None:
    """Plan 7.6: never retry a submit unless the prior outcome is known."""
    assert APPLICATION_TRANSITIONS[ApplicationStatus.SUBMITTED] == frozenset(
        {ApplicationStatus.CONFIRMED, ApplicationStatus.FAILED}
    )


def test_autonomy_levels_are_ordered() -> None:
    assert AutonomyLevel.SCOUT < AutonomyLevel.PREPARE < AutonomyLevel.ASSISTED_APPLY
    assert AutonomyLevel.ASSISTED_APPLY < AutonomyLevel.GUARDED_AUTO_SUBMIT
