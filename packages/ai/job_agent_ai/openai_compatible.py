"""Client for any OpenAI-compatible ``/chat/completions`` endpoint."""

from __future__ import annotations

import json
from collections.abc import AsyncIterator, Sequence
from typing import Any

import httpx

from job_agent_ai.provider import ProviderError
from job_agent_ai.types import (
    Completion,
    Message,
    StreamChunk,
    ToolCallRequest,
    ToolSpec,
    Usage,
)


def _tool_payload(tool: ToolSpec) -> dict[str, Any]:
    return {
        "type": "function",
        "function": {
            "name": tool.name,
            "description": tool.description,
            "parameters": tool.parameters,
        },
    }


def _parse_tool_calls(raw: list[dict[str, Any]] | None) -> list[ToolCallRequest]:
    calls: list[ToolCallRequest] = []
    for item in raw or []:
        function = item.get("function") or {}
        arguments = function.get("arguments") or "{}"
        if isinstance(arguments, str):
            try:
                parsed = json.loads(arguments)
            except json.JSONDecodeError:
                # Malformed arguments are surfaced as an empty call so the
                # registry rejects it with a validation error the model can see.
                parsed = {}
        else:
            parsed = arguments
        calls.append(
            ToolCallRequest(
                id=str(item.get("id") or f"call_{len(calls)}"),
                name=str(function.get("name") or ""),
                arguments=parsed if isinstance(parsed, dict) else {},
            )
        )
    return calls


class OpenAICompatibleProvider:
    """Works against Ollama, MLX-LM, vLLM, and hosted OpenAI-compatible APIs."""

    def __init__(
        self,
        *,
        base_url: str,
        model: str,
        api_key: str = "",
        timeout: float = 120.0,
        embedding_model: str | None = None,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self.name = "openai_compatible"
        self.model = model
        self.embedding_model = embedding_model or model
        headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
        self._client = client or httpx.AsyncClient(
            base_url=base_url.rstrip("/"), timeout=timeout, headers=headers
        )

    def _body(
        self,
        messages: Sequence[Message],
        tools: Sequence[ToolSpec] | None,
        temperature: float,
        max_tokens: int | None,
        stream: bool,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {
            "model": self.model,
            "messages": [m.model_dump(exclude_none=True) for m in messages],
            "temperature": temperature,
            "stream": stream,
        }
        if tools:
            body["tools"] = [_tool_payload(t) for t in tools]
        if max_tokens is not None:
            body["max_tokens"] = max_tokens
        return body

    async def complete(
        self,
        messages: Sequence[Message],
        *,
        tools: Sequence[ToolSpec] | None = None,
        temperature: float = 0.2,
        max_tokens: int | None = None,
    ) -> Completion:
        try:
            response = await self._client.post(
                "/chat/completions",
                json=self._body(messages, tools, temperature, max_tokens, stream=False),
            )
            response.raise_for_status()
            data = response.json()
        except httpx.HTTPError as exc:
            raise ProviderError(f"chat completion failed: {exc}") from exc

        choice = (data.get("choices") or [{}])[0]
        message = choice.get("message") or {}
        usage = data.get("usage") or {}
        return Completion(
            content=message.get("content") or "",
            tool_calls=_parse_tool_calls(message.get("tool_calls")),
            usage=Usage(
                prompt_tokens=int(usage.get("prompt_tokens", 0)),
                completion_tokens=int(usage.get("completion_tokens", 0)),
            ),
            finish_reason=choice.get("finish_reason"),
        )

    async def stream(
        self,
        messages: Sequence[Message],
        *,
        tools: Sequence[ToolSpec] | None = None,
        temperature: float = 0.2,
        max_tokens: int | None = None,
    ) -> AsyncIterator[StreamChunk]:
        body = self._body(messages, tools, temperature, max_tokens, stream=True)
        try:
            async with self._client.stream("POST", "/chat/completions", json=body) as response:
                response.raise_for_status()
                async for line in response.aiter_lines():
                    if not line.startswith("data:"):
                        continue
                    payload = line[len("data:") :].strip()
                    if payload == "[DONE]":
                        yield StreamChunk(done=True)
                        return
                    try:
                        data = json.loads(payload)
                    except json.JSONDecodeError:
                        continue
                    choice = (data.get("choices") or [{}])[0]
                    delta = choice.get("delta") or {}
                    yield StreamChunk(
                        delta=delta.get("content") or "",
                        tool_calls=_parse_tool_calls(delta.get("tool_calls")),
                        done=choice.get("finish_reason") is not None,
                    )
        except httpx.HTTPError as exc:
            raise ProviderError(f"chat stream failed: {exc}") from exc

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        try:
            response = await self._client.post(
                "/embeddings", json={"model": self.embedding_model, "input": list(texts)}
            )
            response.raise_for_status()
            data = response.json()
        except httpx.HTTPError as exc:
            raise ProviderError(f"embedding request failed: {exc}") from exc
        return [item["embedding"] for item in data.get("data", [])]

    async def aclose(self) -> None:
        await self._client.aclose()
