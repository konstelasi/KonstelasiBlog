@php
    use App\Filament\Resources\Posts\PostResource;
@endphp

{{-- Livewire wants an element at the root, so the wrapper stays when there is nothing to list. --}}
<div class="desk-queue">
    @if ($ready->isEmpty() && $halfDone->isEmpty())
        <section>
            <h2>Waiting for you</h2>
            <p class="desk-empty">No one else has a draft waiting for a decision.</p>
        </section>
    @endif

    @if ($ready->isNotEmpty())
        <section>
            <h2>Ready to publish</h2>
            <p class="desk-empty">Both languages are complete. The draft that has waited longest is first.</p>
            <ul class="dash-plain">
                @foreach ($ready as $post)
                    <li>
                        <a href="{{ PostResource::getUrl('edit', ['record' => $post]) }}">{{ $post->title_en ?: 'Untitled' }}</a>
                        <small>by {{ $post->byline() }}, edited {{ $post->updated_at->diffForHumans() }}</small>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($halfDone->isNotEmpty())
        <section>
            <h2>Not ready yet</h2>
            <ul class="dash-plain">
                @foreach ($halfDone as $row)
                    <li>
                        <a href="{{ PostResource::getUrl('edit', ['record' => $row['post']]) }}">{{ $row['post']->title_en ?: 'Untitled' }}</a>
                        <small>by {{ $row['post']->byline() }}, needs {{ implode(' and ', $row['missing']) }}</small>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
