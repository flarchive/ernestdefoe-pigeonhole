<?php

namespace Ernestdefoe\Pigeonhole\Tests\integration\api;

use Ernestdefoe\Pigeonhole\Data\MessageFolders;
use Ernestdefoe\Pigeonhole\Tests\integration\PigeonholeTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** A member's folders with flarum/gdpr: exported, and gone when anonymised or erased. */
class GdprTest extends PigeonholeTestCase
{
    private function type(int $user): MessageFolders
    {
        return $this->app()->getContainer()->make(MessageFolders::class, ['user' => User::find($user), 'erasureRequest' => null]);
    }

    #[Test]
    public function the_export_holds_only_the_members_own_folders()
    {
        $export = $this->type(self::ALICE)->export();

        $this->assertCount(1, $export);
        $folders = json_decode($export[0]['message-folders.json'], true);
        $this->assertSame(['Work', 'Family'], array_column($folders, 'name'));
        $this->assertNull($this->type(self::DAVE)->export(), 'Nothing to export');
    }

    #[Test]
    public function anonymising_or_erasing_removes_them()
    {
        $this->type(self::ALICE)->anonymize();
        $this->assertSame(0, $this->database()->table('pigeonhole_folders')->where('user_id', self::ALICE)->count());

        $this->type(self::BOB)->delete();
        $this->assertSame(0, $this->database()->table('pigeonhole_folders')->count());
    }
}
