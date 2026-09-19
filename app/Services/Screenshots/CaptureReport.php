<?php

namespace App\Services\Screenshots;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CaptureReport
{
    public const LABELS = [
        'detected' => 'Detected screenshots', 'overlay_shown' => 'Displayed overlays',
        'ignored' => 'Ignored overlays', 'share_tapped' => 'Share taps',
        'share_completed' => 'Published', 'private_save_tapped' => 'Save privately taps',
        'private_save_completed' => 'Saved privately', 'share_cancelled' => 'Share cancellations',
        'private_save_cancelled' => 'Private save cancellations', 'share_failed' => 'Share failures',
        'private_save_failed' => 'Private save failures', 'unfinished' => 'Completion not recorded',
        'overlay_replaced' => 'Overlay replaced', 'overlay_unavailable' => 'Overlay unavailable',
        'overlay_interrupted' => 'Overlay interrupted',
    ];

    public function captures(string $user = '', string $device = ''): Builder
    {
        return DB::table('screenshot_captures as c')
            ->when($user === 'anonymous', fn (Builder $q) => $q->whereNull('c.user_id'))
            ->when(ctype_digit($user), fn (Builder $q) => $q->where('c.user_id', (int) $user))
            ->when(ctype_digit($device), fn (Builder $q) => $q->where('c.device_id', (int) $device));
    }

    /**
     * @param  literal-string  $stage
     * @return literal-string
     */
    private function has(string $stage): string
    {
        // Only internal constants reach this SQL expression; no user-controlled identifiers.
        return "EXISTS (SELECT 1 FROM screenshot_capture_stages s WHERE s.capture_id = c.id AND s.stage = '{$stage}')";
    }

    public function aggregates(Builder $query): Builder
    {
        $conditions = [];
        foreach (array_keys(self::LABELS) as $stage) {
            if (! in_array($stage, ['ignored', 'unfinished'], true)) {
                $conditions[$stage] = $this->has($stage);
            }
        }
        $conditions['ignored'] = $this->has('overlay_shown').' AND ('.$this->has('ignored_timeout').' OR '.$this->has('ignored_dismissed').') AND NOT '.$this->has('share_tapped').' AND NOT '.$this->has('private_save_tapped');
        $conditions['unfinished'] = '('.$this->has('share_tapped').' OR '.$this->has('private_save_tapped').')';
        foreach (['share_completed', 'private_save_completed', 'share_cancelled', 'private_save_cancelled', 'share_failed', 'private_save_failed'] as $stage) {
            $conditions['unfinished'] .= ' AND NOT '.$this->has($stage);
        }
        foreach (['share', 'private_save'] as $action) {
            $conditions[$action.'_converted'] = $this->has($action.'_tapped').' AND '.$this->has($action.'_completed');
        }
        foreach ($conditions as $label => $condition) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$label}");
        }

        return $query;
    }
}
