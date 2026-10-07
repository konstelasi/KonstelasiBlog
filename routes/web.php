<?php

use Illuminate\Support\Facades\Route;

// Readers belong on the public blog. This host only runs the admin at
// /admin and the API that the site's build reads. `away()` keeps the
// trailing slash, which the static site's URLs end with.
Route::get('/', fn () => redirect()->away('https://konstelasi.co.id/blog/', 301));
