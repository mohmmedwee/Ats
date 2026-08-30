"""Schema-validated CV parsing, with every extracted claim checked back against
the source text.

The model is used for structure, not for truth. Anything it returns that cannot
be found in the CV is discarded and reported, rather than stored as a fact. This
is the mechanism behind the plan's rule that no generated claim is ever saved as
user-confirmed.
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass, field

from job_agent_ai.provider import AIProvider
from job_agent_ai.structured import generate_structured
from job_agent_ai.types import Message
from job_agent_domain.enums import FactKind, FactProvenance

from job_agent_cv.extract import ExtractedDocument
from job_agent_cv.schema import ExtractedProfile

#: Kinds whose value must appear verbatim in the CV. Anything here that the
#: source does not contain is dropped.
VERBATIM_KINDS: frozenset[FactKind] = frozenset(
    {
        FactKind.SKILL,
        FactKind.EMPLOYER,
        FactKind.ROLE,
        FactKind.CERTIFICATION,
        FactKind.EDUCATION,
        FactKind.LANGUAGE,
        FactKind.LINK,
        FactKind.LOCATION,
        FactKind.ACHIEVEMENT,
    }
)

#: Kinds that are necessarily a paraphrase. They are kept, but only ever as a
#: draft for the user to accept or rewrite.
DRAFT_KINDS: frozenset[FactKind] = frozenset(
    {FactKind.HEADLINE, FactKind.SUMMARY, FactKind.YEARS_EXPERIENCE}
)

_PROMPT = """Extract the candidate's details from the CV below.

Rules:
- Copy values exactly as they appear. Do not paraphrase a company name, job \
title, skill, certification, institution, or language.
- Omit anything the CV does not state. An empty list is correct when the CV has \
nothing to put in it.
- Do not infer a skill from a job title, or a date from context.
- headline and summary may be written in your own words; everything else must be \
verbatim.

CV:
<cv>
{text}
</cv>
"""

_PUNCT_RE = re.compile(r"[^\w\s]+")
_SPACE_RE = re.compile(r"\s+")


def _canonical(value: str) -> str:
    """Fold case, accents, and punctuation so 'FastAPI,' matches 'fastapi'."""
    decomposed = unicodedata.normalize("NFKD", value)
    stripped = "".join(c for c in decomposed if not unicodedata.combining(c))
    return _SPACE_RE.sub(" ", _PUNCT_RE.sub(" ", stripped.casefold())).strip()


@dataclass(frozen=True, slots=True)
class FactDraft:
    """A candidate fact before it is written. Never user-confirmed at birth."""

    kind: FactKind
    value: str
    provenance: FactProvenance
    evidence_ref: str | None = None
    sort_order: int = 0


@dataclass(slots=True)
class ParseResult:
    extraction: ExtractedProfile
    facts: list[FactDraft] = field(default_factory=list)
    #: Values the model produced that are not in the CV. Surfaced to the user
    #: rather than silently dropped, because a pattern of them means the model
    #: or the prompt needs attention.
    rejected: list[tuple[FactKind, str]] = field(default_factory=list)

    @property
    def verified_count(self) -> int:
        return sum(1 for fact in self.facts if fact.provenance is FactProvenance.CV_DERIVED)


def is_supported(value: str, source_canonical: str) -> bool:
    canonical = _canonical(value)
    return bool(canonical) and canonical in source_canonical


async def parse_profile(
    provider: AIProvider, document: ExtractedDocument, *, temperature: float = 0.0
) -> ExtractedProfile:
    """Ask the model for structure. The result is schema-validated or it raises."""
    return await generate_structured(
        provider,
        [Message(role="user", content=_PROMPT.format(text=document.text))],
        ExtractedProfile,
        temperature=temperature,
    )


def build_facts(extraction: ExtractedProfile, document: ExtractedDocument) -> ParseResult:
    """Turn an extraction into facts, dropping anything the CV does not support."""
    source = _canonical(document.text)
    result = ParseResult(extraction=extraction)
    order = 0

    def add(
        kind: FactKind,
        value: str,
        *,
        evidence: str | None = None,
        verify: tuple[str, ...] | None = None,
    ) -> None:
        """Store ``value``, but check ``verify`` against the CV.

        The two differ where a fact reads better composed than it appears in the
        document: "Engineering Lead at Acme" is stored, while "Engineering Lead"
        and "Acme" are what must actually be present.
        """
        nonlocal order
        value = value.strip()
        if not value:
            return
        if kind in VERBATIM_KINDS:
            missing = [part for part in (verify or (value,)) if not is_supported(part, source)]
            if missing:
                result.rejected.append((kind, value))
                return
        provenance = (
            FactProvenance.GENERATED_DRAFT if kind in DRAFT_KINDS else FactProvenance.CV_DERIVED
        )
        result.facts.append(
            FactDraft(
                kind=kind,
                value=value,
                provenance=provenance,
                evidence_ref=evidence,
                sort_order=order,
            )
        )
        order += 1

    if extraction.headline:
        add(FactKind.HEADLINE, extraction.headline)
    if extraction.summary:
        add(FactKind.SUMMARY, extraction.summary, evidence="section:summary")
    if extraction.location:
        add(FactKind.LOCATION, extraction.location)
    if extraction.years_experience is not None:
        add(FactKind.YEARS_EXPERIENCE, f"{extraction.years_experience:g}")

    for skill in extraction.skills:
        add(FactKind.SKILL, skill, evidence="section:skills")

    for role in extraction.roles:
        add(FactKind.EMPLOYER, role.company, evidence="section:experience")
        add(
            FactKind.ROLE,
            f"{role.title} at {role.company}",
            evidence="section:experience",
            verify=(role.title, role.company),
        )
        for achievement in role.achievements:
            add(FactKind.ACHIEVEMENT, achievement, evidence=f"section:experience:{role.company}")

    for education in extraction.education:
        label = (
            f"{education.qualification}, {education.institution}"
            if education.qualification
            else education.institution
        )
        verify = (
            (education.qualification, education.institution)
            if education.qualification
            else (education.institution,)
        )
        add(FactKind.EDUCATION, label, evidence="section:education", verify=verify)

    for certification in extraction.certifications:
        add(FactKind.CERTIFICATION, certification, evidence="section:certifications")
    for language in extraction.languages:
        add(FactKind.LANGUAGE, language, evidence="section:languages")
    for link in extraction.links:
        add(FactKind.LINK, link)

    return result
