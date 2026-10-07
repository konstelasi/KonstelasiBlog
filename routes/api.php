<?php

use App\Http\Controllers\Api\PostController;
use Illuminate\Support\Facades\Route;

// Read-only and public. Only published posts come out, and those are on
// the public site anyway.
Route::get('/posts', [PostController::class, 'index']);
