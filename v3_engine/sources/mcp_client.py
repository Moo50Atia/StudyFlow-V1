"""V3 Engine MCP Client for NotebookLM MCP (FR-004, FR-005 / K10, K17)."""

import asyncio
import json
import os
from pathlib import Path
from typing import Any, Dict, List, Optional

REQUIRED_TOOLS = [
    "notebook_create",
    "notebook_list",
    "notebook_get",
    "notebook_add_text",
    "source_get_content",
    "notebook_query",
]

class McpToolError(RuntimeError):
    """Safe diagnostic category; never includes provider response content."""

    def __init__(self, code: str):
        self.code = code
        super().__init__(f"NotebookLM MCP: {code}")


def _tool_error(payload: Any) -> McpToolError:
    text = str(payload).lower()
    code = "authentication_required" if any(x in text for x in (
        "401", "403", "authentication_required", "authentication failed", "authentication expired", "session expired", "no authentication tokens"
    )) else ("invalid_response" if "invalid_response" in text else "provider_error")
    return McpToolError(code)


class NotebookLmMcpClient:
    """Low-level MCP JSON-RPC 2.0 stdio client connecting to pinned notebooklm-mcp."""

    def __init__(self, command: Optional[List[str]] = None, env: Optional[Dict[str, str]] = None):
        self.env = dict(env if env is not None else os.environ)
        query_timeout = int(self.env.get("NOTEBOOKLM_QUERY_TIMEOUT_MS", "120000"))
        if not 1000 <= query_timeout <= 600000:
            raise ValueError("NOTEBOOKLM_QUERY_TIMEOUT_MS must be between 1000 and 600000")
        self.command = command or [
            "node", str(Path(__file__).resolve().parents[2] / "integrations/notebooklm-mcp/dist/cli.js"),
            "serve", "--query-timeout", str(query_timeout),
        ]
        self.env["NOTEBOOKLM_NO_BROWSER"] = "0" if self.env.get("NOTEBOOKLM_ALLOW_BROWSER_REFRESH") == "1" else "1"
        self._process: Optional[asyncio.subprocess.Process] = None
        self._req_id = 1
        self._lock = asyncio.Lock()

    async def _ensure_process(self):
        if self._process is None or self._process.returncode is not None:
            self._process = await asyncio.create_subprocess_exec(
                *self.command,
                stdin=asyncio.subprocess.PIPE,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.DEVNULL,
                env=self.env,
            )
            # Initialize MCP handshake
            init_req = {
                "jsonrpc": "2.0",
                "id": self._req_id,
                "method": "initialize",
                "params": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {},
                    "clientInfo": {"name": "v3_engine", "version": "0.1.0"},
                },
            }
            self._req_id += 1
            await self._send_raw(init_req)
            await self._read_response()

            # Notification initialized
            notif = {"jsonrpc": "2.0", "method": "notifications/initialized"}
            await self._send_raw(notif)

    async def _send_raw(self, msg: dict):
        line = json.dumps(msg) + "\n"
        self._process.stdin.write(line.encode("utf-8"))
        await self._process.stdin.drain()

    async def _read_response(self) -> dict:
        while True:
            line = await self._process.stdout.readline()
            if not line:
                raise RuntimeError("MCP process stdout closed unexpectedly")
            line_str = line.decode("utf-8").strip()
            if not line_str:
                continue
            data = json.loads(line_str)
            # Return response for request
            if "id" in data or "error" in data:
                return data

    async def call_tool(self, name: str, arguments: dict) -> Any:
        async with self._lock:
            # The MCP implementation reads its own auth.json cache. Require it
            # to match the same verified local profile used by the SDK adapter
            # before allowing a provider operation through this compatibility path.
            try:
                from v3_engine.notebooklm.credentials import NotebookCredentialAuthority
                if not NotebookCredentialAuthority().profile_status()["verified"]:
                    raise McpToolError("authentication_required")
            except McpToolError:
                raise
            except Exception:
                raise McpToolError("authentication_required") from None
            await self._ensure_process()
            req_id = self._req_id
            self._req_id += 1
            req = {
                "jsonrpc": "2.0",
                "id": req_id,
                "method": "tools/call",
                "params": {
                    "name": name,
                    "arguments": arguments,
                },
            }
            await self._send_raw(req)
            resp = await self._read_response()
            if "error" in resp:
                raise _tool_error(resp["error"])
            result = resp.get("result")
            if not isinstance(result, dict):
                raise McpToolError("invalid_response")
            if result.get("isError"):
                raise _tool_error(result)
            content = result.get("content", [])
            if not isinstance(content, list) or len(content) != 1 or not isinstance(content[0], dict):
                raise McpToolError("invalid_response")
            try:
                decoded = json.loads(content[0]["text"])
            except (KeyError, TypeError, ValueError):
                raise McpToolError("invalid_response") from None
            if isinstance(decoded, dict) and decoded.get("status") == "error":
                raise _tool_error(decoded)
            return decoded

    async def check_tools_schema(self) -> bool:
        async with self._lock:
            await self._ensure_process()
            req_id = self._req_id
            self._req_id += 1
            req = {"jsonrpc": "2.0", "id": req_id, "method": "tools/list", "params": {}}
            await self._send_raw(req)
            resp = await self._read_response()
            tools = resp.get("result", {}).get("tools", [])
            tool_names = {t.get("name") for t in tools}
            return all(rt in tool_names for rt in REQUIRED_TOOLS)

    async def notebook_create(self, title: str) -> dict:
        res = await self.call_tool("notebook_create", {"title": title})
        return self._notebook_result(res)

    @staticmethod
    def _notebook_result(res: Any) -> dict:
        notebook = res.get("notebook", res) if isinstance(res, dict) else None
        if not isinstance(notebook, dict) or not isinstance(notebook.get("id"), str) or not notebook["id"]:
            raise McpToolError("invalid_response")
        if not isinstance(notebook.get("title"), str) or not isinstance(notebook.get("sources"), list):
            raise McpToolError("invalid_response")
        return notebook

    async def notebook_list(self, max_results: int = 100) -> List[dict]:
        res = await self.call_tool("notebook_list", {})
        items = res.get("notebooks") if isinstance(res, dict) else res
        if not isinstance(items, list) or any(
            not isinstance(item, dict) or not isinstance(item.get("id"), str) or not item["id"]
            for item in items
        ):
            raise McpToolError("invalid_response")
        return items[:max_results]

    async def notebook_get(self, notebook_id: str) -> dict:
        res = await self.call_tool("notebook_get", {"notebook_id": notebook_id})
        notebook = self._notebook_result(res)
        if notebook["id"] != notebook_id:
            raise McpToolError("invalid_response")
        return notebook

    async def notebook_add_text(self, notebook_id: str, content: str, title: str) -> dict:
        res = await self.call_tool("notebook_add_text", {
            "notebook_id": notebook_id,
            "content": content,
            "title": title,
        })
        return res if isinstance(res, dict) else {"message": str(res)}

    async def source_get_content(self, source_id: str, notebook_id: str) -> dict:
        res = await self.call_tool("source_get_content", {
            "source_id": source_id, "notebook_id": notebook_id,
        })
        if not isinstance(res, dict) or not isinstance(res.get("text"), str):
            raise McpToolError("invalid_response")
        return {"content": res["text"]}

    async def notebook_query(self, notebook_id: str, query: str, source_ids: List[str], conversation_id: Optional[str] = None) -> dict:
        args = {
            "notebook_id": notebook_id,
            "query": query,
            "source_ids": source_ids,
        }
        if conversation_id:
            args["conversation_id"] = conversation_id
        res = await self.call_tool("notebook_query", args)
        if isinstance(res, str):
            return {"answer": res}
        return res

    async def close(self):
        process = self._process
        if process is None:
            return

        try:
            if process.returncode is None:
                process.kill()
            # Drain and close stdin/stdout transports as well as reaping the
            # child. Waiting alone leaves subprocess pipe transports attached
            # to the event loop, which can emit unraisable errors at loop close.
            await asyncio.wait_for(process.communicate(), timeout=2.0)
        except Exception:
            try:
                if process.returncode is None:
                    process.kill()
                await asyncio.wait_for(process.wait(), timeout=2.0)
            except Exception:
                pass
        finally:
            self._process = None
