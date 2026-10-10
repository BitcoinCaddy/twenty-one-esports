---
paths:
  - 'resources/views/**'
---

# Views

Rules R1–R21 of the design canvas (plan "Refactor und Design-Revamp", board "Regeln"). They bind every view; code
comments and tests cite the ID. The canvas board is the spec: change the board first (with a reason and the user's
OK), then the code.

## R1 Grid
One 12-column grid, content 1344 px wide, gutter 24, page margin 48. Main content 8 columns on the left, chat and
secondary features 4 columns on the right. Full bleed only for the key-art band. No alternating full/half-width modules.

## R2 Reading width
Running text at most 68 characters. Tables use the container width, numbers right-aligned, column order fixed.

## R3 Density and scrolling
Key art at most 320 px high. Start page at most 2.5 screens. Lobby, match and tournament: primary action and chat
without scrolling. Lists are paginated. Phone: no module taller than one screen. The first desktop screen shows
purpose, primary action and real data.

## R4 Header
One header for every page: Spiele, Turniere, Mempool, Season, Clans, LIVE; on the right search, Aktionen, bell
(history), Admin (admins only), account.

## R5 Game tabs
Fixed tabs per game kind in the same order (Spielen, Matches, Ladder, Turniere, Regeln), primary action on the right,
sticky. What a game does not have is left out, never rearranged. Every game page renders through the one shared
template for its kind (`<x-game.page>` lobby T1, `<x-ladder.page>` ladder T5); a deviation needs a measured reason and
a canvas update first.

## R6 Chat
Chat is a primary element: right column down to the end of the first screen (at least 480 px), sticky, the list
scrolls, the composer is pinned at the bottom. Phone: a handle above the tab bar. Same place in every template.

## R7 Secondary features
Share, zap, invite, Nostr, follows, comments: one line at the end of the right column, loaded only when opened,
fixed height, never above the page's primary content, no layout shift.

## R8 Chip filters
Priority+: one line, "Alle" first, then by count; chips without hits dimmed but clickable. Only when the row really
runs out of width the rest folds into "+N weitere" (phone: horizontal scroll with an edge fade). Short names from the
registry. Never a second line.

## R9 Tournaments with a pot
Organizer tournaments and tournaments with a pot get the highlight card on top; in lists an accent edge and a pot
chip; the pot in sats, orange, tabular.

## R10 Result entry
Scoreboard: both sides big on the left/right, per game an input with steppers (48 px), a "Nur Sieger" switch, a sum
line before the button, inline errors at the row; checking a result uses the same board.

## R11 Own controls
Never a browser-native select, checkbox or radio: dropdown `.dd`, checkbox `.ck`. Interactive elements (buttons,
tabs, chips, counters) never wrap their text.

## R12 No spec panels
Pages show the real state of their one primary action. States, variants and demos live on the components board only.

## R13 Real data only
Every number comes from code or prod; no invented levels, XP, ranks or streaks. New things are marked "NEU (Scope)"
on the board and asked for first.

## R14 Bitcoin identity
Mempool cubes, season chain, blocks, epochs, sats and Nostr stay visible. No generic esports look.

## R15 One verb per action
Anmelden = log in, Eintragen = tournament sign-up, Herausfordern = a game; the toast uses the same verb.

## R16 Tokens
Colours #0A0A0B, #121215, #2A2A30, #FFFFFF, #ADADB0, #8B8B90, #F7931A, #FF3B3B, #4ADE80, #F87171; Unbounded 600/700
for titles, JetBrains Mono 12/13/14/16/20/24/30/36; spacing 4/8/12/16/24/32/48; radius 4 (marks 2); targets 44 px;
focus 2 px #F9B25F. Revamp primitives live in resources/css/revamp.css (`rv-*`), reuse them.

## R17 Tempo
150–300 ms ease-out; toasts at least 4 s plus 60 ms per character; scenes and statistics wait for a click, texts at
least 3 s; reduced motion: no pulse, no inertia.

## R18 Confirm
Only what is final or costly (resign, withdraw, report a problem); focus on the safe choice.

## R19 New game
Only through a GameRegistry entry and its kind; the kind picks the templates. Own arrangements are forbidden. The
display order is `esports.game_order`: browser games first (Hyperbitcoinization, Proof of Pong, chess, Blockfill,
Blockli, Nine Men's Morris, checkers), the own-copy games after them.

## R20 Lobby access on top
The lobby card (name and password always visible, each with copy, who joined) sits right under the header and the
steps, above every step's content; folded after confirmation. Tournament matches: the league creates the access at
the draw and the tournament director can watch. Casual: the host shares.

## R21 Every page has a template
A new page = an entry in the canvas Code-Mapping and coverage.md before the merge, and in
tests/Support/page_templates.php (guarded by tests/Feature/PageTemplatesTest.php).

## Standing constraints
- Gamer tags are not required: own-copy games work by sharing private match credentials in the match room, so never
  prompt players to add a gamer tag or link a game account, and never link a Nostr profile to a gamer tag publicly.
- No fee wording, no Lightning address text on screens.
- Prod cache runs with `serializable_classes=false`: never cache objects (Carbon, models), only scalars and arrays.
