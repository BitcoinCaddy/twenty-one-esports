{{--
    F4 · Arena · Blockfill's new #1 (BlockfillSlides::MOMENT): the one loud slide of the set, the full orange frame of
    a4. Top left the game's mark (its cover edged in the frame's ink, partials/game-mark) where a4 has the league's,
    beside it the title and "Blockfill Week N, YYYY". The player's avatar big and crowned, the name, the time in fixed
    digit cells as the hero, and what it beat
    (another player's time, the player's own, or nobody: the week's first time). At the foot the deadline and the
    game page. Shown once per new #1, first in line (RotationPlanner). Without a moment (it ran out between the
    planner and the frame) the week's leader as the time to beat; without a leader, the call to be the first.

    Data contract (SceneSource, BlockfillSlides::scene()):
      $blockfill  array{moment: array{name: string, time: string, avatar: ?string, before: array{name: string, time: string}|null,
                    own: bool, by: ?string}|null, leader: array{name: string, time: string}|null, goal: int (the week's blocks), closes: string, url: string, …}|null
      $stats      array: unused (the orange frame has no stats bar)
      $backdrop   ?string, optional: Blockfill's blurred cover, faint through the orange
      $cover      ?string, optional: Blockfill's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    $m = is_array($b['moment'] ?? null) ? $b['moment'] : null;
    $leader = is_array($b['leader'] ?? null) ? $b['leader'] : null;
    $who = $m ?? $leader;
    // The game's name stands in the week line right under it.
    $title = match (true) {
        $m !== null => 'New #1 on the board',
        $who !== null => 'The time to beat',
        default => 'An open board',
    };
    $week = K::fit('Blockfill '.(preg_replace('/^Blockfill\s*/', '', K::text($b ?? [], 'title')) ?: 'this week'), K::MONO, 22, 760);
    // Right of the face up to x 1016: a long name steps down a size before it loses letters, the time's digit
    // cells come down until they end there.
    $nameSize = 56;
    $name = null;
    if ($who) {
        foreach ([56, 48, 40] as $nameSize) {
            $name = K::name(K::text($who, 'name'), 'Player', $nameSize, 716);
            if ($name['text'] === K::clean(K::text($who, 'name'))) {
                break;
            }
        }
    }
    $time = $who ? K::text($who, 'time') : '';
    $cells = null;
    if ($time !== '') {
        foreach ([140, 128, 116, 104, 92] as $timeSize) {
            $cells = K::digitCells($time, 300, $timeSize);
            if ($cells['x1'] <= 1016) {
                break;
            }
        }
    }
    $before = is_array($m['before'] ?? null) ? $m['before'] : null;
    $line = match (true) {
        $m === null && $who !== null => 'The fastest run of the week so far.',
        $m === null => '',
        $before === null => 'The first time on the board this week.',
        (bool) ($m['own'] ?? false) => K::text($m, 'by').' faster than their own '.K::text($before, 'time'),
        // The beaten name gives way, never the times (fitted alone: fitting the line again would fold its "…" to
        // "..." and cut the time).
        default => K::text($m, 'by').' faster than '.(K::fit(K::text($before, 'name'), K::MONO, 26, 716 - K::width(K::text($m, 'by').' faster than \'s '.K::text($before, 'time'), K::MONO, 26)) ?: 'the leader').'\'s '.K::text($before, 'time'),
    };
    $line = $m !== null && $before !== null && ! (bool) ($m['own'] ?? false) ? $line : K::fit($line, K::MONO, 26, 716);
    $closes = K::fit('Beat it by '.K::text($b ?? [], 'closes', 'Monday 00:00 Berlin').'.', K::MONO, 22, 440);
    $url = K::fit(K::text($b ?? [], 'url', 'esports.einundzwanzig.space/blockfill'), K::MONO, 22, 500);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.2])
<rect width="1280" height="720" fill="#F7931A" fill-opacity="{{ K::backdropUri($backdrop ?? null) ? 0.94 : 1 }}"/>
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 40, 'gmW' => 160, 'gmEdge' => '#17120A'])
{{-- Title and week right of the game's mark, up to x 1016 (the viewer count stands in the stream's column). --}}
<text data-unit="title" data-box="223 52 1016 88" x="224" y="80" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A">{{ $title }}</text>
<text data-unit="week" data-box="223 98 1016 124" x="224" y="118" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $week }}</text>

@if ($who)
@include('stream.rotation.partials.face', ['face' => K::prideFace($who), 'x' => 40, 'y' => 224, 'd' => 220, 'id' => 'bf4', 'fUnit' => 'moment-face', 'fRing' => '#17120A', 'fCrown' => $m ? '#17120A' : null])
<text data-unit="name" data-box="299 216 1017 278" x="300" y="264" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $nameSize }}" fill="#17120A">{{ $name['text'] }}</text>
@if ($cells)@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 422, 'fill' => '#17120A'])@endif
@if ($line !== '')<text data-unit="line" data-box="299 460 1017 492" x="300" y="484" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#17120A">{{ $line }}</text>@endif
@else
<text data-unit="name" data-box="39 216 1017 278" x="40" y="264" font-family="Unbounded" font-weight="800" font-size="52" fill="#17120A">Be the first on the board.</text>
<text data-unit="line" data-box="39 300 1017 332" x="40" y="324" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#17120A">Mine {{ max(1, (int) ($b['goal'] ?? 40)) }} blocks. Your best time this week counts.</text>
@endif

<text data-unit="closes" data-box="39 650 480 680" x="40" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $closes }}</text>
<text data-unit="url" data-box="516 650 1017 680" x="1016" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">{{ $url }}</text>
</svg>
