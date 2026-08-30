"""Chat agent errors."""

from __future__ import annotations


class ChatError(RuntimeError):
    pass


class ExternalTierNotCallableError(ChatError):
    """Raised at registration time, not at call time.

    Plan section 7.8: a T2 tool is not "blocked when invoked", it is never in the
    registry at all. Refusing at registration is what makes that testable.
    """


class ToolNotFoundError(ChatError):
    pass


class ConfirmationMismatchError(ChatError):
    """A confirmation was presented for different arguments than it was issued for."""
