<?php

namespace Database\Factories;

use App\Enums\TelemetryKind;
use App\Models\Device;
use App\Models\TelemetryEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelemetryEvent>
 */
class TelemetryEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'event_uuid' => $this->faker->uuid(),
            'kind' => TelemetryKind::Event->value,
            'name' => 'screenshot_detected',
            'occurred_at' => now(),
            'received_at' => now(),
            'extras' => ['relative_path' => 'Pictures/Screenshots/'],
            'breadcrumbs' => [],
        ];
    }

    public function fatalCrash(): static
    {
        return $this->state(fn () => [
            'kind' => TelemetryKind::FatalCrash->value,
            'name' => 'fatal_crash',
            'error_tag' => 'FatalCrashHandler.uncaughtException',
            'exception_class' => 'java.lang.IllegalStateException',
            'error_message' => 'boom',
            'stack_trace' => "java.lang.IllegalStateException: boom\n\tat Foo.bar(Foo.kt:1)",
            'thread_name' => 'main',
            'is_fatal' => true,
        ]);
    }
}
