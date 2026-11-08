@php($drafts = $overview->drafts())
@php($live = $overview->live())
@php($last = $overview->lastPublished())

{{-- The whole blog, not the account's own work, so it sits beside the desk. Numbers on the right, labels on the left. --}}
<section class="desk-facts">
    <h2>The blog</h2>
    <dl>
        <div><dt>Live {{ $live === 1 ? 'post' : 'posts' }}</dt><dd>{{ number_format($live) }}</dd></div>
        <div><dt>{{ $drafts === 1 ? 'Draft' : 'Drafts' }}</dt><dd>{{ number_format($drafts) }}</dd></div>
        <div><dt>Words live, both languages</dt><dd>{{ number_format($overview->liveWords()) }}</dd></div>
        <div><dt>Last published</dt><dd>{{ $last ? $last->diffForHumans() : 'Nothing yet' }}</dd></div>
    </dl>
</section>
