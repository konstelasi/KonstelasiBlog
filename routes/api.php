<?php

use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\V1\PostController as AuthoringPostController;
use App\Http\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;

// Read-only and public. Only published posts come out, and those are on
// the public site anyway. The site build depends on this exact shape.
Route::get('/posts', [PostController::class, 'index']);

// The authoring API. A bearer token stands for an account, so the account's
// role decides what it may do and the token's abilities narrow that further.
// A post route needs `posts:write`, and `PostUpsert` asks for `posts:publish`
// when a write touches a live post. A tag route needs `tags:manage`, kept
// apart on purpose, so a token that writes drafts cannot change the tags a
// post may use. Tokens are made on the profile page, under API tokens.
Route::prefix('v1')
    ->where(['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->middleware(['auth:sanctum', 'throttle:posts-write'])
    ->group(function (): void {
        Route::middleware('abilities:posts:write')->group(function (): void {
            Route::get('/posts/{slug}', [AuthoringPostController::class, 'show']);
            Route::put('/posts/{slug}', [AuthoringPostController::class, 'upsert']);
        });

        // Listing takes either ability, so a client that writes posts can
        // check their tags before it sends them.
        Route::get('/tags', [TagController::class, 'index'])->middleware('ability:posts:write,tags:manage');
        Route::put('/tags/{slug}', [TagController::class, 'upsert'])->middleware('abilities:tags:manage');
    });
