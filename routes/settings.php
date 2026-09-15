<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');

// SEC-002 (Android audit): lets Android's `autoVerify="true"` App Link intent filters
// (ResetPasswordActivity's password-reset link) confirm this domain actually authorizes the
// ly.akukas.akukasapp package to open its https://akukas.ly/... links, instead of falling back to
// a browser disambiguation dialog. Fingerprint is the upload/release keystore's own SHA-256 cert
// fingerprint (`keytool -list -v -keystore my-upload-key.jks -alias upload`) — if the signing key
// is ever rotated, or Play App Signing re-signs the distributed APK with its own key, add that
// key's fingerprint here too rather than replacing this one.
Route::get('.well-known/assetlinks.json', function () {
    return response()->json([
        [
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'ly.akukas.akukasapp',
                'sha256_cert_fingerprints' => [
                    'D6:08:52:A2:D4:9C:F8:A2:B5:E8:9C:B7:C7:7E:04:A0:49:05:9D:AE:0C:93:9B:42:EB:C7:9D:CD:E7:6B:44:3D',
                ],
            ],
        ],
    ]);
})->name('well-known.assetlinks');
