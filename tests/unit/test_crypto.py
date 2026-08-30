"""Encryption of data at rest."""

from __future__ import annotations

import pytest
from job_agent_domain.crypto import (
    DecryptionError,
    decrypt_bytes,
    decrypt_text,
    encrypt_bytes,
    encrypt_text,
)


def test_round_trip() -> None:
    assert decrypt_text(encrypt_text("Amman, Jordan")) == "Amman, Jordan"
    assert decrypt_bytes(encrypt_bytes(b"\x00\x01binary")) == b"\x00\x01binary"


def test_ciphertext_does_not_contain_the_plaintext() -> None:
    token = encrypt_text("mohammed@example.com")
    assert "mohammed" not in token
    assert "example.com" not in token


def test_the_same_input_encrypts_differently_each_time() -> None:
    """Fernet includes a random IV, so equal values are not linkable on disk."""
    assert encrypt_text("Engineering Lead") != encrypt_text("Engineering Lead")


def test_a_wrong_key_fails_loudly() -> None:
    token = encrypt_text("secret", secret="key-one")
    with pytest.raises(DecryptionError):
        decrypt_text(token, secret="key-two")
