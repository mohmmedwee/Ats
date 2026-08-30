"""CV ingestion errors."""

from __future__ import annotations


class CVIngestionError(RuntimeError):
    pass


class UnsupportedFormatError(CVIngestionError):
    """The upload is not a DOCX or PDF, whatever its extension claims."""


class FileTooLargeError(CVIngestionError):
    pass


class ExtractionError(CVIngestionError):
    """The file is the right format but could not be read."""


class EmptyDocumentError(ExtractionError):
    """The file parsed but contained no usable text (often a scanned PDF)."""
