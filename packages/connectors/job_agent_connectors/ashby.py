"""Ashby public job posting connector.

https://developers.ashbyhq.com/docs/public-job-posting-api
"""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from job_agent_domain.enums import RemoteType

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

BASE_URL = "https://api.ashbyhq.com/posting-api/job-board"


def _parse_timestamp(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=UTC)


class AshbySource:
    kind = "ashby"

    def __init__(self, config: dict[str, Any], *, client: SourceClient | None = None) -> None:
        board = config.get("job_board_name")
        if not board:
            raise SourceConfigError("ashby source requires 'job_board_name'")
        self.job_board_name = str(board)
        self.company = str(config.get("company") or self.job_board_name)
        self._client = client or SourceClient(
            rate_limit_per_minute=int(config.get("rate_limit_per_minute", 30))
        )

    @property
    def board_url(self) -> str:
        return f"{BASE_URL}/{self.job_board_name}"

    async def discover(self, cursor: str | None) -> DiscoveryBatch:
        payload = await self._client.get_json(
            self.board_url, params={"includeCompensation": "true"}
        )
        if not isinstance(payload, dict) or "jobs" not in payload:
            raise SourceFetchError(f"unexpected response shape from {self.board_url}")

        since = _parse_timestamp(cursor)
        fetched_at = datetime.now(UTC)
        jobs: list[RawJob] = []
        newest = since

        for item in payload.get("jobs", []):
            published = _parse_timestamp(item.get("publishedAt") or item.get("updatedAt"))
            if since and published and published <= since:
                continue
            if published and (newest is None or published > newest):
                newest = published
            jobs.append(
                RawJob(
                    external_id=str(item.get("id")),
                    source_url=str(item.get("jobUrl") or self.board_url),
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
        """Ashby's board endpoint returns every posting in full, so detail is a
        lookup within that response rather than a second endpoint."""
        payload = await self._client.get_json(
            self.board_url, params={"includeCompensation": "true"}
        )
        if not isinstance(payload, dict):
            raise SourceFetchError(f"unexpected response shape from {self.board_url}")
        for item in payload.get("jobs", []):
            if str(item.get("id")) == external_id:
                return RawJob(
                    external_id=external_id,
                    source_url=str(item.get("jobUrl") or self.board_url),
                    fetched_at=datetime.now(UTC),
                    content_hash=content_hash(repr(sorted(item.items()))),
                    payload=item,
                )
        raise SourceFetchError(f"posting {external_id} is not on the {self.job_board_name} board")

    def normalize(self, raw: RawJob) -> NormalizedJob:
        item = raw.payload
        title = str(item.get("title") or "").strip()
        description = strip_html(
            str(item.get("descriptionPlain") or item.get("descriptionHtml") or "")
        )
        location = item.get("location")
        city, country = split_location(location)
        required, preferred, responsibilities = extract_requirements(description)
        url = str(item.get("jobUrl") or item.get("applyUrl") or raw.source_url)

        # Ashby states remoteness explicitly, so the flag wins over the text —
        # except for hybrid, which the flag cannot express and the text can.
        remote_type = detect_remote_type(location, title, description)
        if item.get("isRemote") is True and remote_type is not RemoteType.HYBRID:
            remote_type = RemoteType.REMOTE
        elif item.get("isRemote") is False and remote_type is RemoteType.UNKNOWN:
            remote_type = RemoteType.ONSITE

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
            remote_type=remote_type,
            employment_type=normalize_employment_type(item.get("employmentType")),
            compensation=(
                item.get("compensation") if isinstance(item.get("compensation"), dict) else None
            ),
            required_skills=required,
            preferred_skills=preferred,
            responsibilities=responsibilities,
            visa_sponsorship=detect_sponsorship(description),
            posted_at=_parse_timestamp(item.get("publishedAt")),
            content_hash=raw.content_hash,
            fingerprint=fingerprint(self.company, title, description),
        )
