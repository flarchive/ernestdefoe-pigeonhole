<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A member's own folders for their conversations, in the order they dragged
 * them into. Nobody else ever sees them.
 */
return Migration::createTable(
    'pigeonhole_folders',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->string('name', 50);
        $table->string('color', 7)->nullable();
        $table->string('icon', 64)->nullable();
        $table->unsignedInteger('position')->default(0);
        $table->dateTime('created_at');

        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->index(['user_id', 'position']);
    }
);
