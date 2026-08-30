"""Hard filters reject, and say why."""

from __future__ import annotations

import pytest
from job_agent_domain.enums import RemoteType, Seniority
from job_agent_matching.filters import apply_hard_filters
from job_agent_matching.preferences import CompensationFloor, SearchPreferences
from job_agent_matching.types import JobView


def job(**overrides: object) -> JobView:
    base = {
        "id": "j1",
        "company": "Northwind Systems",
        "title": "Senior Backend Engineer",
        "description": "Build services.",
        "country": "Jordan",
        "city": "Amman",
        "remote_type": RemoteType.ONSITE,
        "seniority": Seniority.SENIOR,
    }
    return JobView.model_validate(base | overrides)


def rules(blockers) -> set[str]:  # type: ignore[no-untyped-def]
    return {blocker.rule for blocker in blockers}


def test_default_preferences_reject_nothing() -> None:
    """An unanswered question must never reject a job on its own."""
    assert apply_hard_filters(job(), SearchPreferences()) == []


def test_every_reason_is_reported_not_just_the_first() -> None:
    blockers = apply_hard_filters(
        job(company="Initech", title="Junior PHP Developer", seniority=Seniority.JUNIOR),
        SearchPreferences(
            excluded_companies=["Initech"],
            excluded_titles=["PHP"],
            minimum_seniority=Seniority.SENIOR,
        ),
    )
    assert rules(blockers) == {"excluded_company", "excluded_title", "seniority"}


def test_blockers_carry_a_readable_reason_and_a_reference() -> None:
    blockers = apply_hard_filters(job(), SearchPreferences(excluded_companies=["Northwind"]))
    assert len(blockers) == 1
    assert "excluded list" in blockers[0].reason
    assert blockers[0].evidence == "job:j1#company"


def test_a_remote_role_is_not_bound_by_the_country_list() -> None:
    preferences = SearchPreferences(target_countries=["Jordan"])
    assert (
        apply_hard_filters(job(country="Germany", remote_type=RemoteType.REMOTE), preferences) == []
    )
    assert rules(apply_hard_filters(job(country="Germany"), preferences)) == {"country"}


def test_willingness_to_relocate_clears_location_blockers() -> None:
    preferences = SearchPreferences(
        target_countries=["Jordan"], commutable_cities=["Amman"], willing_to_relocate=True
    )
    assert apply_hard_filters(job(country="Germany", city="Berlin"), preferences) == []


def test_commute_only_applies_to_roles_that_need_one() -> None:
    preferences = SearchPreferences(commutable_cities=["Amman"])
    assert rules(apply_hard_filters(job(city="Berlin"), preferences)) == {"commute"}
    assert apply_hard_filters(job(city="Berlin", remote_type=RemoteType.REMOTE), preferences) == []


def test_remote_preference_is_only_applied_when_the_posting_says() -> None:
    preferences = SearchPreferences(remote_types=[RemoteType.REMOTE])
    assert rules(apply_hard_filters(job(remote_type=RemoteType.ONSITE), preferences)) == {
        "remote_type"
    }
    assert apply_hard_filters(job(remote_type=RemoteType.UNKNOWN), preferences) == []


def test_silence_about_sponsorship_is_not_a_refusal() -> None:
    preferences = SearchPreferences(requires_sponsorship=True)
    assert apply_hard_filters(job(visa_sponsorship=None), preferences) == []
    assert rules(apply_hard_filters(job(visa_sponsorship=False), preferences)) == {"sponsorship"}


def test_pay_below_the_floor_is_a_blocker() -> None:
    preferences = SearchPreferences(
        minimum_compensation=CompensationFloor(amount=10000, currency="JOD", period="month")
    )
    low = job(
        compensation={
            "summaryComponents": [
                {
                    "currencyCode": "JOD",
                    "interval": "1 MONTH",
                    "minValue": 4000,
                    "maxValue": 6000,
                }
            ]
        }
    )
    assert rules(apply_hard_filters(low, preferences)) == {"compensation"}


def test_a_different_currency_is_never_silently_converted() -> None:
    """Filtering on a rate the user did not choose is a decision made for them."""
    preferences = SearchPreferences(
        minimum_compensation=CompensationFloor(amount=10000, currency="JOD", period="month")
    )
    usd = job(compensation={"compensationTierSummary": "60,000 - 70,000 USD"})
    assert apply_hard_filters(usd, preferences) == []


def test_an_unstated_salary_is_not_a_rejection() -> None:
    preferences = SearchPreferences(
        minimum_compensation=CompensationFloor(amount=10000, currency="JOD", period="month")
    )
    assert apply_hard_filters(job(compensation=None), preferences) == []


def test_periods_are_annualised_before_comparison() -> None:
    preferences = SearchPreferences(
        minimum_compensation=CompensationFloor(amount=60000, currency="USD", period="year")
    )
    monthly = job(compensation={"compensationTierSummary": "6,000 USD per month"})
    assert apply_hard_filters(monthly, preferences) == []


def test_off_ladder_seniority_is_not_rank_compared() -> None:
    """Manager and director are not a rung above staff; comparing them would be
    meaningless rather than merely wrong."""
    preferences = SearchPreferences(maximum_seniority=Seniority.SENIOR)
    assert apply_hard_filters(job(seniority=Seniority.MANAGER), preferences) == []


@pytest.mark.parametrize("keyword", ["night shift", "Night Shift", "NIGHT SHIFT"])
def test_excluded_keywords_are_matched_case_insensitively(keyword: str) -> None:
    preferences = SearchPreferences(excluded_keywords=["night shift"])
    posting = job(description=f"This role requires {keyword} work.")
    assert rules(apply_hard_filters(posting, preferences)) == {"excluded_keyword"}
