{{--
    The label of the chat's mute button (partials/chat), both states. The opponent's name truncates on its own and the
    verb stays whole: a 39-character name squeezed the sentence beside it to 0 px at 390 and pushed the page 67 px wide
    at 1440 (V3). The full name stays in the sentence beside the button and in its title.

    $labels: the chat's labels (`mute`, `muted`), $name: the opponent's display name.
--}}
@foreach (['mute' => '! opponentMuted', 'muted' => 'opponentMuted'] as $key => $when)
    @php
        $label = (string) ($labels[$key] ?? '');
        $at = $name === '' ? false : mb_strpos($label, $name);
        [$before, $who, $after] = $at === false ? [$label, '', ''] : [mb_substr($label, 0, $at), $name, mb_substr($label, $at + mb_strlen($name))];
    @endphp
    <span x-show="{{ $when }}" x-cloak class="flex min-w-0 items-center" data-test="mute-label-{{ $key }}"><span class="shrink-0 whitespace-pre">{{ $before }}</span><span class="min-w-0 truncate" data-test="mute-name">{{ $who }}</span><span class="shrink-0 whitespace-pre">{{ $after }}</span></span>
@endforeach
