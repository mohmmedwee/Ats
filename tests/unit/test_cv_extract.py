"""Deterministic CV text extraction."""

from __future__ import annotations

import pathlib
import zlib

import pytest
from job_agent_cv.errors import (
    EmptyDocumentError,
    ExtractionError,
    FileTooLargeError,
    UnsupportedFormatError,
)
from job_agent_cv.extract import ResumeFormat, extract, normalise, sniff_format, split_sections

FIXTURE = (
    pathlib.Path(__file__).resolve().parents[2]
    / "fixtures"
    / "resumes"
    / "sample_engineering_lead.docx"
)


# --- format sniffing --------------------------------------------------------


def test_format_comes_from_content_not_extension(make_pdf) -> None:  # type: ignore[no-untyped-def]
    assert sniff_format(FIXTURE.read_bytes(), filename="cv.pdf") is ResumeFormat.DOCX
    assert sniff_format(make_pdf("hello"), filename="cv.docx") is ResumeFormat.PDF


def test_non_document_uploads_are_rejected() -> None:
    for payload in (b"#!/bin/sh\nrm -rf /", b"\x7fELF\x02\x01\x01", b"plain text CV"):
        with pytest.raises(UnsupportedFormatError):
            sniff_format(payload, filename="cv.docx")


# --- limits and malformed input ---------------------------------------------


def test_empty_upload_is_rejected() -> None:
    with pytest.raises(EmptyDocumentError):
        extract(b"")


def test_oversize_upload_is_rejected() -> None:
    with pytest.raises(FileTooLargeError):
        extract(FIXTURE.read_bytes(), max_bytes=100)


def test_truncated_docx_raises_extraction_error() -> None:
    truncated = FIXTURE.read_bytes()[:400]
    with pytest.raises(ExtractionError):
        extract(truncated, filename="cv.docx")


def test_zip_that_is_not_a_docx_raises_extraction_error() -> None:
    import io
    import zipfile

    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        archive.writestr("hello.txt", "not a word document")
    with pytest.raises(ExtractionError):
        extract(buffer.getvalue(), filename="cv.docx")


def test_pdf_without_a_text_layer_is_reported_as_empty(make_pdf) -> None:  # type: ignore[no-untyped-def]
    """A scan parses fine and yields nothing; the user needs to hear that."""
    with pytest.raises(EmptyDocumentError, match="OCR"):
        extract(make_pdf("x"), filename="scan.pdf")


def test_corrupt_pdf_raises_extraction_error() -> None:
    with pytest.raises(ExtractionError):
        extract(b"%PDF-1.4\n" + zlib.compress(b"garbage") * 4, filename="cv.pdf")


# --- the happy path ---------------------------------------------------------


def test_pdf_text_is_extracted(make_pdf) -> None:  # type: ignore[no-untyped-def]
    body = "Engineering Lead with seven years of backend experience " * 4
    document = extract(make_pdf(body), filename="cv.pdf")
    assert document.format is ResumeFormat.PDF
    assert "Engineering Lead" in document.text
    assert document.page_count == 1


def test_docx_fixture_yields_every_major_section() -> None:
    document = extract(FIXTURE.read_bytes(), filename="sample.docx")
    assert set(document.sections) >= {
        "summary",
        "skills",
        "experience",
        "education",
        "certifications",
        "languages",
    }


def test_tabular_experience_survives_extraction() -> None:
    """Reading paragraphs and tables separately used to drop the whole table
    past the education heading, leaving experience empty."""
    document = extract(FIXTURE.read_bytes())
    experience = document.sections["experience"]
    assert "Northwind Systems" in experience
    assert "Cedar Analytics" in experience
    assert "Levant Web Works" in experience
    assert "Led a team of six engineers" in experience
    # Ordering is preserved, so education does not swallow the roles.
    assert document.text.index("Northwind Systems") < document.text.index("BSc Computer Science")


def test_extraction_is_deterministic() -> None:
    data = FIXTURE.read_bytes()
    assert extract(data).text == extract(data).text


# --- normalisation and sectioning -------------------------------------------


def test_normalise_collapses_whitespace_but_keeps_lines() -> None:
    assert normalise("a   b\r\n\r\n\r\n\r\nc \t") == "a b\n\nc"


def test_section_headings_only_match_on_their_own_line() -> None:
    text = "Summary\nI led teams.\nI care about education and mentoring.\nSkills\nPython"
    sections = split_sections(text)
    assert "education" not in sections
    assert sections["skills"] == "Python"
    assert "mentoring" in sections["summary"]
