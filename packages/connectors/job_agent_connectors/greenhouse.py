"""Greenhouse job board connector.

Public board API, no authentication:
https://developers.greenhouse.io/job-board.html
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
    normalize_title,
    split_location,
    strip_html,
)

BASE_URL = "https://boards-api.greenhouse.io/v1/boards"


def _parse_timestamp(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=UTC)


class GreenhouseSource:
    kind = "greenhouse"

    def __init__(self, config: dict[str, Any], *, client: SourceClient | None = None) -> None:
        token = config.get("board_token")
        if not token:
            raise SourceConfigError("greenhouse source requires 'board_token'")
        self.board_token = str(token)
        self.company = str(config.get("company") or self.board_token)
        self._client = client or SourceClient(
            rate_limit_per_minute=int(config.get("rate_limit_per_minute", 30))
        )

    @property
    def board_url(self) -> str:
        return f"{BASE_URL}/{self.board_token}/jobs"

    async def discover(self, cursor: str | None) -> DiscoveryBatch:
        """Fetch the board with full content.

        Greenhouse returns the whole board in one response, so the cursor is a
        watermark on ``updated_at`` rather than a page token: re-running a
        discovery skips postings that have not changed since the last run.
        """
        payload = await self._client.get_json(self.board_url, params={"content": "true"})
        if not isinstance(payload, dict) or "jobs" not in payload:
            raise SourceFetchError(f"unexpected response shape from {self.board_url}")

        since = _parse_timestamp(cursor)
        fetched_at = datetime.now(UTC)
        jobs: list[RawJob] = []
        newest = since

        for item in payload.get("jobs", []):
            updated = _parse_timestamp(item.get("updated_at"))
            if since and updated and updated <= since:
                continue
            if newest is None or (updated and updated > newest):
                newest = updated
            jobs.append(
                RawJob(
                    external_id=str(item.get("id")),
                    source_url=str(item.get("absolute_url") or self.board_url),
                    fetched_at=fetched_at,
                    content_hash=content_hash(repr(sorted(item.items()))),
                    payload=item,
                )
            )

        return DiscoveryBatch(
            jobs=jobs,
            next_cursor=newest.isoformat() if newest else cursor,
            has_more=False,
        )

    async def fetch_details(self, external_id: str) -> RawJob:
        url = f"{self.board_url}/{external_id}"
        item = await self._client.get_json(url, params={"content": "true"})
        if not isinstance(item, dict):
            raise SourceFetchError(f"unexpected response shape from {url}")
        return RawJob(
            external_id=external_id,
            source_url=str(item.get("absolute_url") or url),
            fetched_at=datetime.now(UTC),
            content_hash=content_hash(repr(sorted(item.items()))),
            payload=item,
        )

    def normalize(self, raw: RawJob) -> NormalizedJob:
        item = raw.payload
        title = str(item.get("title") or "").strip()
        # Greenhouse returns the description as escaped HTML.
        description = strip_html(str(item.get("content") or ""))
        location = (item.get("location") or {}).get("name")
        offices = ", ".join(
            str(office.get("name")) for office in item.get("offices") or [] if office.get("name")
        )
        city, country = split_location(location)
        required, preferred, responsibilities = extract_requirements(description)
        url = str(item.get("absolute_url") or raw.source_url)

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
            remote_type=detect_remote_type(location, offices, title, description),
            employment_type=None,
            required_skills=required,
            preferred_skills=preferred,
            responsibilities=responsibilities,
            visa_sponsorship=detect_sponsorship(description),
            posted_at=_parse_timestamp(item.get("updated_at")),
            content_hash=raw.content_hash,
            fingerprint=fingerprint(self.company, title, description),
        )
