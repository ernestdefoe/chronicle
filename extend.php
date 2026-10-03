<?php

use Ernestdefoe\Chronicle\Api\Controller\ChronicleController;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema\Attribute;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The Activity page is its own chunk; this publishes it.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/chronicle/{id:\d+}', 'ernestdefoe-chronicle.feed', ChronicleController::class),

    // A member's own choice, on Settings → Privacy.
    (new Extend\User())
        ->registerPreference('chronicleShowLikes', 'boolval', true),

    (new Extend\Settings())
        ->default('ernestdefoe-chronicle.show_discussion', true)
        ->default('ernestdefoe-chronicle.show_reply', true)
        ->default('ernestdefoe-chronicle.show_like', true)
        ->default('ernestdefoe-chronicle.show_best_answer', true)
        ->default('ernestdefoe-chronicle.show_badge', true)
        ->default('ernestdefoe-chronicle.show_joined', true),

    // Whether to offer the Activity tab on other members' profiles. A member
    // always has it on their own.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Attribute::make('canViewChronicle')
                ->get(fn ($forum, $context) => $context->getActor()->hasPermission('ernestdefoe-chronicle.viewActivity')),
        ]),
];
