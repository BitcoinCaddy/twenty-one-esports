{{--
    TV3 · the standings, as the tournament TV shows its "Standings" scene (pages::tournaments.tv), in the TV frame
    (partials/tv-frame): up to four tables two to a row, each its title and its rows on panels (rank, orange and
    marked with a chevron when through; avatar; name; wins–draws–losses; points), one legend for all under them that
    names the chevron "Through". Every row of a table is shown while the type can stay at 16 px or more: the type
    comes down until the rows fit, never the other way round. A tournament without a table
    (a bracket throughout, which the TV shows no standings for) gets who is still standing instead, in the same
    language: the count and the players with their avatars.

    Data contract: $tournament as TournamentLiveSlides builds it (running: tv.tables, standing, teamSize, results) and
    $viewers. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $tvData = is_array($t['tv'] ?? null) ? $t['tv'] : [];
    $tables = \App\Support\TwentyOne\Stream\TvSlides::tables((array) ($tvData['tables'] ?? []));
    $standing = is_array($t['standing'] ?? null) ? $t['standing'] : null;
    $teams = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
@include('stream.rotation.partials.tv-frame', ['t' => $t, 'part' => 3])

@if ($tables !== [])
@php
    $cols = min(2, count($tables));
    $tRows = (int) ceil(count($tables) / $cols);
    $colW = (Tv::X1 - Tv::X0 - ($cols - 1) * 38) / $cols;
    // One legend under all the tables (26 px), the tables share the rest.
    $legendH = 26;
    $blockH = (Tv::Y1 - Tv::Y0 - $legendH - ($tRows - 1) * 19) / $tRows;
    $legendY = Tv::Y1 - 6;
@endphp
@foreach ($tables as $ti => $table)
@php
    $tx = Tv::X0 + ($ti % $cols) * ($colW + 38);
    $ty = Tv::Y0 + intdiv($ti, $cols) * ($blockH + 19);
    $rows = array_values(array_filter((array) $table['rows'], is_array(...)));
    $k = Tv::k(count($rows), 6);
    $gapY = round(6.4 * $k, 1);
    $room = $blockH - 32;
    // A row is 2.04 type sizes tall (the TV's padding): the largest size at which every row fits, 16 px at least.
    $fs = max(16, floor(10 * min(24 * $k, ($room - (count($rows) - 1) * $gapY) / (2.04 * max(1, count($rows))))) / 10 - 0.1);
    $rowH = round(max($fs * 1.2 * 1.2, $fs * 1.4) + $fs * 0.6, 1);
    $fit = (int) max(1, floor(($room + $gapY) / ($rowH + $gapY)));
    $rows = array_slice($rows, 0, $fit);
    $faceD = round($fs * 1.2, 1);
@endphp
<text data-unit="table-{{ $ti }}" x="{{ round($tx, 1) }}" y="{{ $ty + 19 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">{{ K::fit($table['title'] ?? '', K::DISPLAY, 19, $colW) }}</text>
@foreach ($rows as $ri => $row)
@php
    $ry = $ty + 32 + $ri * ($rowH + $gapY);
    $cy = $ry + $rowH / 2;
    $through = ($row['through'] ?? false) === true;
    $right = $tx + $colW - $fs * 0.5;
    $pointsX = $right;
    $record = str_replace('-', '–', (string) ($row['record'] ?? ''));
    $recordX = $pointsX - $fs * 2.4 - $fs * 0.5;
    $recordW = K::width($record, K::MONO, $fs);
    // The chevron alone in the row; the legend says what it means.
    $throughW = $through ? 14 : 0;
    $throughX = $recordX - $recordW - $fs * 0.5 - $throughW;
    $rankX = $tx + $fs * 0.5 + $fs * 1.4;
    $faceX = $rankX + $fs * 0.5;
    $nameX = $faceX + $faceD + $fs * 0.5;
    $nameRoom = ($through ? $throughX : $recordX - $recordW) - $fs * 0.5 - $nameX;
    $nm = K::name($row['name'] ?? '', 'Player', $fs, $nameRoom);
@endphp
<rect x="{{ round($tx, 1) }}" y="{{ round($ry, 1) }}" width="{{ round($colW, 1) }}" height="{{ $rowH }}" rx="5" fill="{{ Tv::PANEL }}"/>
<text data-unit="rank-{{ $ti }}-{{ $ri }}" x="{{ round($rankX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="Unbounded" font-weight="700" font-size="{{ $fs }}" fill="{{ $through ? Tv::BTC : Tv::INK2 }}" text-anchor="end">{{ (int) ($row['rank'] ?? 0) }}</text>
@include('stream.rotation.partials.face', ['face' => K::face($row, $teams), 'x' => round($faceX, 1), 'y' => round($cy - $faceD / 2, 1), 'd' => $faceD, 'id' => 'tv3-'.$ti.'-'.$ri, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'row-face-'.$ti.'-'.$ri])
<text data-unit="row-name-{{ $ti }}-{{ $ri }}" x="{{ round($nameX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="{{ $nm['font'] }}" font-weight="500" font-size="{{ $fs }}" fill="{{ Tv::INK }}">{{ $nm['text'] }}</text>
@if ($through)
<path data-unit="through-{{ $ti }}-{{ $ri }}" d="M{{ round($throughX + 1, 1) }} {{ round($cy + 3, 1) }}l6 -6l6 6" fill="none" stroke="{{ Tv::BTC_HI }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
@endif
<text data-unit="record-{{ $ti }}-{{ $ri }}" x="{{ round($recordX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="JetBrains Mono" font-size="{{ $fs }}" fill="{{ Tv::INK2 }}" text-anchor="end">{{ $record }}</text>
<text data-unit="points-{{ $ti }}-{{ $ri }}" x="{{ round($pointsX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="Unbounded" font-weight="800" font-size="{{ $fs }}" fill="{{ Tv::INK }}" text-anchor="end">{{ K::clean((string) ($row['points'] ?? '')) }}</text>
@endforeach
@endforeach
<path d="M{{ Tv::X0 + 1 }} {{ $legendY - 2 }}l6 -6l6 6" fill="none" stroke="{{ Tv::BTC_HI }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
<text data-unit="legend-through" x="{{ Tv::X0 + 20 }}" y="{{ $legendY }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::BTC_HI }}">Through</text>
<text data-unit="legend" x="{{ round(Tv::X0 + 20 + K::width('Through', K::MONO, 16) + 26, 1) }}" y="{{ $legendY }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK3 }}">Wins, draws, losses and points</text>
@elseif ($standing !== null)
@php
    $faces = array_values(array_filter((array) ($standing['faces'] ?? []), is_array(...)));
    $count = is_int($standing['count'] ?? null) ? $standing['count'] : count($faces);
    $of = is_int($standing['of'] ?? null) ? $standing['of'] : null;
    $word = $teams ? ($of === 1 ? 'team' : 'teams') : ($of === 1 ? 'player' : 'players');
    $line = $of !== null ? $count.' of '.$of.' '.$word.' still in it' : $count.' still in it';
    $perRow = 4;
    $gridTop = Tv::Y0 + 80;
    $rowsN = max(1, (int) ceil(count($faces) / $perRow));
    $pitch = min(96, (Tv::Y1 - $gridTop) / $rowsN);
    $faceD = round(min(64, $pitch - 16));
    $cellW = (Tv::X1 - Tv::X0) / $perRow;
@endphp
<text data-unit="standing-title" x="38" y="{{ Tv::Y0 + 19 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">Still standing</text>
<text data-unit="standing-count" x="38" y="{{ Tv::Y0 + 54 }}" font-family="JetBrains Mono" font-size="19" fill="{{ Tv::INK2 }}">{{ $line }}</text>
@foreach ($faces as $fi => $entry)
@php
    $fx = Tv::X0 + ($fi % $perRow) * $cellW;
    $fy = $gridTop + intdiv($fi, $perRow) * $pitch;
    $nm = K::name($entry['name'] ?? '', 'Player', 19, $cellW - $faceD - 10 - 19);
@endphp
@include('stream.rotation.partials.face', ['face' => K::face($entry, $teams), 'x' => round($fx, 1), 'y' => round($fy, 1), 'd' => $faceD, 'id' => 'tv3-s'.$fi, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'standing-face-'.$fi])
<text data-unit="standing-name-{{ $fi }}" x="{{ round($fx + $faceD + 10, 1) }}" y="{{ round($fy + $faceD / 2 + 7, 1) }}" font-family="{{ $nm['font'] }}" font-weight="500" font-size="19" fill="{{ Tv::INK }}">{{ $nm['text'] }}</text>
@endforeach
@endif
</svg>
