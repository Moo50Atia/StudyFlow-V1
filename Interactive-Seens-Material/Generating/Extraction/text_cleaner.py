"""
Text Cleaner — Post-extraction text normalization.

Cleans raw extracted text by removing artifacts, normalizing whitespace,
and preparing text for downstream pipeline stages.
"""

import re
import logging

logger = logging.getLogger(__name__)


class TextCleaner:
    """
    Cleans and normalizes extracted text from PDFs.

    Removes common extraction artifacts without destroying document structure.
    """

    def __init__(self):
        # Patterns to remove (compiled for performance)
        self._page_header_pattern = re.compile(
            r'^Page\s*\|\s*\d+.*$', re.MULTILINE | re.IGNORECASE
        )
        self._page_number_pattern = re.compile(
            r'^\s*\d{1,4}\s*$', re.MULTILINE
        )
        self._form_feed_pattern = re.compile(r'\x0c')
        self._excessive_newlines = re.compile(r'\n{4,}')
        self._excessive_spaces = re.compile(r'[ \t]{3,}')
        self._trailing_whitespace = re.compile(r'[ \t]+$', re.MULTILINE)

    def clean(self, text: str) -> str:
        """
        Apply all cleaning steps to the extracted text.

        Args:
            text: Raw extracted text.

        Returns:
            Cleaned text ready for pipeline processing.
        """
        original_len = len(text)

        text = self._remove_form_feeds(text)
        text = self._remove_page_headers(text)
        text = self._remove_standalone_page_numbers(text)
        text = self._normalize_unicode(text)
        text = self._fix_ocr_artifacts(text)
        text = self._normalize_whitespace(text)

        cleaned_len = len(text)
        reduction = ((original_len - cleaned_len) / original_len * 100) if original_len > 0 else 0
        logger.info(
            f"Text cleaned: {original_len} → {cleaned_len} chars "
            f"({reduction:.1f}% reduction)"
        )

        return text

    def _remove_form_feeds(self, text: str) -> str:
        """Remove form feed characters (page breaks in PDF extraction)."""
        return self._form_feed_pattern.sub('\n', text)

    def _remove_page_headers(self, text: str) -> str:
        """Remove common page header/footer patterns."""
        return self._page_header_pattern.sub('', text)

    def _remove_standalone_page_numbers(self, text: str) -> str:
        """
        Remove lines that contain only a page number.

        Only removes numbers that appear on their own line and are likely
        page numbers (1-4 digits). Preserves numbers within content.
        """
        lines = text.split('\n')
        cleaned_lines = []
        for line in lines:
            stripped = line.strip()
            # Skip lines that are just a number (likely page numbers)
            if stripped and stripped.isdigit() and len(stripped) <= 4:
                continue
            cleaned_lines.append(line)
        return '\n'.join(cleaned_lines)

    def _normalize_unicode(self, text: str) -> str:
        """Normalize common Unicode issues from PDF extraction."""
        replacements = {
            '\u2018': "'",   # Left single quote → apostrophe
            '\u2019': "'",   # Right single quote → apostrophe
            '\u201c': '"',   # Left double quote
            '\u201d': '"',   # Right double quote
            '\u2013': '–',   # En dash (preserve)
            '\u2014': '—',   # Em dash (preserve)
            '\u00a0': ' ',   # Non-breaking space → regular space
            '\ufeff': '',    # BOM
            '\u200b': '',    # Zero-width space
        }
        for old, new in replacements.items():
            text = text.replace(old, new)
        return text

    def _fix_ocr_artifacts(self, text: str) -> str:
        """Fix common OCR misrecognition patterns."""
        # Fix common OCR character confusions
        fixes = [
            (r'(?<=[a-z])l(?=[a-z])', 'l'),   # Lowercase L often confused with 1
            (r'(?<!\S)0(?=[a-zA-Z])', 'O'),    # Zero before letters → O
        ]
        for pattern, replacement in fixes:
            text = re.sub(pattern, replacement, text)
        return text

    def _normalize_whitespace(self, text: str) -> str:
        """Normalize excessive whitespace while preserving structure."""
        text = self._trailing_whitespace.sub('', text)
        text = self._excessive_spaces.sub('  ', text)
        text = self._excessive_newlines.sub('\n\n\n', text)
        return text.strip()


def clean_text(text: str) -> str:
    """Convenience function to clean extracted text."""
    cleaner = TextCleaner()
    return cleaner.clean(text)
