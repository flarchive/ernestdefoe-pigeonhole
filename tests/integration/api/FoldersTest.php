<?php

namespace Ernestdefoe\Pigeonhole\Tests\integration\api;

use Ernestdefoe\Pigeonhole\Folder;
use Ernestdefoe\Pigeonhole\Tests\integration\PigeonholeTestCase;
use PHPUnit\Framework\Attributes\Test;

/** A member's own folders: who sees them, who changes them, what they take. */
class FoldersTest extends PigeonholeTestCase
{
    private function folders(int $actor): array
    {
        [$status, $body] = $this->call('GET', '/api/pigeonhole-folders', $actor);
        $this->assertSame(200, $status, json_encode($body));

        return array_map(fn ($item) => ['id' => (int) $item['id']] + $item['attributes'], $body['data']);
    }

    private function create(int $actor, array $attributes): array
    {
        return $this->call('POST', '/api/pigeonhole-folders', $actor, null, [], ['data' => ['type' => 'pigeonhole-folders', 'attributes' => $attributes]]);
    }

    private function update(int $folder, int $actor, array $attributes): array
    {
        return $this->call('PATCH', "/api/pigeonhole-folders/$folder", $actor, null, [], ['data' => ['type' => 'pigeonhole-folders', 'id' => (string) $folder, 'attributes' => $attributes]]);
    }

    #[Test]
    public function guests_have_no_folders()
    {
        $this->assertSame(401, $this->call('GET', '/api/pigeonhole-folders')[0]);
        $this->assertSame(401, $this->call('POST', '/api/pigeonhole-folders', null, null, [], ['data' => ['type' => 'pigeonhole-folders', 'attributes' => ['name' => 'Nope']]])[0]);
        $this->assertSame(3, $this->database()->table('pigeonhole_folders')->count());
    }

    #[Test]
    public function a_member_sees_only_their_own_folders_in_their_order()
    {
        $this->assertSame(['Work', 'Family'], array_column($this->folders(self::ALICE), 'name'));
        $this->assertSame(['Mine'], array_column($this->folders(self::BOB), 'name'));
        $this->assertSame([], $this->folders(self::DAVE));
    }

    #[Test]
    public function nobody_reads_changes_or_deletes_someone_elses_folder()
    {
        $this->assertSame(404, $this->update(3, self::ALICE, ['name' => 'Hijacked'])[0]);
        $this->assertSame(404, $this->call('DELETE', '/api/pigeonhole-folders/3', self::ALICE)[0]);

        $this->assertSame('Mine', $this->database()->table('pigeonhole_folders')->where('id', 3)->value('name'));
    }

    #[Test]
    public function a_new_folder_is_the_actors_and_goes_last()
    {
        [$status, $body] = $this->create(self::ALICE, ['name' => '  Clubs  ', 'color' => '#ABCDEF', 'icon' => 'fas fa-users']);
        $this->assertSame(201, $status, json_encode($body));

        $row = $this->database()->table('pigeonhole_folders')->where('id', $body['data']['id'])->first();
        $this->assertSame(self::ALICE, (int) $row->user_id);
        $this->assertSame('Clubs', $row->name);
        $this->assertSame('#abcdef', $row->color);
        $this->assertSame(2, (int) $row->position);
    }

    #[Test]
    public function a_folder_cannot_be_made_for_someone_else()
    {
        [$status, $body] = $this->create(self::ALICE, ['name' => 'Sneaky', 'userId' => self::BOB, 'user_id' => self::BOB]);

        $this->assertContains($status, [201, 400], json_encode($body));
        $this->assertSame(0, $this->database()->table('pigeonhole_folders')->where('user_id', self::BOB)->where('name', 'Sneaky')->count());
    }

    #[Test]
    public function names_colours_and_icons_are_checked()
    {
        foreach ([
            ['name' => ''],
            ['name' => '   '],
            ['name' => str_repeat('x', 51)],
            ['name' => 'Ok', 'color' => 'red'],
            ['name' => 'Ok', 'color' => '#fff" onclick="x'],
            ['name' => 'Ok', 'icon' => 'fas fa-x" onmouseover="alert(1)'],
            ['name' => 'Ok', 'icon' => '<script>'],
        ] as $attributes) {
            $this->assertSame(422, $this->create(self::ALICE, $attributes)[0], json_encode($attributes));
        }

        $this->assertSame(2, $this->database()->table('pigeonhole_folders')->where('user_id', self::ALICE)->count());
    }

    #[Test]
    public function there_is_a_limit()
    {
        $this->app();
        for ($i = 0; $i < Folder::MAX - 2; $i++) {
            $this->database()->table('pigeonhole_folders')->insert(['user_id' => self::ALICE, 'name' => "F$i", 'position' => $i + 2, 'created_at' => \Carbon\Carbon::now()]);
        }

        $this->assertSame(422, $this->create(self::ALICE, ['name' => 'One too many'])[0]);
        $this->assertSame(201, $this->create(self::BOB, ['name' => 'Bob is fine'])[0]);
    }

    #[Test]
    public function a_member_renames_recolours_and_clears_their_folder()
    {
        [$status, $body] = $this->update(1, self::ALICE, ['name' => 'Job', 'color' => null, 'icon' => null]);
        $this->assertSame(200, $status, json_encode($body));

        $row = $this->database()->table('pigeonhole_folders')->where('id', 1)->first();
        $this->assertSame(['Job', null, null], [$row->name, $row->color, $row->icon]);
    }

    #[Test]
    public function reordering_touches_only_the_actors_folders()
    {
        $this->assertSame(204, $this->call('POST', '/api/pigeonhole-folders/order', self::ALICE, null, [], ['order' => [2, 3, 1]])[0]);

        $this->assertSame(['Family', 'Work'], array_column($this->folders(self::ALICE), 'name'));
        $this->assertSame(0, (int) $this->database()->table('pigeonhole_folders')->where('id', 3)->value('position'), "Bob's folder kept its place");

        $this->assertSame(422, $this->call('POST', '/api/pigeonhole-folders/order', self::ALICE, null, [], ['order' => 'nope'])[0]);
        $this->assertSame(401, $this->call('POST', '/api/pigeonhole-folders/order', null, null, [], ['order' => [1]])[0]);
    }

    #[Test]
    public function deleting_a_folder_puts_its_conversations_back_and_keeps_the_archive()
    {
        $this->linkMessages();
        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(2, self::ALICE, ['pigeonholeFolderId' => 1, 'pigeonholeArchived' => true]);

        $this->assertSame(204, $this->call('DELETE', '/api/pigeonhole-folders/1', self::ALICE)[0]);

        $this->assertNull($this->filing(self::ALICE, 1), 'Nothing left to say, so no row');
        $filing = $this->filing(self::ALICE, 2);
        $this->assertNull($filing->folder_id);
        $this->assertSame(20, (int) $filing->archived_message_id);
        $this->assertSame([1, 3], $this->listed(self::ALICE));
        $this->assertSame([2], $this->listed(self::ALICE, ['folder' => 'archive']));
    }

    #[Test]
    public function counts_are_of_the_actors_conversations_and_unread_ones()
    {
        $this->linkMessages();
        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(3, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(2, self::ALICE, ['pigeonholeFolderId' => 2, 'pigeonholeArchived' => true]);
        // Bob files the same conversation: alice's counts don't move.
        $this->file(1, self::BOB, ['pigeonholeFolderId' => 3]);
        // Alice reads one of the two in Work.
        $this->database()->table('dialog_user')->where('dialog_id', 1)->where('user_id', self::ALICE)->update(['last_read_message_id' => 10]);

        $folders = array_column($this->folders(self::ALICE), null, 'name');
        $this->assertSame([2, 1], [$folders['Work']['dialogCount'], $folders['Work']['unreadCount']]);
        $this->assertSame([0, 0], [$folders['Family']['dialogCount'], $folders['Family']['unreadCount']], 'Archived conversations are in the archive');

        $bob = array_column($this->folders(self::BOB), null, 'name');
        $this->assertSame([1, 1], [$bob['Mine']['dialogCount'], $bob['Mine']['unreadCount']]);
    }

    #[Test]
    public function the_folder_list_is_two_queries_however_many_folders()
    {
        $this->linkMessages();
        $count = function () {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->folders(self::ALICE);
            $queries = count($db->getQueryLog());
            $db->disableQueryLog();

            return $queries;
        };

        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        $count();
        $before = $count();

        for ($i = 0; $i < 20; $i++) {
            $this->database()->table('pigeonhole_folders')->insert(['user_id' => self::ALICE, 'name' => "F$i", 'position' => $i + 2, 'created_at' => \Carbon\Carbon::now()]);
        }
        $this->file(2, self::ALICE, ['pigeonholeFolderId' => 2]);

        $this->assertSame($before, $count());
    }
}
