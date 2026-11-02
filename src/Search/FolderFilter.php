<?php

namespace Ernestdefoe\Pigeonhole\Search;

use Ernestdefoe\Pigeonhole\Filing;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchState;
use Illuminate\Database\Query\Builder;

/**
 * `filter[folder]=<id>` lists the actor's conversations in that folder,
 * `filter[folder]=archive` their archive. A folder that isn't theirs is
 * empty: the filing rows are looked up by the actor, never by the folder alone.
 *
 * Without the filter (and without a search), archived conversations are left
 * out, so the Messages page and the header dropdown don't show them.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class FolderFilter implements FilterInterface
{
    public const KEY = 'folder';

    public function getFilterKey(): string
    {
        return self::KEY;
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $value = is_array($value) ? (string) reset($value) : trim($value);
        $actorId = $state->getActor()->id;

        $state->getQuery()->{$negate ? 'whereNotExists' : 'whereExists'}(function (Builder $query) use ($value, $actorId) {
            self::filings($query, $actorId);

            if ($value === 'archive') {
                Filing::whereArchived($query);
            } else {
                $query->where('pigeonhole_filings.folder_id', (int) $value)
                    ->whereNot(fn (Builder $query) => Filing::whereArchived($query));
            }
        });
    }

    /** Leaves archived conversations out of a plain list of them. */
    public static function hideArchived(SearchState $state, SearchCriteria $criteria): void
    {
        if (! $state instanceof DatabaseSearchState || isset($criteria->filters[self::KEY]) || $criteria->isFulltext() || $state->getActor()->isGuest()) {
            return;
        }

        $actorId = $state->getActor()->id;

        $state->getQuery()->whereNotExists(function (Builder $query) use ($actorId) {
            self::filings($query, $actorId);
            Filing::whereArchived($query);
        });
    }

    private static function filings(Builder $query, int $actorId): void
    {
        $query->selectRaw('1')
            ->from('pigeonhole_filings')
            ->whereColumn('pigeonhole_filings.dialog_id', 'dialogs.id')
            ->where('pigeonhole_filings.user_id', $actorId);
    }
}
