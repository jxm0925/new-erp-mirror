<?php

namespace Tests\Feature\Erp;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LegacySsoConcurrencyTest extends TestCase
{
    private const SECRET = 'phase6b1-sso-concurrency-secret';

    public function test_two_real_php_processes_can_only_consume_the_same_ticket_once(): void
    {
        $legacyId = 992901;
        $nonce = bin2hex(random_bytes(16));
        $ticket = $this->ticket($legacyId, $nonce);
        $database = (string) config('database.connections.mysql.database');

        $this->cleanup($legacyId, $nonce);

        $environment = array_merge($_ENV, [
            'ERP_SSO_PROBE_DATABASE' => $database,
            'ERP_SSO_PROBE_SECRET' => self::SECRET,
            'APP_ENV' => 'testing',
        ]);
        $command = [PHP_BINARY, base_path('tests/Support/consume_sso_ticket.php'), $ticket];
        $first = new Process($command, base_path(), $environment);
        $second = new Process($command, base_path(), $environment);

        try {
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $statuses = [json_decode($first->getOutput(), true)['status'] ?? null, json_decode($second->getOutput(), true)['status'] ?? null];
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(1, DB::table('erp_sso_ticket_consumptions')->where('nonce', $nonce)->count());
            $this->assertSame(1, DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->count());
            $this->assertSame(1, DB::table('erp_rbac_user_roles')->where('user_legacy_id', $legacyId)->count());
        } finally {
            $this->cleanup($legacyId, $nonce);
        }
    }

    private function ticket(int $legacyId, string $nonce): string
    {
        $issuedAt = time();
        $payload = [
            'issuer' => 'fastadmin', 'admin_id' => $legacyId, 'username' => 'sso-concurrent-'.$legacyId,
            'nickname' => '并发账号', 'status' => 'normal', 'issued_at' => $issuedAt,
            'expire_at' => $issuedAt + 300, 'nonce' => $nonce,
            'departments' => [['id' => 72901, 'name' => '生产部', 'is_principal' => false]],
            'auth_groups' => [['id' => 82901, 'name' => '普通员工']],
            'is_sales' => false, 'is_super_admin' => false,
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, self::SECRET);
    }

    private function cleanup(int $legacyId, string $nonce): void
    {
        DB::table('erp_auth_tokens')->where('user_legacy_id', $legacyId)->delete();
        DB::table('erp_rbac_user_role_sources')->where('user_legacy_id', $legacyId)->delete();
        DB::table('erp_rbac_user_roles')->where('user_legacy_id', $legacyId)->delete();
        DB::table('erp_department_users')->where('user_legacy_id', $legacyId)->delete();
        DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->delete();
        DB::table('erp_departments')->where('legacy_id', 72901)->delete();
        DB::table('erp_sso_ticket_consumptions')->where('nonce', $nonce)->delete();
    }
}
