@php
    use App\Filament\Resources\Posts\PostResource;
    use App\Support\ProfileOverview;
@endphp

{{-- Livewire wants an element at the root, so the wrapper stays when there is nothing to list. --}}
<div class="dash-needs">
    @if ($ready->isEmpty() && $halfDone->isEmpty())
        <section class="profile-block dash-block">
            <h2>Nothing is waiting</h2>
            <p class="profile-lead">{{ $publisher ? 'No draft is complete and waiting for review, and none is missing a language.' : 'None of your drafts is missing a language.' }}</p>
        </section>
    @endif

    @if ($ready->isNotEmpty())
        <section class="profile-block dash-block">
            <h2>Ready to publish</h2>
            <p class="profile-lead">Both languages are complete. The draft that has waited longest is first.</p>
            <div class="profile-todo">
                @foreach ($ready as $post)
                    <div class="profile-row">
                        <div class="profile-row-title">
                            {{ $post->title_en ?: 'Untitled' }}
                            <small>by {{ $post->byline() }}, edited {{ $post->updated_at->diffForHumans() }}</small>
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

    @if ($halfDone->isNotEmpty())
        <section class="profile-block dash-block">
            <h2>Not ready yet</h2>
            <p class="profile-lead">{{ $publisher ? 'Drafts that still miss a language.' : 'Your drafts that still miss a language.' }}</p>
            <div class="profile-todo">
                @foreach ($halfDone as $row)
                    <div class="profile-row">
                        <div class="profile-row-title">
                            {{ $row['post']->title_en ?: 'Untitled' }}
                            <small>{{ $publisher ? 'by '.$row['post']->byline().', ' : '' }}edited {{ $row['post']->updated_at->diffForHumans() }}</small>
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
</div>
