<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Who may open a member's Activity tab. Everyone by default, as on a
 * traditional forum. A member can always see their own.
 */
return Migration::addPermissions([
    'ernestdefoe-chronicle.viewActivity' => Group::GUEST_ID,
]);
