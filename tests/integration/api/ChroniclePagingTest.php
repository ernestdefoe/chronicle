<?php

namespace Ernestdefoe\Chronicle\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Paging by cursor: every item exactly once, joining always last, and a page
 * of many threads read in a fixed number of queries (flarum/testing fails the
 * request on repeated queries).
 */
class ChroniclePagingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-chronicle');

        $t = Carbon::parse('2026-01-10 12:00:00', 'UTC');
        $discussions = [];
        $posts = [];

        // 25 replies in 25 threads, one a day; replies 20 and 21 in the same
        // second, across the page break, so the cursor must break the tie by id.
        for ($n = 1; $n <= 25; $n++) {
            $at = $n === 21 ? $t->copy()->subDays(20) : $t->copy()->subDays($n);
            $discussions[] = ['id' => $n, 'title' => "Thread $n", 'created_at' => $t->copy()->subDays(40), 'user_id' => 1, 'first_post_id' => 100 + $n, 'comment_count' => 2];
            $posts[] = ['id' => 100 + $n, 'discussion_id' => $n, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Q</p></t>', 'created_at' => $t->copy()->subDays(40)];
            $posts[] = ['id' => $n, 'discussion_id' => $n, 'number' => 2, 'user_id' => 2, 'type' => 'comment', 'content' => "<t><p>Reply $n</p></t>", 'created_at' => $at];
        }

        $this->prepareDatabase([
            User::class => [['joined_at' => $t->copy()->subDays(60)] + $this->normalUser()],
            Discussion::class => $discussions,
            Post::class => $posts,
        ]);
    }

    private function page(array $query = []): array
    {
        $response = $this->send($this->request('GET', '/api/chronicle/2')->withQueryParams($query));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function the_first_page_holds_twenty_and_points_at_the_rest()
    {
        $first = $this->page();

        $this->assertCount(20, $first['items']);
        $this->assertSame('reply:1', $first['items'][0]['id']);
        $this->assertSame('reply:21', $first['items'][19]['id'], 'Same second: the higher id first');
        $this->assertSame(['before' => '2025-12-21T12:00:00Z', 'key' => 'reply:21'], $first['next']);
    }

    #[Test]
    public function following_the_cursor_reads_every_item_once_and_joining_last()
    {
        $first = $this->page();
        $second = $this->page($first['next']);

        $ids = array_merge(array_column($first['items'], 'id'), array_column($second['items'], 'id'));

        $expected = array_map(fn ($n) => "reply:$n", range(1, 25));
        // Same second: the higher id first.
        [$expected[19], $expected[20]] = ['reply:21', 'reply:20'];
        $expected[] = 'joined:2';

        $this->assertSame($expected, $ids);
        $this->assertNull($second['next']);
    }

    #[Test]
    public function a_feed_of_one_kind_pages_too()
    {
        $first = $this->page(['types' => 'reply']);
        $this->assertCount(20, $first['items']);
        $this->assertNotNull($first['next']);

        $second = $this->page(['types' => 'reply'] + $first['next']);
        $this->assertSame(['reply:22', 'reply:23', 'reply:24', 'reply:25'], array_column(array_slice($second['items'], 1), 'id'));
        $this->assertSame('reply:20', $second['items'][0]['id']);
        $this->assertNull($second['next'], 'Joining was not asked for, so nothing follows');
    }
}
