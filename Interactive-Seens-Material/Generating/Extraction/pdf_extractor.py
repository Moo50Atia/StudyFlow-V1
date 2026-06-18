"""
PDF Extractor — Extracts text from PDFs with OCR fallback.

Replaces the legacy preprocessor.py. Key improvements:
- Per-page text density analysis to detect scanned pages
- Automatic OCR fallback for pages with insufficient text
- Generates extraction_metadata.json with per-page statistics
- No hardcoded assumptions about document structure
"""

import json
import logging
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)


class ExtractionResult:
    """Result of extracting text from a PDF."""

    def __init__(self):
        self.full_text: str = ""
        self.pages: list[dict] = []  # Per-page metadata
        self.total_pages: int = 0
        self.total_chars: int = 0
        self.ocr_pages: list[int] = []  # Page numbers that required OCR
        self.errors: list[str] = []

    def to_metadata_dict(self) -> dict:
        """Generate extraction_metadata.json content."""
        return {
            "extracted_at": datetime.now(timezone.utc).isoformat(),
            "total_pages": self.total_pages,
            "total_characters": self.total_chars,
            "pages_with_text": sum(1 for p in self.pages if p["has_text"]),
            "pages_needing_ocr": len(self.ocr_pages),
            "ocr_page_numbers": self.ocr_pages,
            "total_visual_assets_extracted": sum(len(p.get("visual_assets", [])) for p in self.pages),
            "errors": self.errors,
            "pages": self.pages,
        }


class PDFExtractor:
    """
    Extracts text from PDF files with intelligent OCR fallback.

    Usage:
        extractor = PDFExtractor()
        result = extractor.extract("path/to/document.pdf")
        result.full_text  # The complete extracted text
    """

    def __init__(
        self,
        text_density_threshold: int = 50,
        ocr_backend: str = "tesseract",
        ocr_language: str = "eng",
    ):
        """
        Args:
            text_density_threshold: Min chars per page to consider it text-based.
                                    Pages below this trigger OCR.
            ocr_backend: OCR engine to use ("tesseract" or "easyocr").
            ocr_language: Language for OCR processing.
        """
        self.text_density_threshold = text_density_threshold
        self.ocr_backend = ocr_backend
        self.ocr_language = ocr_language

        # Lazy-loaded OCR processor
        self._ocr_processor = None

    def extract(self, pdf_path: str, output_dir: Optional[str] = None) -> ExtractionResult:
        """
        Extract text from a PDF file.

        Args:
            pdf_path: Path to the PDF file.
            output_dir: Directory to save extracted_text.txt and extraction_metadata.json.
                        If None, files are not saved (only returned).

        Returns:
            ExtractionResult with full text and per-page metadata.
        """
        try:
            import pdfplumber
        except ImportError:
            raise ImportError(
                "pdfplumber is required for PDF extraction. "
                "Run: pip install pdfplumber"
            )

        pdf_path = Path(pdf_path)
        if not pdf_path.exists():
            raise FileNotFoundError(f"PDF not found: {pdf_path}")

        result = ExtractionResult()
        pages_text = []

        logger.info(f"Extracting text from: {pdf_path.name}")

        with pdfplumber.open(str(pdf_path)) as pdf:
            result.total_pages = len(pdf.pages)
            logger.info(f"  Total pages: {result.total_pages}")

            for i, page in enumerate(pdf.pages):
                page_num = i + 1
                page_text = ""
                extraction_method = "pdfplumber"
                char_count = 0

                # Attempt direct text extraction
                try:
                    raw_text = page.extract_text()
                    if raw_text:
                        page_text = raw_text
                        char_count = len(page_text)
                except Exception as e:
                    result.errors.append(f"Page {page_num}: pdfplumber error: {e}")
                    logger.warning(f"  Page {page_num}: pdfplumber failed: {e}")

                # Check if OCR is needed
                if char_count < self.text_density_threshold:
                    logger.info(
                        f"  Page {page_num}: Low text density ({char_count} chars). "
                        f"Attempting OCR..."
                    )
                    try:
                        ocr_text = self._run_ocr_on_page(str(pdf_path), page_num)
                        if ocr_text and len(ocr_text) > char_count:
                            page_text = ocr_text
                            char_count = len(page_text)
                            extraction_method = f"ocr_{self.ocr_backend}"
                            result.ocr_pages.append(page_num)
                    except Exception as e:
                        result.errors.append(f"Page {page_num}: OCR error: {e}")
                        logger.warning(f"  Page {page_num}: OCR failed: {e}")

                # Extract visual assets
                extracted_images = []
                if output_dir:
                    output_dir_path = Path(output_dir)
                    try:
                        if page.images:
                            assets_dir = output_dir_path / "assets"
                            assets_dir.mkdir(parents=True, exist_ok=True)
                            
                            for img_idx, img_obj in enumerate(page.images):
                                try:
                                    # Use bounding box to crop the page image
                                    bbox = (img_obj["x0"], img_obj["top"], img_obj["x1"], img_obj["bottom"])
                                    # Avoid invalid bounding boxes
                                    if bbox[0] >= bbox[2] or bbox[1] >= bbox[3]:
                                        continue
                                        
                                    cropped = page.crop(bbox).to_image(resolution=150)
                                    img_filename = f"page_{page_num}_fig_{img_idx+1}.png"
                                    img_path = assets_dir / img_filename
                                    cropped.save(str(img_path))
                                    
                                    # Store relative to Materials dir: e.g. "CardioLecture/assets/..."
                                    # output_dir is "Materials/CardioLecture", so parent is "Materials"
                                    try:
                                        rel_path = img_path.relative_to(output_dir_path.parent)
                                        extracted_images.append(str(rel_path).replace("\\", "/"))
                                    except ValueError:
                                        extracted_images.append(str(img_path).replace("\\", "/"))
                                        
                                except Exception as e:
                                    logger.warning(f"  Page {page_num}: Failed to crop image {img_idx}: {e}")
                    except Exception as e:
                        logger.warning(f"  Page {page_num}: Image extraction failed: {e}")

                pages_text.append(page_text)
                result.pages.append({
                    "page": page_num,
                    "chars": char_count,
                    "has_text": char_count >= self.text_density_threshold,
                    "extraction_method": extraction_method,
                    "visual_assets": extracted_images,
                })

                # Progress reporting
                if page_num % 50 == 0 or page_num == result.total_pages:
                    logger.info(f"  Processed {page_num}/{result.total_pages} pages")

        # Assemble full text with page markers
        result.full_text = "\n\n".join(pages_text)
        result.total_chars = len(result.full_text)

        logger.info(
            f"  Extraction complete: {result.total_chars} chars, "
            f"{len(result.ocr_pages)} pages needed OCR"
        )

        # Save outputs if output_dir is provided
        if output_dir:
            output_dir = Path(output_dir)
            output_dir.mkdir(parents=True, exist_ok=True)

            # Save extracted text
            text_path = output_dir / "extracted_text.txt"
            with open(text_path, "w", encoding="utf-8") as f:
                f.write(result.full_text)
            logger.info(f"  Saved: {text_path}")

            # Save metadata
            meta_path = output_dir / "extraction_metadata.json"
            with open(meta_path, "w", encoding="utf-8") as f:
                json.dump(result.to_metadata_dict(), f, indent=2, ensure_ascii=False)
            logger.info(f"  Saved: {meta_path}")

        return result

    def _run_ocr_on_page(self, pdf_path: str, page_num: int) -> str:
        """Run OCR on a specific page of the PDF."""
        if self._ocr_processor is None:
            from Generating.OCR.ocr_processor import OCRProcessor
            self._ocr_processor = OCRProcessor(
                backend=self.ocr_backend,
                language=self.ocr_language,
            )

        ocr_result = self._ocr_processor.process_pdf_page(pdf_path, page_num)
        return ocr_result.text if ocr_result else ""


def extract_pdf(
    pdf_path: str,
    output_dir: str,
    text_density_threshold: int = 50,
    ocr_backend: str = "tesseract",
    ocr_language: str = "eng",
) -> ExtractionResult:
    """
    Convenience function to extract text from a PDF.

    This is the main entry point for Stage 1-2 of the pipeline.
    """
    extractor = PDFExtractor(
        text_density_threshold=text_density_threshold,
        ocr_backend=ocr_backend,
        ocr_language=ocr_language,
    )
    return extractor.extract(pdf_path, output_dir)
