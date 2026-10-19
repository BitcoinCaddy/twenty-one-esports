# Threat model: TWENTY ONE Esports

## What this project does

A Laravel 13 / Livewire 4 web app (PHP 8.5, PostgreSQL + Redis in production; SQLite in the test suite) that runs a
Bitcoin-themed esports league: chess and board games played live in the browser, tournaments with Lightning prize
pots, clans, a public ladder, and a 24/7 video stream with an AI radio voice. Identity is a Nostr key; prize money is
real bitcoin moved over Lightning via Nostr Wallet Connect (NWC).

## Where untrusted input enters

- **Login**: `POST auth/nostr/challenge` and `POST auth/nostr/login` (NIP-98 style kind 27235 event,
  `app/Support/Nostr/NostrLogin.php`, challenges in `app/Support/Nostr/LoginChallenges.php`). There is no password
  login. Anything that logs a user in as a pubkey they cannot sign for is critical.
- **Signed Nostr events from the browser**: `app/Support/Nostr/SignedEvent.php`, `SignedEventGate.php`,
  `EsportsEventRules.php`, `POST profiles` (`ProfileCache`). The server prepares event templates; the client signs.
- **Lightning (public, CORS `*`)**: `GET .well-known/lnurlp/{username}` and `GET lnurlp/{username}/callback`
  (`app/Http/Controllers/LnurlPayController.php`, `app/Support/Prizes/PoolInvoices.php`); zap receipts in
  `app/Support/Prizes/ZapReceipts.php`; settlement in `IncomingPayments.php`.
- **Bearer-token machine APIs** (no session, no CSRF): `api/caster/*` (`CasterFetchController`, shared token,
  accepts audio uploads that are later decoded by ffmpeg) and `api/scores/ingest` (`ScoreIngestController`,
  per-server tokens).
- **Secret-in-URL**: `broadcast/{token}` overlays, signed DM opt-out links.
- **Livewire actions** (`/livewire/update`): every public method on a component is reachable by any logged-in user
  who can load the page. Admin pages under `resources/views/pages/admin/` re-check `Gate::authorize('admin')` per
  action; that per-action check is the real guard.
- **Game moves**: chess (`app/Support/Chess/ChessGameService.php`), board games (`app/Support/Board/`), Hyper
  (`HyperMatchController`), Proof of Pong (`PongController`, client-reported scores), Stacker runs replayed in Node
  (`app/Support/Stacker/NodeVerifier.php`).
- **Uploads**: avatar, clan logo, sponsor logo, lobby screenshots, dispute evidence (`matches/⚡room`).
- **Broadcast channels** (`routes/channels.php`): private game/seat channels carry hidden information (seat secrets).
- **Server-side fetches**: Lightning address / LNURL resolution (`app/Support/Lightning/LightningAddress.php`, SSRF
  guard), NWC relay connections (`app/Support/Wallet/RelayGuard.php`), `app/Support/ImageProxy.php`.

## Components that matter most

1. **Money**: payouts (`app/Support/Payouts/PayoutRunner.php`, `PayingWallet`, `NwcClient`), pot invoices and zap
   receipt validation. Paying the wrong recipient, paying twice, paying a different amount, or crediting a pot
   without a settled payment.
2. **Authentication and authorization**: Nostr login, the `admin`, `manage-tournament` and `direct-tournament`
   gates, ownership checks for clans, matches, tournaments and desk chat.
3. **Game integrity**: submitting illegal moves, moving for the opponent, forging results or scores, reading hidden
   information (seat secrets, sealed opponent identity) from channels or responses.
4. **Privacy promises**: a player's Nostr profile is never publicly linked to gamer tags or game profiles; the
   opponent's identity is revealed only to the opponent, sealed, on click. A leak of that link is in scope.

## Out of scope

- `tools/`, `scripts/`, `docs/`, `docker/`, `vendor/`, `tests/Evals/`.
- Console commands that only run on the operator's workstation or the stream daemon (`app/Console/Commands/TwentyOneStream*`,
  `Caster*` commands, `TwentyOneMusic*`), unless reachable from an HTTP request.
- `routes/testing.php` (registered only when `APP_ENV=testing`) and the `styleguide` route.
- Missing rate limits or verbose errors with no further impact; findings that require a malicious operator, a
  compromised server, or the shared caster/score tokens.

## How to exercise it

- `php artisan test --compact` runs the default suites (Unit, Feature, Nostr) offline on SQLite in memory.
  Browser, relay and integration groups need external services and are excluded.
- `oss-scanner-serve` (see the Dockerfile) starts the app on `http://127.0.0.1:8000` against a migrated SQLite
  database. Tests use factories (`database/factories/`) to create users, admins (`admins` table) and tournaments.
- Nostr signing in tests: see `tests/` helpers around `SignedEvent`; a test key signs kind 27235 and kind 0 events.

## How we rate severity

- **Critical**: loss or theft of sats (payout to an attacker, double payout, forged pot credit); login as another
  pubkey; becoming admin without being one; remote code execution; reading or using NWC secrets or Nostr private keys.
- **High**: unauthorized state change in someone else's match, tournament or clan (result, dispute, roster, pot
  settings); stored XSS that runs for other users; SSRF that reaches internal services; leaking the sealed link
  between a Nostr identity and game profiles.
- **Medium**: reflected XSS; IDOR that only reads non-sensitive data; game-integrity bugs that need a cooperating
  opponent; upload of content that is served but not executed.
- **Low**: denial of service of a single request or user, missing hardening headers, information disclosure of
  public data.

## How we would like reports

One finding per report: affected file and line, the request or Livewire call that triggers it, a Pest test that
fails before the fix, and a minimal patch in the style of the surrounding code.
