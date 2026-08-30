"""Provider-agnostic message and completion types."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field

Role = Literal["system", "user", "assistant", "tool"]


class Message(BaseModel):
    role: Role
    content: str
    #: Set on tool result messages so the model can correlate them.
    tool_call_id: str | None = None
    name: str | None = None


class ToolSpec(BaseModel):
    """A tool as advertised to the model. The tier is deliberately absent: the
    model never sees or influences it, the registry decides at dispatch time."""

    name: str
    description: str
    parameters: dict[str, Any]


class ToolCallRequest(BaseModel):
    """A tool the model asked for. It is a request, not a decision."""

    id: str
    name: str
    arguments: dict[str, Any] = Field(default_factory=dict)


class Usage(BaseModel):
    prompt_tokens: int = 0
    completion_tokens: int = 0

    @property
    def total_tokens(self) -> int:
        return self.prompt_tokens + self.completion_tokens


class Completion(BaseModel):
    content: str = ""
    tool_calls: list[ToolCallRequest] = Field(default_factory=list)
    usage: Usage = Field(default_factory=Usage)
    finish_reason: str | None = None


class StreamChunk(BaseModel):
    delta: str = ""
    tool_calls: list[ToolCallRequest] = Field(default_factory=list)
    usage: Usage | None = None
    done: bool = False
