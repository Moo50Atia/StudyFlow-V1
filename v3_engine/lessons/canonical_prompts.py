import hashlib
from typing import Dict

from v3_engine.notebooklm.protocol_bundle import load_local_worker_protocol

_PROTOCOL = load_local_worker_protocol()
DIVIDE_PROMPT = _PROTOCOL["divide_prompt"]
EXECUTION_PROMPT = _PROTOCOL["execution_prompt"]
FIRST_PROMPT = _PROTOCOL["framework_sections"]["first"]
SECOND_PROMPT = _PROTOCOL["framework_sections"]["second"]
THIRD_PROMPT = _PROTOCOL["framework_sections"]["third"]

CANONICAL_DIGESTS: Dict[str, str] = {
    "divide": "77620579df1bb2fb51876116b1266a32fe58ecd963486c3d76d5c98c46d012ff",
    "execution": "5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9",
    "first": "04450e9fd7e0962ceb4f60a1f641e43b671d7be2622718e202af0ca92683b788",
    "second": "a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203",
    "third": "07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955",
}

def verify_digests() -> bool:
    prompts = {
        "divide": DIVIDE_PROMPT,
        "execution": EXECUTION_PROMPT,
        "first": FIRST_PROMPT,
        "second": SECOND_PROMPT,
        "third": THIRD_PROMPT,
    }
    for key, text in prompts.items():
        computed = hashlib.sha256(text.encode("utf-8")).hexdigest()
        if computed != CANONICAL_DIGESTS[key]:
            return False
    return True

# Ensure digests are valid on import
assert verify_digests(), "Canonical prompt digests mismatch!"
