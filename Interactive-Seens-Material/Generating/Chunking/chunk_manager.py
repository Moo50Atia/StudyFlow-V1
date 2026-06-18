"""
Chunk Manager — Splits large documents into LLM-safe chunks.

Never relies on Gemini context window size. Large books, multi-volume texts,
and small lectures are all handled uniformly via configurable chunking.
"""

import json
import logging
from pathlib import Path
from typing import Optional

from Generating.Chunking.chunk_schema import Chunk, ChunkManifest
from Generating.config import CHARS_PER_TOKEN, CHUNK_OVERLAP, CHUNK_TARGET_SIZE

logger = logging.getLogger(__name__)


class ChunkManager:
    """
    Splits extracted text into chunks suitable for LLM processing.

    Chunks respect page boundaries when possible and include overlap
    to preserve context at boundaries.

    Usage:
        manager = ChunkManager()
        manifest = manager.chunk_text(text, page_breaks, "document.pdf")
        for chunk in manifest.chunks:
            chunk_text = manager.get_chunk_text(text, chunk)
    """

    def __init__(
        self,
        target_size: Optional[int] = None,
        overlap: Optional[int] = None,
        chars_per_token: Optional[float] = None,
    ):
        """
        Args:
            target_size: Target chunk size in characters.
            overlap: Overlap between chunks in characters.
            chars_per_token: Approximate characters per token for estimation.
        """
        self.target_size = target_size or CHUNK_TARGET_SIZE
        self.overlap = overlap or CHUNK_OVERLAP
        self.chars_per_token = chars_per_token or CHARS_PER_TOKEN

    def chunk_text(
        self,
        text: str,
        page_char_offsets: Optional[list[dict]] = None,
        source_file: str = "unknown",
    ) -> ChunkManifest:
        """
        Split text into chunks, respecting page boundaries when possible.

        Args:
            text: The full extracted text.
            page_char_offsets: List of dicts with 'page', 'start', 'end' for each page.
                               If None, chunking is purely character-based.
            source_file: Name of the source file (for metadata).

        Returns:
            ChunkManifest with all chunks defined.
        """
        total_chars = len(text)

        # If text fits in a single chunk, return one chunk
        if total_chars <= self.target_size:
            total_pages = len(page_char_offsets) if page_char_offsets else 1
            chunk = Chunk(
                id="chunk_001",
                start_page=1,
                end_page=total_pages,
                char_count=total_chars,
                token_estimate=self._estimate_tokens(total_chars),
                start_char_offset=0,
                end_char_offset=total_chars,
            )
            manifest = ChunkManifest(
                source_file=source_file,
                total_chunks=1,
                total_characters=total_chars,
                total_pages=total_pages,
                chunk_target_size=self.target_size,
                chunk_overlap=self.overlap,
                chunks=[chunk],
            )
            logger.info(
                f"Text fits in single chunk ({total_chars} chars, "
                f"~{chunk.token_estimate} tokens)"
            )
            return manifest

        # Build chunks with page-awareness if page offsets are available
        if page_char_offsets:
            return self._chunk_by_pages(text, page_char_offsets, source_file)
        else:
            return self._chunk_by_characters(text, source_file)

    def _chunk_by_pages(
        self, text: str, page_offsets: list[dict], source_file: str
    ) -> ChunkManifest:
        """
        Split text into chunks aligned to page boundaries.

        Accumulates pages until the target size is reached, then starts
        a new chunk with overlap from the previous chunk's last pages.
        """
        chunks = []
        chunk_num = 0
        current_start_page = 1
        current_start_offset = 0
        current_char_count = 0

        for page_info in page_offsets:
            page_num = page_info["page"]
            page_start = page_info["start"]
            page_end = page_info["end"]
            page_chars = page_end - page_start

            # If adding this page exceeds target, create chunk (unless it's the first page)
            if current_char_count + page_chars > self.target_size and current_char_count > 0:
                chunk_num += 1
                chunks.append(Chunk(
                    id=f"chunk_{chunk_num:03d}",
                    start_page=current_start_page,
                    end_page=page_num - 1,
                    char_count=current_char_count,
                    token_estimate=self._estimate_tokens(current_char_count),
                    start_char_offset=current_start_offset,
                    end_char_offset=page_start,
                ))

                # Start new chunk with overlap
                overlap_start = max(current_start_offset, page_start - self.overlap)
                # Find the page that contains the overlap start
                overlap_page = current_start_page
                for p in page_offsets:
                    if p["start"] <= overlap_start < p["end"]:
                        overlap_page = p["page"]
                        break

                current_start_page = overlap_page
                current_start_offset = overlap_start
                current_char_count = page_start - overlap_start

            current_char_count += page_chars

        # Final chunk
        if current_char_count > 0:
            chunk_num += 1
            chunks.append(Chunk(
                id=f"chunk_{chunk_num:03d}",
                start_page=current_start_page,
                end_page=page_offsets[-1]["page"],
                char_count=current_char_count,
                token_estimate=self._estimate_tokens(current_char_count),
                start_char_offset=current_start_offset,
                end_char_offset=len(text),
            ))

        total_pages = page_offsets[-1]["page"] if page_offsets else 1

        manifest = ChunkManifest(
            source_file=source_file,
            total_chunks=len(chunks),
            total_characters=len(text),
            total_pages=total_pages,
            chunk_target_size=self.target_size,
            chunk_overlap=self.overlap,
            chunks=chunks,
        )

        logger.info(
            f"Chunked into {len(chunks)} chunks "
            f"(target: {self.target_size} chars, overlap: {self.overlap} chars)"
        )
        return manifest

    def _chunk_by_characters(self, text: str, source_file: str) -> ChunkManifest:
        """
        Split text into chunks by character count (no page awareness).

        Tries to break at paragraph boundaries (double newlines) near
        the target size for cleaner chunks.
        """
        chunks = []
        chunk_num = 0
        pos = 0
        total_chars = len(text)

        while pos < total_chars:
            # Determine end position for this chunk
            end = min(pos + self.target_size, total_chars)

            # Try to find a paragraph break near the end
            if end < total_chars:
                # Search backward from end for a good break point
                search_start = max(end - 500, pos)
                break_pos = text.rfind('\n\n', search_start, end)
                if break_pos > pos:
                    end = break_pos + 2  # Include the double newline

            chunk_num += 1
            chunk_text = text[pos:end]
            chunks.append(Chunk(
                id=f"chunk_{chunk_num:03d}",
                start_page=0,  # Unknown without page offsets
                end_page=0,
                char_count=len(chunk_text),
                token_estimate=self._estimate_tokens(len(chunk_text)),
                start_char_offset=pos,
                end_char_offset=end,
            ))

            # Move position forward, accounting for overlap
            pos = max(pos + 1, end - self.overlap)

        manifest = ChunkManifest(
            source_file=source_file,
            total_chunks=len(chunks),
            total_characters=total_chars,
            total_pages=0,
            chunk_target_size=self.target_size,
            chunk_overlap=self.overlap,
            chunks=chunks,
        )

        logger.info(f"Chunked into {len(chunks)} chunks (character-based)")
        return manifest

    def get_chunk_text(self, full_text: str, chunk: Chunk) -> str:
        """Extract the text for a specific chunk from the full document text."""
        return full_text[chunk.start_char_offset:chunk.end_char_offset]

    def save_manifest(self, manifest: ChunkManifest, output_dir: str):
        """Save chunk manifest to JSON."""
        output_path = Path(output_dir) / "chunk_manifest.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(manifest.model_dump(), f, indent=2, ensure_ascii=False)
        logger.info(f"Saved chunk manifest: {output_path}")

    def load_manifest(self, output_dir: str) -> ChunkManifest:
        """Load chunk manifest from JSON."""
        manifest_path = Path(output_dir) / "chunk_manifest.json"
        with open(manifest_path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return ChunkManifest.model_validate(data)

    def _estimate_tokens(self, char_count: int) -> int:
        """Estimate token count from character count."""
        return int(char_count / self.chars_per_token)
