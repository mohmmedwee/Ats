"""PII redaction for logs and telemetry.

Plan section 10 requires PII redaction in logs, and section 7.8 extends that to
chat transcripts. Redaction happens in the logging pipeline rather than at call
sites so that a forgotten ``log.info(user_input)`` still cannot leak an address.
"""

from __future__ import annotations

import re
from typing import Any

EMAIL_RE = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")
#: Deliberately conservative. Dots and colons are excluded as separators and at
#: the boundaries, because ISO timestamps and version strings otherwise look
#: exactly like phone numbers and get redacted out of every log line.
PHONE_RE = re.compile(r"(?<![\w:.])\+?\d[\d\s()-]{6,}\d(?![\w:.])")

#: E.164 allows up to 15 digits; fewer than 8 is more likely an id than a number.
_MIN_PHONE_DIGITS = 8
_MAX_PHONE_DIGITS = 15
BEARER_RE = re.compile(r"(?i)\b(bearer|token|api[_-]?key)\b[\s:=]+\S+")

#: Keys whose values are dropped entirely rather than pattern-matched.
SENSITIVE_KEYS = frozenset(
    {
        "password",
        "secret",
        "secret_key",
        "encryption_key",
        "api_key",
        "ai_api_key",
        "authorization",
        "cookie",
        "storage_state",
        "token",
        "approval_token",
    }
)

REDACTED = "[redacted]"


def _maybe_phone(match: re.Match[str]) -> str:
    digits = sum(character.isdigit() for character in match.group(0))
    if _MIN_PHONE_DIGITS <= digits <= _MAX_PHONE_DIGITS:
        return REDACTED
    return match.group(0)


def redact_text(value: str) -> str:
    value = EMAIL_RE.sub(REDACTED, value)
    value = PHONE_RE.sub(_maybe_phone, value)
    return BEARER_RE.sub(REDACTED, value)


def redact_value(key: str | None, value: Any) -> Any:
    if key is not None and key.lower() in SENSITIVE_KEYS:
        return REDACTED
    if isinstance(value, str):
        return redact_text(value)
    if isinstance(value, dict):
        return {k: redact_value(k, v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return type(value)(redact_value(None, v) for v in value)
    return value
