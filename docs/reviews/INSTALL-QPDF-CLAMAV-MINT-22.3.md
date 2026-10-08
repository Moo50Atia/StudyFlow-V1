# Install qpdf and ClamAV for the V3 PDF intake pipeline

This guide is for the current development machine, Linux Mint 22.3 (Ubuntu Noble package sources). The V3 intake implementation requires **qpdf 11 or later** and a working ClamAV daemon reachable by `clamdscan`. Installing the packages does not start the Laravel website.

The commands below are instructions for the operator to run in a terminal. They were not run as part of this documentation work. This account does not have passwordless sudo, so package installation requires the user's sudo password or an administrator.

**Follow-up status (2026-10-05):** qpdf 11.9.0 and ClamAV 1.5.4 are now installed; `clamav-freshclam` and `clamav-daemon` are active. The app's live PDF tool gate passed (5 tests), the complete intake suite passed (62 tests), a clean synthetic PDF scanned clean, and the harmless EICAR test signature was detected as expected. The full regression run and remaining provider prerequisites are summarized in the [repair report](V3-POC-REPAIR-IMPLEMENTATION-REPORT.md).

## 1. Install packages

```sh
sudo apt update
sudo apt install qpdf clamav clamav-daemon clamav-freshclam
```

The current Noble package candidate observed on this machine is qpdf `11.9.0-1.1ubuntu0.1`, which satisfies the application's qpdf 11+ requirement. Package versions can advance through system updates. [Ubuntu qpdf package](https://packages.ubuntu.com/noble-updates/qpdf), [ClamAV package installation guidance](https://docs.clamav.net/manual/Installing/Packages.html?highlight=update)

`clamav` supplies the command-line tools and signatures support; `clamav-daemon` supplies `clamd`; `clamav-freshclam` updates malware definitions. This application uses `clamdscan`, not a substitute one-off `clamscan` check.

## 2. Start the signature updater and scanner daemon

```sh
sudo systemctl enable --now clamav-freshclam
sudo systemctl enable --now clamav-daemon
```

Check both services:

```sh
systemctl --no-pager --full status clamav-freshclam
systemctl --no-pager --full status clamav-daemon
```

Both should report `active (running)`. FreshClam must download definitions before ClamAV can scan. If the daemon does not start because its signature database is missing, avoid running `freshclam` concurrently with the updater service:

```sh
sudo systemctl stop clamav-freshclam
sudo freshclam
sudo systemctl start clamav-freshclam
sudo systemctl restart clamav-daemon
```

Then inspect `systemctl status` again. If any step fails, inspect `journalctl -u clamav-freshclam -n 80 --no-pager` or `journalctl -u clamav-daemon -n 80 --no-pager` and resolve that service error before application testing. ClamAV's `clamdscan` sends scans to the running daemon; with `--fdpass`, the client opens the file and passes it over a local Unix socket, so the daemon must support a local socket and the PHP process user must be able to access it. [ClamAV scan modes and `--fdpass`](https://docs.clamav.net/manual/Usage/Scanning.html?highlight=false+positive), [ClamAV configuration](https://docs.clamav.net/manual/Usage/Configuration.html?search=%E3%80%82si)

## 3. Verify binaries and daemon connectivity

```sh
qpdf --version
clamdscan --version
freshclam --version
```

Expected: qpdf reports version 11 or newer, and the ClamAV clients print their versions. `clamdscan --version` alone only verifies the client executable, not daemon availability. Check the daemon service status above; the application-level test below proves an actual scan from the current test process.

If a manual smoke test is needed, use a known local PDF and run as the same operating-system user that runs PHP:

```sh
clamdscan --fdpass --no-summary /absolute/path/to/known-clean.pdf
```

Expected for a clean file: exit code 0 and no infection finding. Exit code 1 means infected; exit code 2 or a socket/timeout error means the scanner is unavailable. The application fails closed for both infected and unavailable outcomes. Do not interpret a `clamscan` pass as proof that the required daemon/socket path works.

If the command reports a socket permission error, inspect the socket and service configuration without making it world-writable:

```sh
grep -E '^(LocalSocket|LocalSocketGroup|LocalSocketMode|User)' /etc/clamav/clamd.conf
stat -c '%A %U:%G %n' /run/clamav /run/clamav/clamd.ctl 2>/dev/null
ps -eo user,group,args | grep '[p]hp artisan\|[p]hp-fpm'
```

Grant only the actual PHP service account the minimum needed socket access using the host's group/socket configuration, then restart `clamav-daemon` and repeat the scan as that account. Do not make the socket or uploaded-file directory world-readable. If PHP runs as `www-data`, testing only as the interactive login user is insufficient.

## 4. Run the V3 live tool gate

From `/home/moahmmed/college_project`, run:

```sh
php artisan test tests/Feature/V3/Intake/PdfToolIntegrationTest.php
```

Expected: no environmental skip; the real qpdf adapter accepts its valid synthetic PDF fixture, and the real ClamAV adapter accepts that clean fixture. If this test reports qpdf or `clamdscan` unavailable, or if the ClamAV service/socket is not usable by PHP, the scanner prerequisite is still incomplete. This test does not call any external AI provider or upload a PDF to one.

Then run the full intake feature suite:

```sh
php artisan test tests/Feature/V3/Intake
```

Expected: all tests pass, including duplicate/race, ownership, private storage, upload failure and recovery coverage. Report any test failure separately from package/service installation; do not describe a skipped live scanner gate as a pass.

## 5. Provider and release boundary

The user's approval covers use of the already approved templates and upload of a test PDF to a provider when the specific provider is configured. It does not itself configure provider credentials or prove that a provider accepted the request. The JEV qualification step is owner-waived for the requested release/demo until the JEV API is available: record it as **owner-approved/waived**, not as a live JEV response. This guide makes no change to that workflow or to application code.

Keep provider uploads limited to a test PDF with no personal or confidential information. Follow the selected provider's file-retention setting, and record which provider received the test. Do not place API keys in this document, source control, or chat.

## References

- [Ubuntu Noble qpdf package](https://packages.ubuntu.com/noble-updates/qpdf)
- [ClamAV package installation](https://docs.clamav.net/manual/Installing/Packages.html?highlight=update)
- [ClamAV scanning and file-descriptor passing](https://docs.clamav.net/manual/Usage/Scanning.html?highlight=false+positive)
- [ClamAV configuration](https://docs.clamav.net/manual/Usage/Configuration.html?search=%E3%80%82si)
