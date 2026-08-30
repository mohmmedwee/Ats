"""Skill canonicalisation.

A CV says "Node.js", a posting says "NodeJS", and both mean the same thing. The
alias table is deliberately small and hand-maintained: guessing that two
unrelated strings are the same skill would inflate a score with a match the
candidate cannot back up.
"""

from __future__ import annotations

import re
import unicodedata

_PUNCT_RE = re.compile(r"[^\w\s+#.]+")
_SPACE_RE = re.compile(r"\s+")

#: alias -> canonical form. Keys are already canonicalised.
ALIASES: dict[str, str] = {
    "node": "node.js",
    "nodejs": "node.js",
    "node js": "node.js",
    "js": "javascript",
    "ts": "typescript",
    "py": "python",
    "postgres": "postgresql",
    "psql": "postgresql",
    "pg": "postgresql",
    "k8s": "kubernetes",
    "kube": "kubernetes",
    "aks": "azure kubernetes service",
    "gcp": "google cloud platform",
    "aws": "amazon web services",
    "ms azure": "azure",
    "microsoft azure": "azure",
    "es": "elasticsearch",
    "elastic": "elasticsearch",
    "mongo": "mongodb",
    "ci cd": "ci/cd",
    "cicd": "ci/cd",
    "iac": "infrastructure as code",
    "terraform cloud": "terraform",
    "rest": "rest api",
    "restful": "rest api",
    "restful apis": "rest api",
    "rest apis": "rest api",
    "graph ql": "graphql",
    "oauth2": "oauth 2.0",
    "oauth 2": "oauth 2.0",
    "jwt tokens": "jwt",
    "fast api": "fastapi",
    "sql server": "microsoft sql server",
    "golang": "go",
    "c sharp": "c#",
    "dotnet": ".net",
    "rabbit mq": "rabbitmq",
    "llm": "large language models",
    "llms": "large language models",
    "rag": "retrieval augmented generation",
}

#: Skills that count towards the architecture and cloud dimension.
ARCHITECTURE_SKILLS: frozenset[str] = frozenset(
    {
        "kubernetes",
        "docker",
        "terraform",
        "infrastructure as code",
        "ci/cd",
        "azure",
        "amazon web services",
        "google cloud platform",
        "azure kubernetes service",
        "microservices",
        "distributed systems",
        "event streaming",
        "kafka",
        "rabbitmq",
        "redis",
        "high availability",
        "load balancing",
        "service mesh",
        "observability",
        "helm",
    }
)

#: Words in a job description that indicate the role carries people or
#: technical leadership.
LEADERSHIP_TERMS: frozenset[str] = frozenset(
    {
        "lead",
        "leading",
        "leadership",
        "mentor",
        "mentoring",
        "mentorship",
        "architecture review",
        "roadmap",
        "stakeholder",
        "line management",
        "hiring",
        "team of",
        "direct reports",
        "technical direction",
        "coaching",
    }
)


def canonical(skill: str) -> str:
    """Fold a skill to its comparison form and resolve known aliases."""
    decomposed = unicodedata.normalize("NFKD", skill)
    stripped = "".join(c for c in decomposed if not unicodedata.combining(c))
    folded = _SPACE_RE.sub(" ", _PUNCT_RE.sub(" ", stripped.casefold())).strip()
    folded = folded.rstrip(".")
    return ALIASES.get(folded, folded)


def canonical_set(skills: list[str]) -> set[str]:
    return {canonical(skill) for skill in skills if canonical(skill)}


def mentions(text: str, skill: str) -> bool:
    """Whether a canonical skill appears in free text.

    Word-boundary matching, so "go" does not match "going" and "r" does not
    match every sentence.
    """
    needle = canonical(skill)
    if not needle:
        return False
    pattern = re.compile(rf"(?<!\w){re.escape(needle)}(?!\w)")
    return bool(pattern.search(canonical(text)))
