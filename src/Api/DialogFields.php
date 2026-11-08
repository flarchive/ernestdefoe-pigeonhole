<?php

namespace Ernestdefoe\Pigeonhole\Api;

use Ernestdefoe\Pigeonhole\Filing;
use Ernestdefoe\Pigeonhole\Folder;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Bus\Dispatcher;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Messages\Api\Resource\DialogResource;
use Flarum\Messages\Command\ReadDialog;
use Flarum\Messages\Dialog;

/**
 * Where the actor has filed a conversation, on conversation lists and the
 * conversation itself, read from the `pigeonholeFiling` relation those
 * endpoints load with the list (one query for the page). Written through
 * PATCH /api/dialogs/{id}, which only finds conversations the actor is in.
 */
class DialogFields
{
    public function __construct(private TranslatorInterface $translator, private Dispatcher $bus)
    {
    }

    /**
     * @return array<Schema\Attribute>
     */
    public function __invoke(): array
    {
        return [
            Schema\Integer::make('pigeonholeFolderId')
                ->nullable()
                ->visible(fn (Dialog $dialog, Context $context) => $this->shown($context))
                ->get(fn (Dialog $dialog, Context $context) => Filing::of($dialog, $context->getActor())?->folder_id)
                ->writableOnUpdate()
                ->set(fn () => null)
                ->save(function (Dialog $dialog, ?int $value, Context $context) {
                    $actor = $context->getActor();

                    if ($value !== null && ! Folder::query()->where('user_id', $actor->id)->where('id', $value)->exists()) {
                        throw new ValidationException([
                            'pigeonholeFolderId' => $this->translator->trans('ernestdefoe-pigeonhole.api.unknown_folder'),
                        ]);
                    }

                    Filing::put($actor->id, $dialog->id, ['folder_id' => $value]);
                    $dialog->unsetRelation('pigeonholeFiling');
                }),
            // Archived until something new is said in it. Archiving reads it,
            // so the header's unread badge never counts a conversation the
            // member has put away.
            Schema\Boolean::make('pigeonholeArchived')
                ->visible(fn (Dialog $dialog, Context $context) => $this->shown($context))
                ->get(fn (Dialog $dialog, Context $context) => (bool) Filing::of($dialog, $context->getActor())?->isArchivedIn($dialog))
                ->writableOnUpdate()
                ->set(fn () => null)
                ->save(function (Dialog $dialog, bool $value, Context $context) {
                    $actor = $context->getActor();
                    $last = (int) $dialog->last_message_id;

                    if ($value && $last > 0) {
                        Filing::put($actor->id, $dialog->id, ['archived_message_id' => $last]);
                        $this->bus->dispatch(new ReadDialog($dialog->id, $actor, $last));
                    } elseif (! $value) {
                        Filing::put($actor->id, $dialog->id, ['archived_message_id' => null]);
                    }

                    $dialog->unsetRelation('pigeonholeFiling');
                }),
        ];
    }

    /**
     * Only where conversations are what was asked for, whose endpoints load
     * the filing with them: a conversation included with each of its
     * messages, or in a realtime payload, would look it up every time.
     */
    private function shown(Context $context): bool
    {
        return $context->collection instanceof DialogResource && ! $context->getActor()->isGuest();
    }
}
