<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

final class LoginController extends Controller
{
    public function show(): void
    {
        if ($this->app->auth->check()) {
            $this->redirect($this->homeForUser());
        }

        echo $this->view('auth/login', [
            'title' => 'Sign in',
            'error' => $this->app->session->flash('error'),
            'login' => '',
        ], 'layouts/guest');
    }

    public function attempt(): void
    {
        $max = (int) $this->app->config->get('app.security.login_max_attempts', 10);
        $decay = (int) $this->app->config->get('app.security.login_decay_seconds', 300);
        if ($this->app->security->tooManyAttempts('login', $max, $decay)) {
            $this->app->session->flash('error', 'Too many sign-in attempts. Try again in a few minutes.');
            $this->redirect('login');
        }

        $login = trim((string) $this->app->request->post('username', ''));
        $password = (string) $this->app->request->post('password', '');
        $honeypot = trim((string) $this->app->request->post('website', ''));

        if ($honeypot !== '') {
            $this->app->session->flash('error', 'Invalid username/email or password.');
            $this->redirect('login');
        }

        $user = $this->app->auth->findByLogin($login);
        $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';

        if ($user === null || $hash === '' || !password_verify($password, $hash)) {
            $this->app->session->flash('error', 'Invalid username/email or password.');
            $this->redirect('login');
        }

        if ((string) ($user['status'] ?? '') !== 'active') {
            $this->app->session->flash('error', 'Your account cannot sign in right now.');
            $this->redirect('login');
        }

        $this->app->security->clearAttempts('login');
        $this->app->auth->touchLastLogin((int) $user['id']);
        $this->app->auth->login($user);

        $next = (string) $this->app->session->get('redirect_after_login', '');
        $this->app->session->remove('redirect_after_login');
        if ($next === '' || str_contains($next, '..') || str_contains($next, '://')) {
            $next = $this->homeForUser();
        }

        $this->redirect($next);
    }

    public function logout(): void
    {
        $this->app->tenant->stopImpersonating();
        $this->app->auth->logout();
        $this->redirect('login');
    }

    private function homeForUser(): string
    {
        if ($this->app->auth->isPlatform()) {
            return 'platform';
        }
        return (string) $this->app->config->get('app.default_home', 'admin/orders');
    }
}
