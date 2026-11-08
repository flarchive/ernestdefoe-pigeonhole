<?php

namespace Ernestdefoe\Pigeonhole\Data;

use Ernestdefoe\Pigeonhole\Folder;
use Flarum\Gdpr\Data\Type;
use Illuminate\Support\Arr;

/**
 * A member's message folders, for flarum/gdpr: in their export, and gone when
 * they are anonymised or erased (a folder name is the member's own words, and
 * nobody else ever sees it).
 */
class MessageFolders extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-pigeonhole.lib.gdpr.export_description');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-pigeonhole.lib.gdpr.anonymize_description');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-pigeonhole.lib.gdpr.delete_description');
    }

    public function export(): ?array
    {
        $folders = Folder::query()
            ->where('user_id', $this->user->id)
            ->orderBy('position')
            ->get()
            ->map(fn (Folder $folder) => Arr::only($folder->toArray(), ['name', 'color', 'icon', 'created_at']))
            ->all();

        return $folders ? [['message-folders.json' => $this->encodeForExport($folders)]] : null;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Folder::query()->where('user_id', $this->user->id)->delete();
    }
}
