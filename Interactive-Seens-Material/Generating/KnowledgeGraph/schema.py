"""
Knowledge Graph Schema — Pydantic models for knowledge_graph.json.
"""

from pydantic import BaseModel, Field
from typing import Optional


class GraphNode(BaseModel):
    """A concept node in the knowledge graph."""
    id: str = Field(..., description="Unique node ID")
    label: str = Field(..., description="Human-readable concept name")
    type: str = Field(
        "concept",
        description="Node type: concept, definition, formula, procedure, example"
    )
    chapter_id: str = Field("", description="Chapter this concept belongs to")
    lesson_ids: list[str] = Field(
        default_factory=list,
        description="Lessons where this concept appears"
    )
    importance: float = Field(
        0.5,
        description="Importance score 0-1 (based on frequency and centrality)"
    )


class GraphEdge(BaseModel):
    """A relationship edge between two concepts."""
    source: str = Field(..., description="Source node ID")
    target: str = Field(..., description="Target node ID")
    relation: str = Field(
        "related_to",
        description="Relationship type: prerequisite, contains, related_to, "
                     "leads_to, example_of, implements, contrasts_with"
    )
    weight: float = Field(1.0, description="Edge weight (strength of relationship)")


class KnowledgeGraph(BaseModel):
    """
    Complete knowledge graph for an educational material.

    Supports future features: mind maps, search, AI tutor,
    concept linking, and question mapping.
    """
    material: str = Field(..., description="Material name")
    total_nodes: int = Field(0, description="Total concept nodes")
    total_edges: int = Field(0, description="Total relationship edges")
    nodes: list[GraphNode] = Field(default_factory=list)
    edges: list[GraphEdge] = Field(default_factory=list)

    def count_totals(self):
        """Recompute total counts."""
        self.total_nodes = len(self.nodes)
        self.total_edges = len(self.edges)

    def get_neighbors(self, node_id: str) -> list[str]:
        """Get all nodes connected to a given node."""
        neighbors = set()
        for edge in self.edges:
            if edge.source == node_id:
                neighbors.add(edge.target)
            elif edge.target == node_id:
                neighbors.add(edge.source)
        return list(neighbors)

    def get_prerequisites(self, node_id: str) -> list[str]:
        """Get all prerequisite concepts for a given concept."""
        return [
            edge.source for edge in self.edges
            if edge.target == node_id and edge.relation == "prerequisite"
        ]
