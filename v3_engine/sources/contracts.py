"""V3 Engine Knowledge Source Adapter contracts."""
from typing import Any, Dict, List, Optional
from pydantic import BaseModel, Field

class PageReport(BaseModel):
    page: int
    method: str
    chars: int = 0
    ocr_confidence: Optional[float] = None
    gap_code: Optional[str] = None

class ExtractionResult(BaseModel):
    status: str
    text: Optional[str] = None
    text_sha256: Optional[str] = None
    page_count: int = 0
    ocr_page_count: int = 0
    page_report: List[PageReport] = Field(default_factory=list)
    error_code: Optional[str] = None
    extractor_version: str = "0.1.0"
