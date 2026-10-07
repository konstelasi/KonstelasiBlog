<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The response is a bare JSON array, with no `data` wrapper, because that
 * is the shape the site's content loader expects.
 */
class PostCollection extends ResourceCollection
{
    public static $wrap = null;

    public $collects = PostResource::class;
}
