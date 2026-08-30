"""PII and secret redaction in logs (plan section 10)."""

from __future__ import annotations

from job_agent_observability.redaction import REDACTED, redact_text, redact_value


def test_emails_and_phone_numbers_are_redacted() -> None:
    text = "Contact mohammed@example.com or +962 79 123 4567 about the role"
    result = redact_text(text)
    assert "mohammed@example.com" not in result
    assert "962 79 123 4567" not in result
    assert result.count(REDACTED) == 2


def test_sensitive_keys_are_dropped_entirely() -> None:
    payload = {"api_key": "sk-live-abc123", "encryption_key": "x", "title": "Engineering Lead"}
    result = redact_value(None, payload)
    assert result["api_key"] == REDACTED
    assert result["encryption_key"] == REDACTED
    assert result["title"] == "Engineering Lead"


def test_nested_structures_are_redacted() -> None:
    payload = {"user": {"email": "a@b.com", "tokens": ["Bearer abc123"]}}
    result = redact_value(None, payload)
    assert "a@b.com" not in str(result)
    assert "abc123" not in str(result)


def test_non_string_values_pass_through() -> None:
    assert redact_value("score", 87.5) == 87.5
    assert redact_value("remote", True) is True


def test_timestamps_and_ids_are_not_mistaken_for_phone_numbers() -> None:
    """The phone pattern used to eat ISO timestamps out of every log line."""
    for value in (
        "2026-08-30T00:43:17.820542+00:00",
        "duration_ms=1234.5678",
        "version 1.2.3-rc.4",
        "score 87.5 of 100",
    ):
        assert redact_text(value) == value


def test_real_phone_shapes_are_still_redacted() -> None:
    for value in ("+962 79 123 4567", "+962791234567", "(079) 123-4567"):
        assert REDACTED in redact_text(value)
