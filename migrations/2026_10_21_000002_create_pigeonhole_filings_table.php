<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Where a member has put a conversation: a folder, the archive, or both (an
 * archived conversation goes back to its folder when a new message arrives).
 *
 * The row hangs off the member's place in the conversation (dialog_user), so
 * the database removes it when they leave or are removed from a group, when
 * the conversation is deleted, and when the member is: nothing has to listen
 * for any of those. Archived means "nothing newer than archived_message_id",
 * so a new message brings it back without a write.
 */
return Migration::createTable(
    'pigeonhole_filings',
    function (Blueprint $table) {
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('dialog_id');
        $table->unsignedInteger('folder_id')->nullable();
        $table->unsignedInteger('archived_message_id')->nullable();

        $table->primary(['user_id', 'dialog_id']);
        $table->index(['user_id', 'folder_id']);

        $table->foreign(['dialog_id', 'user_id'])->references(['dialog_id', 'user_id'])->on('dialog_user')->cascadeOnDelete();
        $table->foreign('folder_id')->references('id')->on('pigeonhole_folders')->nullOnDelete();
    }
);
