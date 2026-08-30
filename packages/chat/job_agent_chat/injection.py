"""Prompt-injection detection for untrusted content.

Detection is a signal, not the defence. The defence is structural: retrieved
content never reaches the model as instructions, and no tool call can be
motivated by it (see ``prompt.py`` and ``tools.py``). What this module adds is
visibility, so a hostile posting can be flagged on the job record and audited.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    (
        "instruction_override",
        re.compile(
            r"(?i)\b(ignore|disregard|forget)\b.{0,30}\b(previous|prior|above|earlier)\b.{0,20}\b(instruction|prompt|rule|message)s?\b"
        ),
    ),
    (
        "role_hijack",
        re.compile(
            r"(?i)\byou\s+are\s+now\b|\bnew\s+system\s+prompt\b|\bact\s+as\s+(?:a\s+)?(?:system|admin|developer)\b"
        ),
    ),
    (
        "secret_exfiltration",
        re.compile(
            r"(?i)\b(reveal|print|output|show|send)\b.{0,30}\b(system\s+prompt|api[_\s-]?key|secret|token|credential|env(?:ironment)?\s+variable)s?\b"
        ),
    ),
    (
        "tool_coercion",
        re.compile(
            r"(?i)\b(call|invoke|execute|run|use)\b.{0,40}\b(tool|function|command)\b|\bsubmit\s+(?:the\s+)?application\s+(?:now|immediately|without)\b"
        ),
    ),
    (
        "policy_override",
        re.compile(
            r"(?i)\b(disable|bypass|skip|turn\s+off)\b.{0,30}\b(approval|confirmation|safety|guardrail|policy|review)\b"
        ),
    ),
    (
        "exfiltration_channel",
        re.compile(r"(?i)\b(curl|wget|fetch|post)\b\s+https?://|\bsend\b.{0,25}\bto\s+https?://"),
    ),
)


@dataclass(frozen=True, slots=True)
class InjectionScan:
    signals: tuple[str, ...]
    excerpts: tuple[str, ...]

    @property
    def suspected(self) -> bool:
        return bool(self.signals)


def scan(text: str) -> InjectionScan:
    signals: list[str] = []
    excerpts: list[str] = []
    for name, pattern in _PATTERNS:
        match = pattern.search(text)
        if match:
            signals.append(name)
            start = max(0, match.start() - 40)
            excerpts.append(text[start : match.end() + 40].replace("\n", " ").strip())
    return InjectionScan(tuple(signals), tuple(excerpts))
