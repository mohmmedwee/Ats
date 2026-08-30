"""The provider interface every model backend implements.

Plan section 5: business logic talks to this protocol only, so swapping Ollama
for MLX-LM, vLLM, or a hosted OpenAI-compatible endpoint is configuration.
"""

from __future__ import annotations

from collections.abc import AsyncIterator, Sequence
from typing import Protocol, runtime_checkable

from job_agent_ai.types import Completion, Message, StreamChunk, ToolSpec


@runtime_checkable
class AIProvider(Protocol):
    name: str

    async def complete(
        self,
        messages: Sequence[Message],
        *,
        tools: Sequence[ToolSpec] | None = None,
        temperature: float = 0.2,
        max_tokens: int | None = None,
    ) -> Completion: ...

    def stream(
        self,
        messages: Sequence[Message],
        *,
        tools: Sequence[ToolSpec] | None = None,
        temperature: float = 0.2,
        max_tokens: int | None = None,
    ) -> AsyncIterator[StreamChunk]: ...

    async def embed(self, texts: Sequence[str]) -> list[list[float]]: ...

    async def aclose(self) -> None: ...


class ProviderError(RuntimeError):
    """Raised for transport or protocol failures. Callers decide on retries."""


class ToolCallingUnsupportedError(ProviderError):
    """The configured model could not produce a usable tool call.

    Plan section 5 requires falling back to constrained JSON tool selection
    rather than parsing free-form text.
    """
