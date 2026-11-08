{{-- The post as the website's blog page lays it out, in both languages. Both are drawn on the server and the tabs only switch between them. --}}
@php($languages = ['en' => 'English', 'id' => 'Indonesian'])

<div class="pv" x-data="{ lang: 'en' }">
    <div class="pv-top">
        <div class="pv-tabs" role="tablist" aria-label="Language">
            @foreach ($languages as $lang => $name)
                <button
                    type="button"
                    role="tab"
                    x-on:click="lang = '{{ $lang }}'"
                    x-bind:aria-selected="lang === '{{ $lang }}' ? 'true' : 'false'"
                >{{ $name }}</button>
            @endforeach
        </div>
        <p class="pv-note">Your unsaved changes are included. Code blocks are not highlighted here, and raw HTML is cleaned.</p>
    </div>

    @foreach ($languages as $lang => $name)
        @php($copy = $preview->copy($lang))
        @php($tags = $preview->tags($lang))

        <article
            class="pv-article"
            lang="{{ $lang }}"
            x-show="lang === '{{ $lang }}'"
            @unless ($loop->first) x-cloak @endunless
        >
            <header class="pv-header">
                <span class="pv-back">{{ $copy['all'] }}</span>
                <h1 class="pv-title">
                    @if ($preview->title($lang) !== '')
                        {{ $preview->title($lang) }}
                    @else
                        <span class="pv-empty">No {{ $name }} title yet</span>
                    @endif
                </h1>
                <p class="pv-lead">
                    @if ($preview->description($lang) !== '')
                        {{ $preview->description($lang) }}
                    @else
                        <span class="pv-empty">No {{ $name }} description yet</span>
                    @endif
                </p>
            </header>

            <div class="pv-body">
                <dl class="pv-meta">
                    <div><dt>{{ $copy['published'] }}</dt><dd>{{ $preview->published($lang) }}</dd></div>
                    <div><dt>{{ $copy['author'] }}</dt><dd>{{ $preview->writer() }}</dd></div>
                    <div><dt>{{ $copy['reading'] }}</dt><dd>{{ $preview->readingTime($lang) }}</dd></div>
                    @if ($tags !== [])
                        <div>
                            <dt>{{ $copy['topics'] }}</dt>
                            <dd>
                                <ul class="pv-topics">
                                    @foreach ($tags as $tag)
                                        <li>{{ $tag }}</li>
                                    @endforeach
                                </ul>
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="pv-prose">
                    @if (trim($preview->body($lang)) !== '')
                        {!! $preview->html($lang) !!}
                    @else
                        <p class="pv-empty">Nothing is written in the {{ $name }} body yet.</p>
                    @endif
                </div>
            </div>
        </article>
    @endforeach
</div>
