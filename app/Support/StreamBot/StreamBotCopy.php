<?php

namespace App\Support\StreamBot;

use LogicException;

/**
 * Every word the stream chat bot says (P22), in one place. The language
 * follows the channel (user 2026-10-11, plan "KI-Stimme der Liga"): the
 * live chat templates (the rotation, the GG) are German, in the league's
 * everyday tone, because the memes come from a German podcast; the notes on
 * the bot's Nostr profile stay English for the international audience.
 * These templates are also the league voice's fallback (BotVoice).
 *
 * Each template has one or more variants; a variant is a list of lines
 * (at most four). `:placeholder` values come from the builders
 * (StreamBotBuilders), already formatted. A line whose placeholder is null
 * is left out, so an optional fact (a prize pot, a clan count) needs no
 * extra variant. Links are always the last thing on their line: chat
 * clients take the URL up to the next space, and trailing punctuation
 * would end up inside it.
 *
 * Layout: zap.stream and zap.observer show chat text with `white-space:
 * normal`, so the line breaks collapse into spaces there (measured
 * 2026-09-27: zap.stream src/element/chat/chat-message.tsx renders the
 * content inside a plain <span>; zap.observer's chat line is
 * `whitespace-normal`). Every line therefore starts with an emoji, which
 * separates the lines when they run together. Clients that keep line
 * breaks show them as a small card.
 *
 * Rules (checked by StreamBotCopy::violations() on every message):
 * no `#` anywhere (no hashtags, a standing rule of this project), and at
 * least one direct http(s) link. Change the wording freely; keep the
 * placeholders of a template.
 */
final class StreamBotCopy
{
    /** Most lines one message may have. */
    public const MAX_LINES = 4;

    /**
     * @var array<string, list<list<string>>>
     */
    public const TEMPLATES = [
        // Upcoming tournaments, open for sign-up.
        'tournament_signup' => [
            ['🏆 Anmeldung offen: :name', '🎮 :game · Start :starts', '🪑 :spots:pot', '👉 Platz sichern: :url'],
            ['📣 Neu im Kalender: :name', '🗓️ :starts · :game', '🪑 :spots:pot', '👉 :url'],
            ['🆕 :name sucht noch Leute', '🎮 :game · Start :starts', '🪑 :spots:pot', '👉 Rein da: :url'],
            ['📋 Frisch angesetzt: :name', '🗓️ Start :starts · :game', '🪑 :spots:pot', '👉 :url'],
            ['🎫 Bei :name ist noch Platz', '🎮 :game, los geht\'s :starts', '🪑 :spots:pot', '👉 Eintragen: :url'],
            ['🏟️ :name steht an', '🗓️ :starts · :game', '🪑 :spots:pot', '👉 Mal reinschauen: :url'],
        ],
        'tournament_last_call' => [
            ['⏳ Letzte Chance für :name', '🚪 Anmeldung zu in :left, :open noch frei', '👉 :url'],
            ['⏳ Nur noch :left bis :name dichtmacht', '🪑 :open noch frei · :game', '👉 Eintragen: :url'],
            ['🚨 :name schließt in :left', '🪑 :open übrig · :game', '👉 Schnell noch: :url'],
            ['⌛ Noch :left, dann ist :name zu', '🚪 :open noch offen', '👉 :url'],
        ],

        // Happening now.
        'tournament_live' => [
            ['🔴 :name läuft gerade', '📺 Bracket, Bretter und Ergebnisse live in der TV-Ansicht', '👉 :url'],
            ['📺 :name ist live. Jedes Match, sobald es passiert', '👉 :url'],
            ['🔴 :name in der TV-Ansicht', '👀 Die Bretter springen um, sobald was fertig ist', '👉 :url'],
            ['📡 :name, mittendrin', '👉 Bracket verfolgen: :url'],
        ],
        'live_game' => [
            ['♟️ Live am Brett: :white gegen :black', '⏱️ :mode, läuft', '👀 Zuschauen: :url'],
            ['👀 :white und :black spielen gerade :mode', '👉 Reinschauen: :url'],
            ['♟️ :white gegen :black, :mode, die Uhr tickt', '👀 Stuhl ranziehen: :url'],
            ['🔴 Ein Brett ist live: :white gegen :black', '⏱️ :mode', '👉 :url'],
        ],
        'live_games' => [
            ['🔴 :count Partien laufen gerade', '👀 Brett aussuchen und zuschauen: :url'],
            ['🔴 :count Matches live, genau jetzt', '👉 Zuschauen: :url'],
            ['👀 :count Bretter sind am Laufen', '👉 Such dir eins aus: :url'],
            ['📡 :count Live-Partien, eine Seite', '👉 :url'],
        ],
        'live_series' => [
            ['🏁 :game: :home gegen :away, Best of :best_of', '👉 Match verfolgen: :url'],
            ['🏁 :home gegen :away in :game, Best of :best_of', '👀 Live dabei sein: :url'],
            ['⚔️ Serie läuft: :home gegen :away', '🎮 :game, Best of :best_of', '👉 :url'],
            ['📺 :home und :away spielen eine Serie in :game', '🏁 Best of :best_of', '👉 Zur Match-Seite: :url'],
        ],
        // A chess team match of two clans (plan "Schach Rapid und Clan", P6), posted by the live_series builder.
        'live_team_match' => [
            ['♟️ Clan-Teammatch: :home gegen :away, :boards Bretter Rapid gleichzeitig', '👉 Alle Bretter: :url'],
            ['♟️ :home gegen :away. Schach im Team, :boards Bretter', '👀 Live verfolgen: :url'],
            ['🛡️ :home gegen :away, :boards Bretter auf einmal', '👀 Alles auf der Match-Seite', '👉 :url'],
            ['♟️ Team-Rapid: :home gegen :away', '🏁 :boards Bretter, ein Match', '👉 :url'],
        ],

        // Results and pride.
        // The GG the moment a tournament is decided (ChampionChat), outside the rotation: one winner, then a shared place 1.
        'tournament_gg' => [
            ['🏆 GG! :winner gewinnt :name 🎉 :url'],
            ['🏆 GG! :winner gewinnen :name 🎉 :url'],
        ],
        'tournament_winner' => [
            ['🥇 :winner hat :name gewonnen', '🎉 GG an alle, die mitgespielt haben', '👉 Bracket und Ergebnisse: :url'],
            ['🏆 Sieger von :name: :winner', '👉 So lief es: :url'],
            ['🥇 :name geht an :winner', '🎉 Stark gespielt, das ganze Feld', '👉 :url'],
            ['🏆 Finale von :name: :winner', '👉 Das Bracket: :url'],
        ],
        'rank_up' => [
            ['📈 Aufgestiegen! :player ist jetzt :tier in :game', '👉 :url'],
            ['🎖️ :player klettert auf :tier (:game)', '👉 Profil und Abzeichen: :url'],
            ['📈 :player hat gerade :tier erreicht, in :game', '👉 :url'],
            ['🎖️ :player hat einen neuen Rang: :tier in :game', '👉 Zum Abzeichen: :url'],
        ],
        'new_clan' => [
            ['🛡️ Neuer Clan: :name [:tag]', '🤝 Sag Hallo und fordere sie raus', '👉 :url'],
            ['🛡️ Willkommen in der Liga, :name [:tag]!', '👉 Zum Clan: :url'],
            ['🆕 Clan gegründet: :name [:tag]', '🤝 Die Herausforderung ist offen', '👉 :url'],
            ['🛡️ [:tag] :name ist dabei', '👉 Hallo sagen: :url'],
        ],
        'ladder_top' => [
            ['🪜 :ladder-Ladder gerade', ':podium', '👉 Ganze Ladder: :url'],
            ['🪜 So steht die :ladder-Ladder', ':podium', '👉 Alles sehen: :url'],
            ['🥇 :ladder-Podest heute', ':podium', '👉 Der Rest der Ladder: :url'],
            ['🪜 Spitze in :ladder', ':podium', '👉 :url'],
        ],
        'season_live' => [
            ['⛏️ Die Saison läuft: Gewertete Siege minen Blöcke auf der Liga-Chain', '👉 Der Chain beim Wachsen zusehen: :url'],
            ['⛏️ Saison läuft. Jeder gewertete Sieg mint einen Block', '👉 Zuschauen: :url'],
            ['⛏️ Gewertete Siege minen. Die Chain wächst', '👉 Verfolgen: :url'],
            ['⛓️ Saison-Chain ist live. Ein gewerteter Sieg ist ein Block', '👉 :url'],
        ],
        'season_countdown' => [
            ['⛏️ Block 0 in :left', '🔓 Dann starten Wertung und Mining, bis dahin ist alles Casual', '👉 :url'],
            ['⛏️ Noch :left bis Block 0', '🔓 Dann geht\'s gewertet los, jetzt noch Casual', '👉 :url'],
            ['⏳ Block 0 in :left', '🎮 Bis dahin Casual, danach gewertet', '👉 :url'],
            ['⛏️ Noch :left, dann startet das Mining', '👉 Die Chain: :url'],
        ],
        'stats' => [
            ['📊 Bisher: :players Spieler, :clans Clans, :games Partien gespielt', '👉 Mach mit: :url'],
            ['📊 :players Spieler, :clans Clans, :games Partien bis jetzt', '👉 Komm dazu: :url'],
            ['📊 Die Liga gerade: :players Spieler, :clans Clans', '♟️ :games Partien schon gespielt', '👉 :url'],
            ['👥 :players Spieler dabei, :clans Clans daneben', '📊 :games Partien auf dem Brett', '👉 :url'],
        ],

        // What you can do.
        'play_blitz' => [
            ['⚡ Lust auf eine Partie? Rapid 10+5 oder Blitz 5+3 in der Lobby', '👉 :url'],
            ['♟️ Noch eine Rapid vorm Schlafen? 10+5, direkt im Browser', '👉 :url'],
            ['⚡ Blitz 5+3 ist offen. Die Uhr läuft, sobald beide sitzen', '👉 :url'],
            ['♟️ Rapid 10+5, im Browser, nichts installieren', '👉 Brett schnappen: :url'],
            ['🧠 Eine knackige Partie. Die Lobby reicht', '👉 :url'],
            ['🌙 Spät und noch wach? Rapid ist ein Klick', '👉 :url'],
            ['🏁 Casual-Elo bewegt sich beim Spielen, nicht beim Zuschauen', '👉 Hinsetzen: :url'],
            ['🎯 Blitz oder Rapid, gleiche Lobby, gleiche Ladder', '👉 :url'],
        ],
        'daily_chess' => [
            ['📬 Keine Zeit für live? Fernschach: ein Zug am Tag, wann es dir passt', '👉 Jemanden herausfordern: :url'],
            ['📬 Wenig Zeit? Fernschach: ein Zug am Tag, ganz ohne Stress', '👉 Partie starten: :url'],
            ['📬 Ein Zug heute hält eine echte Partie am Leben', '👉 Fernschach: :url'],
            ['🌙 Fernschach wartet. Um Mitternacht verfällt nichts', '👉 :url'],
            ['♟️ Keine Uhr, ein Zug am Tag, ein echter Gegner', '👉 Partie öffnen: :url'],
            ['📬 Fernschach ohne Pflicht: ein Zug, wenn du einen hast', '👉 :url'],
        ],
        'clan_challenge' => [
            ['🚗 :games: Clans fordern Clans in Best-of-Serien', '👉 Herausfordern: :url'],
            ['🚗 Clans treten in Best-of-Serien gegeneinander an. :games', '👉 Fordere einen raus: :url'],
            ['🛡️ Clan gegen Clan, Best of: :games', '👉 Ab dafür: :url'],
            ['⚔️ Die Clan-Ladder will benutzt werden: :games', '👉 :url'],
            ['🏁 Kein Einzelspiel. Best-of-Serie in :games', '👉 Clan herausfordern: :url'],
            ['🤝 Ruf einen anderen Clan raus. :games', '👉 :url'],
        ],
        'invite_friend' => [
            ['💌 Spiel gegen einen Freund: Einladungslink in der Lobby holen und verschicken', '👉 :url'],
            ['💌 Kumpel zum Spielen da? Hol dir einen Einladungslink in der Lobby', '👉 :url'],
            ['🤝 Ein Freund, ein Link, ein Brett. Mehr braucht\'s nicht', '👉 :url'],
            ['📨 Die Lobby schreibt den Link. Du schickst ihn nur ab', '👉 :url'],
            ['🎮 Keine Schnitzeljagd: Link rein, Freund spielt', '👉 :url'],
            ['🔗 Ein Link aus der Lobby, ein Freund auf der anderen Seite', '👉 :url'],
        ],
        'clans' => [
            ['🛡️ Zusammen geht\'s besser: Tritt einem Clan bei oder gründe einen', '👥 :count Clans sind schon dabei', '👉 :url'],
            ['🛡️ Solo oder Squad? Clan beitreten oder selbst einen starten', '👥 :count Clans schon da', '👉 :url'],
            ['🏴 Ein Clan ist ein Tag und Leute, die auftauchen', '👥 :count Clans sind schon dabei', '👉 :url'],
            ['🤝 Beitreten oder gründen. Zählt beides', '👥 :count Clans schon da', '👉 :url'],
            ['🛡️ Die Clan-Liste ist offen', '👥 :count Clans sind schon dabei', '👉 Finde deinen: :url'],
            ['👥 Mit Squad spielt sich die lange Saison besser', '🛡️ :count Clans schon da', '👉 :url'],
        ],
        'badges' => [
            ['🎖️ Jeder Rang hat ein Abzeichen für dein Nostr-Profil', '👉 Losklettern: :url'],
            ['🎖️ Rang hoch, Abzeichen fürs Nostr-Profil', '👉 :url'],
            ['🏅 Ein Rang ist ein Abzeichen, keine geheime Zahl', '👉 Klettern: :url'],
            ['🎖️ Die Ladder zahlt in Abzeichen. Die pinnst du auf Nostr', '👉 :url'],
            ['📈 Silber, Gold und weiter: Jeder Rang hat sein Abzeichen', '👉 Los: :url'],
            ['🥇 Hier aufsteigen, auf Nostr zeigen', '👉 :url'],
        ],
        'all_games' => [
            ['🎮 :games: alle Spiele und Modi auf einer Seite', '👉 :url'],
            ['🎮 Eine Seite, alle Spiele und Modi: :games', '👉 Schau rein: :url'],
            ['🗂️ Die ganze Liga auf einer Seite: :games', '👉 :url'],
            ['🎯 Spiel aussuchen, Modus checken, hinsetzen', '🎮 :games', '👉 :url'],
            ['🧭 Keine Ahnung, wo anfangen? Die Spiele-Seite', '🎮 :games', '👉 :url'],
            ['🎮 Bretter, Lobbys, Wochen: :games', '👉 Alles: :url'],
        ],
        'login' => [
            ['🔑 Mit Nostr oder Google anmelden, und du bist drin', '👉 :url'],
            ['🔑 Login mit Nostr oder Google, dauert Sekunden', '👉 :url'],
            ['🔐 Nostr oder Google. Dann bist du Spieler, nicht Gast', '👉 :url'],
            ['🪪 Der Login ist die Tür. Dahinter warten die Spiele', '👉 :url'],
            ['⚡ Einmal anmelden. Wertung, Clan und Einladungen bleiben', '👉 :url'],
            ['🔑 Zwei Türen: Nostr oder Google', '👉 :url'],
        ],
        'zap' => [
            ['⚡ Gefällt dir der Stream? Zap ihn direkt hier, jeder Sat sagt Danke', '🌐 Mehr auf der Seite: :url'],
            ['⚡ Stream gefällt? Ein Zap bringt viel', '🌐 :url'],
            ['🧡 Ein Zap auf den Stream ist ein Sat, der ankommt', '🌐 :url'],
            ['⚡ Der Stream läuft den ganzen Tag. Ein Zap ist dein Winken', '🌐 :url'],
            ['💸 Keine Rede nötig: Zap den Stream', '🌐 Mehr von der Liga: :url'],
            ['🙏 Hat dich das Overlay grinsen lassen? Zap es', '🌐 :url'],
        ],

        // TMNF's weekly time attack (plan "Trackmania und Restposten"), in the chat only: the running week, a new
        // best time of it, the finished week's podium. Drivers by Nostr key or league name, never a TMNF login.
        // The favourite link (tmtp://#addfavourite=…) stays on the week page: zap.stream would render its `#` as a hashtag.
        'tmnf_week' => [
            ['🏎️ TMNF-Zeitfahren diese Woche: :track', '🏁 Fahr auf unserem Server :server, deine beste Zeit bis :ends zählt', '👉 So machst du mit: :url'],
            ['🏁 :name läuft auf :track', '🏎️ Kostenlos: Rein auf :server in TMNF und Zeit setzen bis :ends', '👉 Mitmachen: :url'],
            ['🏎️ Die TMNF-Strecke dieser Woche ist :track', '🏁 Server :server, beste Zeit bis :ends', '👉 So machst du mit: :url'],
            ['🏁 :name, Strecke :track, offen bis :ends', '🏎️ Unser Server: :server', '👉 :url'],
        ],
        'tmnf_top' => [
            ['🥇 Neue Bestzeit in :name: :player', '⏱️ :time auf :track:gap', '👉 Schlag das: :url'],
            ['⚡ :player führt :name an, mit :time auf :track', '🏎️ Du bist schneller?', '👉 Dein Zug: :url'],
            ['🥇 :player hat sich :name geschnappt', '⏱️ :time auf :track:gap', '👉 Zur Wochenseite: :url'],
            ['🏎️ Neue Führung in :name: :player, :time', '🏁 :track:gap', '👉 Schlag das: :url'],
        ],
        'tmnf_podium' => [
            ['🏆 :name ist vorbei: :winner gewinnt auf :track', ':podium', '👉 Alle Wochen: :url'],
            ['🏁 Endstand von :name auf :track', ':podium', '👉 :url'],
            ['🏆 :track ist entschieden. :winner holt sich :name', ':podium', '👉 Jede Woche: :url'],
            ['🥇 :name, Endstand: :winner', ':podium', '👉 :url'],
        ],

        // Notes on the bot's own profile (kind 1, TournamentNotes), one per tournament, by its status
        // when the note goes out. The `nostr:naddr1…` of the calendar event follows after a blank line.
        // Every profile note has several wordings; ProfileNotes picks the one after the type's last note, so the same
        // wording never follows itself. A tournament note always names its start (TournamentNotes finds a moved one by it).
        'tournament_note_open' => [
            ['🏆 New tournament: :name', '🎮 :game · starts :starts', '💰 :pot sats in the pot', '👉 Sign up: :url'],
            ['🏆 :name starts :starts. Sign up: :url'],
            ['👀 Worth a seat: :name starts :starts.', '🎮 :game', '💰 :pot sats in the pot', '👉 :url'],
            ['🆕 :name is open. It starts :starts.', '🎮 :game', '💰 :pot sats in the pot', '👉 Take a seat: :url'],
            ['📣 Fresh card: :name starts :starts.', '👉 Sign up: :url'],
        ],
        'tournament_note_running' => [
            ['🏆 Tournament on now: :name', '🎮 :game · started :starts', '💰 :pot sats in the pot', '👉 Follow it: :url'],
            ['🔴 :name is under way. Follow it: :url'],
            ['📺 It is moving: :name started :starts.', '🎮 :game', '👉 Watch the bracket: :url'],
            ['🔴 Live bracket: :name', '🎮 :game · started :starts', '👉 :url'],
        ],
        'tournament_note_finished' => [
            ['🏆 Tournament: :name', '🎮 :game · played :starts', '💰 :pot sats in the pot', '👉 Bracket and results: :url'],
            ['🏁 :name is decided. See how it went: :url'],
            ['🏁 That one is over: :name, played :starts.', '🎮 :game', '👉 :url'],
            ['🏆 :name is in the books', '🎮 :game · played :starts', '👉 Results: :url'],
        ],
        // Free-places reminders on the same profile (FreePlaceNotes, P49), while sign-up is open and
        // places are left; the `nostr:naddr1…` follows after a blank line here too.
        'tournament_note_places' => [
            ['🪑 :free of :places places left: :name', '🎮 :game · starts :starts', '⏳ Sign-up closes in :left', '👉 Grab a place: :url'],
            ['🪑 :name still has :free places. Sign up: :url'],
            ['🪑 Room left in :name, :free of :places, closes in :left.', '🎮 :game · starts :starts', '👉 :url'],
            ['🚨 :free seats left in :name', '⏳ Closes in :left · starts :starts', '👉 :url'],
        ],
        // Blockfill's week notes on the same profile (BlockfillNotes, plan "Blockfill", P6): a week once it is open,
        // its winner and top 3 once it is finished; the week's `nostr:naddr1…` follows after a blank line once published.
        'blockfill_note_week' => [
            ['🧱 A new Blockfill week is open: :name', '⏱️ Mine :blocks blocks as fast as you can, your best ranked run of the week counts', '🗓️ Until :ends', '👉 Play: :url'],
            ['⛏️ :name is open until :ends. Play: :url'],
            ['🧱 Fresh board: :name runs until :ends.', '🧱 :blocks blocks, best run counts', '👉 :url'],
            ['🆕 :name is the live Blockfill week', '⏱️ Best verified run before :ends', '👉 Play: :url'],
        ],
        'blockfill_note_winner' => [
            ['🏆 :name goes to :winner in :time', '🧱 Top 3: :podium', '🔁 A new week is on, every run starts from zero', '👉 All weeks: :url'],
            ['🥇 :winner wins :name in :time', '🧱 Podium: :podium', '🔁 Fresh week, fresh board', '👉 All weeks: :url'],
        ],
        // A verified run that took the running week's first place; `:gap` is empty for the week's first one.
        'blockfill_note_top' => [
            ['🥇 New first place in :name: :player', '⏱️ :time:gap', '🗓️ The week runs until :ends', '👉 Beat it: :url'],
            ['🥇 :player leads :name. Beat it: :url'],
            ['🥇 New name on top of :name: :player.', '⏱️ :time:gap', '👉 :url'],
            ['⚡ :player takes :name', '⏱️ :time:gap', '🗓️ Open until :ends', '👉 Beat it: :url'],
        ],
        // Several first places since the bot's last one (a burst) in one note: `:count` of them, the leader now and
        // the others who held the top meanwhile (`:others` null, so left out, when the leader only beat themself).
        'blockfill_note_top_burst' => [
            ['🥇 :count new first places in :name', '🏁 On top now: :player in :time', '⚔️ Also held it: :others', '👉 Beat it: :url'],
            ['🔥 First place changed hands :count times in :name', '🥇 :player leads with :time', '⚔️ Before: :others', '👉 Your turn: :url'],
        ],
        // TMNF's week notes (TmnfNotes, plan "Trackmania und Restposten", P2), as Blockfill's: the week and its track
        // once it is open, a new first place (`:gap` empty for the week's first), the winner and top 3.
        'tmnf_note_week' => [
            ['🏁 A new TMNF week is open: :name', '🏎️ Track :track on our own server, your best finish of the week counts', '🗓️ Until :ends', '👉 How to join: :url'],
            ['🏎️ :name is on: Track :track', '🏁 Free to play on our own server, only your best finish counts', '🗓️ Open until :ends', '👉 How to join: :url'],
        ],
        'tmnf_note_winner' => [
            ['🏆 :name goes to :winner in :time on :track', '🏁 Top 3: :podium', '🔁 A new week is on, every time starts from zero', '👉 All weeks: :url'],
            ['🥇 :winner wins :name on :track in :time', '🏁 Podium: :podium', '🔁 Fresh week, fresh board', '👉 All weeks: :url'],
        ],
        // On the profile only with `esports.stream_bot.tmnf_notes.top_on_profile`; the chat tells new best times (tmnf_top).
        'tmnf_note_top' => [
            ['🥇 New first place in :name: :player', '⏱️ :time on :track:gap', '🗓️ The week runs until :ends', '👉 Beat it: :url'],
            ['⚡ :player takes the lead in :name', '⏱️ :time on :track:gap', '🗓️ Open until :ends', '👉 Your turn: :url'],
        ],
        'tmnf_note_top_burst' => [
            ['🥇 :count new first places in :name', '🏁 On top now: :player in :time on :track', '⚔️ Also held it: :others', '👉 Beat it: :url'],
            ['🔥 First place changed hands :count times in :name', '🥇 :player leads with :time on :track', '⚔️ Before: :others', '👉 Your turn: :url'],
        ],
        // A finished tournament's champion on the same profile (ChampionNotes), once per tournament: the winner tagged,
        // the prize only once it is paid out; the `nostr:naddr1…` and the rendered champion slide follow after blank lines.
        'champion_note' => [
            ['🏆 :winner wins :name (:game)', '🎉 Congratulations, champion!', '⚡ :prize sats prize paid out', '👉 Bracket and results: :url'],
            ['🏆 :winner takes :name. 👉 :url'],
            ['🏆 That title is settled: :winner wins :name.', '🎮 :game', '⚡ :prize sats paid out to the champion', '👉 :url'],
            ['🎉 Champion: :winner, :name', '🎮 :game', '👉 The bracket: :url'],
        ],
        // Pride notes on the same profile (PrideNotes): players named for what they did, tagged; the
        // rendered slide follows after a blank line. Written by the kommunikator (2026-09-28).
        'pride_note_win' => [
            ['⚡ :winner takes the win over :loser (:mode)', '📈 :elo', '👉 Watch the game: :url'],
            ['🏆 :winner beats :loser. Watch it: :url'],
            ['👏 Nice one: :winner beat :loser in :mode.', '📈 :elo', '👉 :url'],
            ['⚡ :mode goes to :winner', '👀 Over :loser', '📈 :elo', '👉 Watch: :url'],
        ],
        'pride_note_climbers' => [
            ['🚀 Biggest gainers of the last :days days', '📈 :players', '👉 See the ladder: :url'],
            ['📈 Climbing fast this week', '📈 :players', '🗓️ Over the last :days days', '👉 :url'],
            ['🪜 Who moved up in :days days', '📈 :players', '👉 The ladder: :url'],
        ],
        'pride_note_signups' => [
            ['🙌 Welcome aboard: :players', '📝 :count new sign-ups', '❓ Who joins next?', '👉 :url'],
            ['📝 :players just signed up. 👉 :url'],
            ['🙌 New faces: :players.', '📝 :count sign-ups', '👉 :url'],
            ['🆕 :count new players', '🙌 :players', '👉 Come in after them: :url'],
        ],
        'pride_note_prizes' => [
            ['💰 :pot sats in the pot for :name', '🥇 :places', '🎁 :sponsors', '👉 :url'],
            ['⚡ :pot sats up for grabs: :name', '🥇 :places', '🎁 :sponsors', '👉 Sign up: :url'],
            ['💰 :name is holding :pot sats', '🥇 :places', '🎁 :sponsors', '👉 :url'],
        ],
        // A won series (Rocket League, EA Sports FC 1v1), in place of pride_note_win: the match page, not a game to watch.
        'pride_note_series_win' => [
            ['⚡ :winner takes the series over :loser (:mode)', '📈 :elo', '👉 See the match: :url'],
            ['🏆 :winner wins the series against :loser in :mode', '📈 :elo', '🎉 Well played to both', '👉 See the match: :url'],
        ],
        // A board game that won its winner a knockout tournament in its final (plan "Mühle und Dame", P7), in place of pride_note_win.
        'pride_note_tournament_win' => [
            ['🏆 :winner wins :tournament', '⚔️ Deciding game over :loser (:mode)', '📈 :elo', '👉 See the tournament: :url'],
            ['🥇 :tournament goes to :winner', '⚔️ Beat :loser in :mode', '🎉 Well played to both', '👉 :url'],
        ],
        // A lobby tournament's place 1 (plan "AoE2 und Trackmania", P8), in place of pride_note_win: every player on it tagged.
        'pride_note_lobby_win' => [
            ['🏆 :first in :tournament', '🎉 :winners', '🏰 :mode, one lobby match, :players players', '👉 See the places: :url'],
            ['🥇 :tournament is decided', '🏆 :first: :winners', '🏰 Diplomacy in :mode, :players players', '👉 :url'],
        ],
        // The same for a table (Swiss, round robin): the last game decided nothing on its own.
        'pride_note_tournament_table_win' => [
            ['🏆 :winner wins :tournament (:mode)', '📈 :elo', '👉 See the tournament: :url'],
            ['🥇 :tournament goes to :winner', '🎉 Well played, everyone', '👉 :url'],
            ['🏆 Table closed: :winner takes :tournament', '🎮 :mode', '👉 :url'],
        ],

        // Chat tips for games that had no line of their own. A builder offers one only while that game is registered.
        'proof_of_pong' => [
            ['🏓 Proof of Pong: bis 21, im Browser', '🕹️ Gegen den Bot oder live 1v1 um Elo', '👉 :url'],
            ['🏓 Pong, und der Ball ist ein Bitcoin-Meme', '⚡ Bis 21, zwei Punkte Abstand, direkt hier', '👉 Spielen: :url'],
            ['🕹️ Proof of Pong läuft: Schläger, Arena, Elo', '👉 :url'],
            ['🏓 Nichts installieren. Proof of Pong bis 21', '👀 Live-Match gucken oder selbst ran', '👉 :url'],
            ['⚡ Noch eine Runde Pong. Die Ladder merkt sich das', '👉 :url'],
            ['🏓 Klassisches Pong, Liga-Elo, Meme-Arena', '👉 Hinsetzen: :url'],
        ],
        'hyperbitcoinization' => [
            ['🗺️ Hyperbitcoinization: eine Karte für 2 bis 6', '⏱️ Live-Züge oder ein Zug am Tag', '👉 :url'],
            ['🌍 Schnapp dir die Karte. Hyperbitcoinization ist offen', '🎲 Live oder Fernpartie, dein Tisch', '👉 :url'],
            ['🗺️ In Hyperbitcoinization wartet ein Tisch', '👉 Einen aufmachen: :url'],
            ['⚔️ 2 bis 6 Plätze, eine Karte, Fraktionen mit Haltung', '👉 Hyperbitcoinization: :url'],
            ['🌙 Keine Stunde Zeit? Hyperbitcoinization als Fernpartie, ein Zug am Tag', '👉 :url'],
            ['🗺️ Die Saison-Ladder zählt die Karte, nicht den Chat', '👉 Spielen: :url'],
        ],
        'blockfill_play' => [
            ['🧱 Blockfill: Räum die Blöcke der Woche ab, die beste Zeit zählt', '⏱️ Dein Lauf, dein Replay, dein Platz', '👉 :url'],
            ['⛏️ Ein Blockfill-Lauf startet bei null. Die Woche nicht', '👉 Spielen: :url'],
            ['🧱 Stapeln, abräumen, einreichen. Blockfill wertet die geprüfte Zeit', '👉 :url'],
            ['⏱️ Platz 1 diese Woche ist eine Zeit. Schlag sie in Blockfill', '👉 :url'],
            ['🧱 Blockfill läuft im Browser. Kein Konto bei einem anderen Spiel', '👉 :url'],
            ['🏁 Ein guter Lauf reicht für die ganze Blockfill-Woche', '👉 Probier es: :url'],
        ],
        'board_game' => [
            ['♟️ :game, ein Zug am Tag, auf unserem Brett', '👉 :url'],
            ['🧩 :game ist Fernpartie: ein Zug, wenn du einen hast', '👉 Zur Lobby: :url'],
            ['♟️ Keine Uhr. :game wartet auf deinen Zug', '👉 :url'],
            ['🧩 :game steht neben Schach. Gleiche Liga, langsamere Uhr', '👉 :url'],
            ['📬 Ein Brett für jeden Tag: :game', '👉 :url'],
            ['♟️ :game, hier gespielt, hier gewertet', '👉 :url'],
        ],
        'aoe2' => [
            ['🏰 Age of Empires II: Best of 1 oder 3, auf deiner eigenen Kopie', '👉 So läuft ein Match: :url'],
            ['🏰 AoE2 1v1 ist eine Spieler-Ladder. 2v2 und 3v3 sind Clans', '👉 :url'],
            ['⚔️ Age of Empires II ist in der Liga', '🏰 Lobby im Spiel hosten, Sieger hier melden', '👉 :url'],
            ['🏰 Eine Karte, ein Sieger, Elo. Age of Empires II', '👉 :url'],
            ['🛡️ Clan-Aufstellungen spielen AoE2 2v2 und 3v3', '👉 Zur Seite: :url'],
            ['🏰 Age of Empires II: Definitive Edition, Best-of-Serien', '👉 :url'],
        ],
        'ea_fc' => [
            ['⚽ EA Sports FC: Best of 1 oder 3, Tore zählen', '👉 :url'],
            ['⚽ FC 1v1 ist eine Spieler-Ladder. 2v2 ist eine Clan-Aufstellung', '👉 :url'],
            ['🏟️ EA Sports FC, auf deiner Kopie, hier gemeldet', '👉 :url'],
            ['⚽ Eine Serie, kein Freundschaftsspiel: EA Sports FC', '👉 :url'],
            ['🥅 Ein Sieg im Elfmeterschießen ist auch ein Sieg. Trag die Serie ein', '👉 EA Sports FC: :url'],
            ['⚽ Zwei Ausgaben, zwei Ladders. Nimm deine', '👉 :url'],
        ],
    ];

    /**
     * One variant of a template with its values filled in, lines joined
     * with a newline; lines whose placeholder is null are left out.
     *
     * @param  array<string, string|int|null>  $values
     */
    public static function render(string $template, int $variant, array $values): string
    {
        $variants = self::TEMPLATES[$template] ?? throw new LogicException("Unknown stream bot template [{$template}].");
        $lines = $variants[$variant % count($variants)];
        $out = [];

        foreach ($lines as $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);
            $replace = [];
            $skip = false;

            foreach ($matches[1] as $name) {
                if (! array_key_exists($name, $values)) {
                    throw new LogicException("Stream bot template [{$template}] needs the value [{$name}].");
                }

                if ($values[$name] === null) {
                    $skip = true;

                    break;
                }

                $replace[':'.$name] = (string) $values[$name];
            }

            if (! $skip) {
                $out[] = strtr($line, $replace);
            }
        }

        return implode("\n", $out);
    }

    /** "3 days 4 h", "2 h 15 min", "40 min" (German: "3 Tage 4 Std.", "2 Std. 15 Min."); never less than a minute. */
    public static function duration(int $seconds, string $locale = 'en'): string
    {
        $seconds = max(60, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        [$day, $daysWord, $h, $min] = $locale === 'de' ? ['Tag', 'Tage', 'Std.', 'Min.'] : ['day', 'days', 'h', 'min'];

        if ($days > 0) {
            return $days.' '.($days === 1 ? $day : $daysWord).($hours > 0 ? ' '.$hours.' '.$h : '');
        }

        return $hours > 0 ? $hours.' '.$h.($minutes > 0 ? ' '.$minutes.' '.$min : '') : $minutes.' '.$min;
    }

    /** A whole number as the chat writes it: "21.000" in German, "21,000" in English. */
    public static function number(int|float $number, string $locale = 'en'): string
    {
        return $locale === 'de' ? number_format($number, 0, ',', '.') : number_format($number);
    }

    public static function variants(string $template): int
    {
        return count(self::TEMPLATES[$template] ?? []);
    }

    /**
     * A name from the database (a player, clan or tournament) made safe for
     * the chat: one line, no `#` (it would render as a hashtag), no links or
     * `nostr:` references of its own, at most `$max` characters. Empty when
     * nothing is left.
     */
    /**
     * A tournament's name in a result post, without a sats amount in it (user, 2026-10-04: "Der Bot darf keine Gewinn
     * Zahlen schreiben"; "@UWE wins 21,000 Sats, Zero Ball Control" read as a prize): "21,000 Sats, Zero Ball Control"
     * becomes "Zero Ball Control". A name that is nothing but an amount stays as it is.
     */
    public static function resultName(?string $name, int $max = 40): string
    {
        $clean = self::clean($name, 200);
        $stripped = trim((string) preg_replace('/(?<![\p{L}\p{N}])[\d][\d.,\s\x{202F}\x{00A0}]*\s*k?\s*sats?\b[\s,:;\-–—|·]*/iu', ' ', $clean), " \t,:;-–—|·");
        $stripped = trim((string) preg_replace('/\s{2,}/u', ' ', $stripped));

        return self::clean($stripped === '' ? $clean : $stripped, $max);
    }

    public static function clean(?string $name, int $max = 40): string
    {
        $name = (string) $name;
        $name = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S*|\bwww\.\S+|\bnostr:\S+~iu', ' ', $name);
        $name = str_replace('#', '', $name);
        $name = trim((string) preg_replace('/[\s\p{Cc}\p{Cf}]+/u', ' ', $name));

        return mb_strlen($name) > $max ? rtrim(mb_substr($name, 0, $max - 1)).'…' : $name;
    }

    /**
     * What is wrong with a message, empty when nothing: a `#` (hashtag), a
     * `t` tag, no http(s) link, too many lines, an empty text.
     *
     * @param  list<list<string>>  $tags
     * @return list<string>
     */
    public static function violations(string $content, array $tags = []): array
    {
        $problems = [];

        if (trim($content) === '') {
            $problems[] = 'empty';
        }

        if (str_contains($content, '#')) {
            $problems[] = 'contains # (hashtag)';
        }

        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 't') {
                $problems[] = 't tag';
            }
        }

        if (preg_match('~https?://\S+~', $content) !== 1) {
            $problems[] = 'no link';
        }

        if (count(explode("\n", $content)) > self::MAX_LINES) {
            $problems[] = 'more than '.self::MAX_LINES.' lines';
        }

        return $problems;
    }
}
