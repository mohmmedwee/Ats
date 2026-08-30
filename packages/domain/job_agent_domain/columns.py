"""Column types shared by the models."""

from __future__ import annotations

from enum import StrEnum

from sqlalchemy import String, TypeDecorator
from sqlalchemy.engine import Dialect


class StrEnumType[E: StrEnum](TypeDecorator[E]):
    """Store a ``StrEnum`` as text and read it back as the enum member.

    Without this, a value loaded from the database is a plain ``str``. It still
    compares equal to its enum member, so ``==`` looks fine, but ``is`` is
    always False — and identity is the natural way to write a state check. That
    mismatch silently withdrew facts the user had confirmed, because
    ``provenance is FactProvenance.USER_CONFIRMED`` never matched a loaded row.
    """

    impl = String
    cache_ok = True

    def __init__(self, enum_class: type[E], length: int = 50) -> None:
        self.enum_class = enum_class
        super().__init__(length=length)

    def process_bind_param(self, value: E | str | None, dialect: Dialect) -> str | None:
        if value is None:
            return None
        return self.enum_class(value).value

    def process_result_value(self, value: str | None, dialect: Dialect) -> E | None:
        if value is None:
            return None
        return self.enum_class(value)
