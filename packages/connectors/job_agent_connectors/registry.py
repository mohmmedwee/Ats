"""Mapping a stored source row to a connector instance."""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

from job_agent_connectors.ashby import AshbySource
from job_agent_connectors.base import JobSource, SourceConfigError
from job_agent_connectors.greenhouse import GreenhouseSource
from job_agent_connectors.http import SourceClient
from job_agent_connectors.lever import LeverSource

#: The only source kinds that exist. Adding one is a deliberate act, not a
#: string a stored row can invent.
BUILDERS: dict[str, Callable[..., JobSource]] = {
    GreenhouseSource.kind: GreenhouseSource,
    LeverSource.kind: LeverSource,
    AshbySource.kind: AshbySource,
}

SUPPORTED_KINDS = tuple(sorted(BUILDERS))


class UnknownSourceKindError(SourceConfigError):
    pass


def build_source(
    kind: str, config: dict[str, Any], *, client: SourceClient | None = None
) -> JobSource:
    builder = BUILDERS.get(kind)
    if builder is None:
        raise UnknownSourceKindError(
            f"unknown source kind {kind!r}; supported kinds are {', '.join(SUPPORTED_KINDS)}"
        )
    return builder(config, client=client)
