{{--
    TA4 · Arena · a tournament past sign-up, its bracket as it stands (running: live; finished: the final bracket). Dark
    stage over the game's blurred cover: status, the name, the round in play, and the current stage (partials/t-board:
    the bracket cropped around the live matches, the groups, or the table with its pairings). Orange column on the
    right: matches played with a bar, who is still standing (running) or the champion (finished), the matches live now,
    and the address. The now-playing column covers x 1040..1280 (SceneRenderer::svg()).

    Data contract: $tournament as TournamentLiveSlides builds it (docs/plans/2026-09-29T2215-stream-slides-stolz-und-turniere/
    catalog-tournaments.md: name, phase, status, now, board, progress, standing, live, champion, teamSize, url) and the
    backdrop ($backdrop). Every key may be missing (the scene test renders it with a sign-up frame too). $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $finished = ($t['phase'] ?? null) === 'finished';
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    // The board x 40..704, the orange side x 736..1040 with its text within x 760..1016 (the now-playing column covers
    // x 1040..1280).
    $title = K::headline(K::text($t, 'name', 'Tournament'), [34, 28, 24], 664, 1);
    $kind = is_array($t['board'] ?? null) ? ($t['board']['kind'] ?? null) : null;
    $sub = K::fit($finished ? match ($kind) { 'bracket' => 'Final bracket', 'lobbies' => 'Final places', default => 'Final standings' } : K::text($t, 'now', K::text($t, 'format')), K::MONO, 20, 664);
    // A lobby tournament (P10): place 1 across all lobbies, shared by the allies left standing.
    $firsts = K::sharedFirst($t);
    // Who leads a table (nobody is knocked out there, so "still standing" says nothing).
    $leader = $kind === 'table' ? K::tableRows($t['board'], 0, 1, 1, 1, 1)[0]['name'] ?? '' : '';
    $groupCols = $kind === 'groups' && count((array) ($t['board']['groups'] ?? [])) <= 4 ? 2 : 4;
    $played = is_array($t['progress'] ?? null) && is_int($t['progress']['played'] ?? null) ? $t['progress']['played'] : null;
    $total = is_array($t['progress'] ?? null) && is_int($t['progress']['total'] ?? null) ? $t['progress']['total'] : null;
    $share = $played !== null && $total ? max(0, min(1, $played / $total)) : 0;
    $standing = is_array($t['standing'] ?? null) && is_int($t['standing']['count'] ?? null) ? $t['standing']['count'] : null;
    $of = is_array($t['standing'] ?? null) && is_int($t['standing']['of'] ?? null) ? $t['standing']['of'] : null;
    $live = K::pairings($t['live'] ?? [], 2, $clanSeats);
    $champ = K::entryFaces(is_array($t['champion'] ?? null) ? [$t['champion']] : [], 1, $clanSeats);
    $champName = $champ ? K::headline($champ[0]['name'], [26, 22, 18], 256, 2) : null;
    [$host, $path] = K::urlLines($t);
    $urlSize = K::monoSize($host, 16, 248);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.72])
<rect x="736" y="0" width="304" height="720" fill="#F7931A"/>

<use href="#mark" xlink:href="#mark" x="40" y="36" width="32" height="32"/>
<text data-unit="status" data-box="83 40 700 68" x="84" y="60" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ K::fit(K::text($t, 'status', 'Live now'), K::MONO, 20, 560) }}</text>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title" data-box="39 {{ 112 - $title['size'] }} 705 {{ 112 + $title['size'] * 0.25 }}" x="40" y="112" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($sub !== '')<text data-unit="sub" data-box="39 128 705 154" x="40" y="148" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $sub }}</text>@endif

@include('stream.rotation.partials.t-board', ['board' => $t['board'] ?? null, 'bx' => 40, 'by' => 180, 'bw' => 664, 'bh' => 496, 'groupCols' => $groupCols, 'clanSeats' => $clanSeats,
    'panel' => '#16161A', 'rule' => '#2A2A30', 'accent' => '#F7931A', 'nameFill' => '#FFFFFF', 'muted' => '#8B8B90', 'chipInk' => '#17120A', 'bShape' => 'round', 'bId' => 'ta4'])

@if ($played !== null && $total !== null)
<text data-unit="played-label" x="760" y="150" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $kind === 'lobbies' ? 'Lobbies decided' : 'Matches decided' }}</text>
<text data-unit="played" data-box="759 164 1017 204" x="760" y="196" font-family="Unbounded" font-weight="800" font-size="32" fill="#17120A">{{ K::fit($played.' of '.$total, K::DISPLAY, 32, 256) }}</text>
<rect x="760" y="214" width="254" height="10" fill="none" stroke="#17120A" stroke-width="2"/>
@if ($share > 0)<rect x="760" y="214" width="{{ round(254 * $share, 1) }}" height="10" fill="#17120A"/>@endif
@endif
@if ($finished && $champ)
<text data-unit="champ-label" x="760" y="284" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">Champion</text>
@include('stream.rotation.partials.face', ['face' => $champ[0]['face'], 'x' => 760, 'y' => 324, 'd' => 88, 'id' => 'ta4-champ', 'fUnit' => 'champ-face', 'fRing' => '#17120A', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A', 'fTagFill' => '#17120A', 'fTagInk' => '#F7931A', 'fCrown' => '#17120A'])
@foreach ($champName['lines'] as $i => $line)
<text data-unit="champ-name-{{ $i }}" data-box="759 {{ 444 + $i * round($champName['size'] * 1.15) - $champName['size'] }} 1017 {{ 444 + $i * round($champName['size'] * 1.15) + 8 }}" x="760" y="{{ 444 + $i * round($champName['size'] * 1.15) }}" font-family="{{ $champName['font'] }}" font-weight="800" font-size="{{ $champName['size'] }}" fill="#17120A">{{ $line }}</text>
@endforeach
@elseif ($firsts !== [])
<text data-unit="first-label" x="760" y="284" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ count($firsts) > 1 ? 'Shared 1st place' : '1st place' }}</text>
@foreach (array_slice($firsts, 0, 6) as $i => $first)
<text data-unit="first-{{ $i }}" data-box="759 {{ 300 + $i * 36 }} 1017 {{ 330 + $i * 36 }}" x="760" y="{{ 324 + $i * 36 }}" font-family="{{ K::nameFont($first) }}" font-weight="800" font-size="24" fill="#17120A">{{ K::fit($first, K::nameFont($first), 24, 256) }}</text>
@endforeach
@if (count($firsts) > 6)<text data-unit="first-more" x="760" y="{{ 324 + 6 * 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">+{{ count($firsts) - 6 }} more</text>@endif
@elseif ($leader !== '')
<text data-unit="leader-label" x="760" y="284" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">Top of the table</text>
<text data-unit="leader" data-box="759 294 1017 334" x="760" y="326" font-family="{{ K::nameFont($leader) }}" font-weight="800" font-size="28" fill="#17120A">{{ K::fit($leader, K::nameFont($leader), 28, 256) }}</text>
@elseif ($standing !== null && $kind === 'bracket')
<text data-unit="standing-label" x="760" y="284" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">Still standing</text>
<text data-unit="standing" data-box="759 290 1017 340" x="760" y="334" font-family="Unbounded" font-weight="800" font-size="40" fill="#17120A">{{ $standing }}</text>
@if ($of !== null)<text data-unit="standing-of" x="{{ 760 + K::width((string) $standing, K::DISPLAY, 40) * 1.04 + 12 }}" y="334" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">of {{ $of }}</text>@endif
@endif
@if (! $finished && $live !== [])
<text data-unit="live-label" x="760" y="394" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">On now</text>
@foreach ($live as $i => $m)
<text data-unit="live-{{ $i }}-a" data-box="759 {{ 408 + $i * 64 }} 1017 {{ 432 + $i * 64 }}" x="760" y="{{ 426 + $i * 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ K::fit($m['a'], K::MONO, 18, 256) }}</text>
<text data-unit="live-{{ $i }}-b" data-box="759 {{ 432 + $i * 64 }} 1017 {{ 456 + $i * 64 }}" x="760" y="{{ 450 + $i * 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ K::fit('vs '.$m['b'], K::MONO, 18, 256) }}</text>
@endforeach
@endif

<rect x="752" y="552" width="264" height="128" fill="#17120A"/>
<text data-unit="cta" data-box="767 562 1017 610" x="768" y="598" font-family="Unbounded" font-weight="800" font-size="26" fill="#F7931A">{{ $finished ? 'Every result' : 'Follow it' }}</text>
<text data-unit="url" data-box="767 618 1017 644" x="768" y="638" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ $host }}</text>
@if ($path !== '')<text data-unit="url-path" data-box="767 644 1017 670" x="768" y="664" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ K::fit($path, K::MONO, $urlSize, 256) }}</text>@endif
</svg>
