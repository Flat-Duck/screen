<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /** @var list<string> */
    public const USERNAMES = [
        'alice', 'bob', 'carol', 'dana', 'eli', 'fatima', 'gabriel', 'hana', 'ivan', 'jules',
        'kai', 'lina', 'mario', 'nora', 'omar', 'priya', 'quinn', 'rana', 'sam', 'tariq',
        'uma', 'victor', 'willow', 'yara',
    ];

    /** @var array<string, list<string>> */
    public const SPECIALTIES = [
        'alice' => ['flutter', 'dart', 'mobiledev', 'android', 'uidesign'],
        'bob' => ['gaming', 'indiegames', 'steam', 'gamedev', 'pixelart'],
        'carol' => ['design', 'typography', 'branding', 'figma', 'inspiration'],
        'dana' => ['coding', 'laravel', 'php', 'backend', 'opensource'],
        'eli' => ['productivity', 'notetaking', 'workflow', 'automation', 'tools'],
        'fatima' => ['travel', 'maps', 'architecture', 'culture', 'photography'],
        'gabriel' => ['finance', 'investing', 'markets', 'fintech', 'charts'],
        'hana' => ['recipes', 'food', 'cooking', 'baking', 'healthy'],
        'ivan' => ['ai', 'machinelearning', 'research', 'technology', 'future'],
        'jules' => ['music', 'playlists', 'concerts', 'audio', 'artists'],
        'kai' => ['fitness', 'running', 'training', 'health', 'habits'],
        'lina' => ['books', 'reading', 'quotes', 'writing', 'literature'],
        'mario' => ['football', 'sports', 'scores', 'tactics', 'fans'],
        'nora' => ['fashion', 'style', 'shopping', 'outfits', 'beauty'],
        'omar' => ['security', 'privacy', 'cybersecurity', 'linux', 'devops'],
        'priya' => ['education', 'learning', 'study', 'science', 'students'],
        'quinn' => ['memes', 'funny', 'internet', 'reaction', 'humor'],
        'rana' => ['business', 'startup', 'marketing', 'growth', 'entrepreneur'],
        'sam' => ['movies', 'cinema', 'tv', 'reviews', 'streaming'],
        'tariq' => ['news', 'world', 'politics', 'economy', 'analysis'],
        'uma' => ['nature', 'climate', 'wildlife', 'sustainability', 'earth'],
        'victor' => ['cars', 'electricvehicles', 'motorsport', 'engineering', 'tech'],
        'willow' => ['art', 'illustration', 'drawing', 'creative', 'colors'],
        'yara' => ['wellness', 'mindfulness', 'mentalhealth', 'selfcare', 'quotes'],
    ];

    public function run(): void
    {
        $password = Hash::make('password');
        $this->upsertUser('testuser', 'Test Administrator', 'test@example.com', AdminRole::SuperAdmin, $password);
        $this->upsertUser('telemetry', 'Telemetry Engineer', 'telemetry@example.com', AdminRole::TelemetryViewer, $password);
        $this->upsertUser('moderator', 'Demo Moderator', 'moderator@example.com', AdminRole::Moderator, $password);
        $this->upsertUser('support', 'Demo Support', 'support@example.com', AdminRole::Support, $password);

        foreach (self::USERNAMES as $index => $username) {
            $specialties = self::SPECIALTIES[$username];
            $user = User::query()->updateOrCreate(['username' => $username], [
                'name' => ucfirst($username).' '.fake()->lastName(),
                'email' => $username.'@example.com',
                'password' => $password,
                'bio' => 'Screenshots about '.implode(', ', array_slice($specialties, 0, 3)).'.',
                'country_code' => fake()->randomElement(['LY', 'US', 'GB', 'DE', 'JP', 'IN', 'BR', 'CA']),
            ]);
            $user->forceFill([
                'email_verified_at' => now()->subDays(random_int(30, 500)),
                'account_visibility' => $index % 7 === 0 ? 'private' : 'public',
                'is_active' => true,
                'created_at' => now()->subDays(random_int(60, 600)),
            ])->saveQuietly();
        }
    }

    private function upsertUser(string $username, string $name, string $email, AdminRole $role, string $password): void
    {
        $user = User::query()->updateOrCreate(['username' => $username], [
            'name' => $name, 'email' => $email,
            'password' => $password, 'bio' => 'Seeded staff account for local dashboard testing.',
        ]);
        $user->forceFill(['email_verified_at' => now(), 'is_admin' => true, 'admin_role' => $role, 'is_active' => true])->saveQuietly();
    }
}
