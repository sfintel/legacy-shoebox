This directory vendors [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn)
(MIT license, see `LICENSE` in this directory) — a dependency-free PHP
WebAuthn/passkey implementation. Used as-is, unmodified, via plain
`require_once` (the library itself has no Composer/autoloader
requirement, matching this project's own "no build step" philosophy).

Only `includes/webauthn_helper.php` (one directory up) is this
project's own code — it's a thin wrapper around this library's
`\lbuchs\WebAuthn\WebAuthn` class, translating between it and this
app's session/database conventions.

Do not hand-edit files in this directory — if the upstream library
needs an update, re-fetch it wholesale from the source repository above
rather than patching individual files, so this directory stays a clean,
verifiable copy of a known upstream commit.
