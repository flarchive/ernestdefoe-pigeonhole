<?php

namespace Ernestdefoe\Pigeonhole;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $color
 * @property string|null $icon
 * @property int $position
 * @property \Carbon\Carbon $created_at
 * @property-read User $user
 */
class Folder extends AbstractModel
{
    /** How many folders one member may have. */
    public const MAX = 50;

    protected $table = 'pigeonhole_folders';

    protected $casts = [
        'user_id' => 'integer',
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
