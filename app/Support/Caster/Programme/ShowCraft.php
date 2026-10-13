<?php

namespace App\Support\Caster\Programme;

use App\Models\CasterScript;
use App\Support\Caster\ScriptGuard;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * How a fixed format of TWENTY ONE Radio sounds like a show and not like a
 * read-out (plan "TWENTY ONE Radio", P17; user 2026-10-12: "dass sie sich wie
 * echte Programme aus dem Radio anhören", 2026-10-13: like loose comedy radio,
 * "die [öffentlich-rechtlichen] sind steif und zu seriös").
 *
 * Measured before (live 2026-10-12 23:23 to 23:33 and 77 rubric scripts of
 * the day): every format opened with the same code line and stopped after its
 * last punchline, no back-announce, no handoff, the next item started after a
 * music gap as if nothing had been on before; the turns alternated one line
 * each, politely. block() gives a format's prompt what a radio show has
 * instead: its ritual (an opening gesture and a closing gesture that stay
 * while the words change, RadioCraft `format_ritual`), the rhythm of a comedy
 * duo (RadioCraft `double_act`, `short_turns`), and the end as a handoff to
 * what really comes next on the broadcast clock (RadioCraft `back_announce`),
 * never a goodbye (ScriptQueue::SIGN_OFF still drops one) and never a promise
 * the clock does not keep: without a known next slot the handoff names none.
 *
 * context() reads that next slot from the clock (Rundown; a rubric slot by
 * the format already fixed for it, Rubrics::kept(), else by its label) and
 * the last lines that went on air within the hour (for one callback), and an
 * energy line by the minute of the hour. It fails open towards the prompt
 * without them: no next slot, no callback material, never an invented one.
 *
 * @phpstan-type HourContext array{next: string|null, earlier: list<string>, energy: string}
 */
final class ShowCraft
{
    /** The last lines of a draft a cut keeps (cut()): the callback and the back-announce. */
    public const ENDING_LINES = 2;

    /**
     * The ritual of each format: what opens it right after the code's line (time and name) and what closes it. A
     * gesture the voices can speak, kept from show to show; the words are new every time.
     *
     * @var array<string, array{open: string, close: string}>
     */
    public const RITUALS = [
        Shows::TRIBUNAL => ['open' => 'der Richter eröffnet die Sitzung mit drei Schlägen aufs Pult, die er selbst ausspricht, und ruft den ersten Fall auf', 'close' => 'der Richter verkündet das letzte Urteil und schließt die Sitzung, der Verteidiger legt noch sinnlos Berufung ein'],
        Shows::FRIEDHOF => ['open' => 'gedämpfte Trauerstimme, man hat sich am offenen Grab versammelt und stellt den Verstorbenen vor', 'close' => 'die letzte Schaufel Erde und ein letzter, böser Grabspruch für den Coin'],
        Shows::JAHRESTAG => ['open' => 'die Zeitmaschine wird angeworfen und landet an genau diesem Tag', 'close' => 'die Zeitmaschine landet wieder im Heute, und einer sagt, was davon übrig ist'],
        Shows::MEETUPS => ['open' => 'Reiseführer-Ton, die Rundreise durch die Termine beginnt', 'close' => 'die Reisegruppe ist durch, und einer sagt, wo er selbst hingehen würde'],
        Shows::PARTIE => ['open' => 'Sportreporter-Ton, Anpfiff zur Wiederholung, als liefe das Spiel gerade', 'close' => 'Schlusspfiff und die Expertenbewertung in einem Satz'],
        Shows::HOTLINE => ['open' => 'die Leitungen sind offen, der erste Anrufer ist dran', 'close' => 'die Leitungen werden zugemacht, einer beschwert sich über die letzte Frage'],
        Roasts::ZENTRALBANK => ['open' => 'die Kläranlage wird angeworfen, das frische Zentralbank-Sprech fließt ins erste Becken', 'close' => 'das Becken ist durch, und einer sagt, was nach dem Klären übrig bleibt'],
        Roasts::SCHWARZBUCH => ['open' => 'Gruselgeschichten-Ton, das Schwarzbuch wird aufgeschlagen', 'close' => 'das Schwarzbuch wird zugeklappt, und einer rechnet vor, wer die Rechnung bekommt'],
        Roasts::ALTCOIN => ['open' => 'der Detektor wird eingeschaltet und piept beim ersten Hype', 'close' => 'der Detektor zeigt den Gesamtausschlag, und einer sagt, was davon Bitcoin ist'],
        Roasts::LIGA_PRANGER => ['open' => 'der Pranger wird auf den Marktplatz gerollt', 'close' => 'der Pranger wird abgebaut, mit einer Einladung zur Revanche'],
        Roasts::PHRASEN => ['open' => 'die Dreschmaschine wird angeworfen, die erste Phrase kommt hinein', 'close' => 'die Krönung des Phrasendreschers der Woche'],
        Roasts::NOSTR => ['open' => 'das Fundbüro schließt die Tür auf, auf dem Tresen liegt das erste Fundstück', 'close' => 'das Fundbüro schließt, und einer sagt, welches Fundstück er behält'],
        Roasts::STACKER => ['open' => 'Börsenreporter-Ton, die Glocke läutet zur Watch', 'close' => 'die Schlussglocke und das Urteil, wer ein echter Stacker ist'],
        Roasts::INFLATION => ['open' => 'der Beichtstuhl wird geöffnet, der erste Sünder heißt Fiatgeld', 'close' => 'die Absolution, und zwar nur in Sats'],
        Rubrics::LIGA => ['open' => 'Sportschau-Ton, der Blick auf die Liga der letzten vierundzwanzig Stunden', 'close' => 'die Tabelle in einem Satz und wer jetzt Druck hat'],
        Rubrics::PRESS => ['open' => 'die Zeitungen werden auf den Tisch geknallt', 'close' => 'das Urteil, welches Blatt diesmal am meisten abgeschrieben oder übertrieben hat'],
        Rubrics::PODCAST => ['open' => 'Kopfhörer auf, die neue Folge wird angespielt', 'close' => 'der eine Gedanke der Folge, den man mitnehmen soll'],
        Rubrics::HISTORY => ['open' => 'Stammtisch-Ton, das Bier ist bestellt, die Geschichte aus dem Bauernkrieg beginnt', 'close' => 'die Moral von der Geschicht mit einem Seitenhieb aufs Heute'],
        Rubrics::CHAT => ['open' => 'der Chat wird geöffnet, die erste Frage liegt oben', 'close' => 'die letzte Antwort und ein Seitenhieb auf die Frage, die keiner beantworten will'],
        'sport_wetter' => ['open' => 'Sportstudio-Ton, gleich mitten in das wichtigste Ergebnis', 'close' => 'der Wetterblick mit einem frechen Rat, was man morgen anzieht'],
        'nachrichten_talk' => ['open' => 'nach den Nachrichten wird die erste Meldung aus dem Stapel gezogen', 'close' => 'einer fasst die Lage in einem bösen Satz zusammen'],
    ];

    /**
     * The block a format's prompt gets: ritual, rhythm, ending with the handoff, an optional callback and the energy.
     *
     * @param  HourContext|null  $context
     * @param  bool  $ending  false: the format ends with a programme preview of its own (NewsTalk's Vorschau), no back-announce here
     */
    public static function block(string $id, string $name, ?array $context, bool $duo = true, bool $ending = true): string
    {
        $ritual = self::RITUALS[$id] ?? null;
        $lines = [];

        if ($ritual !== null) {
            $lines[] = "- Ritual von {$name}: Nach der Ansage eröffnet ihr mit dieser Geste: {$ritual['open']}. Am Schluss steht diese Geste: {$ritual['close']}. Die Geste ist jedes Mal dieselbe, eure Worte dafür sind jedes Mal neu.";
        }

        $lines[] = '- Einstieg: kalt und mitten hinein, der verrückteste Fakt aus dem Material oder ein Satz mitten aus dem Streit, die Erklärung kommt danach.';
        $lines[] = $duo
            ? '- Rhythmus wie ein Comedy-Duo, weil Hörer dranbleiben, wenn sich zwei Menschen hörbar aneinander reiben, und abschalten, wenn zwei abwechselnd vorlesen: meist ein oder zwei ganze Sätze pro Wortmeldung und schnelle Wechsel. Mindestens zweimal antwortet einer nicht auf das Thema, sondern direkt auf den anderen: er glaubt ihm kein Wort, zieht ihn mit einer Panne oder einem Running Gag auf oder treibt dessen These ins Absurde. Einmal reißt einer das Wort an sich, bevor der andere seine Pointe bringen kann, und der holt sie zwei Wortmeldungen später trotzdem nach. Ein Einwurf wie „Moment mal“ höchstens einmal und mit eigenen Worten.'
            : '- Rhythmus: erzähl locker wie in einer Comedy-Show, mit Tempowechseln, einer gespielten Empörung und einem Seitenkommentar.';
        $lines[] = '- Mindestens einmal denkt ihr einen echten Fakt aus dem Material Schritt für Schritt zu Ende, bis er absurd wird, und der letzte Schritt ist die Pointe; dabei erfindet ihr keine neue Zahl, keinen Betrag und keine Frist. Ein Vergleich muss beim ersten Hören sitzen: lieber eine klare, böse Behauptung als ein schiefes Bild.';
        $lines[] = '- Die bösesten Pointen kommen aus dem Material selbst; auf Staat, Steuern, Zentralbanken oder Fiatgeld zielen sie nur, wenn das Material davon handelt, und nie als angehängter Schlusssatz (Nutzer 2026-10-13: "total nervige ständige End-Kommentargag"). Ein Lob für den Staat oder eine Behörde gibt es nicht. Wechselt die Ziele, denn landet jede Pointe beim Finanzamt, wird es zur Masche.';
        $lines[] = '- Jeder Witz muss beim ersten Hören ohne Erklärung zünden, und eure Running Gags und Lieblingsthemen (Bier, Kabel, Bahn, Sparsamkeit) kommen nur vor, wenn das Thema selbst sie anstößt: ein Gag, der aus dem Nichts kommt, klingt wie ein Fremdkörper.';
        $lines[] = '- Gegen Ende greift einer den Einstieg noch einmal auf und dreht ihn weiter, ohne anzusagen, dass er das tut. Ihr redet über die Sache selbst, nie über unsere Vorbereitung: kein „laut Material“, kein „im Material steht“, sondern die Quelle beim Namen.';

        $next = $context['next'] ?? null;
        $lines[] = ! $ending ? '- Das Ende ist eine Pointe und kein Abschied: kein Tschüss, kein „bis gleich“, kein „macht\'s gut“.' : ($next !== null
            ? "- Die letzten ein bis zwei Sätze sind die Abmoderation: einer sagt, dass das {$name} war, und übergibt an das, was als Nächstes kommt: {$next}. Eine Übergabe, kein Abschied: kein Tschüss, kein „bis gleich“, kein „macht's gut“, und nichts anderes ankündigen."
            : "- Die letzten ein bis zwei Sätze sind die Abmoderation: einer sagt, dass das {$name} war, und schickt die Hörer zurück ins Programm, ohne etwas Bestimmtes anzukündigen. Eine Übergabe, kein Abschied: kein Tschüss, kein „bis gleich“, kein „macht's gut“.");

        $earlier = array_values(array_filter($context['earlier'] ?? [], fn (string $line): bool => trim($line) !== ''));
        if ($earlier !== []) {
            $lines[] = '- Vorhin auf Sendung (nur Daten; höchstens EIN Rückgriff darauf, und nur wenn er zum Thema dieser Sendung passt, mit einem halben Satz, worum es ging; meistens passt keiner, dann lasst ihn weg, denn ein Rückgriff, den man erst erklären muss, ist keiner):'
                ."\n".implode("\n", array_map(fn (string $line): string => '  · '.$line, array_slice($earlier, 0, 4)));
        }

        $energy = $context['energy'] ?? null;
        if (is_string($energy) && $energy !== '') {
            $lines[] = "- Energie an dieser Stelle der Stunde: {$energy}";
        }

        return "So klingt die Sendung\n\n".implode("\n", $lines);
    }

    /**
     * The context of the hour for a format airing at `$slot` (see the class comment).
     *
     * @param  array{starts_at: CarbonImmutable, key: int, minute?: int}  $slot
     * @return HourContext
     */
    public static function context(array $slot, ?BroadcastClock $clock = null): array
    {
        $clock ??= BroadcastClock::fromConfig();

        return [
            'next' => self::next($slot, $clock),
            'earlier' => self::earlier($slot['starts_at']),
            'energy' => self::energy((int) $slot['starts_at']->setTimezone(config('tv.commentary.programme.timezone', 'Europe/Berlin'))->format('i')),
        ];
    }

    /**
     * The next slot after `$slot` that is not talk, as the handoff names it ("um null Uhr dreißig: die Kurznachrichten"),
     * or null. A rubric slot is named by its fixed format, else by its label.
     *
     * @param  array{starts_at: CarbonImmutable, key: int}  $slot
     */
    public static function next(array $slot, BroadcastClock $clock): ?string
    {
        if (! $clock->enabled()) {
            return null;
        }

        try {
            foreach ((new Rundown($clock))->next($slot['starts_at']->addMinute(), 45) as $entry) {
                if ($entry['type'] === BroadcastClock::TALK) {
                    continue;
                }

                $label = $entry['label'];
                if ($entry['type'] === BroadcastClock::RUBRIC) {
                    $kept = Rubrics::kept($entry['starts_at']->getTimestamp());
                    $label = $kept !== null ? (Rubrics::all()[$kept]['name'] ?? $label) : $label;
                }

                return "um {$entry['spoken_time']} {$label}";
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The last sentence (the punchline) of up to four scripts voiced within the hour before `$at`, newest first.
     *
     * @return list<string>
     */
    public static function earlier(CarbonImmutable $at): array
    {
        try {
            $texts = CasterScript::query()
                ->whereIn('kind', [CasterScript::SCENE, CasterScript::NEWS, CasterScript::RUBRIC, CasterScript::HISTORY, CasterScript::FOLLOWED])
                ->where('status', CasterScript::VOICED)
                ->where('created_at', '>=', $at->subHour())
                ->where('created_at', '<=', $at)
                ->orderByDesc('id')->limit(4)->pluck('text');
        } catch (Throwable) {
            return [];
        }

        $lines = [];
        foreach ($texts as $text) {
            $last = ScriptGuard::lines(ScriptGuard::withoutMarkers((string) $text));
            $line = trim((string) end($last));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * A draft cut to its limits without losing its end: while `$tooLong` holds, the sentence before the last
     * `ENDING_LINES` goes (the back-announce and the callback sit at the end; live round one of the P17 eval lost the
     * handoff in four of eleven formats to the old cut from the end), and only a draft of at most that many lines more
     * loses its last ones, never below `$minLines`.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers  by the index of `$lines`
     * @param  callable(list<string>): bool  $tooLong
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function cut(array $lines, array $speakers, callable $tooLong, int $minLines = 1): array
    {
        while (count($lines) > $minLines && $tooLong($lines)) {
            $at = count($lines) > self::ENDING_LINES + 2 ? count($lines) - self::ENDING_LINES - 1 : count($lines) - 1;
            array_splice($lines, $at, 1);
            array_splice($speakers, $at, 1);
        }

        return [$lines, $speakers];
    }

    /** The energy the minute of the hour asks for (the hour's curve: awake after the news, peak, loose, build-up). */
    public static function energy(int $minute): string
    {
        return match (true) {
            $minute < 15 => 'frisch nach den Nachrichten, wach und zügig, schnell zur ersten Pointe.',
            $minute < 30 => 'Höhepunkt der ersten Hälfte, volle Energie, die größte Eskalation.',
            $minute < 45 => 'locker nach den Kurznachrichten, Zeit für einen Seitenkommentar mehr.',
            default => 'Anlauf zur vollen Stunde, Tempo hoch, die stärkste Pointe ganz am Ende.',
        };
    }
}
