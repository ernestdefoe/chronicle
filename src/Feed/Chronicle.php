<?php

namespace Ernestdefoe\Chronicle\Feed;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * One page of a member's activity, newest first.
 *
 * There is no event table: every item is read from the data the forum already
 * keeps (discussions, posts, likes, best answers, badges), so the feed has the
 * member's whole history the day it is installed.
 *
 * Visibility is the security model. Every discussion and post goes through
 * core's whereVisibleTo() for the person LOOKING, so a restricted tag, a
 * private discussion (FoF Byobu's included) or an unapproved post never
 * appears for anyone who couldn't open it anyway. Hidden posts and hidden
 * discussions are left out for everybody: a feed is not a moderation tool.
 *
 * Paging is by a cursor of (time, type, id), never an offset, so items
 * arriving while somebody reads don't shift the next page under them.
 */
class Chronicle
{
    public const PER_PAGE = 20;

    public function __construct(
        protected Sources $sources,
        protected ConnectionInterface $db,
        protected SlugManager $slugs
    ) {
    }

    /**
     * @param string[]|null $requested types asked for, or null for everything
     * @param array{0: string, 1: ?string, 2: ?int}|null $cursor [time, type, id]
     */
    public function page(User $actor, User $user, ?array $requested, ?array $cursor): array
    {
        $types = $this->typesFor($actor, $user);
        $wanted = $requested === null ? $types : array_values(array_intersect($types, $requested));

        $limit = self::PER_PAGE;
        $rows = new Collection();

        foreach ($wanted as $type) {
            if ($type === 'joined') {
                continue;
            }

            $rows = $rows->concat($this->fetch($type, $actor, $user, $cursor, $limit + 1));
        }

        // Newest first; same second → type order; then the newest row.
        $rank = array_flip(Sources::TYPES);
        $rows = $rows->sort(function (array $a, array $b) use ($rank) {
            return [$b['ts'], $rank[$a['type']], $b['id']] <=> [$a['ts'], $rank[$b['type']], $a['id']];
        })->values();

        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->values();

        /*
         * Joining is the first thing anybody does, so it is the last item of
         * the feed, always — even on imported forums where the join date is
         * later than the member's oldest posts. It comes once everything else
         * has run out.
         */
        if (! $more && in_array('joined', $wanted, true) && $user->joined_at) {
            if ($items->count() < $limit) {
                $items->push($this->joinedItem($user));
            } else {
                $more = true;
            }
        }

        $last = $items->last();

        return [
            'items' => $items->map(fn (array $row) => $row['item'])->all(),
            'next' => $more && $last ? ['before' => $last['item']['date'], 'key' => $last['type'].':'.$last['id']] : null,
            'types' => $types,
        ];
    }

    /** The kinds of item this viewer may see on this member's feed. */
    public function typesFor(User $actor, User $user): array
    {
        $self = (int) $actor->id === (int) $user->id;

        return array_values(array_filter($this->sources->enabled(), function (string $type) use ($actor, $user, $self) {
            return match ($type) {
                // A member's own choice. They still see their own likes.
                'like' => $self || $user->getPreference('chronicleShowLikes', true) !== false,
                'badge' => $self || $actor->hasPermission('badges.viewUserBadges') || $actor->hasPermission('badges.moderate'),
                default => true,
            };
        }));
    }

    private function fetch(string $type, User $actor, User $user, ?array $cursor, int $take): array
    {
        return match ($type) {
            'discussion' => $this->discussions($actor, $user, $cursor, $take),
            'reply' => $this->replies($actor, $user, $cursor, $take),
            'like' => $this->likes($actor, $user, $cursor, $take),
            'best_answer' => $this->bestAnswers($actor, $user, $cursor, $take),
            'badge' => $this->badges($actor, $user, $cursor, $take),
            default => [],
        };
    }

    private function discussions(User $actor, User $user, ?array $cursor, int $take): array
    {
        $query = Discussion::query()
            ->whereVisibleTo($actor)
            ->select('discussions.*', 'discussions.created_at as chronicle_at')
            ->where('discussions.user_id', $user->id)
            ->whereNull('discussions.hidden_at')
            ->whereNotNull('discussions.first_post_id')
            ->with('firstPost');

        if ($this->sources->hasApproval('discussions')) {
            $query->where('discussions.is_approved', true);
        }

        $this->after($query, 'discussion', 'discussions.created_at', 'discussions.id', $cursor);

        return $query->limit($take)->get()->map(function (Discussion $discussion) {
            $first = $discussion->firstPost;

            return $this->row('discussion', (int) $discussion->id, $discussion->getAttribute('chronicle_at'), [
                'discussion' => $this->discussionPayload($discussion),
                'postNumber' => 1,
                'excerpt' => $first ? Excerpt::from($first->getAttributes()['content'] ?? null) : '',
            ]);
        })->all();
    }

    private function replies(User $actor, User $user, ?array $cursor, int $take): array
    {
        $query = $this->visiblePosts($actor)
            ->select('posts.*', 'posts.created_at as chronicle_at')
            ->where('posts.user_id', $user->id)
            ->where('posts.number', '>', 1)
            ->with('discussion');

        $this->after($query, 'reply', 'posts.created_at', 'posts.id', $cursor);

        return $query->limit($take)->get()->map(fn (Post $post) => $this->postRow('reply', $post))->all();
    }

    private function likes(User $actor, User $user, ?array $cursor, int $take): array
    {
        $query = $this->visiblePosts($actor)
            ->join('post_likes', 'post_likes.post_id', '=', 'posts.id')
            ->select('posts.*', 'post_likes.created_at as chronicle_at')
            ->where('post_likes.user_id', $user->id)
            ->with(['discussion', 'user']);

        $this->after($query, 'like', 'post_likes.created_at', 'posts.id', $cursor);

        return $query->limit($take)->get()->map(function (Post $post) {
            $row = $this->postRow('like', $post);
            $author = $post->user;
            $row['item']['author'] = $author ? ['displayName' => $author->display_name, 'username' => $author->username] : null;

            return $row;
        })->all();
    }

    private function bestAnswers(User $actor, User $user, ?array $cursor, int $take): array
    {
        $query = $this->visiblePosts($actor)
            ->join('discussions as chronicle_ba', 'chronicle_ba.best_answer_post_id', '=', 'posts.id')
            ->select('posts.*', 'chronicle_ba.best_answer_set_at as chronicle_at')
            ->where('posts.user_id', $user->id)
            ->whereNotNull('chronicle_ba.best_answer_set_at')
            ->whereNull('chronicle_ba.hidden_at')
            ->with('discussion');

        $this->after($query, 'best_answer', 'chronicle_ba.best_answer_set_at', 'posts.id', $cursor);

        return $query->limit($take)->get()->map(fn (Post $post) => $this->postRow('best_answer', $post))->all();
    }

    private function badges(User $actor, User $user, ?array $cursor, int $take): array
    {
        $query = $this->db->table('fof_badge_user')
            ->join('fof_badges', 'fof_badges.id', '=', 'fof_badge_user.badge_id')
            ->select(
                'fof_badge_user.id',
                'fof_badge_user.earned_at as chronicle_at',
                'fof_badges.name',
                'fof_badges.slug',
                'fof_badges.icon',
                'fof_badges.icon_color',
                'fof_badges.background_color'
            )
            ->where('fof_badge_user.user_id', $user->id);

        // FoF Badges' own rules: inactive badges are for moderators; hidden
        // ones for moderators and the member who holds them.
        if (! $actor->hasPermission('badges.moderate')) {
            $query->where('fof_badges.is_active', true);

            if ((int) $actor->id !== (int) $user->id) {
                $query->where('fof_badges.is_visible', true);
            }
        }

        $this->after($query, 'badge', 'fof_badge_user.earned_at', 'fof_badge_user.id', $cursor);

        return $query->limit($take)->get()->map(function ($badge) {
            return $this->row('badge', (int) $badge->id, $badge->chronicle_at, [
                'badge' => [
                    'name' => $badge->name,
                    'slug' => $badge->slug,
                    'icon' => $badge->icon,
                    'iconColor' => $badge->icon_color,
                    'backgroundColor' => $badge->background_color,
                ],
            ]);
        })->all();
    }

    /**
     * Comment posts the viewer can see, in discussions that are not hidden.
     * Hidden posts are left out even for moderators who could open them.
     *
     * @return Builder<Post>
     */
    private function visiblePosts(User $actor): Builder
    {
        $query = Post::query()
            ->whereVisibleTo($actor)
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->whereIn('posts.discussion_id', function (QueryBuilder $live) {
                $live->select('id')->from('discussions')->whereNull('hidden_at');
            });

        if ($this->sources->hasApproval('posts')) {
            $query->where('posts.is_approved', true);
        }

        return $query;
    }

    /**
     * Rows that come after the cursor in feed order: (time desc, type order,
     * id desc). Within one source the type is fixed, so the comparison folds
     * to one of three shapes.
     */
    private function after(Builder|QueryBuilder $query, string $type, string $timeColumn, string $idColumn, ?array $cursor): void
    {
        $query->orderByDesc($timeColumn)->orderByDesc($idColumn);

        if ($cursor === null) {
            return;
        }

        [$time, $cursorType, $cursorId] = $cursor;

        if ($cursorType === null || ! in_array($cursorType, Sources::TYPES, true)) {
            $query->where($timeColumn, '<', $time);

            return;
        }

        $rank = array_flip(Sources::TYPES);

        if ($rank[$type] > $rank[$cursorType]) {
            $query->where($timeColumn, '<=', $time);
        } elseif ($rank[$type] < $rank[$cursorType]) {
            $query->where($timeColumn, '<', $time);
        } else {
            $query->where(function ($q) use ($timeColumn, $idColumn, $time, $cursorId) {
                $q->where($timeColumn, '<', $time)
                    ->orWhere(fn ($q) => $q->where($timeColumn, '=', $time)->where($idColumn, '<', (int) $cursorId));
            });
        }
    }

    private function postRow(string $type, Post $post): array
    {
        return $this->row($type, (int) $post->id, $post->getAttribute('chronicle_at'), [
            'discussion' => $post->discussion ? $this->discussionPayload($post->discussion) : null,
            'postNumber' => (int) $post->number,
            'excerpt' => Excerpt::from($post->getAttributes()['content'] ?? null),
        ]);
    }

    private function joinedItem(User $user): array
    {
        return $this->row('joined', (int) $user->id, $user->joined_at, []);
    }

    private function row(string $type, int $id, mixed $time, array $fields): array
    {
        $date = $time instanceof \DateTimeInterface ? Carbon::instance($time) : Carbon::parse((string) $time, 'UTC');
        $date = $date->utc();

        return [
            'type' => $type,
            'id' => $id,
            'ts' => $date->getTimestamp(),
            'item' => ['type' => $type, 'id' => $type.':'.$id, 'date' => $date->format('Y-m-d\TH:i:s\Z')] + $fields,
        ];
    }

    private function discussionPayload(Discussion $discussion): array
    {
        return [
            'id' => (int) $discussion->id,
            'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
            'title' => $discussion->title,
        ];
    }
}
