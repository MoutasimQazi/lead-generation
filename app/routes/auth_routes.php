<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/audit.php';

function route_auth_login(): never
{
    $email    = body_string('email', '', 190) ?? '';
    $password = (string) (json_body()['password'] ?? '');

    if ($email === '' || $password === '') {
        fail('Enter your email and password.', 422);
    }

    $user = auth_login($email, $password);

    audit('auth.login', $user);

    json_ok(['user' => $user, 'csrf' => csrf_token()]);
}

function route_auth_logout(): never
{
    $user = current_user();

    if ($user) {
        audit('auth.logout', $user);
    }

    auth_logout();

    json_ok();
}

/**
 * Session probe. Every page calls this on load to decide whether to render or
 * bounce to the login screen, and to pick up its CSRF token.
 *
 * Returns 200 with authenticated:false rather than 401, so that the shared
 * fetch wrapper's "401 means redirect" rule does not fire on the login page.
 */
function route_auth_me(): never
{
    $user = current_user();

    if (!$user) {
        json_out(['success' => true, 'authenticated' => false]);
    }

    json_out([
        'success'       => true,
        'authenticated' => true,
        'csrf'          => csrf_token(),
        'user'          => [
            'id'        => (int) $user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
            'is_admin'  => $user['role'] === 'admin',
        ],
    ]);
}
