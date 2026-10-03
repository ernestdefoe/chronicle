<?php

namespace Ernestdefoe\Chronicle\Feed;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Which kinds of activity this forum can actually show.
 *
 * The optional ones come from other extensions' tables. An extension can be
 * disabled with its tables still in place, or enabled before its migrations
 * ran, so a source needs both: the extension on, and the columns there.
 */
class Sources
{
    /** Every type, in the order they rank when two share the same second. */
    public const TYPES = ['discussion', 'reply', 'best_answer', 'like', 'badge', 'joined'];

    private ?array $schema = null;

    public function __construct(
        protected ConnectionInterface $db,
        protected Cache $cache,
        protected ExtensionManager $extensions,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /** The types the admin has switched on and the forum can provide. */
    public function enabled(): array
    {
        return array_values(array_filter(self::TYPES, fn (string $type) => $this->available($type) && $this->switchedOn($type)));
    }

    public function available(string $type): bool
    {
        $schema = $this->schema();

        return match ($type) {
            'discussion', 'reply', 'joined' => true,
            'like' => $this->extensions->isEnabled('flarum-likes') && $schema['likes'],
            'best_answer' => $this->extensions->isEnabled('fof-best-answer') && $schema['bestAnswer'],
            'badge' => $this->extensions->isEnabled('fof-badges') && $schema['badges'],
            default => false,
        };
    }

    public function switchedOn(string $type): bool
    {
        return (bool) $this->settings->get("ernestdefoe-chronicle.show_$type", true);
    }

    /** Whether posts / discussions carry flarum/approval's flag. */
    public function hasApproval(string $table): bool
    {
        return $this->schema()['approval_'.$table];
    }

    /**
     * The schema checks, cached: they run on every feed request otherwise, and
     * the answers only change when an extension is installed or migrated. The
     * key carries the list of enabled extensions, so enabling one is seen at
     * once rather than after the cache expires.
     */
    private function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $key = 'ernestdefoe-chronicle.schema.'.md5(implode(',', array_keys($this->extensions->getEnabledExtensions())));

        return $this->schema = $this->cache->remember($key, 3600, function () {
            $schema = $this->db->getSchemaBuilder();

            return [
                'likes' => $schema->hasTable('post_likes') && $schema->hasColumn('post_likes', 'created_at'),
                'bestAnswer' => $schema->hasColumn('discussions', 'best_answer_post_id') && $schema->hasColumn('discussions', 'best_answer_set_at'),
                'badges' => $schema->hasTable('fof_badge_user') && $schema->hasTable('fof_badges')
                    && $schema->hasColumn('fof_badge_user', 'earned_at') && $schema->hasColumn('fof_badges', 'is_visible'),
                'approval_posts' => $schema->hasColumn('posts', 'is_approved'),
                'approval_discussions' => $schema->hasColumn('discussions', 'is_approved'),
            ];
        });
    }
}
