@php
    use App\Filament\Resources\Posts\PostResource;
@endphp

<section class="desk-live">
    <h2>Your live posts</h2>

    @if ($posts->isEmpty())
        <p class="desk-empty">None of your posts is live yet. Once an Editor or an Admin publishes one, it shows up here with its address on the site.</p>
    @else
        {{-- The date leads each line, like a log, and the two links open the post's page on the site. --}}
        <ul class="desk-dated">
            @foreach ($posts as $post)
                <li>
                    <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->timezone('Asia/Jakarta')->format('j M Y') }}</time>
                    <a class="desk-dated-title" href="{{ PostResource::can('update', $post) ? PostResource::getUrl('edit', ['record' => $post]) : PostResource::getUrl('view', ['record' => $post]) }}">{{ $post->title_en ?: 'Untitled' }}</a>
                    <span class="desk-sites">
                        <a href="{{ $post->publicUrl('en') }}" target="_blank" rel="noopener">EN<span class="sr-only"> page on the site</span></a>
                        <a href="{{ $post->publicUrl('id') }}" target="_blank" rel="noopener">ID<span class="sr-only"> page on the site</span></a>
                    </span>
                </li>
            @endforeach
        </ul>

        @if ($total > $posts->count())
            <p class="desk-more">{{ $total - $posts->count() }} older {{ $total - $posts->count() === 1 ? 'post is' : 'posts are' }} in <a class="profile-act" href="{{ PostResource::getUrl('index') }}">the Posts list</a>.</p>
        @endif
    @endif
</section>
