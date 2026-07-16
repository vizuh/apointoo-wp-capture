# WordPress.org submission pre-flight

Last verified: 2026-07-16 against the current submission page, Detailed Plugin Guidelines,
Plugin Developer FAQ, official readme validator, and Plugin Check 2.0.0.

## Verdict

The `0.5.0` ZIP is technically ready for manual submission after the release branch is committed/merged.
No code, packaging, readme, license, or external-service blocker remains.

One Plugin Check runtime warning is intentionally accepted: `EnqueuedScriptsScope` for
`assets/js/tracker.js`. The tracker must load across the public site to capture the landing visit and preserve
first/last-touch attribution before the visitor reaches a form. The script is local, deferred, and does not
contact Apointoo unless an administrator configures the service.

## Verified release

- Artifact: `dist/apointoo-capture-0.5.0.zip`.
- Plugin name/slug/text domain: `Apointoo Capture` / `apointoo-capture` / `apointoo-capture`.
- License: GPLv2 or later in the plugin header, `readme.txt`, and `LICENSE`.
- Requirements: WordPress 6.4+, PHP 8.0+, tested through WordPress 7.0.
- Package: one `apointoo-capture/` root, runtime files only, about 152 KB uncompressed.
- The local attribution feature works without an Apointoo account or external request.
- Connected mode sends form lead fields and consent-aware attribution only after an administrator saves an
  Apointoo tenant key and HTTPS intake URL.
- Intake URLs are restricted to `https://dash.apointoo.com`; outbound posts use `wp_safe_remote_post()`.
- No remote executable code, bundled WordPress libraries, public credits, license gates, trials, quotas, or
  unrelated SMTP behavior.

## External-service disclosure

- Service: https://www.apointoo.com/
- Terms: https://www.apointoo.com/en/terms (HTTP 200 verified 2026-07-16)
- Privacy: https://www.apointoo.com/en/privacy (HTTP 200 verified 2026-07-16)
- Both legal pages identify Apointoo as operated by Vizuh OÜ.

## Naming and ownership

- Submit with the existing WordPress.org account `hugoc` (`hugo@vizuh.com`), not the nonexistent `apointoo`
  account. `Contributors: hugoc` matches the verified profile.
- The `hugoc` profile identifies Hugo as Vizuh's founder and links to `vizuh.com`; Apointoo's legal pages identify
  Vizuh OÜ as operator. Keep this evidence ready if the reviewer asks for Guideline 17 ownership proof.
- The requested permanent slug is `apointoo-capture`. Confirm it before final submission.

## Checks run

- Install and activation on WordPress 7.0.1: pass.
- Plugin Check 2.0.0 static checks: pass.
- Plugin Check 2.0.0 runtime checks: one accepted `EnqueuedScriptsScope` warning; no other findings.
- Official WordPress readme validator: no errors or warnings; only optional missing sections (upgrade notice,
  screenshots, donate link).
- WordPress Coding Standards PHPCS: pass, 23/23 files.
- PHPCompatibilityWP for PHP 8.0+: pass, 23/23 files.
- PHP 8.0 attribution/consent contract: pass.
- PHP 8.0 endpoint allowlist contract: pass.
- JavaScript channel-bucket/form-injection contract: pass.
- ZIP integrity and `git diff --check`: pass.

## Manual submission gate

1. Commit and merge the release diff; rebuild the ZIP from that exact commit.
2. Confirm no other plugin is currently awaiting first review on `hugoc`.
3. Confirm the WordPress.org profile email remains human-monitored and whitelist `plugins@wordpress.org`.
4. On the submission page, acknowledge the FAQ, guidelines, Plugin Check result, naming ownership, trialware,
   and directory-compliance statements.
5. Upload `dist/apointoo-capture-0.5.0.zip`; request/confirm slug `apointoo-capture`.
6. Do not push to the assigned SVN repository until the plugin is ready to go live; then publish finished code in
   `trunk/` and tag `0.5.0` with matching stable tag/version.
