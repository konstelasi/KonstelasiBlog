<?php

use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\V1\PostController as AuthoringPostController;
use Illuminate\Support\Facades\Route;

// Read-only and public. Only published posts come out, and those are on
// the public site anyway. The site build depends on this exact shape.
Route::get('/posts', [PostController::class, 'index']);

// The authoring API. A bearer token stands for an account, so the account's
// role decides what it may do and the token's abilities narrow that further.
// A route needs `posts:write`, and `PostUpsert` asks for `posts:publish` when
// a write touches a live post. Tokens are made in the admin under API tokens.
Route::prefix('v1')
    ->where(['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->middleware(['auth:sanctum', 'abilities:posts:write', 'throttle:posts-write'])
    ->group(function (): void {
        Route::get('/posts/{slug}', [AuthoringPostController::class, 'show']);
        Route::put('/posts/{slug}', [AuthoringPostController::class, 'upsert']);
    });
