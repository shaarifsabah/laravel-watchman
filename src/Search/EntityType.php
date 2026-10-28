<?php

declare(strict_types=1);

namespace Watchman\Search;

/**
 * Entity types supported by Watchman. Values match pkg/search.EntityType.
 */
final class EntityType
{
    public const UNKNOWN = 'unknown';
    public const PERSON = 'person';
    public const BUSINESS = 'business';
    public const ORGANIZATION = 'organization';
    public const AIRCRAFT = 'aircraft';
    public const VESSEL = 'vessel';

    public const ALL = [self::PERSON, self::BUSINESS, self::ORGANIZATION, self::AIRCRAFT, self::VESSEL];
}
