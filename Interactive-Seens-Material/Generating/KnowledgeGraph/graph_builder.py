"""
Knowledge Graph Builder — Constructs concept relationship graphs.

Takes the structure.json and extracted text, then uses AI to identify
key concepts and their relationships. Outputs knowledge_graph.json.

Future support: mind maps, search, AI tutor, concept linking, question mapping.
"""

import json
import logging
from pathlib import Path

from Generating.KnowledgeGraph.schema import GraphEdge, GraphNode, KnowledgeGraph
from Generating.Structure.schema import MaterialStructure

logger = logging.getLogger(__name__)


GRAPH_SYSTEM_PROMPT = """You are a Knowledge Graph Architect for educational materials.
You identify key concepts, definitions, formulas, and procedures, then map
the relationships between them. Output ONLY valid JSON."""


class GraphBuilder:
    """
    Builds a knowledge graph from educational material structure and text.

    Usage:
        builder = GraphBuilder(ai_client)
        graph = builder.build(structure, text)
    """

    def __init__(self, ai_client):
        self.ai_client = ai_client

    def build(
        self,
        structure: MaterialStructure,
        text: str,
        chunk_texts: list[str] = None,
    ) -> KnowledgeGraph:
        """
        Build a knowledge graph from the material.

        Args:
            structure: The extracted material structure.
            text: The full extracted text.
            chunk_texts: Optional list of text chunks for large documents.

        Returns:
            KnowledgeGraph with nodes and edges.
        """
        logger.info("Building knowledge graph...")

        # Process by chapter to keep prompts manageable
        all_nodes = []
        all_edges = []
        node_id_counter = 0

        for chapter in structure.chapters:
            # Get the text content for this chapter's page range
            chapter_text = self._get_chapter_text(chapter, text, chunk_texts)

            if not chapter_text.strip():
                continue

            # Extract concepts and relationships for this chapter
            prompt = self._build_chapter_prompt(chapter, chapter_text)

            try:
                data = self.ai_client.generate_json(prompt, GRAPH_SYSTEM_PROMPT)

                # Process nodes
                for node_data in data.get("nodes", []):
                    node_id_counter += 1
                    lesson_ids = []
                    for mc in chapter.mini_chapters:
                        for lesson in mc.lessons:
                            lesson_ids.append(lesson.id)

                    all_nodes.append(GraphNode(
                        id=f"N{node_id_counter}",
                        label=node_data.get("label", ""),
                        type=node_data.get("type", "concept"),
                        chapter_id=chapter.id,
                        lesson_ids=node_data.get("lesson_ids", lesson_ids[:1]),
                        importance=float(node_data.get("importance", 0.5)),
                    ))

                # Process edges
                for edge_data in data.get("edges", []):
                    all_edges.append(GraphEdge(
                        source=edge_data.get("source", ""),
                        target=edge_data.get("target", ""),
                        relation=edge_data.get("relation", "related_to"),
                        weight=float(edge_data.get("weight", 1.0)),
                    ))

                logger.info(
                    f"  {chapter.id}: {len(data.get('nodes', []))} concepts, "
                    f"{len(data.get('edges', []))} relationships"
                )

            except Exception as e:
                logger.warning(f"  {chapter.id}: Graph extraction failed: {e}")

        # Build the final graph
        graph = KnowledgeGraph(
            material=structure.material,
            nodes=all_nodes,
            edges=all_edges,
        )
        graph.count_totals()

        # Remap edge references to use the final node IDs
        graph = self._remap_edges(graph)

        logger.info(
            f"Knowledge graph built: {graph.total_nodes} nodes, "
            f"{graph.total_edges} edges"
        )

        return graph

    def _get_chapter_text(
        self, chapter, text: str, chunk_texts: list[str] = None
    ) -> str:
        """Extract text for a specific chapter based on page references."""
        # Get page range from lessons
        pages = []
        for mc in chapter.mini_chapters:
            for lesson in mc.lessons:
                if lesson.page_start > 0:
                    pages.extend(range(lesson.page_start, lesson.page_end + 1))

        if not pages:
            # Fallback: use a portion of the text
            chapter_idx = int(chapter.id.replace("ch", "")) - 1
            total_chapters = 10  # Rough estimate
            chunk_size = len(text) // max(total_chapters, 1)
            start = chapter_idx * chunk_size
            end = start + chunk_size
            return text[start:end]

        # Use the full text with page markers if available
        # For now, return a proportional slice
        if pages:
            total_pages_est = max(pages) if pages else 100
            start_ratio = min(pages) / max(total_pages_est, 1)
            end_ratio = max(pages) / max(total_pages_est, 1)
            start_char = int(start_ratio * len(text))
            end_char = int(end_ratio * len(text))
            return text[start_char:end_char]

        return ""

    def _build_chapter_prompt(self, chapter, chapter_text: str) -> str:
        """Build the prompt for extracting concepts from a chapter."""
        lesson_list = []
        for mc in chapter.mini_chapters:
            for lesson in mc.lessons:
                lesson_list.append(f"  - {lesson.id}: {lesson.title}")
        lessons_str = "\n".join(lesson_list)

        return f"""Analyze this chapter and extract key concepts and their relationships.

CHAPTER: {chapter.title}
LESSONS IN THIS CHAPTER:
{lessons_str}

CHAPTER TEXT:
{chapter_text[:8000]}

Extract:
1. KEY CONCEPTS — Important terms, definitions, formulas, procedures, examples
2. RELATIONSHIPS — How concepts relate: prerequisite, contains, related_to,
   leads_to, example_of, implements, contrasts_with

For each concept node, provide:
- label: The concept name
- type: One of [concept, definition, formula, procedure, example]
- importance: 0.0 to 1.0 (how central is this concept)
- lesson_ids: Which lesson IDs this concept appears in

For each edge, provide:
- source: Source concept label
- target: Target concept label
- relation: Type of relationship
- weight: Strength of relationship (0.0 to 1.0)

OUTPUT — Valid JSON:
{{
    "nodes": [
        {{"label": "...", "type": "concept", "importance": 0.8, "lesson_ids": ["L1"]}},
        ...
    ],
    "edges": [
        {{"source": "ConceptA", "target": "ConceptB", "relation": "prerequisite", "weight": 0.9}},
        ...
    ]
}}"""

    def _remap_edges(self, graph: KnowledgeGraph) -> KnowledgeGraph:
        """Remap edge source/target from labels to node IDs."""
        label_to_id = {node.label.lower(): node.id for node in graph.nodes}

        valid_edges = []
        for edge in graph.edges:
            source_id = label_to_id.get(edge.source.lower(), "")
            target_id = label_to_id.get(edge.target.lower(), "")

            if source_id and target_id and source_id != target_id:
                valid_edges.append(GraphEdge(
                    source=source_id,
                    target=target_id,
                    relation=edge.relation,
                    weight=edge.weight,
                ))

        graph.edges = valid_edges
        graph.count_totals()
        return graph

    def save_graph(self, graph: KnowledgeGraph, output_dir: str):
        """Save knowledge graph to JSON."""
        output_path = Path(output_dir) / "knowledge_graph.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(graph.model_dump(), f, indent=2, ensure_ascii=False)
        logger.info(f"Saved knowledge graph: {output_path}")

    def load_graph(self, output_dir: str) -> KnowledgeGraph:
        """Load knowledge graph from JSON."""
        graph_path = Path(output_dir) / "knowledge_graph.json"
        with open(graph_path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return KnowledgeGraph.model_validate(data)
