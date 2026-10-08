{{-- Shown on every admin page while the last request to rebuild the site failed. --}}
<div style="margin-block-end: 1.5rem">
    <x-filament::callout
        color="warning"
        icon="heroicon-o-exclamation-triangle"
        heading="The last site rebuild did not start"
        :description="'The public blog may be out of date. It was tried '.$failedAt->diffForHumans().'. Save a published post again to retry.'"
    />
</div>
