<?php

namespace App\Enums;

enum ContentEventType: string
{
    case Impression = 'impression';
    case Open = 'open';
    case CarouselSwipe = 'carousel_swipe';
    case Zoom = 'zoom';
    case Dwell = 'dwell';
    case Like = 'like';
    case Comment = 'comment';
    case Save = 'save';
    case CollectionAdd = 'collection_add';
    case Repost = 'repost';
    case Share = 'share';
    case ProfileOpen = 'profile_open';
    case FollowAuthor = 'follow_author';
    case Hide = 'hide';
    case NotInterested = 'not_interested';
    case Report = 'report';

    public function requiresPost(): bool
    {
        return ! in_array($this, [self::ProfileOpen, self::FollowAuthor], true);
    }
}
