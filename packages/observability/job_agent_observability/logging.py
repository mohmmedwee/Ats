"""Structured logging configuration."""

from __future__ import annotations

import logging
import sys
from collections.abc import MutableMapping
from contextvars import ContextVar
from typing import Any

import structlog

from job_agent_observability.redaction import redact_value

request_id_var: ContextVar[str | None] = ContextVar("request_id", default=None)

_configured = False


def _add_request_id(
    _logger: Any, _name: str, event_dict: MutableMapping[str, Any]
) -> MutableMapping[str, Any]:
    request_id = request_id_var.get()
    if request_id is not None:
        event_dict.setdefault("request_id", request_id)
    return event_dict


def _redact(
    _logger: Any, _name: str, event_dict: MutableMapping[str, Any]
) -> MutableMapping[str, Any]:
    return {key: redact_value(key, value) for key, value in event_dict.items()}


def configure_logging(level: str = "INFO", json_output: bool = True) -> None:
    """Idempotent: safe to call from the API, the worker, and tests."""
    global _configured

    logging.basicConfig(format="%(message)s", stream=sys.stdout, level=level.upper(), force=True)

    renderer: Any = (
        structlog.processors.JSONRenderer() if json_output else structlog.dev.ConsoleRenderer()
    )
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso", utc=True),
            _add_request_id,
            _redact,
            structlog.processors.StackInfoRenderer(),
            structlog.processors.format_exc_info,
            renderer,
        ],
        wrapper_class=structlog.make_filtering_bound_logger(
            logging.getLevelNamesMapping()[level.upper()]
        ),
        cache_logger_on_first_use=True,
    )
    _configured = True


def get_logger(name: str | None = None) -> structlog.stdlib.BoundLogger:
    if not _configured:
        configure_logging()
    return structlog.get_logger(name)  # type: ignore[no-any-return]
