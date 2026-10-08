import { afterEach, expect, it, vi } from 'vitest';
vi.mock('../browser-auth.js', () => ({ refreshCookiesHeadless: vi.fn(), runBrowserAuthFlow: vi.fn() }));
vi.mock('../auth.js', async (original) => ({ ...await original<typeof import('../auth.js')>(), saveTokens: vi.fn() }));
import { AuthState } from '../rpc/auth-state.js';
import { RpcTransport } from '../rpc/transport.js';
import { refreshCookiesHeadless, runBrowserAuthFlow } from '../browser-auth.js';
import { extractCsrfFromPage } from '../auth.js';
const tokens = { cookies: {SID:'fixture'}, csrf_token:'csrf', session_id:'sid', extracted_at:1 };
afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs(); vi.clearAllMocks(); });
it('no-browser policy rejects refresh before invoking either browser flow', async () => {
 vi.stubEnv('NOTEBOOKLM_NO_BROWSER', '1');
 await expect(new AuthState(tokens).refreshOnce()).rejects.toThrow('authentication_required');
 expect(refreshCookiesHeadless).not.toHaveBeenCalled();
 expect(runBrowserAuthFlow).not.toHaveBeenCalled();
});
it('session id cannot substitute for missing CSRF', () => {
 expect(extractCsrfFromPage('{"FdrFJe":"session-only"}')).toBeNull();
});
it('landing page rejects login redirects without forwarding cookies', async () => {
 const fetch = vi.fn().mockResolvedValue(new Response('', {status:302, headers:{location:'https://accounts.google.com/'}}));
 vi.stubGlobal('fetch', fetch);
 await expect(new RpcTransport(new AuthState(tokens), 100).fetchLandingHtml()).rejects.toThrow('authentication_required');
 expect(fetch).toHaveBeenCalledTimes(1);
 expect(fetch.mock.calls[0][1].redirect).toBe('manual');
});
it('landing HTML without actual page tokens is not authenticated', async () => {
 vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Sign in</html>')));
 await expect(new RpcTransport(new AuthState(tokens), 100).fetchLandingHtml()).rejects.toThrow('authentication_required');
});
it.each([401, 403])('HTTP %s is a clear authentication failure', async status => {
 const fetch = vi.fn().mockResolvedValue(new Response('', {status}));
 vi.stubGlobal('fetch', fetch);
 await expect(new RpcTransport(new AuthState(tokens), 100).callBatchexecute('fixture', [])).rejects.toThrow('authentication_required');
 expect(fetch).toHaveBeenCalledTimes(1);
});
it('valid token-bearing landing HTML is accepted', async () => {
 const html = '{"SNlM0e":"fixture-csrf","FdrFJe":"fixture-session"}';
 vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(html)));
 await expect(new RpcTransport(new AuthState(tokens),100).fetchLandingHtml()).resolves.toBe(html);
});
