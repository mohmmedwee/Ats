"""Reading a compensation figure out of a posting, conservatively.

A wrong number here rejects a job the candidate wanted, so anything ambiguous
returns None. Currencies are never converted: filtering on a rate the user did
not choose would be a silent decision on their behalf.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

#: Multipliers to a yearly figure. Working days and hours, not calendar ones.
ANNUALISE: dict[str, float] = {
    "year": 1.0,
    "month": 12.0,
    "week": 52.0,
    "day": 260.0,
    "hour": 2080.0,
}

_PERIOD_WORDS: dict[str, str] = {
    "annual": "year",
    "annually": "year",
    "yearly": "year",
    "year": "year",
    "yr": "year",
    "pa": "year",
    "month": "month",
    "monthly": "month",
    "mo": "month",
    "week": "week",
    "weekly": "week",
    "day": "day",
    "daily": "day",
    "hour": "hour",
    "hourly": "hour",
    "hr": "hour",
}

_CURRENCY_SYMBOLS = {"$": "USD", "£": "GBP", "€": "EUR"}

#: Postings use hyphens, en dashes, em dashes, and the word "to" for ranges.
_RANGE_SEPARATOR = r"(?:-|\u2013|\u2014|to)"
_RANGE_RE = re.compile(
    r"(?P<sym>[$£€])?\s*(?P<low>\d[\d,._]*)\s*" + _RANGE_SEPARATOR + r"\s*(?P<sym2>[$£€])?\s*"
    r"(?P<high>\d[\d,._]*)\s*(?P<code>[A-Z]{3})?",
)
_SINGLE_RE = re.compile(r"(?P<sym>[$£€])?\s*(?P<amount>\d[\d,._]*)\s*(?P<code>[A-Z]{3})?")
_PERIOD_RE = re.compile(r"(?i)\b(" + "|".join(_PERIOD_WORDS) + r")\b")


@dataclass(frozen=True, slots=True)
class Compensation:
    minimum: float | None
    maximum: float | None
    currency: str
    period: str

    def annual_maximum(self) -> float | None:
        if self.maximum is None:
            return None
        return self.maximum * ANNUALISE.get(self.period, 1.0)

    def annual_minimum(self) -> float | None:
        if self.minimum is None:
            return None
        return self.minimum * ANNUALISE.get(self.period, 1.0)


def _number(raw: str) -> float | None:
    cleaned = raw.replace(",", "").replace("_", "")
    # A single trailing group of three after a dot is a thousands separator in
    # some locales; treating "12.000" as 12 would misread a salary by 1000x.
    if re.fullmatch(r"\d+\.\d{3}", cleaned):
        cleaned = cleaned.replace(".", "")
    try:
        return float(cleaned)
    except ValueError:
        return None


def _period(text: str) -> str:
    match = _PERIOD_RE.search(text)
    return _PERIOD_WORDS[match.group(1).lower()] if match else "year"


def parse_text(text: str) -> Compensation | None:
    """Parse a compensation string such as "12,000 - 15,000 JOD per month"."""
    if not text:
        return None

    period = _period(text)
    range_match = _RANGE_RE.search(text)
    if range_match:
        low = _number(range_match.group("low"))
        high = _number(range_match.group("high"))
        currency = (
            range_match.group("code")
            or _CURRENCY_SYMBOLS.get(range_match.group("sym") or "")
            or _CURRENCY_SYMBOLS.get(range_match.group("sym2") or "")
        )
        if low is not None and high is not None and currency:
            return Compensation(low, high, currency.upper(), period)
        return None

    single = _SINGLE_RE.search(text)
    if single:
        amount = _number(single.group("amount"))
        currency = single.group("code") or _CURRENCY_SYMBOLS.get(single.group("sym") or "")
        if amount is not None and currency:
            return Compensation(amount, amount, currency.upper(), period)
    return None


def parse(compensation: dict[str, object] | None) -> Compensation | None:
    """Read a posting's compensation block.

    Handles the structured shape boards like Ashby return, then falls back to
    the human-readable summary. Returns None when nothing is unambiguous.
    """
    if not compensation:
        return None

    components = compensation.get("summaryComponents")
    if isinstance(components, list):
        for component in components:
            if not isinstance(component, dict):
                continue
            currency = component.get("currencyCode")
            minimum = component.get("minValue")
            maximum = component.get("maxValue")
            if not isinstance(currency, str):
                continue
            interval = str(component.get("interval") or "")
            period = _period(interval) if interval else "year"
            low = float(minimum) if isinstance(minimum, (int, float)) else None
            high = float(maximum) if isinstance(maximum, (int, float)) else low
            if low is None and high is None:
                continue
            return Compensation(low, high, currency.upper(), period)

    summary = compensation.get("compensationTierSummary") or compensation.get("summary")
    if isinstance(summary, str):
        return parse_text(summary)
    return None
