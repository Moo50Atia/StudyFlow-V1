"""
Chunk Schema — Pydantic models for chunk_manifest.json.
"""

from pydantic import BaseModel, Field
from typing import Optional


class Chunk(BaseModel):
    """A single chunk of text from a document."""
    id: str = Field(..., description="Unique chunk ID, e.g., 'chunk_001'")
    start_page: int = Field(..., description="First page number in this chunk (1-indexed)")
    end_page: int = Field(..., description="Last page number in this chunk (1-indexed)")
    char_count: int = Field(..., description="Number of characters in this chunk")
    token_estimate: int = Field(..., description="Estimated token count")
    start_char_offset: int = Field(0, description="Start character offset in full text")
    end_char_offset: int = Field(0, description="End character offset in full text")


class ChunkManifest(BaseModel):
    """Complete chunk manifest for a document."""
    source_file: str = Field(..., description="Name of the source file that was chunked")
    total_chunks: int = Field(..., description="Total number of chunks")
    total_characters: int = Field(..., description="Total characters across all chunks")
    total_pages: int = Field(..., description="Total pages in the source document")
    chunk_target_size: int = Field(..., description="Target chunk size in characters")
    chunk_overlap: int = Field(..., description="Overlap between chunks in characters")
    chunks: list[Chunk] = Field(default_factory=list)
