"""Encryption for data at rest.

Plan section 10 requires sensitive profile fields to be encrypted at rest. The
CV and the text extracted from it are the most PII-dense things this system
stores, so they go through here rather than being written in the clear.

The key comes from ``ENCRYPTION_KEY``. Rotating it makes existing ciphertext
unreadable, which is why ``decrypt`` raises a distinct error rather than
returning something plausible.
"""

from __future__ import annotations

import base64
import hashlib
from functools import lru_cache
from typing import Any

from cryptography.fernet import Fernet, InvalidToken
from sqlalchemy import LargeBinary, String, TypeDecorator
from sqlalchemy.engine import Dialect

from job_agent_domain.settings import get_settings


class DecryptionError(RuntimeError):
    """Ciphertext could not be read with the configured key."""


@lru_cache(maxsize=4)
def _fernet(secret: str) -> Fernet:
    # Fernet needs 32 url-safe base64 bytes; the configured secret is arbitrary
    # text, so it is hashed to that shape rather than constrained in .env.
    digest = hashlib.sha256(secret.encode("utf-8")).digest()
    return Fernet(base64.urlsafe_b64encode(digest))


def encrypt_bytes(data: bytes, *, secret: str | None = None) -> bytes:
    return _fernet(secret or get_settings().encryption_key).encrypt(data)


def decrypt_bytes(token: bytes, *, secret: str | None = None) -> bytes:
    try:
        return _fernet(secret or get_settings().encryption_key).decrypt(token)
    except InvalidToken as exc:
        raise DecryptionError("could not decrypt with the configured ENCRYPTION_KEY") from exc


def encrypt_text(value: str, *, secret: str | None = None) -> str:
    return encrypt_bytes(value.encode("utf-8"), secret=secret).decode("ascii")


def decrypt_text(token: str, *, secret: str | None = None) -> str:
    return decrypt_bytes(token.encode("ascii"), secret=secret).decode("utf-8")


class EncryptedText(TypeDecorator[str]):
    """A text column that is ciphertext on disk and plaintext in Python.

    Encrypting in the type means a new column cannot forget to do it, and a
    query that dumps the table shows tokens rather than someone's history.
    """

    impl = String
    cache_ok = True

    def process_bind_param(self, value: str | None, dialect: Dialect) -> str | None:
        if value is None:
            return None
        return encrypt_text(value)

    def process_result_value(self, value: str | None, dialect: Dialect) -> str | None:
        if value is None:
            return None
        return decrypt_text(value)


class EncryptedBytes(TypeDecorator[bytes]):
    """Binary column stored encrypted. Used for uploaded resume files."""

    impl = LargeBinary
    cache_ok = True

    def process_bind_param(self, value: bytes | None, dialect: Dialect) -> bytes | None:
        if value is None:
            return None
        return encrypt_bytes(value)

    def process_result_value(self, value: Any, dialect: Dialect) -> bytes | None:
        if value is None:
            return None
        return decrypt_bytes(bytes(value))
