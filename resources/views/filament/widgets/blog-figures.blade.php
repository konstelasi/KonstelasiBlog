@php($drafts = $overview->drafts())
@php($live = $overview->live())
@php($last = $overview->lastPublished())

{{-- Same ruled line as the profile Overview, not cards. --}}
<div class="profile-figures">
    <div><b>{{ number_format($live) }}</b><span>live {{ $live === 1 ? 'post' : 'posts' }}</span></div>
    <div><b>{{ number_format($drafts) }}</b><span>{{ $drafts === 1 ? 'draft' : 'drafts' }}</span></div>
    <div><b>{{ number_format($overview->liveWords()) }}</b><span>words live, both languages</span></div>
    <div>
        <b class="dash-when">{{ $last ? $last->diffForHumans() : 'Nothing yet' }}</b>
        <span>last published</span>
    </div>
</div>
