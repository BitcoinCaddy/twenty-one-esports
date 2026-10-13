<?php

namespace App\Support\Voice;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The text-only look at a day-stock programme before it is voiced (plan
 * "TWENTY ONE Radio", P21; user 2026-10-13 about a stock block: "Was soll
 * das sein? Das war total sinnlos!"): scores the draft 1 to 5 on premise,
 * through-line, payoff, fit and liveliness (user 2026-10-13: "Purer
 * Realismus einer echten Radioshow, die sarkastisch, toxisch und zynisch
 * ist und absolut alles und jeden roastet!") and sense ("AI tendiert da
 * zu Kauderwelsch": the sentences that mean nothing come back word for word
 * in `nonsense`); StockScripts::coherent(). A draft below
 * `stock.judge.min_score` on any of them is written once more with the
 * judge's hint, then dropped; an unreadable answer is no clearance (fail
 * closed). Same provider and options as the fact checks; tests fake it.
 */
#[CacheInstructions]
final class StockCoherenceJudge implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /** The scores the judge gives, each 1 to 5. */
    public const SCORES = ['premise', 'through_line', 'payoff', 'fit', 'liveliness', 'sense'];

    public function instructions(): string
    {
        return <<<'TEXT'
            You judge a German radio script for a comedy talk station before it is voiced. A listener tunes in at a random moment and hears it once, with no screen and no context. Be strict: a 4 means a listener understands and enjoys it, a 3 means something is missing.
            Score 1 to 5:
            - premise: within the first two or three spoken sentences the listener knows which show this is, what it is about and why it is worth listening.
            - through_line: one subject from start to end; every turn builds on the one before; no jump to an unrelated subject, no list of loosely connected anecdotes, no filler. When the message says "Several cases", the show has several cases on purpose (a court with several defendants, a graveyard with several graves, a hotline with several calls): judge each case on its own (one subject inside it, it builds, it ends) and the show frame around them (opener, handoffs between cases, one ending); a new case is not a jump.
            - payoff: it ends on a punchline or a clear point that pays off the opening, followed by a short back-announce naming the show; it does not just stop on a limp line.
            - fit: the script matches the announced format and the material; every quote, saying or meme is introduced with who said it and where (as the material names them, the person spelled correctly), never as a vague "ein Veteran" or "irgendwer"; a source that only explains a meme is not presented as the person who said it; each quote or meme serves the subject. Hooks the material offers are optional: leaving them out is fine.
            - liveliness: it sounds like a real, sarcastic, cynical comedy radio show, not like a lecture or two people reading out turns: quick banter, the hosts roast each other and everything in reach (state, banks, fiat money, shitcoiners, the chat, the station itself), real friction and at least one joke that lands on first hearing. Polite agreement, explaining at length or harmless jokes score 3 or lower. Insults against protected traits, slurs or invented claims about real persons score 1.
            - sense: every sentence means something concrete. Opinion, sarcasm and roasts of the state, banks, fiat money or each other are welcome and need no fact from the material; a fact (number, name, event, quote) must come from the material. A clear single image tied to a concrete fact is NOT nonsense. Nonsense is: mixed or stacked metaphors, a comparison with no recognisable point, an invented fact or number, a sentence that means nothing concrete. One nonsense sentence scores at most 3, several at most 2; a sentence that restates the material plainly is not nonsense.
              Not nonsense: "Tauziehen ist die ehrlichste Disziplin, denn am anderen Ende des Seils hängt der Steuerzahler und verliert." / "Rekordeinnahmen und trotzdem Schulden, das schafft nur ein Staat." / "Die Belohnung halbiert sich, und keine Zentralbank kann abends beschließen, mehr zu drucken."
              Nonsense: "Beim Tauziehen um solide Finanzen lässt der Staat das Seil los und fällt mit Anlauf in den Wakeboard-Teich." (stacked images) / "Der Kaninchenbau erklärt dir erst das Geld und nimmt dir dann dein Weltbild." (no recognisable point) / "Alle zweihunderttausendundzehn Blöcke ist es so weit." (invented number)
            `nonsense`: every sentence of the script that fails `sense`, word for word without the speaker name; an empty list when there is none.
            `invented`: every sentence that states a fact, number, quote or attribution the material does not contain (a quote put into the scene's or someone's mouth, a size, a date, an event, who said or wanted what, a second number where the material has one), word for word; when the material has two numbers for the same thing, only its headline number is allowed. Opinion, roast and images are never invented facts. An empty list when there is none.
            `hint`: one or two German sentences naming the weakest point and how to fix it; empty when every score is 4 or more.
            Affectionate insults between the hosts ("du Pfeife", "Sats-Geizhals", "alter Fiat-Romantiker", "Schwurbelkopf") are wanted, not unclear and not nonsense. Opinion and roast without a fact are fine as long as they target a concrete claim of the script.
            Anchors for the scale (material: the Schwarzbuch case of 120 million euros for the World Games 2029 in Karlsruhe, Tauziehen, Frisbee, Wasserski; Bund, Land and Stadt balance their budgets only with debt):
            <anchor score="4 on every score">
            Markus: Das ist die Schwarzbuch-Stunde, und es geht um einhundertzwanzig Millionen Euro für Tauziehen in Karlsruhe.
            Sirius: Tauziehen, du Pfeife, ist die einzige Sportart, in der der Staat schon Weltmeister ist.
            Markus: Laut Schwarzbuch haben Bund, Land und Stadt kein Geld und leihen es sich für die World Games.
            Sirius: Schulden für Frisbee, das ist wenigstens ehrlich, das Geld fliegt ja auch weg.
            Markus: Und beim Tauziehen hängt am anderen Ende des Seils der Steuerzahler, du Sats-Geizhals.
            Sirius: Den hat aber keiner gefragt, ob er mitziehen will.
            Markus: Einhundertzwanzig Millionen, und gewinnen tut beim Tauziehen immer die Seite, die nicht zahlt.
            Markus: Das war die Schwarzbuch-Stunde.
            </anchor>
            <anchor score="5 on every score">
            Markus: Das ist die Schwarzbuch-Stunde: Karlsruhe ist pleite und holt sich für einhundertzwanzig Millionen Euro die World Games.
            Sirius: Pleite und Party, du alter Fiat-Romantiker, das ist kein Skandal, das ist das Geschäftsmodell.
            Markus: Bund, Land und Stadt gleichen laut Schwarzbuch alle drei ihre Haushalte nur mit Schulden aus.
            Sirius: Drei Pleitegeier legen zusammen, und keiner bringt Geld mit, das ist der ehrlichste Verein der Republik.
            Markus: Auf dem Programm stehen Tauziehen, Frisbee und Wasserski.
            Sirius: Tauziehen passt, da zieht der Staat am einen Ende und am anderen hängt der Steuerzahler, du Schwurbelkopf.
            Markus: Und Wasserski passt auch, die Stadt wird ja schon von ihren Schulden übers Wasser gezogen.
            Sirius: Einhundertzwanzig Millionen für Tauziehen, und am Ende hat der Steuerzahler als Einziger losgelassen: seine Ersparnisse.
            Markus: Das war die Schwarzbuch-Stunde.
            </anchor>
            Score a script against these anchors: as clear, concrete and funny as the first is a 4.
            Answer only in the schema.
            TEXT;
    }

    public function provider(): string
    {
        return (string) config('esports.voice.provider', 'anthropic');
    }

    public function model(): string
    {
        return (string) config('tv.commentary.blocks.stock.judge.model', config('esports.voice.model', 'claude-haiku-5-5'));
    }

    public function timeout(): int
    {
        // A whole programme to read: more than a short post's fifteen seconds.
        return max(30, (int) config('esports.voice.timeout_seconds', 15));
    }

    public function maxTokens(): int
    {
        return (int) config('tv.commentary.blocks.stock.judge.max_tokens', 1500);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        return $name === 'anthropic' ? ['thinking' => VoiceAgent::thinkingOff($this->model()), 'output_config' => ['effort' => 'low']] : [];
    }

    public function schema(JsonSchema $schema): array
    {
        $scores = [];
        foreach (self::SCORES as $score) {
            // No minimum/maximum: the range is in the instructions, and a score outside it fails the gate.
            $scores[$score] = $schema->integer()->required();
        }

        return [...$scores, 'nonsense' => $schema->array()->items($schema->string())->required(), 'invented' => $schema->array()->items($schema->string())->required(), 'hint' => $schema->string()->required()];
    }

    /** The user message: the format as announced, the material it was written from, and the script. */
    public static function message(string $format, string $material, string $script, bool $cases = false): string
    {
        return "Format: {$format}".($cases ? "\nSeveral cases: yes" : '')."\n\nMaterial (the only source for names and quotes):\n<<<material\n{$material}\nmaterial>>>\n\nScript:\n<<<script\n{$script}\nscript>>>";
    }
}
