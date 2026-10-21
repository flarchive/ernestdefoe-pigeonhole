<?php

use Ernestdefoe\Pigeonhole\Api\DialogFields;
use Ernestdefoe\Pigeonhole\Api\FolderResource;
use Ernestdefoe\Pigeonhole\Data\MessageFolders;
use Ernestdefoe\Pigeonhole\Filing;
use Ernestdefoe\Pigeonhole\Search\FolderFilter;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Messages\Api\Resource\DialogResource;
use Flarum\Messages\Dialog;
use Flarum\Messages\Search\DialogSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less'),

    new Extend\Locales(__DIR__.'/locale'),

    // The actor's filing of each conversation, the way flarum/messages reads
    // their read state: one query for a whole list.
    (new Extend\Model(Dialog::class))
        ->relationship('pigeonholeFiling', fn (Dialog $dialog) => $dialog
            ->hasOne(Filing::class, 'dialog_id')
            ->where('pigeonhole_filings.user_id', Dialog::stateUser()?->id)),

    new Extend\ApiResource(FolderResource::class),

    (new Extend\ApiResource(DialogResource::class))
        ->fields(DialogFields::class)
        ->endpoint(['index', 'show'], fn ($endpoint) => $endpoint->eagerLoad('pigeonholeFiling')),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(DialogSearcher::class, FolderFilter::class)
        ->addMutator(DialogSearcher::class, FolderFilter::hideArchived(...)),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(MessageFolders::class),
        ]),
];
