import { expect, it, vi, afterEach } from 'vitest';
const mocks = vi.hoisted(() => ({ execFileSync:vi.fn(),spawn:vi.fn(),mkdirSync:vi.fn(),existsSync:vi.fn(()=>false),readlinkSync:vi.fn() }));
vi.mock('node:child_process', async original => ({...await original<typeof import('node:child_process')>(),execFileSync:mocks.execFileSync,spawn:mocks.spawn}));
vi.mock('node:fs', async original => ({...await original<typeof import('node:fs')>(),mkdirSync:mocks.mkdirSync,existsSync:mocks.existsSync,readlinkSync:mocks.readlinkSync,rmSync:vi.fn()}));
import { launchChrome } from '../browser-auth.js';
afterEach(() => {vi.unstubAllEnvs();vi.restoreAllMocks();vi.clearAllMocks();mocks.existsSync.mockReturnValue(false);});
it('selects Brave executable and dedicated profile explicitly', async () => {
 vi.stubEnv('NOTEBOOKLM_BROWSER_PATH','/usr/bin/brave-browser');
 vi.stubEnv('NOTEBOOKLM_BROWSER_PROFILE_DIR','/tmp/v3-brave-profile');
 mocks.spawn.mockReturnValue({kill:vi.fn(),stderr:{on(_event:string,cb:any){queueMicrotask(()=>cb('DevTools listening on ws://127.0.0.1:54321/'));},off(){}}});
 await launchChrome(false);
 expect(mocks.spawn.mock.calls[0][0]).toBe('/usr/bin/brave-browser');
 expect(mocks.spawn.mock.calls[0][1]).toContain('--user-data-dir=/tmp/v3-brave-profile');
 expect(mocks.spawn.mock.calls[0][1]).toContain('--profile-directory=Default');
 expect(mocks.spawn.mock.calls[0][1]).toContain('--disable-features=IsolateOrigins,site-per-process,ProfilePickerOnStartup');
});

it('selects the requested existing Brave profile without treating it as MCP-owned', async () => {
 vi.stubEnv('NOTEBOOKLM_BROWSER_PATH','/usr/bin/brave-browser');
 vi.stubEnv('NOTEBOOKLM_BROWSER_PROFILE_DIR','/home/moahmmed/.config/Brave-Browser');
 vi.stubEnv('NOTEBOOKLM_BROWSER_PROFILE_DIRECTORY','Profile 2');
 vi.stubEnv('NOTEBOOKLM_USE_EXISTING_PROFILE','1');
 mocks.spawn.mockReturnValue({kill:vi.fn(),stderr:{on(_event:string,cb:any){queueMicrotask(()=>cb('DevTools listening on ws://127.0.0.1:54322/'));},off(){}}});
 await launchChrome(false);
 expect(mocks.spawn.mock.calls.at(-1)?.[1]).toContain('--user-data-dir=/home/moahmmed/.config/Brave-Browser');
 expect(mocks.spawn.mock.calls.at(-1)?.[1]).toContain('--profile-directory=Profile 2');
});

it('refuses to take over an existing Brave profile while it is open', async () => {
 vi.stubEnv('NOTEBOOKLM_BROWSER_PATH','/usr/bin/brave-browser');
 vi.stubEnv('NOTEBOOKLM_BROWSER_PROFILE_DIR','/home/moahmmed/.config/Brave-Browser');
 vi.stubEnv('NOTEBOOKLM_BROWSER_PROFILE_DIRECTORY','Profile 2');
 vi.stubEnv('NOTEBOOKLM_USE_EXISTING_PROFILE','1');
 mocks.existsSync.mockReturnValue(true);
 mocks.readlinkSync.mockReturnValue(`host-${process.pid}`);
 vi.spyOn(process, 'kill').mockImplementation(() => true as any);
 await expect(launchChrome(false)).rejects.toThrow(/Close all Brave windows/);
 expect(mocks.spawn).not.toHaveBeenCalled();
});
