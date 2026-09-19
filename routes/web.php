<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Phase 2 carries a single scaffold-check route so the design foundation can be
| verified by eye. The real routes are migrated from the legacy front controller
| in later phases — the full map is in docs/migration/routes.md, and the existing
| URLs are preserved.
|
| The agent API (/webhooks/*) is a separate, frozen contract; see
| docs/migration/api-contract.md.
|
*/

Route::get('/', function () {
    return view('dev.preview', [
        'icons' => require resource_path('icons/icons.php'),
    ]);
})->name('dev.preview');
