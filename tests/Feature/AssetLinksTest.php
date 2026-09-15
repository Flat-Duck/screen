<?php

test('assetlinks.json authorizes the Android app to handle akukas.ly links', function () {
    $response = $this->getJson('/.well-known/assetlinks.json');

    $response->assertOk();
    $response->assertJsonPath('0.relation', ['delegate_permission/common.handle_all_urls']);
    $response->assertJsonPath('0.target.namespace', 'android_app');
    $response->assertJsonPath('0.target.package_name', 'ly.akukas.akukasapp');
    $response->assertJsonPath('0.target.sha256_cert_fingerprints.0', 'D6:08:52:A2:D4:9C:F8:A2:B5:E8:9C:B7:C7:7E:04:A0:49:05:9D:AE:0C:93:9B:42:EB:C7:9D:CD:E7:6B:44:3D');
});

test('assetlinks.json is reachable without authentication', function () {
    $this->getJson('/.well-known/assetlinks.json')->assertOk();
});
