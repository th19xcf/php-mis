<?php

namespace App\Libraries;

class SessionUserContext
{
    public function getSessionValue(string $key, $default = null)
    {
        $value = \Config\Services::session()->get($key);

        return $value ?? $default;
    }

    public function getSessionUser(): array
    {
        $session = \Config\Services::session();

        return [
            'companyId' => trim((string) $session->get('company_id')),
            'userId' => trim((string) $session->get('user_id')),
            'workId' => trim((string) $session->get('user_workid')),
            'userName' => trim((string) $session->get('user_name')),
            'password' => (string) $session->get('user_pswd'),
            'location' => trim((string) $session->get('user_location')),
            'deptCode' => trim((string) $session->get('user_dept_code')),
            'deptName' => trim((string) $session->get('user_dept_name')),
            'role' => trim((string) $session->get('user_role')),
            'roleAuthz' => trim((string) $session->get('user_role_authz')),
            'deptAuthz' => trim((string) $session->get('dept_authz')),
            'locationAuthz' => trim((string) $session->get('user_location_authz')),
        ];
    }

    public function requireLogin(): array
    {
        $user = $this->getSessionUser();

        if ($user['companyId'] === '' || $user['workId'] === '') {
            throw new \RuntimeException('登录态已失效，请重新登录');
        }

        return $user;
    }
}