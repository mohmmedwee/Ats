"""Turning a posting from any board into the same shape.

Plan section 7.3. Everything here is deterministic and testable: the same raw
posting always normalises to the same record, which is what makes deduplication
and the reproducible-score requirement in Phase 3 possible.
"""

from __future__ import annotations

import hashlib
import html
import re
import unicodedata
from datetime import datetime
from typing import Any
from urllib.parse import urlsplit, urlunsplit

from job_agent_domain.enums import RemoteType, Seniority
from pydantic import BaseModel, Field

_PUNCT_RE = re.compile(r"[^\w\s]+")
_SPACE_RE = re.compile(r"\s+")
_TAG_RE = re.compile(r"<[^>]+>")
_LIST_ITEM_RE = re.compile(r"(?i)<li[^>]*>")
_BREAK_RE = re.compile(r"(?i)<br\s*/?>")
_BLOCK_END_RE = re.compile(r"(?i)</(p|div|h[1-6]|ul|ol|li|tr|table|section)>")

#: Ordered longest-first so "senior staff" does not match "senior" alone.
_SENIORITY_PATTERNS: tuple[tuple[Seniority, re.Pattern[str]], ...] = (
    (Seniority.INTERN, re.compile(r"\b(intern|internship|trainee)\b", re.I)),
    (Seniority.PRINCIPAL, re.compile(r"\bprincipal\b", re.I)),
    (Seniority.DIRECTOR, re.compile(r"\b(director|vp|vice\s+president|head\s+of)\b", re.I)),
    (Seniority.MANAGER, re.compile(r"\b(manager|engineering\s+manager|em)\b", re.I)),
    (Seniority.STAFF, re.compile(r"\bstaff\b", re.I)),
    (Seniority.LEAD, re.compile(r"\b(lead|tech\s+lead|team\s+lead)\b", re.I)),
    (Seniority.SENIOR, re.compile(r"\b(senior|sr\.?|snr)\b", re.I)),
    (Seniority.JUNIOR, re.compile(r"\b(junior|jr\.?|graduate|entry[\s-]level)\b", re.I)),
    (Seniority.MID, re.compile(r"\b(mid[\s-]level|intermediate|ii|iii)\b", re.I)),
)

#: Words stripped from a title before it is used for duplicate matching.
_TITLE_NOISE_RE = re.compile(
    r"\b(senior|sr\.?|snr|junior|jr\.?|staff|principal|lead|intern|internship|trainee|"
    r"i{1,3}|iv|remote|hybrid|onsite|on[\s-]site|full[\s-]time|part[\s-]time|contract|"
    r"m/f/d|m/w/d|f/m/d|all\s+genders?)\b",
    re.I,
)

_REMOTE_RE = re.compile(r"\bremote\b|\bwork\s+from\s+home\b|\bwfh\b|\bdistributed\b", re.I)
_HYBRID_RE = re.compile(r"\bhybrid\b|\bpartially\s+remote\b", re.I)
_ONSITE_RE = re.compile(r"\bon[\s-]?site\b|\bin[\s-]office\b", re.I)

_EMPLOYMENT_TYPES: dict[str, str] = {
    "fulltime": "full_time",
    "full time": "full_time",
    "full-time": "full_time",
    "parttime": "part_time",
    "part time": "part_time",
    "part-time": "part_time",
    "contract": "contract",
    "contractor": "contract",
    "temporary": "temporary",
    "intern": "internship",
    "internship": "internship",
}

#: Query parameters that identify a campaign, not a posting.
_TRACKING_PARAMS = ("utm_", "gh_src", "gh_jid", "source", "ref", "src", "trk", "lever-source")

_SPONSORSHIP_YES = re.compile(
    r"\b(visa\s+sponsorship\s+(is\s+)?(available|provided|offered)|we\s+sponsor|"
    r"sponsorship\s+available|will\s+sponsor)\b",
    re.I,
)
_SPONSORSHIP_NO = re.compile(
    r"\b(no\s+visa\s+sponsorship|unable\s+to\s+sponsor|cannot\s+sponsor|"
    r"sponsorship\s+is\s+not\s+available|not\s+able\s+to\s+(provide|offer)\s+sponsorship|"
    r"must\s+(be\s+)?(legally\s+)?authoriz(ed|e)\s+to\s+work\s+without\s+sponsorship)\b",
    re.I,
)


class NormalizedJob(BaseModel):
    """The board-independent view of one posting."""

    external_id: str
    company: str
    title: str
    normalized_title: str
    seniority: Seniority = Seniority.UNKNOWN
    description: str
    application_url: str
    canonical_url: str
    location: str | None = None
    country: str | None = None
    city: str | None = None
    remote_type: RemoteType = RemoteType.UNKNOWN
    employment_type: str | None = None
    compensation: dict[str, Any] | None = None
    required_skills: list[str] = Field(default_factory=list)
    preferred_skills: list[str] = Field(default_factory=list)
    responsibilities: list[str] = Field(default_factory=list)
    visa_sponsorship: bool | None = None
    posted_at: datetime | None = None
    closes_at: datetime | None = None
    content_hash: str
    fingerprint: str


def fold(value: str) -> str:
    """Case, accent, and punctuation folded. The comparison form used
    throughout deduplication."""
    decomposed = unicodedata.normalize("NFKD", value)
    stripped = "".join(c for c in decomposed if not unicodedata.combining(c))
    return _SPACE_RE.sub(" ", _PUNCT_RE.sub(" ", stripped.casefold())).strip()


def strip_html(value: str) -> str:
    """Boards return HTML, escaped HTML, or plain text depending on the endpoint.

    Two details matter here:

    * Entities are unescaped *before* tags are stripped. Greenhouse returns its
      description as escaped HTML, so stripping first finds no tags and leaves
      ``<p>`` sitting in the text as literal characters.
    * ``<li>`` becomes a ``-`` bullet rather than a bare newline. Requirement
      extraction reads bulleted lines, so dropping the marker turns a
      requirements list into unattributed prose and loses it entirely.
    """
    text = html.unescape(value)
    text = _LIST_ITEM_RE.sub("\n- ", text)
    text = _BREAK_RE.sub("\n", text)
    text = _BLOCK_END_RE.sub("\n", text)
    text = _TAG_RE.sub(" ", text)
    lines = [_SPACE_RE.sub(" ", line).strip() for line in text.split("\n")]
    return re.sub(r"\n{3,}", "\n\n", "\n".join(line for line in lines if line)).strip()


def detect_seniority(title: str) -> Seniority:
    for seniority, pattern in _SENIORITY_PATTERNS:
        if pattern.search(title):
            return seniority
    return Seniority.UNKNOWN


def normalize_title(title: str) -> str:
    """Strip seniority and boilerplate so the same role matches across boards."""
    without_parens = re.sub(r"\([^)]*\)", " ", title)
    cleaned = _TITLE_NOISE_RE.sub(" ", without_parens)
    return fold(cleaned)


def detect_remote_type(*fields: str | None) -> RemoteType:
    """Hybrid wins over remote: a posting saying both means hybrid."""
    haystack = " ".join(field for field in fields if field)
    if not haystack:
        return RemoteType.UNKNOWN
    if _HYBRID_RE.search(haystack):
        return RemoteType.HYBRID
    if _REMOTE_RE.search(haystack):
        return RemoteType.REMOTE
    if _ONSITE_RE.search(haystack):
        return RemoteType.ONSITE
    return RemoteType.UNKNOWN


def split_location(location: str | None) -> tuple[str | None, str | None]:
    """Return (city, country) from a free-text location.

    Boards do not agree on a format, so this is best effort and deliberately
    conservative: a single token is treated as a city, not guessed at.
    """
    if not location:
        return None, None
    cleaned = re.sub(r"\b(remote|hybrid|on[\s-]?site)\b", " ", location, flags=re.I)
    parts = [part.strip() for part in re.split(r"[,/|]|\s+-\s+", cleaned) if part.strip()]
    if not parts:
        return None, None
    if len(parts) == 1:
        return parts[0], None
    return parts[0], parts[-1]


def normalize_employment_type(value: str | None) -> str | None:
    if not value:
        return None
    return _EMPLOYMENT_TYPES.get(
        value.strip().casefold().replace("_", " "), value.strip().casefold()
    )


def detect_sponsorship(description: str) -> bool | None:
    """None means the posting is silent, which is not the same as "no"."""
    if _SPONSORSHIP_NO.search(description):
        return False
    if _SPONSORSHIP_YES.search(description):
        return True
    return None


def canonicalize_url(url: str) -> str:
    """Drop tracking parameters and fragments so the same posting has one URL."""
    parts = urlsplit(url.strip())
    query = "&".join(
        param
        for param in parts.query.split("&")
        if param and not any(param.lower().startswith(prefix) for prefix in _TRACKING_PARAMS)
    )
    path = parts.path.rstrip("/") or "/"
    return urlunsplit((parts.scheme.lower(), parts.netloc.lower(), path, query, ""))


def content_hash(payload: str) -> str:
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def fingerprint(company: str, title: str, description: str) -> str:
    """Stable across whitespace and markup changes, so a board reformatting a
    posting does not make it look like a new job."""
    material = f"{fold(company)}|{normalize_title(title)}|{fold(description)[:2000]}"
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


_REQUIRED_HEADING = re.compile(
    r"^(requirements?|qualifications?|what\s+(you'?ll\s+need|we'?re\s+looking\s+for)|"
    r"who\s+you\s+are|must\s+have|basic\s+qualifications?|skills?\s+(and|&)\s+experience)\b",
    re.I,
)
_PREFERRED_HEADING = re.compile(
    r"^(nice\s+to\s+have|bonus|preferred\s+qualifications?|preferred|plus(es)?|"
    r"desirable|it'?s\s+a\s+plus|extra\s+credit)\b",
    re.I,
)
_RESPONSIBILITY_HEADING = re.compile(
    r"^(responsibilities|what\s+you'?ll\s+do|the\s+role|your\s+impact|day\s+to\s+day|"
    r"about\s+the\s+role|duties)\b",
    re.I,
)
_OTHER_HEADING = re.compile(
    r"^(benefits?|perks?|about\s+(us|the\s+company)|compensation|salary|equal\s+opportunity|"
    r"our\s+values|how\s+to\s+apply|interview\s+process)\b",
    re.I,
)

_BULLET_RE = re.compile(r"^\s*(?:[-*•·▪‣]|\d+[.)])\s+")


def extract_requirements(description: str) -> tuple[list[str], list[str], list[str]]:
    """Pull required, preferred, and responsibility bullets out of a posting.

    Heading-driven and deterministic. A posting that does not use recognisable
    headings yields empty lists rather than a guess: the match engine reports a
    missing requirement as evidence, so an invented one would be worse than none.
    """
    required: list[str] = []
    preferred: list[str] = []
    responsibilities: list[str] = []
    bucket: list[str] | None = None

    for raw_line in description.split("\n"):
        line = raw_line.strip()
        if not line:
            continue

        heading = line.rstrip(":").strip()
        if len(heading) <= 80 and not _BULLET_RE.match(line):
            if _REQUIRED_HEADING.match(heading):
                bucket = required
                continue
            if _PREFERRED_HEADING.match(heading):
                bucket = preferred
                continue
            if _RESPONSIBILITY_HEADING.match(heading):
                bucket = responsibilities
                continue
            if _OTHER_HEADING.match(heading):
                bucket = None
                continue

        if bucket is None:
            continue
        item = _BULLET_RE.sub("", line).strip()
        # Only bulleted lines: prose under a heading is context, not a requirement.
        if item and item != line and 3 <= len(item) <= 400:
            bucket.append(item)

    return required, preferred, responsibilities
