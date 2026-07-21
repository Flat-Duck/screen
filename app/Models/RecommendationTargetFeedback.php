<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendationTargetFeedback extends Model
{
    public const AUTHOR = 'author';

    public const HASHTAG = 'hashtag';

    protected $table = 'recommendation_target_feedback';

    protected $guarded = [];
}
