"""Schema-validated model output.

Plan section 5: every LLM response used by the workflow is validated against a
Pydantic schema. A response that does not validate is an error, never a
best-effort parse, and never something that reaches a database or a form.
"""

from __future__ import annotations

import json
import re
from collections.abc import Sequence

from pydantic import BaseModel, ValidationError

from job_agent_ai.provider import AIProvider
from job_agent_ai.types import Message

_JSON_BLOCK_RE = re.compile(r"```(?:json)?\s*(\{.*?\}|\[.*?\])\s*```", re.DOTALL)


class StructuredOutputError(ValueError):
    """The model did not return output matching the requested schema."""

    def __init__(self, message: str, raw: str) -> None:
        super().__init__(message)
        self.raw = raw


def _extract_json(text: str) -> str:
    match = _JSON_BLOCK_RE.search(text)
    if match:
        return match.group(1)
    start = min((i for i in (text.find("{"), text.find("[")) if i != -1), default=-1)
    if start == -1:
        return text
    end = max(text.rfind("}"), text.rfind("]"))
    return text[start : end + 1] if end > start else text


async def generate_structured[T: BaseModel](
    provider: AIProvider,
    messages: Sequence[Message],
    schema: type[T],
    *,
    attempts: int = 2,
    temperature: float = 0.0,
) -> T:
    """Ask for JSON matching ``schema``, retrying once with the validation error.

    The retry feeds the error back to the model rather than loosening the schema.
    """
    conversation = list(messages)
    instruction = Message(
        role="system",
        content=(
            "Reply with a single JSON object matching this schema. "
            "No prose, no code fences.\n"
            f"{json.dumps(schema.model_json_schema())}"
        ),
    )
    conversation.insert(0, instruction)

    last_error: Exception | None = None
    raw = ""
    for _ in range(max(1, attempts)):
        completion = await provider.complete(conversation, temperature=temperature)
        raw = completion.content
        try:
            return schema.model_validate_json(_extract_json(raw))
        except (ValidationError, ValueError) as exc:
            last_error = exc
            conversation.append(Message(role="assistant", content=raw))
            conversation.append(
                Message(
                    role="user",
                    content=(
                        "That did not validate against the schema. "
                        f"Error: {exc}. Return corrected JSON only."
                    ),
                )
            )

    raise StructuredOutputError(f"model output failed schema validation: {last_error}", raw)
