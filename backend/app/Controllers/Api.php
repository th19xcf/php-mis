<?php

namespace App\Controllers;

class Api extends BaseApiController
{
    public function test()
    {
        return $this->success([
            'message' => 'API test successful',
            'timestamp' => time()
        ]);
    }

    public function login()
    {
        $data = $this->request->getJSON();

        return $this->success([
            'token' => 'test-token-123',
            'user' => [
                'id' => 1,
                'name' => 'Test User',
                'role' => 'admin'
            ]
        ], 'Login successful');
    }
}
