"""LLM output is schema-validated or it is an error (plan section 5)."""

from __future__ import annotations

import pytest
from job_agent_ai import Completion, FakeProvider, Message, StructuredOutputError
from job_agent_ai.structured import generate_structured
from pydantic import BaseModel


class Extraction(BaseModel):
    title: str
    years: int


async def test_valid_json_is_parsed() -> None:
    provider = FakeProvider(completions=[Completion(content='{"title": "Lead", "years": 7}')])
    result = await generate_structured(
        provider, [Message(role="user", content="extract")], Extraction
    )
    assert result == Extraction(title="Lead", years=7)


async def test_fenced_json_is_recovered() -> None:
    provider = FakeProvider(
        completions=[Completion(content='```json\n{"title": "Lead", "years": 7}\n```')]
    )
    result = await generate_structured(provider, [Message(role="user", content="x")], Extraction)
    assert result.years == 7


async def test_invalid_output_retries_then_raises() -> None:
    provider = FakeProvider(
        completions=[
            Completion(content="I think it is a lead role"),
            Completion(content="still prose"),
        ]
    )
    with pytest.raises(StructuredOutputError):
        await generate_structured(provider, [Message(role="user", content="x")], Extraction)
    assert len(provider.calls) == 2


async def test_retry_feeds_the_validation_error_back() -> None:
    provider = FakeProvider(
        completions=[
            Completion(content='{"title": "Lead"}'),
            Completion(content='{"title": "Lead", "years": 7}'),
        ]
    )
    result = await generate_structured(provider, [Message(role="user", content="x")], Extraction)
    assert result.years == 7
    second_call = provider.calls[1]
    assert "did not validate" in second_call[-1].content


async def test_fake_embeddings_are_deterministic_and_distinct() -> None:
    provider = FakeProvider()
    first = await provider.embed(["engineering lead"])
    again = await provider.embed(["engineering lead"])
    other = await provider.embed(["barista"])
    assert first == again
    assert first != other
    assert len(first[0]) == 384
