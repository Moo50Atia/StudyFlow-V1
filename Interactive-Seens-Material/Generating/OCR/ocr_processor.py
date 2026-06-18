"""
OCR Processor — Optical Character Recognition for scanned and handwritten pages.

Supports multiple backends (Tesseract, EasyOCR) with automatic content type detection.
Generates per-page OCR confidence scores and ocr_report.json.
"""

import json
import logging
from dataclasses import dataclass, field, asdict
from datetime import datetime, timezone
from enum import Enum
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)


class ContentType(str, Enum):
    """Type of content detected on a page."""
    PRINTED_TEXT = "PRINTED_TEXT"
    HANDWRITTEN = "HANDWRITTEN"
    DIAGRAM = "DIAGRAM"
    MIXED = "MIXED"
    EMPTY = "EMPTY"


@dataclass
class OCRResult:
    """Result of OCR processing on a single page or image."""
    text: str = ""
    confidence: float = 0.0
    content_type: ContentType = ContentType.PRINTED_TEXT
    ocr_engine: str = "tesseract"
    page_number: int = 0
    processing_time_ms: float = 0.0


@dataclass
class OCRReport:
    """Complete OCR report for a document."""
    source_file: str = ""
    processed_at: str = ""
    total_pages_processed: int = 0
    pages: list[dict] = field(default_factory=list)

    def to_dict(self) -> dict:
        return {
            "source_file": self.source_file,
            "processed_at": self.processed_at,
            "total_pages_processed": self.total_pages_processed,
            "pages": self.pages,
        }

    def save(self, output_path: str):
        """Save OCR report to JSON file."""
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(self.to_dict(), f, indent=2, ensure_ascii=False)


class OCRProcessor:
    """
    Universal OCR processor with multiple backend support.

    Usage:
        processor = OCRProcessor(backend="tesseract", language="eng")
        result = processor.process_pdf_page("document.pdf", page_num=5)
        print(result.text, result.confidence)
    """

    def __init__(
        self,
        backend: str = "tesseract",
        language: str = "eng",
        tesseract_path: Optional[str] = None,
    ):
        """
        Args:
            backend: OCR engine — "tesseract" or "easyocr".
            language: Language code for OCR.
            tesseract_path: Path to Tesseract binary (if not on PATH).
        """
        self.backend = backend
        self.language = language

        if tesseract_path:
            import pytesseract
            pytesseract.pytesseract.tesseract_cmd = tesseract_path

        self._easyocr_reader = None

    def process_image(self, image, page_number: int = 0) -> OCRResult:
        """
        Process a single PIL Image and return extracted text with confidence.

        Args:
            image: A PIL.Image.Image object.
            page_number: The source page number (for tracking).

        Returns:
            OCRResult with text, confidence, and content type.
        """
        import time
        start = time.time()

        result = OCRResult(page_number=page_number, ocr_engine=self.backend)

        try:
            if self.backend == "tesseract":
                result = self._process_tesseract(image, page_number)
            elif self.backend == "easyocr":
                result = self._process_easyocr(image, page_number)
            else:
                raise ValueError(f"Unsupported OCR backend: {self.backend}")
        except Exception as e:
            logger.error(f"OCR processing failed for page {page_number}: {e}")
            result.text = ""
            result.confidence = 0.0

        result.processing_time_ms = (time.time() - start) * 1000
        result.content_type = self.detect_content_type(image, result)

        return result

    def process_pdf_page(self, pdf_path: str, page_num: int) -> OCRResult:
        """
        Extract a specific page from a PDF as image and run OCR.

        Args:
            pdf_path: Path to the PDF file.
            page_num: 1-indexed page number.

        Returns:
            OCRResult with extracted text.
        """
        try:
            from pdf2image import convert_from_path
        except ImportError:
            raise ImportError(
                "pdf2image is required for OCR on PDF pages. "
                "Run: pip install pdf2image\n"
                "Also requires poppler: https://github.com/oschwartz10612/poppler-windows/releases"
            )

        images = convert_from_path(
            pdf_path,
            first_page=page_num,
            last_page=page_num,
            dpi=300,
        )

        if not images:
            return OCRResult(page_number=page_num)

        return self.process_image(images[0], page_number=page_num)

    def process_pdf(self, pdf_path: str) -> OCRReport:
        """
        Process an entire PDF with OCR and generate a report.

        Args:
            pdf_path: Path to the PDF file.

        Returns:
            OCRReport with per-page results.
        """
        try:
            from pdf2image import convert_from_path
        except ImportError:
            raise ImportError("pdf2image is required. Run: pip install pdf2image")

        pdf_path = Path(pdf_path)
        report = OCRReport(
            source_file=pdf_path.name,
            processed_at=datetime.now(timezone.utc).isoformat(),
        )

        logger.info(f"Running full OCR on: {pdf_path.name}")

        # Convert all pages to images
        images = convert_from_path(str(pdf_path), dpi=300)
        report.total_pages_processed = len(images)

        for i, image in enumerate(images):
            page_num = i + 1
            result = self.process_image(image, page_number=page_num)

            report.pages.append({
                "page": page_num,
                "type": result.content_type.value,
                "confidence": round(result.confidence, 2),
                "ocr_engine": result.ocr_engine,
                "chars_extracted": len(result.text),
                "processing_time_ms": round(result.processing_time_ms, 1),
            })

            if page_num % 20 == 0:
                logger.info(f"  OCR processed {page_num}/{len(images)} pages")

        logger.info(f"  OCR complete: {len(images)} pages processed")
        return report

    def detect_content_type(self, image, ocr_result: OCRResult) -> ContentType:
        """
        Detect the type of content on a page.

        Uses heuristics based on text density and character patterns.
        """
        text = ocr_result.text.strip()

        if len(text) < 10:
            return ContentType.EMPTY

        # Heuristic: very low confidence often indicates handwriting
        if ocr_result.confidence < 40:
            return ContentType.HANDWRITTEN

        # Heuristic: check ratio of alphabetic chars to total
        alpha_ratio = sum(1 for c in text if c.isalpha()) / max(len(text), 1)

        if alpha_ratio < 0.3:
            return ContentType.DIAGRAM

        if ocr_result.confidence < 70:
            return ContentType.MIXED

        return ContentType.PRINTED_TEXT

    def _process_tesseract(self, image, page_number: int) -> OCRResult:
        """Process image with Tesseract OCR."""
        import pytesseract
        from Generating.OCR.ocr_utils import preprocess_for_ocr

        # Preprocess image for better OCR accuracy
        processed = preprocess_for_ocr(image)

        # Get detailed OCR data (includes confidence scores)
        ocr_data = pytesseract.image_to_data(
            processed, lang=self.language, output_type=pytesseract.Output.DICT
        )

        # Extract text
        text = pytesseract.image_to_string(processed, lang=self.language)

        # Calculate average confidence (exclude -1 values which mean no text)
        confidences = [
            int(c) for c in ocr_data["conf"] if int(c) >= 0
        ]
        avg_confidence = sum(confidences) / max(len(confidences), 1)

        return OCRResult(
            text=text.strip(),
            confidence=avg_confidence,
            ocr_engine="tesseract",
            page_number=page_number,
        )

    def _process_easyocr(self, image, page_number: int) -> OCRResult:
        """Process image with EasyOCR (better for handwriting)."""
        import numpy as np

        if self._easyocr_reader is None:
            import easyocr
            lang_map = {"eng": "en", "ara": "ar"}
            lang = lang_map.get(self.language, self.language)
            self._easyocr_reader = easyocr.Reader([lang])

        # Convert PIL Image to numpy array
        img_array = np.array(image)

        # Run EasyOCR
        results = self._easyocr_reader.readtext(img_array)

        # Extract text and confidence
        texts = []
        confidences = []
        for (bbox, text, conf) in results:
            texts.append(text)
            confidences.append(conf)

        full_text = " ".join(texts)
        avg_confidence = (
            sum(confidences) / len(confidences) * 100
            if confidences else 0
        )

        return OCRResult(
            text=full_text,
            confidence=avg_confidence,
            ocr_engine="easyocr",
            page_number=page_number,
        )
