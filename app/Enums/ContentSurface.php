<?php

namespace App\Enums;

enum ContentSurface: string
{
    case FollowingFeed = 'following_feed';
    case ForYouFeed = 'for_you_feed';
    case Explore = 'explore';
    case Search = 'search';
    case Hashtag = 'hashtag';
    case Profile = 'profile';
    case PostDetail = 'post_detail';
    case Saved = 'saved';
    case Notification = 'notification';
    case ShareSheet = 'share_sheet';
}
