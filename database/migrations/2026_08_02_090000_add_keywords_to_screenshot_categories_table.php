<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenshot_categories', function (Blueprint $table): void {
            // Keyword list this category is auto-matched against by
            // App\Services\Screenshots\CategoryMatcher (a post's hashtags + OCR'd text are scored
            // against every active category's keywords) — see that class's kdoc. Never empty for
            // an automatically-reachable category; "other" is deliberately left with none, since
            // an unconfident post should stay uncategorized rather than being forced into a
            // catch-all bucket.
            $table->json('keywords')->nullable()->after('name');
        });

        $keywords = [
            'social' => ['social', 'friends', 'follow', 'followers', 'following', 'instagram', 'facebook', 'twitter', 'tiktok', 'snapchat', 'profile', 'feed', 'story', 'reel'],
            'messaging' => ['message', 'messages', 'chat', 'whatsapp', 'telegram', 'imessage', 'sms', 'messenger', 'dm', 'conversation', 'reply', 'inbox'],
            'code' => ['code', 'coding', 'programming', 'developer', 'github', 'gitlab', 'python', 'javascript', 'typescript', 'java', 'kotlin', 'swift', 'function', 'exception', 'terminal', 'console', 'git', 'commit', 'pullrequest', 'bug', 'stacktrace', 'compile', 'repository'],
            'shopping' => ['shopping', 'cart', 'checkout', 'order', 'price', 'discount', 'amazon', 'ebay', 'sale', 'buy', 'purchase', 'shipping', 'product', 'coupon'],
            'finance' => ['bank', 'banking', 'balance', 'transfer', 'payment', 'invoice', 'budget', 'expense', 'income', 'salary', 'transaction', 'crypto', 'bitcoin', 'stock', 'investment', 'paypal', 'finance'],
            'work' => ['meeting', 'deadline', 'project', 'task', 'email', 'calendar', 'office', 'slack', 'zoom', 'teams', 'presentation', 'report', 'spreadsheet', 'agenda', 'work'],
            'technology' => ['tech', 'technology', 'software', 'hardware', 'update', 'download', 'install', 'device', 'app', 'settings', 'battery', 'wifi', 'bluetooth', 'gadget'],
            'design' => ['design', 'figma', 'sketch', 'photoshop', 'illustrator', 'ui', 'ux', 'layout', 'typography', 'mockup', 'prototype', 'canva'],
            'entertainment' => ['movie', 'movies', 'netflix', 'youtube', 'show', 'episode', 'music', 'spotify', 'concert', 'celebrity', 'trailer', 'stream', 'streaming'],
            'education' => ['school', 'university', 'course', 'lecture', 'homework', 'exam', 'study', 'quiz', 'assignment', 'grade', 'learning', 'tutorial', 'education'],
            'lifestyle' => ['fitness', 'workout', 'recipe', 'travel', 'health', 'wellness', 'fashion', 'beauty', 'lifestyle', 'diy'],
            'news' => ['news', 'breaking', 'headline', 'politics', 'article', 'journalist', 'world', 'election'],
            'gaming' => ['game', 'games', 'gaming', 'xbox', 'playstation', 'nintendo', 'steam', 'level', 'achievement', 'multiplayer', 'esports'],
            'business' => ['business', 'startup', 'revenue', 'client', 'marketing', 'sales', 'strategy', 'company', 'brand'],
            'sports' => ['sports', 'football', 'soccer', 'basketball', 'match', 'team', 'league', 'tournament', 'championship'],
            'other' => [],
        ];

        foreach ($keywords as $slug => $words) {
            DB::table('screenshot_categories')->where('slug', $slug)->update(['keywords' => json_encode($words)]);
        }
    }

    public function down(): void
    {
        Schema::table('screenshot_categories', function (Blueprint $table): void {
            $table->dropColumn('keywords');
        });
    }
};
