@php
    use App\Enums\PostStatus;
    use App\Filament\Resources\Posts\PostResource;
    use App\Support\ProfileOverview;

    $incomplete = $overview->incompleteDrafts();
    $ready = $overview->readyToPublish();
    $posts = $overview->posts();
    $drafts = $overview->drafts();
    $live = $overview->live();
@endphp

<div class="profile-overview">
    {{-- The figures sit in one ruled line, not in cards. --}}
    <div class="profile-figures">
        <div><b>{{ number_format($drafts) }}</b><span>{{ $drafts === 1 ? 'draft' : 'drafts' }}</span></div>
        <div><b>{{ number_format($live) }}</b><span>live {{ $live === 1 ? 'post' : 'posts' }}</span></div>
        <div><b>{{ number_format($overview->words()) }}</b><span>words, both languages</span></div>
    </div>

    @if ($incomplete->isNotEmpty())
        <section class="profile-block">
            <h2>Needs attention</h2>
            <p class="profile-lead">{{ $incomplete->count() === 1 ? 'One of your drafts cannot' : $incomplete->count().' of your drafts cannot' }} be published yet.</p>
            <div class="profile-todo">
                @foreach ($incomplete as $row)
                    <div class="profile-row">
                        <div class="profile-row-title">
                            {{ $row['post']->title_en ?: 'Untitled' }}
                            <small>Edited {{ $row['post']->updated_at->diffForHumans() }}</small>
                        </div>
                        <div class="profile-langs">
                            <span @class(['profile-lang', 'is-ok' => $row['post']->hasLanguage('en')])>EN</span>
                            <span @class(['profile-lang', 'is-ok' => $row['post']->hasLanguage('id')])>ID</span>
                        </div>
                        <a class="profile-act" href="{{ PostResource::getUrl('edit', ['record' => $row['post']]) }}">{{ ProfileOverview::nextStep($row['missing']) }}</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($ready->isNotEmpty())
        <section class="profile-block">
            <h2>Ready to publish</h2>
            <p class="profile-lead">Drafts by others with both languages complete.</p>
            <div class="profile-todo">
                @foreach ($ready as $post)
                    <div class="profile-row">
                        <div class="profile-row-title">
                            {{ $post->title_en ?: 'Untitled' }}
                            <small>by {{ $post->byline() }}</small>
                        </div>
                        <div class="profile-langs">
                            <span class="profile-lang is-ok">EN</span>
                            <span class="profile-lang is-ok">ID</span>
                        </div>
                        <a class="profile-act" href="{{ PostResource::getUrl('edit', ['record' => $post]) }}">Review</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="profile-block">
        <h2>Your posts</h2>
        @if ($posts->isEmpty())
            <p class="profile-lead">You have not written a post yet. Posts you start show up here.</p>
        @else
            <div class="profile-tablewrap">
                <table class="profile-table">
                    <thead>
                        <tr><th>Title</th><th>Status</th><th>EN</th><th>ID</th><th><span class="sr-only">Open</span></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($posts as $post)
                            <tr>
                                <td>{{ $post->title_en ?: 'Untitled' }}</td>
                                <td><span @class(['profile-status', 'is-live' => $post->status === PostStatus::Published])>{{ $post->status === PostStatus::Published ? 'Live' : 'Draft' }}</span></td>
                                <td><span @class(['profile-lang', 'is-ok' => $post->hasLanguage('en')])>EN</span></td>
                                <td><span @class(['profile-lang', 'is-ok' => $post->hasLanguage('id')])>ID</span></td>
                                <td>
                                    @if (PostResource::can('update', $post))
                                        <a class="profile-act" href="{{ PostResource::getUrl('edit', ['record' => $post]) }}">Edit</a>
                                    @else
                                        <a class="profile-act" href="{{ PostResource::getUrl('view', ['record' => $post]) }}">View</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="profile-block">
        <h2>What your role allows</h2>
        <p class="profile-lead">{{ $overview->roleLead() }}</p>
        <div class="profile-can">
            @foreach ($overview->abilityGroups() as $group => $abilities)
                <div>
                    <h3>{{ $group }}</h3>
                    <ul>
                        @foreach ($abilities as $ability)
                            <li @class(['is-no' => ! $ability['allowed']])>
                                {{ $ability['sentence'] }}
                                @unless ($ability['allowed'])<span class="sr-only"> Not allowed for your role.</span>@endunless
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
</div>
