# Security Policy

## Reporting a vulnerability

Please report security issues privately by email to **office@primesoftware.company**. Do not open a public issue
for a vulnerability.

Include what you found, how to reproduce it (the request, page or Livewire action involved) and the impact you
expect. We confirm receipt and keep you posted until it is fixed.

## Scope

The application in this repository as deployed at <https://esports.einundzwanzig.space>: Nostr login, tournaments
and their Lightning prize pots, payouts, matches and the stream. `.oss-scanner/threat_model.md` describes what we
consider critical.

Out of scope: the tooling under `tools/` and `scripts/`, and third-party services such as Nostr relays and
Lightning wallets.

## Supported versions

Only the current `master` branch is supported; it is what runs in production.
