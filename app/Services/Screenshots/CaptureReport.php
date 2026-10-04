<?php

namespace App\Services\Screenshots;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CaptureReport
{
    public const LABELS = [
        'detected' => 'Detected screenshots', 'overlay_shown' => 'Displayed overlays',
        'ignored' => 'Ignored overlays', 'share_tapped' => 'Screenshots with Share tapped',
        'share_completed' => 'Published', 'private_save_tapped' => 'Screenshots with Save tapped',
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
        return "COALESCE(capture_stages.{$stage}, 0) = 1";
    }

    /**
     * @param  'share'|'private_save'  $action
     * @return literal-string
     */
    private function unfinished(string $action): string
    {
        // Legacy clients have taps but no started stage. For new clients started advances on retry.
        $started = "capture_stages.{$action}_latest_start";
        $terminal = "capture_stages.{$action}_latest_terminal";

        return $this->has($action.'_tapped')." AND ({$terminal} IS NULL OR {$started} > {$terminal})";
    }

    /**
     * @param  literal-string  $condition
     * @return literal-string
     */
    private function sumWhen(string $condition): string
    {
        return "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0)";
    }

    public function aggregates(Builder $query): Builder
    {
        // Restrict the single stage scan to the same cohort as this report. Lifetime reporting
        // scans retained stages once; period/user/device reports do not summarize unrelated rows.
        $cohort = (clone $query)->select('c.id');
        $stages = DB::table('screenshot_capture_stages as s')
            ->whereIn('s.capture_id', $cohort)->select('s.capture_id')->groupBy('s.capture_id');
        $stageNames = array_merge(
            array_diff(array_keys(self::LABELS), ['ignored', 'unfinished']),
            ['ignored_timeout', 'ignored_dismissed'],
        );
        foreach ($stageNames as $stage) {
            $stages->selectRaw("MAX(CASE WHEN s.stage = '{$stage}' THEN 1 ELSE 0 END) AS {$stage}");
        }
        foreach (['share', 'private_save'] as $action) {
            $stages->selectRaw("MAX(CASE WHEN s.stage IN ('{$action}_tapped', '{$action}_started') THEN COALESCE(s.last_occurred_at, s.occurred_at) END) AS {$action}_latest_start");
            $stages->selectRaw("MAX(CASE WHEN s.stage IN ('{$action}_completed', '{$action}_cancelled', '{$action}_failed') THEN COALESCE(s.last_occurred_at, s.occurred_at) END) AS {$action}_latest_terminal");
        }
        $query->leftJoinSub($stages, 'capture_stages', 'capture_stages.capture_id', '=', 'c.id');

        $query
            ->selectRaw($this->sumWhen($this->has('detected')).' AS detected')
            ->selectRaw($this->sumWhen($this->has('overlay_shown')).' AS overlay_shown')
            ->selectRaw($this->sumWhen($this->has('share_tapped')).' AS share_tapped')
            ->selectRaw($this->sumWhen($this->has('share_completed')).' AS share_completed')
            ->selectRaw($this->sumWhen($this->has('private_save_tapped')).' AS private_save_tapped')
            ->selectRaw($this->sumWhen($this->has('private_save_completed')).' AS private_save_completed')
            ->selectRaw($this->sumWhen($this->has('share_cancelled')).' AS share_cancelled')
            ->selectRaw($this->sumWhen($this->has('private_save_cancelled')).' AS private_save_cancelled')
            ->selectRaw($this->sumWhen($this->has('share_failed')).' AS share_failed')
            ->selectRaw($this->sumWhen($this->has('private_save_failed')).' AS private_save_failed')
            ->selectRaw($this->sumWhen($this->has('overlay_replaced')).' AS overlay_replaced')
            ->selectRaw($this->sumWhen($this->has('overlay_unavailable')).' AS overlay_unavailable')
            ->selectRaw($this->sumWhen($this->has('overlay_interrupted')).' AS overlay_interrupted')
            ->selectRaw($this->sumWhen($this->has('overlay_shown').' AND ('.$this->has('ignored_timeout').' OR '.$this->has('ignored_dismissed').') AND NOT '.$this->has('share_tapped').' AND NOT '.$this->has('private_save_tapped')).' AS ignored')
            ->selectRaw($this->sumWhen('('.$this->unfinished('share').' OR '.$this->unfinished('private_save').')').' AS unfinished')
            ->selectRaw($this->sumWhen($this->has('share_tapped').' AND '.$this->has('share_completed')).' AS share_converted')
            ->selectRaw($this->sumWhen($this->has('private_save_tapped').' AND '.$this->has('private_save_completed')).' AS private_save_converted');

        return $query;
    }
}
