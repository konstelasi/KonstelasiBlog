<section class="dash-rhythm">
    <h2>Published, last 12 months</h2>

    @if ($total === 0)
        <p class="profile-lead">Nothing published in the last 12 months.</p>
    @else
        {{-- The bars are decoration for the numbers beside them, which stay readable as a list. --}}
        <ol class="dash-bars">
            @foreach ($months as $month)
                <li style="--n: {{ $month['count'] }}; --max: {{ $max }}">
                    <span class="dash-count">{{ $month['count'] }}</span>
                    <span class="dash-bar" aria-hidden="true"></span>
                    <span class="dash-month">{{ $month['label'] }}<span class="sr-only"> {{ $month['year'] }}</span></span>
                </li>
            @endforeach
        </ol>
    @endif
</section>
