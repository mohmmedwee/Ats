<?php

use Illuminate\Support\Facades\Route;

// The API serves no HTML; the storefront is the Nuxt app.
Route::get('/', fn () => response()->json(['name' => 'GulfCart API', 'docs' => '/api/v1']));
