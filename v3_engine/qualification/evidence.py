"""
Evidence assembler helper in Python matching Laravel SceneEvidenceAssembler.
"""

import hashlib
import json
from typing import Any, Dict

def compute_evidence_digest(evidence: Dict[str, Any]) -> str:
    serialized = json.dumps(evidence, sort_keys=True).encode("utf-8")
    return hashlib.sha256(serialized).hexdigest()
