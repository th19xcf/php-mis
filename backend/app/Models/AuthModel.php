<?php

namespace App\Models;

class AuthModel
{
    /**
     * Minimal in-memory users for initial integration.
     * Replace with database queries when user table is ready.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $users = [
        [
            'id' => 1,
            'user_name' => 'admin',
            'password' => '123456',
            'role' => 'R_SUPER',
            'buttons' => []
        ]
    ];

    public function verifyUser(string $userName, string $password): ?array
    {
        $user = $this->getUserByName($userName);

        if (!$user) {
            return null;
        }

        if ($user['password'] !== $password) {
            return null;
        }

        return $user;
    }

    public function getUserById(int $userId): ?array
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $userId) {
                return $user;
            }
        }

        return null;
    }

    private function getUserByName(string $userName): ?array
    {
        foreach ($this->users as $user) {
            if ($user['user_name'] === $userName) {
                return $user;
            }
        }

        return null;
    }
}
