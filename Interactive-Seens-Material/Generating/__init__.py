"""
Generating Pipeline — Universal Educational Content Generation System.

A scalable, JSON-first pipeline for transforming arbitrary educational PDFs
into structured, interactive learning materials with Dynamic View support.

Pipeline Stages:
    1. PDF Intake + Text Extraction
    2. OCR Detection & Processing
    3. Text Chunking
    4. Route Detection (AI-based domain classification)
    5. Structure Extraction (Chapter → Mini Chapter → Lesson)
    6. Knowledge Graph Construction
    7. Question Extraction & Mapping
    8. Educational Section Generation
    9. Validation (Coverage + Visualization Readiness)
   10. Dynamic View Mapping
   11. Dynamic View Prompt Generation
   12. Manifest Update
"""

__version__ = "2.0.0"
__author__ = "StudyFlow"
