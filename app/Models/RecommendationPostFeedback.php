<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendationPostFeedback extends Model
{
    public const HIDDEN = 'hidden';

    public const NOT_INTERESTED = 'not_interested';

    protected $table = 'recommendation_post_feedback';

    protected $guarded = [];
}
