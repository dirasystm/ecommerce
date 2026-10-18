<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

/** Sign-in pages accept an email or a phone number (customers, sellers and staff). */
final class PhoneSignInHttpTest extends FeatureTestCase
{
    private function account(string $email, string $phone, bool $staff = false): int
    {
        $id = $this->db->insert('users', ['email' => $email, 'phone' => $phone, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'first_name' => 'Achieng', 'last_name' => 'Otieno', 'is_staff' => $staff ? 1 : 0]);
        if ($staff) {
            $this->db->exec("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'support'", [$id]);
        }
        return $id;
    }

    public function testCustomerSignsInWithPhoneOrEmail(): void
    {
        $this->account('achieng@example.com', '254712345678');
        $form = $this->get('/login')['body'];
        $this->assertStringContainsString('Email or phone number', $form);
        $this->assertStringContainsString('name="login"', $form);
        $this->assertStringContainsString('autocomplete="username"', $form);

        foreach (['0712 345 678', '+254712345678', 'achieng@example.com'] as $login) {
            $r = $this->post('/login', ['login' => $login, 'password' => 'Secret123'], '/login');
            $this->assertSame(303, $r['status'], $login);
            $this->assertStringContainsString('Welcome back, Achieng', $this->follow($r)['body'], $login);
            $this->post('/logout', [], '/account');
        }
        // Older forms posting "email" still work.
        $this->assertStringContainsString('Welcome back', $this->follow($this->post('/login', ['email' => 'achieng@example.com', 'password' => 'Secret123'], '/login'))['body']);
        $this->post('/logout', [], '/account');

        $bad = $this->follow($this->post('/login', ['login' => '0712 345 678', 'password' => 'Wrong1234'], '/login'))['body'];
        $this->assertStringContainsString('Incorrect email, phone number or password.', $bad);
        $this->assertStringContainsString('value="0712 345 678"', $bad, 'what they typed is kept (never the password)');
        $unknown = $this->follow($this->post('/login', ['login' => '0799 999 999', 'password' => 'Wrong1234'], '/login'))['body'];
        $this->assertStringContainsString('Incorrect email, phone number or password.', $unknown, 'same message: no hint whether the number is registered');
    }

    public function testStaffSignInWithPhone(): void
    {
        $this->account('ops@example.com', '254733000111', true);
        $this->assertStringContainsString('Email or phone number', $this->get('/admin/login')['body']);
        $r = $this->post('/admin/login', ['login' => '0733 000 111', 'password' => 'Secret123'], '/admin/login');
        $this->assertSame(303, $r['status']);
        $this->assertStringEndsWith('/admin', (string) ($r['headers']['location'] ?? ''));
        $this->post('/logout', [], '/admin');
        $this->account('customer@example.com', '254733000222');
        $r = $this->post('/admin/login', ['login' => '0733 000 222', 'password' => 'Secret123'], '/admin/login');
        $this->assertStringContainsString('Incorrect email, phone number or password.', $this->follow($r)['body'], 'customers cannot use the staff login');
    }

    public function testRegistrationRefusesATakenPhoneNumber(): void
    {
        $this->account('first@example.com', '254712345678');
        $r = $this->post('/register', ['first_name' => 'Second', 'last_name' => 'Person', 'email' => 'second@example.com', 'phone' => '0712 345 678',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $this->assertStringContainsString('An account with this phone number already exists', $this->follow($r)['body']);
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM users WHERE email = 'second@example.com'"));
    }
}
