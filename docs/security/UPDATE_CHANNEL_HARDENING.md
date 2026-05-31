# Update channel integrity — future hardening

Status: NOT implemented in security plan C (2026-05-31). Documented for a future release.

## Gap
`src/Updates/UpdateChecker.php` uses PUC v5 against GitHub Releases. The release
workflow publishes `slashbooking-<v>.zip.sha256`, but nothing verifies it before
WordPress extracts and runs the downloaded ZIP. Anyone who can publish a Release
on the source repo can push arbitrary PHP to every install.

## Recommended fix (future)
1. Hook `upgrader_pre_download` (or PUC's `puc_pre_inject_update`).
2. Fetch the `.sha256` sidecar over HTTPS for the resolved version.
3. Reject the install unless `hash_file('sha256', $downloaded) === $published`.
4. Better: sign each ZIP (minisign/cosign) in `release.yml` and verify a detached
   signature against a public key shipped in the plugin before extraction.
5. Pin all GitHub Actions to commit SHAs and scope `contents: write` to the
   publish step only (audit finding 9).

## Why deferred
Requires CI changes plus an authenticated fetch path; out of scope for the
behavior-only hardening in plan C.
