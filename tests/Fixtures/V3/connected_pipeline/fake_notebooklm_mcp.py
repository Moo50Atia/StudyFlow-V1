"""Minimal deterministic MCP JSON-RPC server used only by the offline gate."""
import json
import os
import sys
import uuid

STATE = os.environ["V3_TEST_MCP_STATE"]


def load_state():
    try:
        with open(STATE, encoding="utf-8") as handle:
            return json.load(handle)
    except FileNotFoundError:
        return {"notebooks": {}, "sources": {}}


def save_state(state):
    temp = STATE + ".tmp"
    with open(temp, "w", encoding="utf-8") as handle:
        json.dump(state, handle, ensure_ascii=False)
    os.replace(temp, STATE)


def result(request_id, value):
    print(json.dumps({"jsonrpc": "2.0", "id": request_id, "result": value}), flush=True)


def tool_result(request_id, value):
    result(request_id, {"content": [{"type": "text", "text": json.dumps(value, ensure_ascii=False)}]})


def answer_for(query):
    if "COVERAGE CHECK v1" in query:
        return "PAGE 1: W1"
    if "CANONICAL FIRST BODY" in query or query.strip() == "Continue":
        ordinal = 1
        for token in query.split():
            if token.startswith("W") and token[1:].isdigit():
                ordinal = int(token[1:])
                break
        return (
            f"Lecture: W{ordinal} | Connected Pipeline Sample\n"
            "Section 1:\n"
            "Section Identity: Pendulum motion\n"
            "Core Understanding: Periodic motion repeats over time.\n"
            "Visual-First Explanation: Follow the pendulum as it swings.\n"
            "Visual Scene Script: Show a bob moving between two positions.\n"
            "Dynamic View Blueprint: A pendulum with a visible phase marker.\n"
            "Visual Learning Outcome: Relate phase to position.\n"
            "Concept Check: Where is speed greatest?\n"
            "Conceptual Summary: Motion changes while energy is conserved.\n"
            "• شرح للجزء بالعامية المصرية: البندول بيتحرك رايح جاي بشكل دوري.\n"
            "القوانين (إن وُجدت): T = 2π√(L/g)\n"
            "إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم: أرجوحة تتحرك ذهاباً وإياباً.\n"
            "✅ Lesson [1] Completed. Awaiting 'Continue' for the next lesson."
        )
    if query.strip() == "System Ready" or "EXECUTION PROTOCOL" in query:
        return "System Ready"
    if "Dynamic View prompt preparation engine" in query or "OUTPUT STRUCTURE (STRICT)" in query:
        return 'Lecture: Connected Pipeline Sample\n\nLec1 (Sec1):\n"Pendulum motion placeholder"'
    if "Now generate the final Google Dynamic View prompts" in query or "GLOBAL CONSTRAINTS" in query:
        return 'Lecture: Connected Pipeline Sample\n\nLec1 (Sec1):\n"Process a pendulum swinging between two positions; show phase and speed changing over time."'
    if "DIVIDE" in query.upper() or "Chapter" in query:
        return "Chapter: Oscillations\nLesson: Pendulum motion"
    return "Grounded response for Connected Pipeline Sample."


for raw in sys.stdin:
    request = json.loads(raw)
    method = request.get("method")
    request_id = request.get("id")
    if method == "initialize":
        result(request_id, {"protocolVersion": "2024-11-05", "capabilities": {"tools": {}}, "serverInfo": {"name": "offline-v3-fixture", "version": "1"}})
    elif method == "notifications/initialized":
        continue
    elif method == "tools/list":
        names = ["notebook_create", "notebook_list", "notebook_get", "notebook_add_text", "source_get_content", "notebook_query"]
        result(request_id, {"tools": [{"name": name, "inputSchema": {"type": "object"}} for name in names]})
    elif method == "tools/call":
        name = request["params"]["name"]
        args = request["params"].get("arguments", {})
        state = load_state()
        if name == "notebook_create":
            notebook_id = "nb-" + uuid.uuid4().hex
            state["notebooks"][notebook_id] = {"id": notebook_id, "title": args["title"], "sources": []}
            value = {"id": notebook_id}
        elif name == "notebook_list":
            value = list(state["notebooks"].values())
        elif name == "notebook_get":
            value = state["notebooks"].get(args["notebook_id"], {"id": args["notebook_id"], "sources": []})
        elif name == "notebook_add_text":
            source_id = "src-" + uuid.uuid4().hex
            src = {"id": source_id, "title": args["title"]}
            state["notebooks"][args["notebook_id"]]["sources"].append(src)
            state["sources"][source_id] = args["content"]
            value = {"id": source_id}
        elif name == "source_get_content":
            value = {"content": state["sources"][args["source_id"]]}
        elif name == "notebook_query":
            if not args.get("source_ids"):
                raise RuntimeError("queries must be source-scoped")
            value = {"answer": answer_for(args["query"]), "conversation_id": args.get("conversation_id") or "conversation-1"}
        else:
            raise RuntimeError("unexpected MCP tool " + name)
        save_state(state)
        tool_result(request_id, value)
    elif method:
        if request_id is not None:
            result(request_id, {})
