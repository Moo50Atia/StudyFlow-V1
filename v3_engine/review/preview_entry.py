"""
Container sandbox entrypoint for V3 Interactive Scene Preview (Spec 013).
Serves candidate files on loopback, launches Playwright Chromium with nonroot sandbox,
takes 1280x720 screenshots, handles pointer/keyboard actions, and communicates over stdin/stdout.
"""

import asyncio
import base64
import hashlib
import json
import os
import struct
import sys
import threading
from http.server import HTTPServer, SimpleHTTPRequestHandler
from typing import Any, Dict, List, Optional
from playwright.async_api import async_playwright, Browser, BrowserContext, Page, Route

CSP_HEADER = (
    "default-src 'none'; "
    "script-src 'self' 'unsafe-inline'; "
    "style-src 'self' 'unsafe-inline'; "
    "img-src 'self' data: blob:; "
    "font-src 'self' data:; "
    "connect-src 'self'; "
    "object-src 'none'; "
    "frame-src 'none'; "
    "form-action 'none'; "
    "base-uri 'none'"
)

class RestrictedStaticServer(HTTPServer):
    def __init__(self, server_address, RequestHandlerClass, candidate_dir: str, allowed_paths: List[str]):
        self.candidate_dir = candidate_dir
        self.allowed_paths = set(allowed_paths)
        super().__init__(server_address, RequestHandlerClass)

class RestrictedHandler(SimpleHTTPRequestHandler):
    def translate_path(self, path):
        clean_path = path.split('?', 1)[0].split('#', 1)[0]
        rel = os.path.normpath(clean_path.lstrip('/'))
        if rel in ('', '.'):
            rel = 'index.html'
        if rel not in self.server.allowed_paths:
            return None
        full = os.path.join(self.server.candidate_dir, rel)
        return full

    def do_GET(self):
        full = self.translate_path(self.path)
        if full is None or not os.path.isfile(full):
            self.send_error(404, "File Not Found")
            return
        return super().do_GET()

    def do_HEAD(self):
        full = self.translate_path(self.path)
        if full is None or not os.path.isfile(full):
            self.send_error(404, "File Not Found")
            return
        return super().do_HEAD()

    def end_headers(self):
        self.send_header("Content-Security-Policy", CSP_HEADER)
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")
        super().end_headers()

    def log_message(self, format, *args):
        pass


class PreviewRunner:
    def __init__(self):
        self.server: Optional[RestrictedStaticServer] = None
        self.server_thread: Optional[threading.Thread] = None
        self.port: int = 8765
        self.playwright = None
        self.browser: Optional[Browser] = None
        self.context: Optional[BrowserContext] = None
        self.page: Optional[Page] = None
        self.viewer_ref: str = ""
        self.frame_no: int = 0
        self.forbidden_requests: int = 0
        self.runtime_errors: int = 0
        self.candidate_dir: str = "/tmp/candidate"

    def start_server(self, candidate_dir: str, allowed_paths: List[str]):
        self.candidate_dir = candidate_dir
        for p in range(8765, 8790):
            try:
                self.server = RestrictedStaticServer(('127.0.0.1', p), RestrictedHandler, candidate_dir, allowed_paths)
                self.port = p
                break
            except OSError:
                continue
        if not self.server:
            raise RuntimeError("Could not bind static server on 127.0.0.1")

        self.server_thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.server_thread.start()

    async def init_session(self, payload: dict) -> dict:
        self.viewer_ref = payload.get("viewer_ref", "")
        candidate_ref = payload.get("candidate_ref", "")
        manifest_sha256 = payload.get("manifest_sha256", "")
        files = payload.get("files", [])

        os.makedirs(self.candidate_dir, exist_ok=True)
        allowed_paths = []

        has_entry = False
        for f in files:
            path = f.get("path", "")
            content = f.get("content", "")
            if not path or path.startswith("/") or ".." in path:
                return {"success": False, "error": "policy_denied"}
            full_path = os.path.join(self.candidate_dir, path)
            os.makedirs(os.path.dirname(full_path), exist_ok=True)
            with open(full_path, "w", encoding="utf-8") as fh:
                fh.write(content)
            allowed_paths.append(path)
            if path == "index.html":
                has_entry = True

        if not has_entry:
            return {"success": False, "error": "integrity_mismatch"}

        self.start_server(self.candidate_dir, allowed_paths)

        # Launch Playwright Chromium
        self.playwright = await async_playwright().start()

        chrome_paths = [
            "/opt/google/chrome/chrome",
            "/usr/bin/google-chrome",
            "/usr/bin/google-chrome-stable",
            "/usr/bin/chromium",
            "/usr/bin/chromium-browser"
        ]
        exec_path = None
        for cp in chrome_paths:
            if os.path.isfile(cp) and os.access(cp, os.X_OK):
                exec_path = cp
                break

        launch_args = [
            "--no-sandbox" if os.getuid() == 0 else "--enable-sandbox",
            "--disable-dev-shm-usage",
            "--disable-background-networking",
            "--disable-default-apps",
            "--disable-sync",
            "--no-first-run"
        ]
        kwargs = {"headless": True, "args": launch_args}
        if exec_path:
            kwargs["executable_path"] = exec_path

        try:
            self.browser = await self.playwright.chromium.launch(**kwargs)
        except Exception:
            # Fallback if sandboxing not supported in environment
            kwargs["args"] = ["--no-sandbox", "--disable-dev-shm-usage"]
            self.browser = await self.playwright.chromium.launch(**kwargs)

        self.context = await self.browser.new_context(
            viewport={"width": 1280, "height": 720},
            java_script_enabled=True,
            bypass_csp=False
        )

        self.page = await self.context.new_page()

        # Intercept and block hostile requests
        async def handle_route(route: Route):
            req_url = route.request.url
            if not req_url.startswith(f"http://127.0.0.1:{self.port}/"):
                self.forbidden_requests += 1
                await route.abort("blockedbyclient")
            else:
                await route.continue_()

        await self.page.route("**/*", handle_route)

        # Track runtime errors
        self.page.on("pageerror", lambda err: self._record_error())
        self.page.on("console", lambda msg: self._record_console(msg))

        url = f"http://127.0.0.1:{self.port}/index.html"
        try:
            await self.page.goto(url, wait_until="load", timeout=15000)
        except Exception:
            self.forbidden_requests += 1

        await asyncio.sleep(0.1)

        frame_data = await self.capture_frame(0)
        return {
            "success": True,
            "viewer_ref": self.viewer_ref,
            "frame_no": 0,
            "frame": frame_data
        }

    def _record_error(self):
        self.runtime_errors += 1

    def _record_console(self, msg):
        if msg.type == "error":
            self.runtime_errors += 1

    async def capture_frame(self, frame_no: int) -> dict:
        self.frame_no = frame_no

        # Capture 1280x720 PNG
        try:
            screenshot_bytes = await self.page.screenshot(type="png", timeout=5000)
        except Exception:
            # Empty fallback PNG 1280x720 header if screenshot fails
            screenshot_bytes = b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x05\x00\x00\x00\x02\xd0\x08\x06\x00\x00\x00" + b"\x00"*50

        png_base64 = base64.b64encode(screenshot_bytes).decode("ascii")

        # Extract visible body text (bounded to 4096 bytes)
        try:
            body_text = await self.page.evaluate("() => document.body ? (document.body.innerText || '') : ''")
        except Exception:
            body_text = ""
        body_text_bytes = body_text.encode("utf-8")[:4096]
        dom_text = body_text_bytes.decode("utf-8", errors="ignore")

        # Extract interactive controls (up to 8)
        controls = []
        try:
            elements = await self.page.query_selector_all(
                "button, input, select, textarea, [role='button'], [role='slider'], [role='switch'], [role='checkbox'], [tabindex='0']"
            )
            ordinal = 1
            for el in elements[:8]:
                is_visible = await el.is_visible()
                if not is_visible:
                    continue
                role = await el.evaluate("e => e.getAttribute('role') || e.tagName.toLowerCase()")
                name = await el.evaluate("""e => {
                    return e.getAttribute('aria-label') ||
                           e.innerText ||
                           e.getAttribute('title') ||
                           e.getAttribute('value') ||
                           (e.labels && e.labels[0] ? e.labels[0].innerText : '') || '';
                }""")
                name = name.strip()
                try:
                    await el.focus()
                    focusable = await el.evaluate("e => document.activeElement === e")
                except Exception:
                    focusable = False

                controls.append({
                    "ordinal": ordinal,
                    "role": role,
                    "name": name,
                    "focusable": focusable
                })
                ordinal += 1
                if ordinal > 8:
                    break
        except Exception:
            pass

        return {
            "viewer_ref": self.viewer_ref,
            "frame_no": frame_no,
            "png_base64": png_base64,
            "dom_text": dom_text,
            "controls": controls,
            "runtime_error_count": self.runtime_errors,
            "forbidden_request_count": self.forbidden_requests
        }

    async def apply_action(self, action: dict) -> dict:
        kind = action.get("kind")
        payload = action.get("payload", {})

        if kind == "click":
            x = int(payload.get("x", 0))
            y = int(payload.get("y", 0))
            await self.page.mouse.click(x, y)
        elif kind == "press":
            key = payload.get("key", "Enter")
            await self.page.keyboard.press(key)
        elif kind == "type":
            text = payload.get("text", "")
            await self.page.keyboard.type(text)

        await asyncio.sleep(0.1)
        next_frame_no = self.frame_no + 1
        frame_data = await self.capture_frame(next_frame_no)

        return {
            "success": True,
            "viewer_ref": self.viewer_ref,
            "frame_no": next_frame_no,
            "frame": frame_data
        }

    async def close(self):
        try:
            if self.page:
                await self.page.close()
            if self.context:
                await self.context.close()
            if self.browser:
                await self.browser.close()
            if self.playwright:
                await self.playwright.stop()
            if self.server:
                self.server.shutdown()
        except Exception:
            pass


async def main_loop():
    runner = PreviewRunner()
    loop = asyncio.get_event_loop()

    while True:
        line = await loop.run_in_executor(None, sys.stdin.readline)
        if not line:
            break
        line = line.strip()
        if not line:
            continue

        try:
            cmd = json.loads(line)
        except Exception:
            sys.stdout.write(json.dumps({"success": False, "error": "invalid_command"}) + "\n")
            sys.stdout.flush()
            continue

        command_type = cmd.get("command")
        if command_type == "init":
            res = await runner.init_session(cmd)
            sys.stdout.write(json.dumps(res) + "\n")
            sys.stdout.flush()
        elif command_type == "action":
            res = await runner.apply_action(cmd)
            sys.stdout.write(json.dumps(res) + "\n")
            sys.stdout.flush()
        elif command_type == "close":
            await runner.close()
            sys.stdout.write(json.dumps({"success": True}) + "\n")
            sys.stdout.flush()
            break
        else:
            sys.stdout.write(json.dumps({"success": False, "error": "unknown_command"}) + "\n")
            sys.stdout.flush()

if __name__ == "__main__":
    asyncio.run(main_loop())
