"""Lever postings connector.

Public postings API, no authentication:
https://github.com/lever/postings-api
"""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from job_agent_connectors.base import DiscoveryBatch, RawJob, SourceConfigError
from job_agent_connectors.http import SourceClient, SourceFetchError
from job_agent_connectors.normalize import (
    NormalizedJob,
    canonicalize_url,
    content_hash,
    detect_remote_type,
    detect_seniority,
    detect_sponsorship,
    extract_requirements,
    fingerprint,
    normalize_employment_type,
    normalize_title,
    split_location,
    strip_html,
)

BASE_URL = "https://api.lever.co/v0/postings"

#: Lever list headings that hold requirements or responsibilities.
_LIST_TEXT_KEY = "text"
_LIST_CONTENT_KEY = "content"


def _from_millis(value: Any) -> datetime | None:
    try:
        return datetime.fromtimestamp(int(value) / 1000, tz=UTC)
    except (TypeError, ValueError, OSError):
        return None


class LeverSource:
    kind = "lever"

    def __init__(self, config: dict[str, Any], *, client: SourceClient | None = None) -> None:
        site = config.get("site")
        if not site:
            raise SourceConfigError("lever source requires 'site'")
        self.site = str(site)
        self.company = str(config.get("company") or self.site)
        self._client = client or SourceClient(
            rate_limit_per_minute=int(config.get("rate_limit_per_minute", 30))
        )

    @property
    def board_url(self) -> str:
        return f"{BASE_URL}/{self.site}"

    async def discover(self, cursor: str | None) -> DiscoveryBatch:
        payload = await self._client.get_json(self.board_url, params={"mode": "json"})
        if not isinstance(payload, list):
            raise SourceFetchError(f"unexpected response shape from {self.board_url}")

        since = float(cursor) if cursor and cursor.replace(".", "", 1).isdigit() else None
        fetched_at = datetime.now(UTC)
        jobs: list[RawJob] = []
        newest = since

        for item in payload:
            created = item.get("createdAt")
            created_value = float(created) if isinstance(created, (int, float)) else None
            if since is not None and created_value is not None and created_value <= since:
                continue
            if created_value is not None and (newest is None or created_value > newest):
                newest = created_value
            jobs.append(
                RawJob(
                    external_id=str(item.get("id")),
                    source_url=str(item.get("hostedUrl") or self.board_url),
                    fetched_at=fetched_at,
                    content_hash=content_hash(repr(sorted(item.items()))),
                    payload=item,
                )
            )

        return DiscoveryBatch(
            jobs=jobs,
            next_cursor=str(newest) if newest is not None else cursor,
            has_more=False,
        )

    async def fetch_details(self, external_id: str) -> RawJob:
        url = f"{self.board_url}/{external_id}"
        item = await self._client.get_json(url)
        if not isinstance(item, dict):
            raise SourceFetchError(f"unexpected response shape from {url}")
        return RawJob(
            external_id=external_id,
            source_url=str(item.get("hostedUrl") or url),
            fetched_at=datetime.now(UTC),
            content_hash=content_hash(repr(sorted(item.items()))),
            payload=item,
        )

    def normalize(self, raw: RawJob) -> NormalizedJob:
        item = raw.payload
        title = str(item.get("text") or "").strip()
        categories = item.get("categories") or {}
        location = categories.get("location")

        # Lever splits a posting into a plain description plus titled lists.
        parts = [str(item.get("descriptionPlain") or item.get("description") or "")]
        for block in item.get("lists") or []:
            heading = str(block.get(_LIST_TEXT_KEY) or "").strip()
            body = strip_html(str(block.get(_LIST_CONTENT_KEY) or ""))
            if heading:
                parts.append(f"\n{heading}\n{body}")
            else:
                parts.append(body)
        description = strip_html("\n".join(parts))

        city, country = split_location(location)
        required, preferred, responsibilities = extract_requirements(description)
        url = str(item.get("hostedUrl") or item.get("applyUrl") or raw.source_url)

        return NormalizedJob(
            external_id=raw.external_id,
            company=self.company,
            title=title,
            normalized_title=normalize_title(title),
            seniority=detect_seniority(title),
            description=description,
            application_url=url,
            canonical_url=canonicalize_url(url),
            location=location,
            city=city,
            country=country,
            remote_type=detect_remote_type(
                item.get("workplaceType"), location, categories.get("commitment"), title
            ),
            employment_type=normalize_employment_type(categories.get("commitment")),
            required_skills=required,
            preferred_skills=preferred,
            responsibilities=responsibilities,
            visa_sponsorship=detect_sponsorship(description),
            posted_at=_from_millis(item.get("createdAt")),
            content_hash=raw.content_hash,
            fingerprint=fingerprint(self.company, title, description),
        )
