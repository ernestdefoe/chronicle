<?php

namespace Ernestdefoe\Chronicle\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A member's activity feed: what it shows, to whom, and in what order.
 */
class ChronicleTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private Carbon $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-likes', 'fof-badges', 'fof-best-answer', 'ernestdefoe-chronicle');

        $t = $this->t = Carbon::parse('2026-01-10 12:00:00', 'UTC');

        $this->prepareDatabase([
            User::class => [
                ['joined_at' => $t->copy()->subDays(30)] + $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $t->copy()->subDays(30)],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MEMBER_ID]],
            Discussion::class => [
                // Started by the member.
                ['id' => 1, 'title' => 'Started it', 'slug' => 'started-it', 'created_at' => $t->copy()->subDays(10), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                // The admin's, which the member replied in and liked.
                ['id' => 2, 'title' => 'Replied in it', 'created_at' => $t->copy()->subDays(12), 'user_id' => 1, 'first_post_id' => 5, 'comment_count' => 3, 'best_answer_post_id' => 2, 'best_answer_set_at' => $t->copy()->subDays(6)],
                // Hidden: out of everyone's feed.
                ['id' => 3, 'title' => 'Hidden one', 'created_at' => $t->copy()->subDays(8), 'user_id' => 2, 'first_post_id' => 4, 'comment_count' => 2, 'hidden_at' => $t],
                // Private: nobody here can open it.
                ['id' => 4, 'title' => 'Private one', 'created_at' => $t->copy()->subDays(12), 'user_id' => 1, 'first_post_id' => 6, 'comment_count' => 2, 'is_private' => true],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>My <s>**</s>first<e>**</e> topic</p></t>', 'created_at' => $t->copy()->subDays(10)],
                ['id' => 2, 'discussion_id' => 2, 'number' => 2, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>A reply</p></t>', 'created_at' => $t->copy()->subDays(9)],
                ['id' => 3, 'discussion_id' => 2, 'number' => 3, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hidden reply</p></t>', 'created_at' => $t->copy()->subDays(7), 'hidden_at' => $t],
                ['id' => 4, 'discussion_id' => 3, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>In a hidden discussion</p></t>', 'created_at' => $t->copy()->subDays(8)],
                ['id' => 5, 'discussion_id' => 2, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>The admin asks</p></t>', 'created_at' => $t->copy()->subDays(12)],
                ['id' => 6, 'discussion_id' => 4, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Private start</p></t>', 'created_at' => $t->copy()->subDays(12)],
                ['id' => 7, 'discussion_id' => 4, 'number' => 2, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Private reply</p></t>', 'created_at' => $t->copy()->subDays(3)],
                ['id' => 8, 'discussion_id' => 3, 'number' => 2, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Reply in a hidden discussion</p></t>', 'created_at' => $t->copy()->subDays(2)],
            ],
            'post_likes' => [
                ['post_id' => 5, 'user_id' => 2, 'created_at' => $t->copy()->subDays(5)],
            ],
            'fof_badges' => [
                ['id' => 1, 'name' => 'Helper', 'slug' => 'helper', 'icon' => 'fas fa-star', 'is_visible' => true, 'is_active' => true],
                ['id' => 2, 'name' => 'Secret', 'slug' => 'secret', 'icon' => 'fas fa-star', 'is_visible' => false, 'is_active' => true],
                ['id' => 3, 'name' => 'Retired', 'slug' => 'retired', 'icon' => 'fas fa-star', 'is_visible' => true, 'is_active' => false],
            ],
            'fof_badge_user' => [
                ['id' => 1, 'user_id' => 2, 'badge_id' => 1, 'earned_at' => $t->copy()->subDays(4)],
                ['id' => 2, 'user_id' => 2, 'badge_id' => 2, 'earned_at' => $t->copy()->subDays(4)->addHour()],
                ['id' => 3, 'user_id' => 2, 'badge_id' => 3, 'earned_at' => $t->copy()->subDays(4)->addHours(2)],
            ],
        ]);
    }

    private function feed(int $user, ?int $actor = null, array $query = []): array
    {
        $response = $this->send(
            $this->request('GET', "/api/chronicle/$user", $actor ? ['authenticatedAs' => $actor] : [])
                ->withQueryParams($query)
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return list<string> the feed's item ids, in order */
    private function ids(array $body): array
    {
        return array_column($body['items'], 'id');
    }

    #[Test]
    public function a_guest_sees_the_public_record_newest_first()
    {
        [$status, $body] = $this->feed(2);

        $this->assertSame(200, $status);
        $this->assertSame([
            'badge:1',
            'like:5',
            'best_answer:2',
            'reply:2',
            'discussion:1',
            'joined:2',
        ], $this->ids($body), 'No hidden post, no post in a hidden or private discussion, no hidden or retired badge');
        $this->assertNull($body['next']);
    }

    #[Test]
    public function a_feed_is_not_a_moderation_tool()
    {
        [$status, $body] = $this->feed(2, 1);

        $this->assertSame(200, $status);
        $ids = $this->ids($body);

        $this->assertContains('reply:2', $ids);
        $this->assertNotContains('reply:3', $ids, 'A hidden reply, though the admin could open it');
        $this->assertNotContains('discussion:3', $ids, 'A hidden discussion');
        $this->assertNotContains('reply:8', $ids, 'A reply in a hidden discussion');
    }

    #[Test]
    public function each_item_carries_its_thread_and_excerpt()
    {
        [, $body] = $this->feed(2);
        $items = array_column($body['items'], null, 'id');

        $this->assertSame(['id' => 1, 'slug' => '1-started-it', 'title' => 'Started it'], $items['discussion:1']['discussion']);
        $this->assertSame('My first topic', $items['discussion:1']['excerpt'], 'Markup characters are not text');
        $this->assertSame(2, $items['reply:2']['postNumber']);
        $this->assertSame('2026-01-01T12:00:00Z', $items['reply:2']['date']);
        $this->assertSame(['displayName' => 'admin', 'username' => 'admin'], $items['like:5']['author']);
        $this->assertSame('Helper', $items['badge:1']['badge']['name']);
    }

    #[Test]
    public function a_member_who_hides_their_likes_still_sees_them_on_their_own_feed()
    {
        $this->database()->table('users')->where('id', 2)->update(['preferences' => json_encode(['chronicleShowLikes' => false])]);

        [, $guest] = $this->feed(2);
        $this->assertNotContains('like:5', $this->ids($guest));
        $this->assertNotContains('like', $guest['types']);

        [, $own] = $this->feed(2, 2);
        $this->assertContains('like:5', $this->ids($own));
    }

    #[Test]
    public function hidden_badges_are_for_their_holder_and_retired_ones_for_moderators()
    {
        [, $own] = $this->feed(2, 2);
        $this->assertSame(['badge:2', 'badge:1'], array_values(array_filter($this->ids($own), fn ($id) => str_starts_with($id, 'badge:'))));

        [, $admin] = $this->feed(2, 1);
        $this->assertSame(['badge:3', 'badge:2', 'badge:1'], array_values(array_filter($this->ids($admin), fn ($id) => str_starts_with($id, 'badge:'))));
    }

    #[Test]
    public function the_forum_can_switch_a_kind_of_item_off()
    {
        $this->setting('ernestdefoe-chronicle.show_reply', '0');

        [, $body] = $this->feed(2);

        $this->assertNotContains('reply:2', $this->ids($body));
        $this->assertNotContains('reply', $body['types']);
    }

    #[Test]
    public function the_viewer_can_ask_for_some_kinds_only()
    {
        [, $body] = $this->feed(2, null, ['types' => 'reply,discussion']);

        $this->assertSame(['reply:2', 'discussion:1'], $this->ids($body));
    }

    #[Test]
    public function a_missing_or_unknown_member_is_not_found()
    {
        [$status] = $this->feed(999);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function without_the_permission_only_the_member_sees_their_own_feed()
    {
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-chronicle.viewActivity')->delete();

        [$status] = $this->feed(2);
        $this->assertSame(403, $status, 'Guest');

        [$status] = $this->feed(2, 3);
        $this->assertSame(403, $status, 'Another member');

        [$status] = $this->feed(2, 2);
        $this->assertSame(200, $status, 'The member themself');
    }

    #[Test]
    public function the_forum_says_whether_the_activity_tab_is_offered()
    {
        $response = $this->send($this->request('GET', '/api'));
        $this->assertTrue(json_decode((string) $response->getBody(), true)['data']['attributes']['canViewChronicle']);
    }

    #[Test]
    public function the_tab_is_not_offered_without_the_permission()
    {
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-chronicle.viewActivity')->delete();

        $response = $this->send($this->request('GET', '/api'));
        $this->assertFalse(json_decode((string) $response->getBody(), true)['data']['attributes']['canViewChronicle']);
    }
}
