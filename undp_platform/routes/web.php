<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;

Route::get('/storage/mobile/avatars/{filename}', function (string $filename) {
    $path = 'mobile/avatars/'.$filename;

    abort_unless(Storage::disk('public')->exists($path), 404);

    return response()->file(
        Storage::disk('public')->path($path),
        [
            'Content-Type' => Storage::disk('public')->mimeType($path) ?? 'application/octet-stream',
        ],
    );
})->where('filename', '[A-Za-z0-9._-]+')->name('storage.mobile-avatar');

Route::view('/{any?}', 'app')->where('any', '^(?!api).*$');
