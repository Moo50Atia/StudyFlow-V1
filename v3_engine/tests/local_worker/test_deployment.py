"""Deployment boundaries for an outbound laptop independent of Laravel files."""
import os
from pathlib import Path
import runpy
from unittest.mock import patch

import pytest


@pytest.mark.parametrize('load_project_env,expected_calls', [('0', 0), ('1', 1)])
def test_terminal_entrypoint_can_run_without_laravel_dotenv(monkeypatch, load_project_env, expected_calls):
    monkeypatch.setenv('V3_TERMINAL_LOAD_PROJECT_ENV', load_project_env)
    entry = Path(__file__).resolve().parents[3] / 'scripts/v3/run_temporal_pipeline.py'
    with patch('v3_engine.local_env.load_local_env') as load, patch('v3_engine.terminal_pipeline.cli.main', return_value=0):
        with pytest.raises(SystemExit) as stopped:
            runpy.run_path(str(entry), run_name='__main__')
    assert stopped.value.code == 0
    assert load.call_count == expected_calls


@pytest.mark.parametrize('origin', ['https://school.example', 'https://school.example:8443/', 'http://localhost:8766', 'http://127.0.0.1:8766', 'http://[::1]:8766'])
def test_worker_origin_accepts_remote_tls_and_local_loopback(origin):
    from v3_engine.local_worker.client import validate_server_origin
    assert validate_server_origin(origin) == origin.rstrip('/')


@pytest.mark.parametrize('origin', ['http://school.example', 'http://192.168.1.20', 'ftp://school.example', 'https://user:secret@school.example', 'https://school.example/api', 'https://school.example?token=secret', 'https://school.example#fragment', 'https://school.example:99999'])
def test_worker_origin_rejects_insecure_or_non_origin_urls(origin):
    from v3_engine.local_worker.client import validate_server_origin
    with pytest.raises(ValueError):
        validate_server_origin(origin)


def test_laptop_environment_excludes_server_secrets(monkeypatch):
    runtime = runpy.run_path(str(Path(__file__).resolve().parents[3] / 'bin/local-worker-runtime'))
    monkeypatch.setenv('APP_KEY', 'server-only')
    monkeypatch.setenv('DB_PASSWORD', 'server-only')
    monkeypatch.setenv('V3_BRIDGE_HMAC_KEY', 'server-only')
    monkeypatch.setenv('GEMINI_API_KEY', 'unused-paid-key')
    config = {'terminal_root': '/private/terminal', 'temporal_address': '127.0.0.1:7234',
              'temporal_namespace': 'v3-local-worker', 'session_path': '/private/existing-session.json'}
    env = runtime['runtime_environment'](config)
    assert all(key not in env for key in ('APP_KEY', 'DB_PASSWORD', 'V3_BRIDGE_HMAC_KEY', 'GEMINI_API_KEY'))
    assert env['V3_TERMINAL_LOAD_PROJECT_ENV'] == '0'
    assert env['V3_NOTEBOOKLM_STORAGE_STATE'] == config['session_path']


def test_device_client_uses_verified_tls_and_bearer_protocol(tmp_path, monkeypatch):
    import json
    import ssl
    import subprocess
    import threading
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
    import httpx
    from v3_engine.local_worker.client import LocalWorkerHttpClient

    certificate, key = tmp_path/'certificate.pem', tmp_path/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                    '-keyout',str(key),'-out',str(certificate),'-subj','/CN=localhost',
                    '-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],check=True,capture_output=True)
    received=[]
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass
        def do_GET(self):
            received.append((self.path,self.headers.get('Authorization')))
            body=json.dumps({'schema_version':1,'device_id':'tls-device','status':'active'}).encode()
            self.send_response(200);self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(certificate,key)
    server.socket=context.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        client=LocalWorkerHttpClient(f'https://127.0.0.1:{server.server_port}',device_token='test-device-token')
        with pytest.raises(httpx.ConnectError):
            client.get_device()  # Untrusted certificates are never silently accepted.
        monkeypatch.setenv('SSL_CERT_FILE',str(certificate))
        assert client.get_device()['device_id']=='tls-device'
        assert received==[('/api/v3/local-worker/v1/device','Bearer test-device-token')]
    finally:
        server.shutdown();server.server_close();thread.join(timeout=3)
