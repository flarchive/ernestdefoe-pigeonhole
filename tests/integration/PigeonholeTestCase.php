<?php

namespace Ernestdefoe\Pigeonhole\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;

/**
 * Users: 2 alice, 3 bob, 4 carol, 5 dave.
 * Dialog 1: alice + bob, message 10 by bob.
 * Dialog 2: alice + carol, message 20 by carol.
 * Dialog 3: a group of alice, bob and carol (Group Messages), message 30 by bob.
 * Dialog 4: bob + dave, message 40 by dave. Alice is not in it.
 * Nobody has read anything.
 * Folders: 1 "Work" and 2 "Family" are alice's, 3 "Mine" is bob's.
 */
abstract class PigeonholeTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const ALICE = 2;
    protected const BOB = 3;
    protected const CAROL = 4;
    protected const DAVE = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-messages', 'ernestdefoe-group-messages', 'ernestdefoe-pigeonhole');

        $now = Carbon::now();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1];
        $member = fn (int $dialog, int $user) => ['dialog_id' => $dialog, 'user_id' => $user, 'joined_at' => $now, 'last_read_message_id' => 0];
        $dialog = fn (int $id, string $type = 'direct') => ['id' => $id, 'type' => $type, 'created_at' => $now, 'updated_at' => $now];
        $message = fn (int $id, int $dialog, int $user) => ['id' => $id, 'dialog_id' => $dialog, 'number' => 1, 'user_id' => $user, 'content' => '<t><p>Hello</p></t>', 'created_at' => $now, 'updated_at' => $now];

        $this->prepareDatabase([
            User::class => [$user(2, 'alice'), $user(3, 'bob'), $user(4, 'carol'), $user(5, 'dave')],
            'dialogs' => [$dialog(1), $dialog(2), $dialog(3, 'group'), $dialog(4)],
            'dialog_messages' => [$message(10, 1, 3), $message(20, 2, 4), $message(30, 3, 3), $message(40, 4, 5)],
            'dialog_user' => [
                $member(1, 2), $member(1, 3),
                $member(2, 2), $member(2, 4),
                $member(3, 2), $member(3, 3), $member(3, 4),
                $member(4, 3), $member(4, 5),
            ],
            'group_dialogs' => [
                ['dialog_id' => 3, 'title' => 'The trio', 'icon_url' => null, 'owner_id' => 3, 'created_at' => $now, 'updated_at' => $now],
            ],
            'pigeonhole_folders' => [
                ['id' => 1, 'user_id' => 2, 'name' => 'Work', 'color' => '#0f766e', 'icon' => 'fas fa-briefcase', 'position' => 0, 'created_at' => $now],
                ['id' => 2, 'user_id' => 2, 'name' => 'Family', 'color' => null, 'icon' => null, 'position' => 1, 'created_at' => $now],
                ['id' => 3, 'user_id' => 3, 'name' => 'Mine', 'color' => null, 'icon' => null, 'position' => 0, 'created_at' => $now],
            ],
        ]);
    }

    /** Fixtures can't set these at insert: the messages they point at come later. */
    protected function linkMessages(): void
    {
        $this->app();

        foreach ([1 => 10, 2 => 20, 3 => 30, 4 => 40] as $dialog => $message) {
            $this->database()->table('dialogs')->where('id', $dialog)->update([
                'first_message_id' => $message,
                'last_message_id' => $message,
                'last_message_at' => Carbon::now(),
                'last_message_user_id' => $this->database()->table('dialog_messages')->where('id', $message)->value('user_id'),
            ]);
        }
    }

    /** @return array{0: int, 1: mixed} */
    protected function call(string $method, string $path, ?int $actor = null, ?array $attributes = null, array $query = [], ?array $body = null): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];

        if ($body !== null) {
            $options['json'] = $body;
        } elseif ($attributes !== null) {
            $options['json'] = ['data' => ['attributes' => $attributes]];
        }

        $request = $this->request($method, $path, $options)->withQueryParams($query);

        if (! $actor && $method !== 'GET') {
            $session = $this->send($this->request('GET', '/api'));
            $request = $this->request($method, $path, $options + ['cookiesFrom' => $session])->withHeader('X-CSRF-Token', $session->getHeaderLine('X-CSRF-Token'));
        }

        // Messages sent here are seconds apart: flood control is not what is tested.
        $response = $this->send($request->withAttribute('bypassThrottling', true));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** PATCH a conversation's attributes as someone. */
    protected function file(int $dialog, int $actor, array $attributes): array
    {
        return $this->call('PATCH', "/api/dialogs/$dialog", $actor, null, [], ['data' => ['type' => 'dialogs', 'id' => (string) $dialog, 'attributes' => $attributes]]);
    }

    /** @return list<int> the conversations someone's list shows, with a filter. */
    protected function listed(int $actor, array $filter = []): array
    {
        [$status, $body] = $this->call('GET', '/api/dialogs', $actor, null, $filter ? ['filter' => $filter] : []);
        $this->assertSame(200, $status, json_encode($body));

        $ids = array_map(fn ($item) => (int) $item['id'], $body['data']);
        sort($ids);

        return $ids;
    }

    protected function attributes(int $dialog, int $actor): array
    {
        [$status, $body] = $this->call('GET', "/api/dialogs/$dialog", $actor);
        $this->assertSame(200, $status, json_encode($body));

        return $body['data']['attributes'];
    }

    protected function filing(int $user, int $dialog): ?object
    {
        return $this->database()->table('pigeonhole_filings')->where('user_id', $user)->where('dialog_id', $dialog)->first();
    }
}
