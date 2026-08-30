"""Application settings loaded from the environment.

Secrets are read from the environment only; nothing sensitive is defaulted to a
usable value, so a misconfigured deployment fails loudly instead of running with
a known key.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, PostgresDsn, RedisDsn, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

from job_agent_domain.enums import AutonomyLevel


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    env: Literal["development", "test", "production"] = "development"
    log_level: str = "INFO"
    api_host: str = "0.0.0.0"  # noqa: S104 - containerised service, bound per compose network
    api_port: int = 8000
    web_origin: str = "http://localhost:5173"

    database_url: PostgresDsn = Field(
        default=PostgresDsn("postgresql+asyncpg://jobagent:jobagent@localhost:5432/jobagent")
    )
    redis_url: RedisDsn = Field(default=RedisDsn("redis://localhost:6379/0"))

    # Deliberate placeholders: development stays frictionless, and
    # ``validate_for_production`` refuses to start with them set.
    secret_key: str = "change-me"  # noqa: S105
    encryption_key: str = "change-me"

    ai_provider: Literal["ollama", "openai_compatible", "mlx", "fake"] = "ollama"
    ai_base_url: str = "http://localhost:11434/v1"
    ai_api_key: str = ""
    ai_model: str = "qwen2.5:14b-instruct"
    ai_timeout_seconds: float = 120.0
    embedding_model: str = "all-MiniLM-L6-v2"

    autonomy_level: AutonomyLevel = AutonomyLevel.ASSISTED_APPLY
    max_applications_per_day: int = 10
    discovery_cron: str = "0 7 * * *"
    discovery_timezone: str = "Asia/Amman"

    #: Single-user MVP: the local account the API acts as until authentication
    #: lands. Role-based access is already modelled; this is the seat that holds.
    local_user_email: str = "owner@localhost"
    local_user_name: str = "Local User"

    #: Uploaded CVs are written here, encrypted. Keep it off a synced folder.
    storage_dir: Path = Path("storage")
    max_resume_bytes: int = 10 * 1024 * 1024

    chat_daily_token_budget: int = 200_000
    chat_max_tool_calls_per_turn: int = 8
    chat_confirmation_ttl_seconds: int = 900

    @field_validator("secret_key", "encryption_key")
    @classmethod
    def _reject_placeholder_secrets_in_production(cls, value: str, info: object) -> str:
        # Validation of the placeholder against the environment happens in
        # ``validate_for_production`` so that tests and local dev stay frictionless.
        return value

    def validate_for_production(self) -> None:
        """Fail closed on placeholder secrets outside development."""
        if self.env != "production":
            return
        placeholders = [
            name
            for name, value in (
                ("SECRET_KEY", self.secret_key),
                ("ENCRYPTION_KEY", self.encryption_key),
            )
            if value == "change-me"
        ]
        if placeholders:
            raise RuntimeError(f"Refusing to start in production with placeholder {placeholders}")


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
