<?php

namespace App\Enums;

enum CandidateSource: string
{
    case Following = 'following';
    case Trending = 'trending';
    case RegionalTrending = 'regional_trending';
    case FollowedHashtag = 'followed_hashtag';
    case OnboardingInterest = 'onboarding_interest';
    case Category = 'category';
    case TwoHop = 'two_hop';
    case SimilarAuthor = 'similar_author';
    case SimilarTopic = 'similar_topic';
    case NewCreator = 'new_creator';
    case Search = 'search';
    case Profile = 'profile';
    case Direct = 'direct';
    case Notification = 'notification';
}
