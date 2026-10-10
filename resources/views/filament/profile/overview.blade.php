@php
    use App\Filament\Resources\Posts\PostResource;

    $incomplete = $overview->incompleteDrafts();
    $ready = $overview->readyToPublish();
@endphp

{{-- The figures sit in one line, not in a row of cards, like the rest of the flat admin. --}}
<div class="flex flex-col gap-6">
    <p class="text-base text-gray-950 dark:text-white">
        <strong class="font-semibold">{{ number_format($overview->drafts()) }}</strong> {{ $overview->drafts() === 1 ? 'draft' : 'drafts' }},
        <strong class="font-semibold">{{ number_format($overview->live()) }}</strong> live,
        <strong class="font-semibold">{{ number_format($overview->words()) }}</strong> words written in both languages.
    </p>

    @if ($incomplete->isNotEmpty() || $ready->isNotEmpty())
        <section class="flex flex-col gap-3">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Needs attention</h3>

            @if ($incomplete->isNotEmpty())
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <p>Your drafts that are not ready to publish.</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($incomplete as $row)
                            <li>
                                <a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="{{ PostResource::getUrl('edit', ['record' => $row['post']]) }}">{{ $row['post']->title_en ?: 'Untitled' }}</a>
                                needs {{ implode(' and ', $row['missing']) }} finished.
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($ready->isNotEmpty())
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <p>Drafts by others that have both languages and are ready to publish.</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($ready as $post)
                            <li>
                                <a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="{{ PostResource::getUrl('edit', ['record' => $post]) }}">{{ $post->title_en ?: 'Untitled' }}</a>
                                by {{ $post->byline() }}.
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    @endif

    <section class="flex flex-col gap-1">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Your role is {{ $overview->roleName() }}</h3>
        <ul class="list-disc ps-5 text-sm text-gray-600 dark:text-gray-400">
            @foreach ($overview->abilities() as $sentence)
                <li>{{ $sentence }}</li>
            @endforeach
        </ul>
    </section>
</div>
