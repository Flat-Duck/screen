<?php

use App\Exceptions\PermanentRemoteImageException;
use App\Services\ImageProcessingService;
use App\Services\SocialAuth\PublicSocialImageAddressResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mockery\MockInterface;

it('resolves and pins every trusted HTTPS redirect before fetching the final image', function (): void {
    Http::preventStrayRequests();
    Storage::fake('public');

    $resolver = Mockery::mock(PublicSocialImageAddressResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('resolve')->once()->with('lh3.googleusercontent.com')->andReturn(['8.8.8.8']);
        $mock->shouldReceive('resolve')->once()->with('scontent-ams2-1.xx.fbcdn.net')->andReturn(['1.1.1.1']);
        $mock->shouldReceive('curlResolveEntries')->once()
            ->with('lh3.googleusercontent.com', ['8.8.8.8'])
            ->andReturn(['lh3.googleusercontent.com:443:8.8.8.8']);
        $mock->shouldReceive('curlResolveEntries')->once()
            ->with('scontent-ams2-1.xx.fbcdn.net', ['1.1.1.1'])
            ->andReturn(['scontent-ams2-1.xx.fbcdn.net:443:1.1.1.1']);
    });
    $this->app->instance(PublicSocialImageAddressResolver::class, $resolver);

    $manager = new ImageManager(new Driver);
    $bytes = (string) $manager->create(1, 1)->fill('#b23f2a')->toPng();

    Http::fakeSequence()
        ->push('', 302, ['Location' => 'https://scontent-ams2-1.xx.fbcdn.net/avatar'])
        ->push($bytes, 200, ['Content-Type' => 'image/png']);

    $image = app(ImageProcessingService::class)->storeFromUrl(
        'https://lh3.googleusercontent.com/avatar',
        'avatars',
    );

    expect($image['width'])->toBe(1)
        ->and($image['height'])->toBe(1)
        ->and(Storage::disk('public')->exists($image['path']))->toBeTrue();

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/avatar',
        fn (Request $request): bool => $request->url() === 'https://scontent-ams2-1.xx.fbcdn.net/avatar',
    ]);
});

it('does not send a request to an untrusted redirect host', function (): void {
    Http::preventStrayRequests();
    $resolver = Mockery::mock(PublicSocialImageAddressResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('resolve')->once()->with('lh3.googleusercontent.com')->andReturn(['8.8.8.8']);
        $mock->shouldReceive('curlResolveEntries')->once()
            ->with('lh3.googleusercontent.com', ['8.8.8.8'])
            ->andReturn(['lh3.googleusercontent.com:443:8.8.8.8']);
    });
    $this->app->instance(PublicSocialImageAddressResolver::class, $resolver);

    Http::fakeSequence()
        ->push('', 302, ['Location' => 'https://attacker.example/avatar']);

    expect(fn () => app(ImageProcessingService::class)->storeFromUrl(
        'https://lh3.googleusercontent.com/avatar',
        'avatars',
    ))->toThrow(PermanentRemoteImageException::class);

    Http::assertSentCount(1);
});

it('stops following redirects at the configured hop limit', function (): void {
    Http::preventStrayRequests();
    config()->set('social.images.remote_avatar_max_redirects', 1);
    $resolver = Mockery::mock(PublicSocialImageAddressResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('resolve')->once()->with('lh3.googleusercontent.com')->andReturn(['8.8.8.8']);
        $mock->shouldReceive('resolve')->once()->with('scontent-ams2-1.xx.fbcdn.net')->andReturn(['1.1.1.1']);
        $mock->shouldReceive('curlResolveEntries')->once()
            ->with('lh3.googleusercontent.com', ['8.8.8.8'])
            ->andReturn(['lh3.googleusercontent.com:443:8.8.8.8']);
        $mock->shouldReceive('curlResolveEntries')->once()
            ->with('scontent-ams2-1.xx.fbcdn.net', ['1.1.1.1'])
            ->andReturn(['scontent-ams2-1.xx.fbcdn.net:443:1.1.1.1']);
    });
    $this->app->instance(PublicSocialImageAddressResolver::class, $resolver);

    Http::fakeSequence()
        ->push('', 302, ['Location' => 'https://scontent-ams2-1.xx.fbcdn.net/avatar'])
        ->push('', 302, ['Location' => 'https://graph.facebook.com/42/picture']);

    expect(fn () => app(ImageProcessingService::class)->storeFromUrl(
        'https://lh3.googleusercontent.com/avatar',
        'avatars',
    ))->toThrow(PermanentRemoteImageException::class);

    Http::assertSentCount(2);
});
