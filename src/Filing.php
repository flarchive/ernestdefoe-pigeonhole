<?php

namespace Ernestdefoe\Pigeonhole;

use Flarum\Database\AbstractModel;
use Flarum\Messages\Dialog;
use Flarum\User\User;
use Illuminate\Database\Query\Builder;

/**
 * Where one member has put one conversation. Read through Dialog's
 * `pigeonholeFiling` relation; written only with {@see self::put()}, as the
 * table has a two-column key Eloquent cannot save by.
 *
 * @property int $user_id
 * @property int $dialog_id
 * @property int|null $folder_id
 * @property int|null $archived_message_id
 */
class Filing extends AbstractModel
{
    protected $table = 'pigeonhole_filings';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $casts = [
        'user_id' => 'integer',
        'dialog_id' => 'integer',
        'folder_id' => 'integer',
        'archived_message_id' => 'integer',
    ];

    /**
     * The actor's filing of a conversation, from the `pigeonholeFiling`
     * relation. Never anyone else's, whoever the relation was loaded for.
     */
    public static function of(Dialog $dialog, User $actor): ?self
    {
        $filing = $dialog->getRelationValue('pigeonholeFiling');

        return $filing instanceof self && $filing->user_id === $actor->id ? $filing : null;
    }

    public function isArchivedIn(Dialog $dialog): bool
    {
        return $this->archived_message_id !== null
            && $dialog->last_message_id !== null
            && $this->archived_message_id >= $dialog->last_message_id;
    }

    /**
     * Sets columns of one member's filing of one conversation, making the row
     * if there is none. A row left saying nothing is removed.
     *
     * @param array{folder_id?: int|null, archived_message_id?: int|null} $values
     */
    public static function put(int $userId, int $dialogId, array $values): void
    {
        $key = ['user_id' => $userId, 'dialog_id' => $dialogId];
        $query = static::query()->where($key);

        if (! $query->clone()->update($values)) {
            // Nothing to make when the change is to take something away.
            if (array_filter($values, fn ($value) => $value !== null)) {
                static::query()->insertOrIgnore($key + $values);
                // Lost a race with another first write: that one's row now exists.
                $query->clone()->update($values);
            }

            return;
        }

        $query->whereNull('folder_id')->whereNull('archived_message_id')->delete();
    }

    /**
     * Narrows a query to the filing rows that archive their conversation:
     * nothing has been said in it since it was archived.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Filing>|Builder $query
     */
    public static function whereArchived($query, string $dialogs = 'dialogs'): void
    {
        $query->whereNotNull('pigeonhole_filings.archived_message_id')
            ->whereColumn('pigeonhole_filings.archived_message_id', '>=', "$dialogs.last_message_id");
    }
}
