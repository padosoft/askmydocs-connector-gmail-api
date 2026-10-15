# Verification record

Date: 2026-10-15. Runtime implementation commit: `9943899` on `codex/gmail-api`.
All content, messages, identities and credentials in these tests are synthetic.
No mailbox, paid AI, push, release tag or PR was used.

| Check | Result |
|---|---|
| Laravel 13.36 / PHP 8.4.25 / PHPUnit 12.5 | 91 tests, 405 assertions passed |
| Separate Laravel 12.70 / PHP 8.3.33 / PHPUnit 11.5 copy | 91 tests, 405 assertions passed |
| `composer validate --strict` | Passed |
| `vendor/bin/pint --test` | Passed for the entire package |
| `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` | Passed, level 5, entire src |
| `git diff --check` | Passed |
| Editorial README validator | Header plus three separate explanatory PNGs; existing transparent email artwork reused |
| Compatibility CI | Configured PHP 8.3/Laravel 12 and PHP 8.4/8.5/Laravel 13; not run remotely |

The second runtime was installed in `/private/tmp/askmydocs-gmail-laravel12`
with Testbench ^10 and PHPUnit ^11 using Herd `php83`, then supplied the final
source/config/tests from this repository. Its dependencies are independent of
the package's Laravel 13 development vendor directory.

The synthetic coverage includes signed OIDC/state/replay/nonce/scope/refresh,
HTTP endpoint and byte/time/retry gates, full/history multipage resume and
concurrent catch-up, attachment dispatch crash replay, expired history,
malformed MIME/full responses, exact list settings, configuration/project
changes, labels rename, scoped deletion, same-name/inline/oversize attachments,
redaction commitments, event-before-write and failed storage/listener behavior.

The isolated AskMyDocs host worktree additionally runs native Gmail and Microsoft
Mail discovery, health, acquisition and triage on/off through the real host
bridge. Its smoke plus connector registry tests passed 7 tests / 100 assertions.
The common host regression and UI evidence is recorded with the host delivery.

Live Google OAuth/consent, Google restricted-scope verification, approved callback
routing for the existing Herd HTTPS site and live-mailbox performance were not
executed. Host `test-fetch` and `email-triage:pilot` remain IMAP-only; Gmail uses
its own read-only health/discovery. A generic downstream host must implement the
email v1 listener and revision/receipt idempotency to offer shared triage.
