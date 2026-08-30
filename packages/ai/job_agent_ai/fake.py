"""Deterministic provider for tests and offline development.

Every test that exercises workflow or chat logic runs against this, so no test
depends on a model being installed and none of them are flaky.
"""

from __future__ import annotations

import hashlib
from collections.abc import AsyncIterator, Sequence

from job_agent_ai.types import Completion, Message, StreamChunk, ToolCallRequest, ToolSpec, Usage


class FakeProvider:
    """Replays scripted completions, then falls back to an echo."""

    def __init__(
        self,
        *,
        completions: Sequence[Completion] | None = None,
        embedding_dim: int = 384,
    ) -> None:
        self.name = "fake"
        self._scripted = list(completions or [])
        self._embedding_dim = embedding_dim
        self.calls: list[list[Message]] = []

    def queue(self, completion: Completion) -> None:
        self._scripted.append(completion)

    def queue_tool_call(self, name: str, arguments: dict[str, object]) -> None:
        self._scripted.append(
            Completion(
                tool_calls=[
                    ToolCallRequest(id=f"call_{name}", name=name, arguments=dict(arguments))
                ]
            )
        )

    async def complete(
        self,
        messages: Sequence[Message],
        *,
        tools: Sequence[ToolSpec] | None = None,
        temperature: float = 0.2,
        max_tokens: int | None = None,
    ) -> Completion:
        self.calls.append(list(messages))
        if self._scripted:
            return self._scripted.pop(0)
        last = messages[-1].content if messages else ""
        return Completion(
            content=f"fake:{last[:200]}",
            usage=Usage(prompt_tokens=len(last) // 4, completion_tokens=8),
            finish_reason="stop",
        )

    async def stream(
        self,
        messages: Sequence[Message],
        *,
        tools: Sequence[ToolSpec] | None = None,
        temperature: float = 0.2,
        max_tokens: int | None = None,
    ) -> AsyncIterator[StreamChunk]:
        completion = await self.complete(
            messages, tools=tools, temperature=temperature, max_tokens=max_tokens
        )
        for word in completion.content.split(" "):
            yield StreamChunk(delta=word + " ")
        if completion.tool_calls:
            yield StreamChunk(tool_calls=completion.tool_calls)
        yield StreamChunk(usage=completion.usage, done=True)

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Hash-derived vectors: stable across runs, different across inputs."""
        vectors: list[list[float]] = []
        for text in texts:
            digest = hashlib.sha256(text.encode("utf-8")).digest()
            raw = [digest[i % len(digest)] / 255.0 for i in range(self._embedding_dim)]
            norm = sum(value * value for value in raw) ** 0.5 or 1.0
            vectors.append([value / norm for value in raw])
        return vectors

    async def aclose(self) -> None:
        return None
