<?php

namespace App\Support\Memes;

/**
 * The league's own meme library (plan "KI-Stimme der Liga", P5): templates of a meme card, each a motif the league
 * draws itself (MemeCard, no foreign artwork, no real person) and one quote from the approved meme canon
 * (resources/data/voice/meme-canon.json), by its ID. A template never carries a wording of its own: the card prints
 * the canon's wording, so only verified memes reach a picture (tests/Feature/Memes/MemeLibraryTest.php reads every ID
 * against the canon and its usage).
 *
 * Several templates per moment for variety; for() picks one by the match's key, so the same match always gets the
 * same card (a stable file and URL) and the next match most likely another one.
 */
final class MemeLibrary
{
    /**
     * Per moment: [template id, canon id, motif]. Motifs: `flip` (two cubes trading places), `laser` (a cube with two
     * beams), `chart` (bars rising), `dip` (bars falling, then rising), `diamond`, `hodl` (a stack of blocks),
     * `scoreboard` (the two scores as blocks), `first` (one block lit in a row).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public const TEMPLATES = [
        'upset' => [
            ['upset-woran', 'e21-woran-hat-et-jelegen', 'flip'],
            ['upset-nerds', 'tx-bitcoin-ist-the-revenge-of-the-nerds', 'laser'],
            ['upset-gemueter', 'tx-wir-haben-heute-ein-paar-gemueter-geaender', 'chart'],
            ['upset-dust', 'title-another-one-bites-the-dust', 'flip'],
        ],
        'comeback' => [
            ['comeback-dip', 'btc-buy-the-dip', 'dip'],
            ['comeback-hodl', 'btc-hodl', 'hodl'],
            ['comeback-aufgeben', 'tx-er-gibt-nicht-auf', 'dip'],
            ['comeback-diamond', 'tx-das-einzige-was-bei-mir-glaenzt-sind-meine', 'diamond'],
        ],
        'pong_deuce' => [
            ['deuce-eskalieren', 'tx-e-warum-muss-das-immer-so-eskalieren-mit-e', 'scoreboard'],
            ['deuce-diamond', 'btc-diamond-hands', 'diamond'],
            ['deuce-loesung', 'tx-wie-wir-immer-sagen-es-gibt-immer-eine-loe', 'scoreboard'],
        ],
        'first_win' => [
            ['first-bitcoiner', 'title-ich-bin-bitcoiner', 'first'],
            ['first-block', 'title-liebe-auf-den-ersten-block', 'first'],
            ['first-lichtblick', 'e21-lichtblick-der-woche', 'chart'],
        ],
        'close_game' => [
            ['close-lob', 'tx-lob-kein-lob-kein-lob-kein-lob-ich-lob-mic', 'scoreboard'],
            ['close-loesung', 'tx-wie-wir-immer-sagen-es-gibt-immer-eine-loe', 'scoreboard'],
            ['close-diamond', 'btc-diamond-hands', 'diamond'],
        ],
    ];

    /**
     * The canon contexts a template's entry must carry one of for its moment: the canon's own rule of when an entry
     * fits (plan, Meme-Kanon: "Kontextregeln je Eintrag").
     *
     * @var array<string, list<string>>
     */
    public const CONTEXTS = [
        'upset' => ['Upset', 'Favorit verliert'],
        'comeback' => ['Comeback'],
        'pong_deuce' => ['Pong', 'Pong 21:20', 'knapp', 'knappes Spiel'],
        'first_win' => ['erster Sieg'],
        'close_game' => ['knapp', 'knappes Spiel'],
    ];

    /**
     * The template of a moment for one match key, or null for a moment without templates.
     *
     * @return array{id: string, moment: string, canon_id: string, motif: string}|null
     */
    public static function for(string $moment, string $key): ?array
    {
        $templates = self::TEMPLATES[$moment] ?? [];

        if ($templates === []) {
            return null;
        }

        [$id, $canonId, $motif] = $templates[crc32($moment.'|'.$key) % count($templates)];

        return ['id' => $id, 'moment' => $moment, 'canon_id' => $canonId, 'motif' => $motif];
    }

    /**
     * Every template, flat.
     *
     * @return list<array{id: string, moment: string, canon_id: string, motif: string}>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::TEMPLATES as $moment => $templates) {
            foreach ($templates as [$id, $canonId, $motif]) {
                $all[] = ['id' => $id, 'moment' => $moment, 'canon_id' => $canonId, 'motif' => $motif];
            }
        }

        return $all;
    }
}
