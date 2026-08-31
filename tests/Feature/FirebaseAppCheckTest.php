<?php

use App\Services\AppCheck\AppCheckVerification;
use App\Services\AppCheck\FirebaseAppCheckVerifier;

beforeEach(function () {
    config()->set('app_check.mode', 'enforce');
});

function fakeAppCheck(AppCheckVerification $result): void
{
    $verifier = Mockery::mock(FirebaseAppCheckVerifier::class);
    $verifier->shouldReceive('verify')->andReturn($result);
    app()->instance(FirebaseAppCheckVerifier::class, $verifier);
}

test('enforcement rejects a missing or invalid app check token on writes', function (AppCheckVerification $result) {
    fakeAppCheck($result);

    $this->postJson('/api/v1/devices/enroll', [])->assertUnauthorized()
        ->assertJsonPath('code', 'APP_CHECK_REQUIRED');
})->with([AppCheckVerification::Missing, AppCheckVerification::Invalid]);

test('enforcement returns retryable service unavailable when verification infrastructure is down', function () {
    fakeAppCheck(AppCheckVerification::Unavailable);

    $this->postJson('/api/v1/devices/enroll', [])->assertStatus(503)
        ->assertJsonPath('code', 'APP_CHECK_UNAVAILABLE');
});

test('a valid token reaches the protected endpoint', function () {
    fakeAppCheck(AppCheckVerification::Valid);

    $this->withHeader('X-Firebase-AppCheck', 'valid-token')
        ->postJson('/api/v1/devices/enroll', [])
        ->assertUnprocessable();
});

test('monitor mode records but does not reject invalid clients', function () {
    config()->set('app_check.mode', 'monitor');
    fakeAppCheck(AppCheckVerification::Missing);

    $this->postJson('/api/v1/devices/enroll', [])->assertUnprocessable();
});

test('safe read requests are not gated by app check', function () {
    $this->getJson('/api/v1/feed')->assertUnauthorized();
});
