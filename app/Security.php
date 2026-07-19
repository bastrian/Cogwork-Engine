<?php

declare(strict_types=1);

namespace Modright;

final class Security
{
    public static function csrfToken(): string
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $token = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
            throw new HttpException(419, 'The form expired. Please try again.');
        }
    }

    public static function requireLogin(): void
    {
        if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id'])) {
            header('Location: ' . Application::url('login'));
            exit;
        }
    }

    public static function loginAllowed(): bool
    {
        $attempts = array_filter($_SESSION['login_attempts'] ?? [], static fn (int $time): bool => $time > time() - 900);
        $_SESSION['login_attempts'] = $attempts;
        return count($attempts) < 10;
    }

    public static function recordFailure(): void
    {
        $_SESSION['login_attempts'][] = time();
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
