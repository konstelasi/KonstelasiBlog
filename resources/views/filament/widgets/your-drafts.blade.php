@php
    use App\Filament\Resources\Posts\PostResource;

    $open = fn ($post): string => PostResource::can('update', $post)
        ? PostResource::getUrl('edit', ['record' => $post])
        : PostResource::getUrl('view', ['record' => $post]);

    $lead = $drafts->first();
@endphp

<section class="desk-drafts">
    <h2>Your drafts</h2>

    @if ($lead === null)
        <p class="desk-empty">Nothing in progress. <a class="profile-act" href="{{ PostResource::getUrl('create') }}">Start a post</a></p>
    @else
        @php
            $post = $lead['post'];
            $state = match (count($lead['missing'])) {
                0 => $publisher
                    ? 'Both languages are complete. You can publish it.'
                    : 'Both languages are complete. An Editor or an Admin can publish it.',
                1 => $lead['missing'][0].' is not complete yet.',
                default => 'Neither language is complete yet.',
            };
        @endphp

        {{-- The draft to pick up, set large. The others are a plain list below it. --}}
        <div class="desk-lead">
            <p class="desk-edited">Last edited {{ $post->updated_at->diffForHumans() }}</p>
            <a class="desk-lead-title" href="{{ $open($post) }}">{{ $post->title_en ?: 'Untitled' }}</a>
            <p class="desk-lead-state">{{ $state }}</p>
            <div class="desk-lead-foot">
                <a class="profile-act" href="{{ $open($post) }}">Continue writing</a>
                <span class="profile-langs">
                    <span @class(['profile-lang', 'is-ok' => $post->hasLanguage('en')])>EN</span>
                    <span @class(['profile-lang', 'is-ok' => $post->hasLanguage('id')])>ID</span>
                </span>
            </div>
        </div>

        @if ($drafts->count() > 1)
            <ul class="desk-others">
                @foreach ($drafts->slice(1) as $row)
                    <li>
                        <div>
                            <a href="{{ $open($row['post']) }}">{{ $row['post']->title_en ?: 'Untitled' }}</a>
                            <small>Edited {{ $row['post']->updated_at->diffForHumans() }}, {{ $row['missing'] === [] ? 'complete' : 'needs '.implode(' and ', $row['missing']) }}</small>
                        </div>
                        <span class="profile-langs">
                            <span @class(['profile-lang', 'is-ok' => $row['post']->hasLanguage('en')])>EN</span>
                            <span @class(['profile-lang', 'is-ok' => $row['post']->hasLanguage('id')])>ID</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</section>
