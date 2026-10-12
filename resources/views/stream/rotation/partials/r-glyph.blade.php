{{--
    The glyph of a radio format family (ProgrammeSlides::COLOURS) on its colour square, so a family never rests on
    colour alone: ticker lines for the news, a speech bubble for talk, a flame for the roasts, a star for the shows, a
    microphone for the podcast, a note for music. $family, $gx, $gy (top left), $gs (size); drawn in a 24-unit box.
--}}
@use('App\Support\TwentyOne\Stream\ProgrammeSlides', 'PS')
<g data-unit="glyph" transform="translate({{ $gx }} {{ $gy }}) scale({{ round($gs / 24, 4) }})">
<rect width="24" height="24" rx="2" fill="{{ PS::COLOURS[$family] ?? '#FFFFFF' }}"/>
<g transform="translate(4 4) scale(0.6667)" fill="#0A0A0B" stroke="#0A0A0B">
@switch($family)
@case('news')<rect x="3" y="4" width="18" height="3" stroke="none"/><rect x="3" y="10.5" width="18" height="3" stroke="none"/><rect x="3" y="17" width="11" height="3" stroke="none"/>@break
@case('talk')<path stroke="none" d="M2 3h20v13H10l-6 5v-5H2z"/>@break
@case('roast')<path stroke="none" d="M12 1c1 4 7 6.5 7 13a7 7 0 0 1-14 0c0-3.5 2.2-5.6 3.4-7.8.3 3 1.4 4.6 2.6 4.6.4-3.6-1-6.2 1-9.8z"/>@break
@case('show')<path stroke="none" d="M12 1.5l3.1 6.7 7.4.8-5.5 5 1.6 7.3L12 17.6l-6.6 3.7L7 14l-5.5-5 7.4-.8z"/>@break
@case('podcast')<rect x="8" y="1" width="8" height="13" rx="4" stroke="none"/><path fill="none" stroke-width="2.4" d="M4.5 10.5a7.5 7.5 0 0 0 15 0M12 18v4M7.5 22.5h9"/>@break
@default<path fill="none" stroke-width="2.4" d="M9 18.5V4.5l11-2v13.5"/><circle cx="6" cy="18.5" r="3.2" stroke="none"/><circle cx="17" cy="16" r="3.2" stroke="none"/>
@endswitch
</g>
</g>
