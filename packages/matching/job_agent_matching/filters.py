"""Hard filters.

A hard filter is a reason the candidate cannot take the job, not a reason the
job scores badly. Failing one rejects the posting outright — so each rule is
narrow, and every rejection carries a reason the user can read and disagree
with. Silence in a posting is never treated as a "no".
"""

from __future__ import annotations

from dataclasses import dataclass

from job_agent_domain.enums import SENIORITY_RANK, RemoteType, Seniority

from job_agent_matching.compensation import parse as parse_compensation
from job_agent_matching.preferences import SearchPreferences
from job_agent_matching.skills import canonical
from job_agent_matching.types import JobView


@dataclass(frozen=True, slots=True)
class HardBlocker:
    """One reason a job was rejected, with what it was read from."""

    rule: str
    reason: str
    evidence: str | None = None

    def as_dict(self) -> dict[str, str | None]:
        return {"rule": self.rule, "reason": self.reason, "evidence": self.evidence}


def _contains_any(haystack: str, needles: list[str]) -> str | None:
    folded = canonical(haystack)
    for needle in needles:
        candidate = canonical(needle)
        if candidate and candidate in folded:
            return needle
    return None


def _location_blockers(job: JobView, preferences: SearchPreferences) -> list[HardBlocker]:
    blockers: list[HardBlocker] = []

    # An unknown arrangement is not checked: the posting simply did not say.
    if (
        preferences.remote_types
        and job.remote_type is not RemoteType.UNKNOWN
        and job.remote_type not in preferences.remote_types
    ):
        allowed = ", ".join(sorted(item.value for item in preferences.remote_types))
        blockers.append(
            HardBlocker(
                rule="remote_type",
                reason=f"role is {job.remote_type.value}; you accept {allowed}",
                evidence=f"job:{job.id}#remote_type",
            )
        )

    # A fully remote role is not constrained by the country list.
    if (
        preferences.target_countries
        and job.country
        and job.remote_type is not RemoteType.REMOTE
        and not preferences.willing_to_relocate
    ):
        countries = {canonical(country) for country in preferences.target_countries}
        if canonical(job.country) not in countries:
            blockers.append(
                HardBlocker(
                    rule="country",
                    reason=(
                        f"role is in {job.country}; you are looking in "
                        f"{', '.join(preferences.target_countries)}"
                    ),
                    evidence=f"job:{job.id}#country",
                )
            )

    if (
        preferences.commutable_cities
        and job.city
        and job.remote_type in (RemoteType.ONSITE, RemoteType.HYBRID)
        and not preferences.willing_to_relocate
    ):
        cities = {canonical(city) for city in preferences.commutable_cities}
        if canonical(job.city) not in cities:
            blockers.append(
                HardBlocker(
                    rule="commute",
                    reason=f"{job.remote_type.value} role in {job.city}, which is not commutable",
                    evidence=f"job:{job.id}#city",
                )
            )

    return blockers


def _compensation_blocker(job: JobView, preferences: SearchPreferences) -> HardBlocker | None:
    floor = preferences.minimum_compensation
    if floor is None:
        return None

    stated = parse_compensation(job.compensation)
    if stated is None:
        # A posting that does not state pay is not rejected; it is a gap the
        # score reflects and the user asks about.
        return None
    if stated.currency.upper() != floor.currency.upper():
        # No conversion: see compensation.py.
        return None

    ceiling = stated.annual_maximum()
    from job_agent_matching.compensation import ANNUALISE

    wanted = floor.amount * ANNUALISE.get(floor.period, 1.0)
    if ceiling is not None and ceiling < wanted:
        return HardBlocker(
            rule="compensation",
            reason=(
                f"top of range is {stated.maximum:,.0f} {stated.currency} per {stated.period}; "
                f"your minimum is {floor.amount:,.0f} {floor.currency} per {floor.period}"
            ),
            evidence=f"job:{job.id}#compensation",
        )
    return None


def _seniority_blocker(job: JobView, preferences: SearchPreferences) -> HardBlocker | None:
    if job.seniority is Seniority.UNKNOWN:
        return None

    job_rank = SENIORITY_RANK.get(job.seniority)
    if job_rank is None:
        # Manager and director sit off the individual-contributor ladder, so a
        # rank comparison would be meaningless rather than merely wrong.
        return None

    if preferences.minimum_seniority is not None:
        floor = SENIORITY_RANK.get(preferences.minimum_seniority)
        if floor is not None and job_rank < floor:
            return HardBlocker(
                rule="seniority",
                reason=(
                    f"role is {job.seniority.value}; you are looking for "
                    f"{preferences.minimum_seniority.value} or above"
                ),
                evidence=f"job:{job.id}#seniority",
            )

    if preferences.maximum_seniority is not None:
        ceiling = SENIORITY_RANK.get(preferences.maximum_seniority)
        if ceiling is not None and job_rank > ceiling:
            return HardBlocker(
                rule="seniority",
                reason=(
                    f"role is {job.seniority.value}; you are looking for "
                    f"{preferences.maximum_seniority.value} or below"
                ),
                evidence=f"job:{job.id}#seniority",
            )
    return None


def apply_hard_filters(job: JobView, preferences: SearchPreferences) -> list[HardBlocker]:
    """Every reason this job is out, not just the first."""
    blockers: list[HardBlocker] = []

    excluded_company = _contains_any(job.company, preferences.excluded_companies)
    if excluded_company:
        blockers.append(
            HardBlocker(
                rule="excluded_company",
                reason=f"{job.company} is on your excluded list",
                evidence=f"job:{job.id}#company",
            )
        )

    excluded_title = _contains_any(job.title, preferences.excluded_titles)
    if excluded_title:
        blockers.append(
            HardBlocker(
                rule="excluded_title",
                reason=f"title contains {excluded_title!r}, which you excluded",
                evidence=f"job:{job.id}#title",
            )
        )

    excluded_keyword = _contains_any(
        f"{job.title}\n{job.description}", preferences.excluded_keywords
    )
    if excluded_keyword:
        blockers.append(
            HardBlocker(
                rule="excluded_keyword",
                reason=f"posting mentions {excluded_keyword!r}, which you excluded",
                evidence=f"job:{job.id}#description",
            )
        )

    blockers.extend(_location_blockers(job, preferences))

    if preferences.requires_sponsorship and job.visa_sponsorship is False:
        blockers.append(
            HardBlocker(
                rule="sponsorship",
                reason="posting states it cannot sponsor a visa, and you need sponsorship",
                evidence=f"job:{job.id}#visa_sponsorship",
            )
        )

    compensation = _compensation_blocker(job, preferences)
    if compensation is not None:
        blockers.append(compensation)

    seniority = _seniority_blocker(job, preferences)
    if seniority is not None:
        blockers.append(seniority)

    return blockers
