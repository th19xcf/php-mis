<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Api extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    public function test()
    {
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => [
                'message' => 'API test successful',
                'timestamp' => time()
            ]
        ]);
    }

    public function login()
    {
        $data = $this->request->getJSON();
        
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Login successful',
            'data' => [
                'token' => 'test-token-123',
                'user' => [
                    'id' => 1,
                    'name' => 'Test User',
                    'role' => 'admin'
                ]
            ]
        ]);
    }
}