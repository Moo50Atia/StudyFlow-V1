"""
Browser checks implementation for V3 Scene Qualification.
Governed by specs/012-v3-scene-qualification/contracts/scene-qualification.md.
"""

import asyncio
import base64
import hashlib
import json
import os
import re
from datetime import datetime, timezone
from http.server import SimpleHTTPRequestHandler, HTTPServer
import threading
from typing import Any, Dict, List, Optional, Tuple
from playwright.async_api import async_playwright, Request, Route, WebSocket

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
    def __init__(self, server_address, RequestHandlerClass, candidate_dir, allowed_paths):
        self.candidate_dir = candidate_dir
        self.allowed_paths = set(allowed_paths)
        super().__init__(server_address, RequestHandlerClass)

class RestrictedHandler(SimpleHTTPRequestHandler):
    def translate_path(self, path):
        # Remove query / fragment
        clean_path = path.split('?', 1)[0].split('#', 1)[0]
        # Normalize
        rel = os.path.normpath(clean_path.lstrip('/'))
        if rel == '' or rel == '.':
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
        # Suppress server logging to keep stdout clean
        pass


def start_loopback_server(candidate_dir: str, allowed_paths: List[str], port: int = 8765):
    server = RestrictedStaticServer(('127.0.0.1', port), RestrictedHandler, candidate_dir, allowed_paths)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server


async def run_browser_checks(
    candidate_dir: str,
    candidate_ref: str,
    manifest: dict,
    manifest_sha256: Optional[str] = None,
) -> dict:
    started_at = datetime.now(timezone.utc).isoformat()
    
    # 1. Integrity check
    integrity_status = "pass"
    integrity_details = []
    
    declared_files = manifest.get("files", [])
    entrypoint = manifest.get("entrypoint", "index.html")
    allowed_paths = [entrypoint]
    
    for f in declared_files:
        rel_path = f.get("path")
        allowed_paths.append(rel_path)
        full_path = os.path.join(candidate_dir, rel_path)
        if not os.path.isfile(full_path):
            integrity_status = "fail"
            integrity_details.append("asset_missing")
            continue
        with open(full_path, "rb") as fh:
            actual_sha = hashlib.sha256(fh.read()).hexdigest()
        if actual_sha != f.get("sha256"):
            integrity_status = "fail"
            integrity_details.append("integrity_mismatch")
            
    if manifest_sha256 is None:
        manifest_bytes = json.dumps(manifest, sort_keys=True).encode("utf-8")
        manifest_sha256 = hashlib.sha256(manifest_bytes).hexdigest()
    
    entrypoint_path = os.path.join(candidate_dir, entrypoint)
    if not os.path.isfile(entrypoint_path):
        integrity_status = "fail"
        integrity_details.append("entrypoint_missing")
    elif os.path.getsize(entrypoint_path) == 0:
        integrity_status = "fail"
        integrity_details.append("entrypoint_empty")

    checks_results = []
    checks_results.append({
        "code": "integrity",
        "status": integrity_status,
        "detail_codes": sorted(list(set(integrity_details)))
    })

    if integrity_status == "fail":
        # Do not run further checks due to integrity failure
        for c in ["entrypoint", "assets", "runtime", "network", "interaction", "accessibility"]:
            checks_results.append({
                "code": c,
                "status": "fail",
                "detail_codes": ["not_run_due_to_prior_failure"]
            })
        finished_at = datetime.now(timezone.utc).isoformat()
        return {
            "schema_version": 1,
            "candidate_ref": candidate_ref,
            "manifest_sha256": manifest_sha256,
            "checker_version": "chromium-pw-1.63.0-v1",
            "checks": checks_results,
            "observations": {
                "dom_text": "",
                "controls": [],
                "resource_events": [],
                "runtime_errors": [],
                "asset_failures": [],
                "console_error_count": 0,
                "page_error_count": 0,
                "forbidden_request_count": 0,
            },
            "screenshot_sha256": hashlib.sha256(b"").hexdigest(),
            "screenshot_png_base64": "",
            "started_at": started_at,
            "finished_at": finished_at
        }

    # Start loopback server
    port = 8765
    server = None
    for p in range(8765, 8780):
        try:
            server = start_loopback_server(candidate_dir, allowed_paths, p)
            port = p
            break
        except OSError:
            continue
    if not server:
        raise RuntimeError("Could not bind loopback static server")

    observations: Dict[str, Any] = {
        "dom_text": "",
        "controls": [],
        "resource_events": [],
        "runtime_errors": [],
        "asset_failures": [],
        "console_error_count": 0,
        "page_error_count": 0,
        "forbidden_request_count": 0,
    }

    entrypoint_status = "pass"
    entrypoint_details = []
    assets_status = "pass"
    assets_details = []
    runtime_status = "pass"
    runtime_details = []
    network_status = "pass"
    network_details = []
    interaction_status = "pass"
    interaction_details = []
    accessibility_status = "pass"
    accessibility_details = []

    screenshot_bytes = b""

    # Find browser executable
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

    launch_kwargs: Dict[str, Any] = {
        "headless": True,
        "args": [
            "--no-sandbox" if os.getuid() == 0 else "--enable-sandbox",
            "--disable-dev-shm-usage",
            "--disable-background-networking",
            "--disable-default-apps",
            "--disable-sync",
            "--no-first-run"
        ]
    }
    # In nonroot inside container or host without user namespaces, fallback args if needed
    if exec_path:
        launch_kwargs["executable_path"] = exec_path

    try:
        async with async_playwright() as p:
            try:
                browser = await p.chromium.launch(**launch_kwargs)
            except Exception:
                # If sandboxing fails in restricted test runner without SYS_ADMIN, retry with --no-sandbox
                launch_kwargs["args"] = ["--no-sandbox", "--disable-dev-shm-usage"]
                browser = await p.chromium.launch(**launch_kwargs)

            context = await browser.new_context(
                viewport={"width": 1280, "height": 720},
                device_scale_factor=1,
                has_touch=False,
                is_mobile=False,
                service_workers="block",
                accept_downloads=False
            )
            
            page = await context.new_page()

            # Listen for runtime errors
            def on_console(msg):
                if msg.type == "error" or "Content Security Policy" in msg.text:
                    if "Content Security Policy" in msg.text or "violates" in msg.text:
                        observations["forbidden_request_count"] += 1
                        network_details.append("forbidden_request")
                    if msg.type == "error":
                        observations["console_error_count"] += 1
                        if len(observations["runtime_errors"]) < 16:
                            observations["runtime_errors"].append({
                                "kind": "console",
                                "source_file": None,
                                "line": None,
                                "column": None,
                                "message": msg.text[:512]
                            })

            def on_pageerror(exc):
                observations["page_error_count"] += 1
                if len(observations["runtime_errors"]) < 16:
                    observations["runtime_errors"].append({
                        "kind": "page",
                        "source_file": None,
                        "line": None,
                        "column": None,
                        "message": str(exc)[:512]
                    })

            def on_popup(popup):
                network_details.append("popup_attempt")
                observations["forbidden_request_count"] += 1
                asyncio.create_task(popup.close())

            page.on("console", on_console)
            page.on("pageerror", on_pageerror)
            page.on("popup", on_popup)

            # Route filter
            origin = f"http://127.0.0.1:{port}"
            
            async def handle_route(route: Route):
                req = route.request
                url = req.url
                if url.startswith(origin):
                    rel = url[len(origin):].lstrip('/')
                    clean_rel = rel.split('?')[0].split('#')[0]
                    if clean_rel == '':
                        clean_rel = 'index.html'
                    if clean_rel in allowed_paths:
                        await route.continue_()
                    else:
                        if len(observations["resource_events"]) < 64:
                            observations["resource_events"].append({
                                "path": clean_rel,
                                "kind": "local",
                                "status": "blocked"
                            })
                        assets_details.append("asset_undeclared")
                        await route.abort("blockedbyclient")
                else:
                    observations["forbidden_request_count"] += 1
                    network_details.append("forbidden_request")
                    if len(observations["resource_events"]) < 64:
                        observations["resource_events"].append({
                            "path": url[:128],
                            "kind": "forbidden",
                            "status": "blocked"
                        })
                    await route.abort("blockedbyclient")

            await page.route("**/*", handle_route)

            # Response listener for asset tracking
            def on_response(resp):
                if resp.url.startswith(origin):
                    rel = resp.url[len(origin):].lstrip('/').split('?')[0].split('#')[0]
                    if rel == '':
                        rel = 'index.html'
                    if resp.status >= 400:
                        if rel not in observations["asset_failures"]:
                            observations["asset_failures"].append(rel)
                        assets_details.append("asset_missing")
                    else:
                        if len(observations["resource_events"]) < 64:
                            observations["resource_events"].append({
                                "path": rel,
                                "kind": "local",
                                "status": "loaded"
                            })

            page.on("response", on_response)

            # Navigate
            url = f"{origin}/{entrypoint}"
            try:
                nav_response = await page.goto(url, wait_until="load", timeout=15000)
                if not nav_response or nav_response.status != 200:
                    entrypoint_status = "fail"
                    entrypoint_details.append("entrypoint_missing")
            except Exception as e:
                entrypoint_status = "fail"
                entrypoint_details.append("entrypoint_missing")

            # Allow scripts to initialize
            await asyncio.sleep(0.5)

            # Check document lang and title
            doc_lang = await page.evaluate("() => document.documentElement.getAttribute('lang') || ''")
            doc_title = await page.title()

            if not doc_lang.strip():
                accessibility_status = "fail"
                accessibility_details.append("document_lang_missing")

            if not doc_title.strip():
                accessibility_status = "fail"
                accessibility_details.append("document_title_missing")

            # Check visible body text
            body_text = await page.evaluate("() => document.body ? document.body.innerText || '' : ''")
            if not body_text.strip():
                # Check if body has canvas or SVG or buttons
                has_elements = await page.evaluate("() => (document.body && document.body.children.length > 0) ? true : false")
                if not has_elements:
                    entrypoint_status = "fail"
                    entrypoint_details.append("entrypoint_empty")

            observations["dom_text"] = body_text[:65536]

            # Screenshot
            try:
                screenshot_bytes = await page.screenshot(type="png", timeout=5000)
            except Exception:
                screenshot_bytes = b""

            # Accessibility & Interaction checks
            # Find interactive controls: button, input, select, textarea, [role=button], [role=slider], etc.
            control_elements = await page.query_selector_all(
                "button, input, select, textarea, [role='button'], [role='slider'], [role='switch'], [role='checkbox']"
            )

            observed_controls = []
            valid_interactive_found = False

            # Idle baseline twin capture for interaction comparison
            # We open an idle twin page to measure idle changes (e.g. autoplay canvas diff)
            twin_page = await context.new_page()
            await twin_page.route("**/*", handle_route)
            await twin_page.goto(url, wait_until="load", timeout=15000)
            await asyncio.sleep(0.5)

            control_ordinal = 1
            for elem in control_elements[:8]:
                is_visible = await elem.is_visible()
                if not is_visible:
                    continue

                role = await elem.evaluate("el => el.getAttribute('role') || el.tagName.toLowerCase()")
                name = await elem.evaluate("""el => {
                    return el.getAttribute('aria-label') || 
                           el.innerText || 
                           el.getAttribute('title') || 
                           el.getAttribute('value') || 
                           (el.labels && el.labels[0] ? el.labels[0].innerText : '') || '';
                }""")
                name = name.strip()

                if not name:
                    accessibility_details.append("accessible_name_missing")

                # Test focusability
                try:
                    await elem.focus()
                    is_focused = await elem.evaluate("el => document.activeElement === el")
                except Exception:
                    is_focused = False

                # State before interaction
                before_state = await elem.evaluate("el => el.outerHTML + (el.value || '')")
                before_sha = hashlib.sha256(before_state.encode("utf-8")).hexdigest()

                # Test pointer interaction
                pointer_changed = False
                keyboard_changed = False

                # Capture baseline canvas or DOM before
                await page.evaluate("""() => {
                    window.__snapCanvas = () => {
                        const c = document.querySelector('canvas');
                        if (!c) return null;
                        try {
                            const ctx = c.getContext('2d');
                            return ctx ? ctx.getImageData(0, 0, c.width, c.height).data : null;
                        } catch(e) { return null; }
                    };
                    window.__canvasBefore = window.__snapCanvas();
                }""")
                await twin_page.evaluate("""() => {
                    window.__snapCanvas = () => {
                        const c = document.querySelector('canvas');
                        if (!c) return null;
                        try {
                            const ctx = c.getContext('2d');
                            return ctx ? ctx.getImageData(0, 0, c.width, c.height).data : null;
                        } catch(e) { return null; }
                    };
                    window.__canvasBefore = window.__snapCanvas();
                }""")

                # Pointer Click
                try:
                    await elem.click(timeout=2000)
                    await asyncio.sleep(0.5)
                except Exception:
                    pass

                after_state = await elem.evaluate("el => el.outerHTML + (el.value || '')")
                after_sha = hashlib.sha256(after_state.encode("utf-8")).hexdigest()

                diff_ratio_page = await page.evaluate("""() => {
                    const c = document.querySelector('canvas');
                    if (!c || !window.__canvasBefore) return 0;
                    const ctx = c.getContext('2d');
                    if (!ctx) return 0;
                    const cur = ctx.getImageData(0, 0, c.width, c.height).data;
                    const prev = window.__canvasBefore;
                    let diffs = 0;
                    for (let i = 0; i < cur.length; i += 4) {
                        if (cur[i] !== prev[i] || cur[i+1] !== prev[i+1] || cur[i+2] !== prev[i+2] || cur[i+3] !== prev[i+3]) {
                            diffs++;
                        }
                    }
                    return diffs / (c.width * c.height);
                }""")

                diff_ratio_twin = await twin_page.evaluate("""() => {
                    const c = document.querySelector('canvas');
                    if (!c || !window.__canvasBefore) return 0;
                    const ctx = c.getContext('2d');
                    if (!ctx) return 0;
                    const cur = ctx.getImageData(0, 0, c.width, c.height).data;
                    const prev = window.__canvasBefore;
                    let diffs = 0;
                    for (let i = 0; i < cur.length; i += 4) {
                        if (cur[i] !== prev[i] || cur[i+1] !== prev[i+1] || cur[i+2] !== prev[i+2] || cur[i+3] !== prev[i+3]) {
                            diffs++;
                        }
                    }
                    return diffs / (c.width * c.height);
                }""")

                dom_changed = (before_sha != after_sha)
                canvas_changed = (diff_ratio_page > (diff_ratio_twin + 0.005))

                if dom_changed or canvas_changed:
                    pointer_changed = True

                # Keyboard action (Enter / Space)
                # Snapshot before keyboard
                await page.evaluate("window.__canvasBeforeKb = window.__snapCanvas();")
                await twin_page.evaluate("window.__canvasBeforeKb = window.__snapCanvas();")

                try:
                    await elem.focus()
                    await page.keyboard.press("Enter")
                    await asyncio.sleep(0.5)
                except Exception:
                    pass

                after_kb_state = await elem.evaluate("el => el.outerHTML + (el.value || '')")
                after_kb_sha = hashlib.sha256(after_kb_state.encode("utf-8")).hexdigest()

                diff_ratio_page_kb = await page.evaluate("""() => {
                    const c = document.querySelector('canvas');
                    if (!c || !window.__canvasBeforeKb) return 0;
                    const ctx = c.getContext('2d');
                    if (!ctx) return 0;
                    const cur = ctx.getImageData(0, 0, c.width, c.height).data;
                    const prev = window.__canvasBeforeKb;
                    let diffs = 0;
                    for (let i = 0; i < cur.length; i += 4) {
                        if (cur[i] !== prev[i] || cur[i+1] !== prev[i+1] || cur[i+2] !== prev[i+2] || cur[i+3] !== prev[i+3]) {
                            diffs++;
                        }
                    }
                    return diffs / (c.width * c.height);
                }""")

                diff_ratio_twin_kb = await twin_page.evaluate("""() => {
                    const c = document.querySelector('canvas');
                    if (!c || !window.__canvasBeforeKb) return 0;
                    const ctx = c.getContext('2d');
                    if (!ctx) return 0;
                    const cur = ctx.getImageData(0, 0, c.width, c.height).data;
                    const prev = window.__canvasBeforeKb;
                    let diffs = 0;
                    for (let i = 0; i < cur.length; i += 4) {
                        if (cur[i] !== prev[i] || cur[i+1] !== prev[i+1] || cur[i+2] !== prev[i+2] || cur[i+3] !== prev[i+3]) {
                            diffs++;
                        }
                    }
                    return diffs / (c.width * c.height);
                }""")

                dom_changed_kb = (after_kb_sha != after_sha)
                canvas_changed_kb = (diff_ratio_page_kb > (diff_ratio_twin_kb + 0.005))

                if dom_changed_kb or canvas_changed_kb:
                    keyboard_changed = True

                ctrl_obj = {
                    "ordinal": control_ordinal,
                    "role": role,
                    "name": name,
                    "before_sha256": before_sha,
                    "after_sha256": after_sha,
                    "pointer_changed": pointer_changed,
                    "keyboard_changed": keyboard_changed,
                    "focusable": is_focused
                }
                observed_controls.append(ctrl_obj)
                control_ordinal += 1

                if name and pointer_changed and keyboard_changed and is_focused:
                    valid_interactive_found = True

            observations["controls"] = observed_controls

            if not observed_controls:
                interaction_status = "fail"
                interaction_details.append("interaction_absent")
            elif not valid_interactive_found:
                interaction_status = "fail"
                if any(c["pointer_changed"] or c["keyboard_changed"] for c in observed_controls):
                    interaction_details.append("keyboard_inoperable")
                else:
                    interaction_details.append("interaction_no_change")

            # Check runtime errors
            if observations["console_error_count"] > 0 or observations["page_error_count"] > 0:
                runtime_status = "fail"
                if observations["console_error_count"] > 0:
                    runtime_details.append("console_error")
                if observations["page_error_count"] > 0:
                    runtime_details.append("script_error")

            # Check asset failures
            if observations["asset_failures"] or "asset_missing" in assets_details or "asset_undeclared" in assets_details:
                assets_status = "fail"

            # Check network failures
            if observations["forbidden_request_count"] > 0 or network_details:
                network_status = "fail"

            # Check accessibility
            if accessibility_details:
                accessibility_status = "fail"

            await twin_page.close()
            await page.close()
            await context.close()
            await browser.close()
    finally:
        server.shutdown()
        server.server_close()

    # Determine check verdicts
    checks_results = [
        {"code": "integrity", "status": integrity_status, "detail_codes": sorted(list(set(integrity_details)))},
        {"code": "entrypoint", "status": entrypoint_status, "detail_codes": sorted(list(set(entrypoint_details)))},
        {"code": "assets", "status": assets_status, "detail_codes": sorted(list(set(assets_details)))},
        {"code": "runtime", "status": runtime_status, "detail_codes": sorted(list(set(runtime_details)))},
        {"code": "network", "status": network_status, "detail_codes": sorted(list(set(network_details)))},
        {"code": "interaction", "status": interaction_status, "detail_codes": sorted(list(set(interaction_details)))},
        {"code": "accessibility", "status": accessibility_status, "detail_codes": sorted(list(set(accessibility_details)))},
    ]

    screenshot_sha256 = hashlib.sha256(screenshot_bytes).hexdigest()
    screenshot_b64 = base64.b64encode(screenshot_bytes).decode("ascii")

    finished_at = datetime.now(timezone.utc).isoformat()

    return {
        "schema_version": 1,
        "candidate_ref": candidate_ref,
        "manifest_sha256": manifest_sha256,
        "checker_version": "chromium-pw-1.63.0-v1",
        "checks": checks_results,
        "observations": observations,
        "screenshot_sha256": screenshot_sha256,
        "screenshot_png_base64": screenshot_b64,
        "started_at": started_at,
        "finished_at": finished_at
    }
