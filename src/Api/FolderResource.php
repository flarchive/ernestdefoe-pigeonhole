<?php

namespace Ernestdefoe\Pigeonhole\Api;

use Carbon\Carbon;
use Ernestdefoe\Pigeonhole\Filing;
use Ernestdefoe\Pigeonhole\Folder;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ServerRequestInterface;
use Tobyz\JsonApiServer\Context as OriginalContext;
use WeakMap;

/**
 * A member's folders. Every endpoint reads through scope(), which is the
 * actor's own folders only: anyone else's folder is a 404, never a 403 that
 * says it exists.
 *
 * @extends AbstractDatabaseResource<Folder>
 */
class FolderResource extends AbstractDatabaseResource
{
    /** @var WeakMap<ServerRequestInterface, array<int, array{count: int, unread: int}>> */
    private WeakMap $counts;

    public function __construct(private TranslatorInterface $translator, private ConnectionInterface $db)
    {
        $this->counts = new WeakMap();
    }

    public function type(): string
    {
        return 'pigeonhole-folders';
    }

    public function model(): string
    {
        return Folder::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        if ($context instanceof Context) {
            $query->where('pigeonhole_folders.user_id', $context->getActor()->id);
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('position'),
            Endpoint\Create::make()
                ->authenticated(),
            Endpoint\Update::make()
                ->authenticated(),
            Endpoint\Delete::make()
                ->authenticated(),
            // The order the member dragged their folders into: ids, first to last.
            Endpoint\Endpoint::make('order')
                ->route('POST', '/order')
                ->authenticated()
                ->action(function (Context $context) {
                    $this->order($context->getActor(), $context->body()['order'] ?? null);
                })
                ->response(fn () => new EmptyResponse(204)),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('position'),
        ];
    }

    public function creating(object $model, OriginalContext $context): ?object
    {
        /** @var Folder $model */
        /** @var Context $context */
        $actor = $context->getActor();

        $existing = Folder::query()->where('user_id', $actor->id);

        if ($existing->clone()->count() >= Folder::MAX) {
            throw new ValidationException([
                'name' => $this->translator->trans('ernestdefoe-pigeonhole.api.too_many_folders', ['count' => Folder::MAX]),
            ]);
        }

        $model->user_id = $actor->id;
        $model->position = (int) $existing->max('position') + 1;
        $model->created_at = Carbon::now();

        return $model;
    }

    /** Its conversations go back to the list; archived ones stay archived. */
    public function deleting(object $model, OriginalContext $context): void
    {
        /** @var Folder $model */
        Filing::query()->where('folder_id', $model->id)->whereNull('archived_message_id')->delete();
        Filing::query()->where('folder_id', $model->id)->update(['folder_id' => null]);
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(50)
                ->rule('regex:/\S/')
                ->set(fn (Folder $folder, string $value) => $folder->name = trim($value)),
            Schema\Str::make('color')
                ->writable()
                ->nullable()
                ->rule('regex:/^#[0-9a-fA-F]{6}$/')
                ->set(fn (Folder $folder, ?string $value) => $folder->color = $value ? strtolower($value) : null),
            // A Font Awesome class list, such as "fas fa-briefcase".
            Schema\Str::make('icon')
                ->writable()
                ->nullable()
                ->maxLength(64)
                ->rule('regex:/^[a-z0-9 -]+$/')
                ->set(fn (Folder $folder, ?string $value) => $folder->icon = trim((string) $value) ?: null),
            Schema\Integer::make('position'),
            // Conversations in it (archived ones are in the archive), and how
            // many of those have something unread. One query for all of the
            // member's folders, however many there are.
            Schema\Integer::make('dialogCount')
                ->get(fn (Folder $folder, Context $context) => $this->counts($context)[$folder->id]['count'] ?? 0),
            Schema\Integer::make('unreadCount')
                ->get(fn (Folder $folder, Context $context) => $this->counts($context)[$folder->id]['unread'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{count: int, unread: int}>
     */
    private function counts(Context $context): array
    {
        return $this->counts[$context->request] ??= $this->db->table('pigeonhole_filings')
            ->join('dialog_user', function ($join) {
                $join->on('dialog_user.dialog_id', '=', 'pigeonhole_filings.dialog_id')
                    ->on('dialog_user.user_id', '=', 'pigeonhole_filings.user_id');
            })
            ->join('dialogs', 'dialogs.id', '=', 'pigeonhole_filings.dialog_id')
            ->where('pigeonhole_filings.user_id', $context->getActor()->id)
            ->whereNotNull('pigeonhole_filings.folder_id')
            ->whereNot(fn ($query) => Filing::whereArchived($query))
            ->groupBy('pigeonhole_filings.folder_id')
            ->select('pigeonhole_filings.folder_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.$this->db->getQueryGrammar()->wrap('dialog_user.last_read_message_id').' < '.$this->db->getQueryGrammar()->wrap('dialogs.last_message_id').' THEN 1 ELSE 0 END) as unread')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->folder_id => ['count' => (int) $row->total, 'unread' => (int) $row->unread]])
            ->all();
    }

    /**
     * @param mixed $ids
     */
    private function order(User $actor, $ids): void
    {
        if (! is_array($ids) || count($ids) > Folder::MAX) {
            throw new ValidationException(['order' => $this->translator->trans('ernestdefoe-pigeonhole.api.invalid_order')]);
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        $this->db->transaction(function () use ($actor, $ids) {
            foreach ($ids as $position => $id) {
                Folder::query()
                    ->where('user_id', $actor->id)
                    ->where('id', $id)
                    ->update(['position' => $position]);
            }
        });
    }
}
