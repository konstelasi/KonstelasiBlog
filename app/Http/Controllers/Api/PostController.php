<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostCollection;
use App\Models\Post;

class PostController extends Controller
{
    /**
     * Every published post, newest first. The Astro site fetches this at
     * build time and bakes each post into a static page per language.
     */
    public function index(): PostCollection
    {
        return new PostCollection(
            Post::query()->published()->orderByDesc('published_at')->orderByDesc('id')->get(),
        );
    }
}
