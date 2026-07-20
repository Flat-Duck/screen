<?php

namespace App\Http\Requests;

use App\Enums\AccountVisibility;
use App\Enums\InteractionAudience;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Always operates on $request->user() — there's no {user} route param to check ownership of.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only `notifications` exists today — see SettingsService's doc comment for how this
     * grows to more top-level keys without a new endpoint per setting.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notifications' => ['sometimes', 'array:push_enabled,likes,comments,replies,follows,mentions,reposts,messages,message_requests,follow_requests,product_updates,quiet_hours'],
            'notifications.push_enabled' => ['sometimes', 'boolean'],
            'notifications.likes' => ['sometimes', 'boolean'],
            'notifications.comments' => ['sometimes', 'boolean'],
            'notifications.replies' => ['sometimes', 'boolean'],
            'notifications.follows' => ['sometimes', 'boolean'],
            'notifications.mentions' => ['sometimes', 'boolean'],
            'notifications.reposts' => ['sometimes', 'boolean'],
            'notifications.messages' => ['sometimes', 'boolean'],
            'notifications.message_requests' => ['sometimes', 'boolean'],
            'notifications.follow_requests' => ['sometimes', 'boolean'],
            'notifications.product_updates' => ['sometimes', 'boolean'],
            'notifications.quiet_hours' => ['sometimes', 'array:enabled,start,end,timezone'],
            'notifications.quiet_hours.enabled' => ['sometimes', 'boolean'],
            'notifications.quiet_hours.start' => ['sometimes', 'date_format:H:i'],
            'notifications.quiet_hours.end' => ['sometimes', 'date_format:H:i'],
            'notifications.quiet_hours.timezone' => ['sometimes', 'timezone'],
            'privacy' => ['sometimes', 'array'],
            'privacy.account_visibility' => ['sometimes', Rule::enum(AccountVisibility::class)],
            'interactions' => ['sometimes', 'array:comments_from,mentions_from,messages_from,reposts_from,reposts_allowed'],
            'interactions.comments_from' => ['sometimes', Rule::enum(InteractionAudience::class)],
            'interactions.mentions_from' => ['sometimes', Rule::enum(InteractionAudience::class)],
            'interactions.messages_from' => ['sometimes', Rule::enum(InteractionAudience::class)],
            'interactions.reposts_from' => ['sometimes', Rule::enum(InteractionAudience::class)],
            'interactions.reposts_allowed' => ['sometimes', 'boolean'],
            'content_filters' => ['sometimes', 'array:hide_offensive_comments,hide_offensive_messages'],
            'content_filters.hide_offensive_comments' => ['sometimes', 'boolean'],
            'content_filters.hide_offensive_messages' => ['sometimes', 'boolean'],
        ];
    }
}
