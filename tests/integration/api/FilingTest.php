<?php

namespace Ernestdefoe\Pigeonhole\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Pigeonhole\Tests\integration\PigeonholeTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Putting conversations in folders and the archive, and who may. */
class FilingTest extends PigeonholeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->linkMessages();
    }

    /**
     * The database removes a filing with the member's place in the
     * conversation. Flarum's test harness switches SQLite's foreign keys off
     * for the whole test (installed forums have them on), so only MySQL and
     * PostgreSQL can show it.
     */
    private function needsForeignKeys(): void
    {
        if ($this->database()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Foreign keys are off in SQLite tests.');
        }
    }

    #[Test]
    public function a_member_files_a_conversation_and_the_other_person_sees_no_change()
    {
        [$status, $body] = $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(1, $body['data']['attributes']['pigeonholeFolderId']);

        $this->assertSame(1, $this->attributes(1, self::ALICE)['pigeonholeFolderId']);
        $this->assertNull($this->attributes(1, self::BOB)['pigeonholeFolderId']);
        $this->assertSame([1], $this->listed(self::ALICE, ['folder' => '1']));
        $this->assertSame([1, 3, 4], $this->listed(self::BOB), "Bob's list is as it was");
    }

    #[Test]
    public function a_member_cannot_file_a_conversation_they_are_not_in()
    {
        $this->assertSame(404, $this->file(4, self::ALICE, ['pigeonholeFolderId' => 1])[0]);
        $this->assertSame(404, $this->file(4, self::ALICE, ['pigeonholeArchived' => true])[0]);

        $this->assertSame(0, $this->database()->table('pigeonhole_filings')->count());
    }

    #[Test]
    public function a_member_cannot_file_into_someone_elses_folder()
    {
        // Bob is in dialog 1; folder 1 is alice's.
        $this->assertSame(422, $this->file(1, self::BOB, ['pigeonholeFolderId' => 1])[0]);
        $this->assertSame(422, $this->file(1, self::BOB, ['pigeonholeFolderId' => 999])[0]);

        $this->assertNull($this->filing(self::BOB, 1));
    }

    #[Test]
    public function guests_cannot_file_anything()
    {
        // Not found: a guest is in no conversation.
        $this->assertContains($this->call('PATCH', '/api/dialogs/1', null, null, [], ['data' => ['type' => 'dialogs', 'id' => '1', 'attributes' => ['pigeonholeFolderId' => 1]]])[0], [401, 404]);
        $this->assertSame(0, $this->database()->table('pigeonhole_filings')->count());
    }

    #[Test]
    public function another_members_folder_filter_shows_nothing()
    {
        $this->file(1, self::BOB, ['pigeonholeFolderId' => 3]);

        // Alice is in dialog 1 too, but folder 3 is bob's filing, not hers.
        $this->assertSame([], $this->listed(self::ALICE, ['folder' => '3']));
        $this->assertSame([1], $this->listed(self::BOB, ['folder' => '3']));
    }

    #[Test]
    public function moving_and_removing_from_a_folder()
    {
        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 2]);
        $this->assertSame([], $this->listed(self::ALICE, ['folder' => '1']));
        $this->assertSame([1], $this->listed(self::ALICE, ['folder' => '2']));

        $this->file(1, self::ALICE, ['pigeonholeFolderId' => null]);
        $this->assertNull($this->filing(self::ALICE, 1), 'An empty filing is removed');
        $this->assertSame([1, 2, 3], $this->listed(self::ALICE));
    }

    #[Test]
    public function folders_hold_group_conversations_too()
    {
        $this->file(3, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(3, self::CAROL, ['pigeonholeArchived' => true]);

        $this->assertSame([3], $this->listed(self::ALICE, ['folder' => '1']));
        $this->assertSame([2], $this->listed(self::CAROL), "Carol archived the group; alice and bob still see it");
        $this->assertSame([1, 3, 4], $this->listed(self::BOB));
    }

    #[Test]
    public function archiving_hides_it_and_reads_it()
    {
        [$status, $body] = $this->file(2, self::ALICE, ['pigeonholeArchived' => true]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['data']['attributes']['pigeonholeArchived']);

        $this->assertSame([1, 3], $this->listed(self::ALICE));
        $this->assertSame([2], $this->listed(self::ALICE, ['folder' => 'archive']));
        $this->assertSame(20, (int) $this->database()->table('dialog_user')->where('dialog_id', 2)->where('user_id', self::ALICE)->value('last_read_message_id'));
        $this->assertSame([], $this->listed(self::ALICE, ['unread' => '1', 'folder' => 'archive']));
        $this->assertSame([1, 3], $this->listed(self::ALICE, ['unread' => '1']), 'The header dropdown leaves it out');
        $this->assertSame([2, 3], $this->listed(self::CAROL), 'Carol still sees it');
    }

    #[Test]
    public function a_new_message_brings_an_archived_conversation_back_to_its_folder()
    {
        $this->file(2, self::ALICE, ['pigeonholeFolderId' => 2, 'pigeonholeArchived' => true]);
        $this->assertSame([], $this->listed(self::ALICE, ['folder' => '2']));

        [$status, $body] = $this->call('POST', '/api/dialog-messages', self::CAROL, null, [], ['data' => [
            'type' => 'dialog-messages',
            'attributes' => ['content' => 'Are you there?'],
            'relationships' => ['dialog' => ['data' => ['type' => 'dialogs', 'id' => '2']]],
        ]]);
        $this->assertSame(201, $status, json_encode($body));

        $this->assertSame([1, 2, 3], $this->listed(self::ALICE));
        $this->assertSame([2], $this->listed(self::ALICE, ['folder' => '2']));
        $this->assertSame([], $this->listed(self::ALICE, ['folder' => 'archive']));
        $this->assertFalse($this->attributes(2, self::ALICE)['pigeonholeArchived']);
    }

    #[Test]
    public function unarchiving_puts_it_back()
    {
        $this->file(2, self::ALICE, ['pigeonholeArchived' => true]);
        $this->file(2, self::ALICE, ['pigeonholeArchived' => false]);

        $this->assertNull($this->filing(self::ALICE, 2));
        $this->assertSame([1, 2, 3], $this->listed(self::ALICE));
    }

    #[Test]
    public function leaving_a_group_takes_it_out_of_your_folders()
    {
        $this->needsForeignKeys();

        $this->file(3, self::CAROL, ['pigeonholeFolderId' => null, 'pigeonholeArchived' => true]);
        $this->file(3, self::ALICE, ['pigeonholeFolderId' => 1]);

        // Through Group Messages' own leave endpoint.
        $this->assertSame(200, $this->call('POST', '/api/dialogs/3/leave', self::ALICE)[0]);
        $this->assertNull($this->filing(self::ALICE, 3));
        $this->assertNotNull($this->filing(self::CAROL, 3), "Carol's filing is hers");

        // And rejoining starts clean.
        $this->database()->table('dialog_user')->insert(['dialog_id' => 3, 'user_id' => self::ALICE, 'joined_at' => Carbon::now(), 'last_read_message_id' => 0]);
        $this->assertSame([3], array_values(array_intersect([3], $this->listed(self::ALICE))));
        $this->assertSame([], $this->listed(self::ALICE, ['folder' => '1']));
    }

    #[Test]
    public function deleting_a_conversation_or_a_member_leaves_no_filings()
    {
        $this->needsForeignKeys();

        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(2, self::ALICE, ['pigeonholeFolderId' => 1]);

        $this->database()->table('dialogs')->where('id', 1)->update(['first_message_id' => null, 'last_message_id' => null]);
        $this->database()->table('dialog_messages')->where('dialog_id', 1)->delete();
        $this->database()->table('dialogs')->where('id', 1)->delete();
        $this->assertNull($this->filing(self::ALICE, 1));

        $this->assertSame(204, $this->call('DELETE', '/api/users/'.self::ALICE, 1)[0]);
        $this->assertSame(0, $this->database()->table('pigeonhole_filings')->count());
        $this->assertSame(0, $this->database()->table('pigeonhole_folders')->where('user_id', self::ALICE)->count());
    }

    #[Test]
    public function the_filing_is_not_sent_where_conversations_are_only_included()
    {
        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);

        [$status, $body] = $this->call('GET', '/api/dialog-messages', self::ALICE, null, ['filter' => ['dialog' => '1'], 'include' => 'dialog']);
        $this->assertSame(200, $status, json_encode($body));

        $dialog = collect($body['included'] ?? [])->firstWhere('type', 'dialogs');
        $this->assertNotNull($dialog);
        $this->assertArrayNotHasKey('pigeonholeFolderId', $dialog['attributes']);
    }

    #[Test]
    public function searching_finds_archived_conversations()
    {
        $this->file(2, self::ALICE, ['pigeonholeArchived' => true]);

        // Group Messages' search by who a conversation is with.
        $this->assertContains(2, $this->listed(self::ALICE, ['q' => 'carol']));
    }

    #[Test]
    public function the_list_loads_filings_in_one_query_however_many_conversations()
    {
        $count = function (array $filter = []) {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->listed(self::ALICE, $filter);
            $queries = count($db->getQueryLog());
            $db->disableQueryLog();

            return $queries;
        };

        $this->file(1, self::ALICE, ['pigeonholeFolderId' => 1]);
        // Warm: the first request also fills caches no later one needs.
        $count();
        $one = $count();
        $oneInFolder = $count(['folder' => '1']);

        $this->file(2, self::ALICE, ['pigeonholeFolderId' => 1]);
        $this->file(3, self::ALICE, ['pigeonholeFolderId' => 1]);

        $this->assertSame($one, $count());
        $this->assertSame($oneInFolder, $count(['folder' => '1']));
    }
}
