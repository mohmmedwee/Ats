"""Provider selection from settings."""

from __future__ import annotations

from job_agent_domain.settings import Settings, get_settings

from job_agent_ai.fake import FakeProvider
from job_agent_ai.openai_compatible import OpenAICompatibleProvider
from job_agent_ai.provider import AIProvider


def build_provider(settings: Settings | None = None) -> AIProvider:
    settings = settings or get_settings()
    if settings.ai_provider == "fake":
        return FakeProvider()
    # Ollama and MLX-LM both expose OpenAI-compatible routes, so they share a
    # client and differ only in base URL and model name.
    return OpenAICompatibleProvider(
        base_url=settings.ai_base_url,
        model=settings.ai_model,
        api_key=settings.ai_api_key,
        timeout=settings.ai_timeout_seconds,
        embedding_model=settings.embedding_model,
    )
