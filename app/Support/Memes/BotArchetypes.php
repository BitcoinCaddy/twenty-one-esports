<?php

namespace App\Support\Memes;

/**
 * The bot opponents' characters (plan "KI-Stimme der Liga", P5): Bitcoin archetypes, never a real person. A bot that
 * wears a real person's caricature in its game (Proof of Pong's Goldbug and the two central bankers, approved as
 * figures in the plan "Proof of Pong") talks as its archetype: the game chat names the archetype, so no sentence is
 * ever put into a real person's mouth.
 *
 * Every line is German (the game chat's language, plan "Sprachen je Kanal"), friendly teasing about the game and the
 * archetype's own world, addressed to "du" without a name, never an insult. A line that quotes the meme canon carries
 * that entry's ID; tests/Feature/Memes/BotBanterTest.php runs every line through the voice's guard (VoiceGuard) and
 * BotBanter::refusals().
 *
 * Occasions: `start` (before the first serve), `bot_point`, `player_point`, `deuce` (both at 20 or more), `bot_win`,
 * `player_win`.
 */
final class BotArchetypes
{
    public const OCCASIONS = ['start', 'bot_point', 'player_point', 'deuce', 'bot_win', 'player_win'];

    /**
     * Per archetype: its name in the chat, a short description for the voice, and per occasion its template lines as
     * [text, canon id or null].
     *
     * @var array<string, array{name: string, about: string, lines: array<string, list<array{0: string, 1: string|null}>>}>
     */
    public const ROSTER = [
        'hodler' => [
            'name' => 'Hodler',
            'about' => 'verkauft nie, bleibt immer ruhig, denkt in Blöcken statt in Punkten',
            'lines' => [
                'start' => [['Ich bleib einfach stehen. HODL.', 'btc-hodl'], ['Ganz ruhig. Ich verkauf keinen einzigen Punkt.', null]],
                'bot_point' => [['Punkt. Gekauft und nie wieder hergegeben.', null], ['Langer Atem zahlt sich aus.', null]],
                'player_point' => [['Egal. Ich denk in Blöcken, nicht in Punkten.', null], ['Ein Dip. Kein Grund zur Panik.', null]],
                'deuce' => [['Jetzt bloß nicht nervös werden.', null], ['Knapp hier. Ich bleib entspannt.', null]],
                'bot_win' => [['Gewonnen. Geduld schlägt alles.', null], ['Sieg. Und ich hab keinen Punkt verkauft.', null]],
                'player_win' => [['Verloren. Verkaufen tu ich trotzdem nicht.', null], ['Gut gespielt. Ich halte meine Revanche.', null]],
            ],
        ],
        'laser_eyes' => [
            'name' => 'Laser Eyes',
            'about' => 'Tunnelblick, glühende Augen, sieht jeden Ball kommen',
            'lines' => [
                'start' => [['Laser Eyes an. Ich seh jeden Ball.', 'btc-laser-eyes'], ['Tunnelblick an. Los geht es.', null]],
                'bot_point' => [['Volltreffer. Die Augen lügen nicht.', null], ['Punkt! Ich hab ihn kommen sehen.', null]],
                'player_point' => [['Kurz geblinzelt. Kommt nicht wieder vor.', null], ['Okay, der war gut. Ich zieh nach.', null]],
                'deuce' => [['Jetzt volle Konzentration. Nur noch der Ball.', null], ['Knapp! Meine Augen glühen schon.', null]],
                'bot_win' => [['Gewonnen. Fokus schlägt Glück.', null], ['Sieg! Die Augen bleiben an.', null]],
                'player_win' => [['Verloren. Ich brauch stärkere Augen.', null], ['Gut gespielt. Du hast mich geblendet.', null]],
            ],
        ],
        'shitcoiner' => [
            'name' => 'Shitcoiner',
            'about' => 'hat für jeden Ballwechsel einen neuen Coin, verspricht viel, liefert wenig',
            'lines' => [
                'start' => [['Neues Spiel, neuer Coin. Ich hab für jeden Ballwechsel einen.', null], ['Mein Schläger hat ein eigenes Whitepaper.', null]],
                'bot_point' => [['Punkt! Gleich mach ich einen Token draus.', null], ['Ein Punkt für mich. Der Kurs steigt!', null]],
                'player_point' => [['Kein Problem. Ich forke einfach den Spielstand.', null], ['Autsch. Zum Glück hab ich noch zwölf andere Coins.', null]],
                'deuce' => [['So knapp! Ich bring schnell einen Spielstand-Coin raus.', null], ['Jetzt zählt jeder Punkt. Wie beim Airdrop.', null]],
                'bot_win' => [['Gewonnen! Ab sofort gibt es den Siegercoin.', null], ['Sieg! Bleibt nur die Frage, auf welcher Chain.', null]],
                'player_win' => [['Okay, okay. Ist alles Shitcoin, was nicht Bitcoin ist?', 'tx-ist-alles-shitcoin-was-nicht-bitcoin-ist'], ['Mein Coin steht gerade auf null. Gut gespielt.', null]],
            ],
        ],
        'nocoiner' => [
            'name' => 'Nocoiner',
            'about' => 'hält Bitcoin für eine Mode, schwört auf Sparbuch und Euro',
            'lines' => [
                'start' => [['Pong? Ist das so ein Internetding wie Bitcoin? Na gut, los.', null], ['Ich spiel mit dem Sparbuch im Rücken. Sicher ist sicher.', null]],
                'bot_point' => [['Siehste. Alte Schule.', null], ['Punkt für mich. Ganz ohne Blockchain.', null]],
                'player_point' => [['Das war Glück. Wie bei Bitcoin.', null], ['Hm. Erklär mir das nochmal mit dem Ball.', null]],
                'deuce' => [['Jetzt wird es knapp. Ich bleib trotzdem beim Sparbuch.', null], ['Spannend. Fast wie Zinsen. Nur schneller.', null]],
                'bot_win' => [['Gewonnen! Bitcoin ist eh nur eine Phase.', null], ['Ich gewinne. Nächstes Mal erklär ich dir Bausparen.', null]],
                'player_win' => [['Na gut. Vielleicht schau ich mir das mit Bitcoin doch mal an.', null], ['Respekt. Ich glaub, ich brauch eine Orange Pill.', 'btc-orange-pill']],
            ],
        ],
        'goldbug' => [
            'name' => 'Goldbug',
            'about' => 'schwört auf Gold, hält alles Digitale für Spielerei',
            'lines' => [
                'start' => [['Gold hat schon gewonnen, bevor es Pong gab.', null], ['Mein Schläger ist aus Gold. Schwer, aber ehrlich.', null]],
                'bot_point' => [['Punkt. Gold glänzt eben immer.', null], ['Siehst du? Echte Werte.', null]],
                'player_point' => [['Pff. Digitales Gold, oder was?', 'e21-digitales-gold'], ['Der Ball war zu leicht. Nicht gedeckt.', null]],
                'deuce' => [['Jetzt wird es eng. Gold bleibt ruhig.', null], ['Spannend. Ich leg den Ball erst mal in den Tresor.', null]],
                'bot_win' => [['Gold gewinnt. Wie seit fünftausend Jahren.', null], ['Sieg für das gelbe Metall.', null]],
                'player_win' => [['Na gut. Vielleicht ist an deinem Bitcoin doch was dran.', null], ['Verloren. Ich trag es mit Fassung.', null]],
            ],
        ],
        'notenbanker' => [
            'name' => 'Notenbanker',
            'about' => 'druckt sich neue Punkte, findet alles stabil, plant alles im Rat',
            'lines' => [
                'start' => [['Keine Sorge. Ich druck mir einfach neue Punkte.', null], ['Willkommen. Der Ball ist ab heute programmierbar.', null]],
                'bot_point' => [['Punkt für mich. Frisch gedruckt.', null], ['Und noch einer. Die Druckerpresse läuft.', null]],
                'player_point' => [['Kein Problem. Ich senke einfach den Zins auf deine Punkte.', null], ['Das zählt nur vorläufig. Wir prüfen das im Rat.', null]],
                'deuce' => [['Knapp? Dann druck ich eben zwei Punkte mehr.', null], ['Alles stabil. Ganz bestimmt.', null]],
                'bot_win' => [['Gewonnen. Ganz nach Plan, wie immer.', null], ['Sieg! Deine Punkte hat leider die Inflation gefressen.', null]],
                'player_win' => [['Verloren. Das ist nur vorübergehend. Ganz sicher.', null], ['Gut gespielt. Fiat hat heute verloren.', 'e21-fiat']],
            ],
        ],
    ];

    /** Proof of Pong's bots (resources/js/pong/cast.json) and the archetype each one talks as. */
    public const PONG_BOTS = [
        'nocoiner' => 'nocoiner',
        'shitcoiner' => 'shitcoiner',
        'schiff' => 'goldbug',
        'schnabel' => 'notenbanker',
        'lagarde' => 'notenbanker',
    ];
}
