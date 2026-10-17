<?php

require_once __DIR__ . '/wp-load.php';

$username = 'munscr';
$new_password = 'munscr2026!';

$user = get_user_by('login', $username);

if (!$user) {
    exit('User not found.');
}

wp_set_password($new_password, $user->ID);

echo 'Password successfully changed. DELETE THIS FILE NOW.';