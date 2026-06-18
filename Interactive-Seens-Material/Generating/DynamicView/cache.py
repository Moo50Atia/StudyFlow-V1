"""
Dynamic View Cache — Avoids regenerating identical Dynamic View prompts.

Uses content hashing to detect duplicate sections and reuse previously
generated prompts, reducing API costs.
"""

import hashlib
import json
import logging
from pathlib import Path

from Generating.config import DV_CACHE_ENABLED

logger = logging.getLogger(__name__)


class DynamicViewCache:
    """
    Cache for Dynamic View prompts.

    Uses SHA-256 hash of section content to detect duplicates.

    Usage:
        cache = DynamicViewCache(output_dir)
        cached = cache.get(section_content)
        if cached:
            prompt = cached
        else:
            prompt = generate_prompt(...)
            cache.set(section_content, prompt)
    """

    def __init__(self, output_dir: str):
        self.output_dir = Path(output_dir)
        self.cache_path = self.output_dir / "dynamic_view_cache.json"
        self._cache = {}
        self._hits = 0
        self._misses = 0

        if DV_CACHE_ENABLED:
            self._load()

    def _load(self):
        """Load cache from disk."""
        if self.cache_path.exists():
            try:
                with open(self.cache_path, "r", encoding="utf-8") as f:
                    data = json.load(f)
                self._cache = data.get("entries", {})
                logger.info(f"DV cache loaded: {len(self._cache)} entries")
            except Exception as e:
                logger.warning(f"Failed to load DV cache: {e}")
                self._cache = {}

    def save(self):
        """Persist cache to disk."""
        if not DV_CACHE_ENABLED:
            return

        self.output_dir.mkdir(parents=True, exist_ok=True)
        data = {
            "total_entries": len(self._cache),
            "hits": self._hits,
            "misses": self._misses,
            "entries": self._cache,
        }
        with open(self.cache_path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        logger.info(
            f"DV cache saved: {len(self._cache)} entries "
            f"(hits: {self._hits}, misses: {self._misses})"
        )

    def get(self, section_content: str) -> dict | None:
        """
        Look up a cached prompt for a section's content.

        Args:
            section_content: The section content to look up.

        Returns:
            Cached prompt dict if found, None otherwise.
        """
        if not DV_CACHE_ENABLED:
            return None

        content_hash = self._hash(section_content)

        if content_hash in self._cache:
            self._hits += 1
            logger.debug(f"DV cache HIT: {content_hash[:12]}...")
            return self._cache[content_hash]

        self._misses += 1
        return None

    def set(self, section_content: str, prompt_data: dict):
        """
        Store a generated prompt in the cache.

        Args:
            section_content: The section content (used as key).
            prompt_data: The generated prompt data to cache.
        """
        if not DV_CACHE_ENABLED:
            return

        content_hash = self._hash(section_content)
        self._cache[content_hash] = prompt_data

    def invalidate(self, section_content: str):
        """Remove a cached entry."""
        content_hash = self._hash(section_content)
        self._cache.pop(content_hash, None)

    def clear(self):
        """Clear the entire cache."""
        self._cache = {}
        self._hits = 0
        self._misses = 0
        if self.cache_path.exists():
            self.cache_path.unlink()

    def _hash(self, content: str) -> str:
        """Generate SHA-256 hash of content."""
        return hashlib.sha256(content.encode("utf-8")).hexdigest()

    @property
    def stats(self) -> dict:
        """Return cache statistics."""
        total = self._hits + self._misses
        return {
            "entries": len(self._cache),
            "hits": self._hits,
            "misses": self._misses,
            "hit_rate": round(self._hits / max(total, 1) * 100, 1),
        }
