@props(['count'])

{{-- The number of things waiting behind an admin page (open cases, payouts to send): the board's count chip (`.n`). --}}
<span class="rv-n shrink-0">{{ $count }}<span class="sr-only"> {{ __('waiting') }}</span></span>
