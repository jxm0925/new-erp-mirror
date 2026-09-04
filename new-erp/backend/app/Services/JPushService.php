<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JPushService
{
    private const PUSH_URL = 'https://api.jpush.cn/v3/push';

    public function sendExamAssignment(array $userIds, int $examId, string $examName, ?string $deadlineAt = null): void
    {
        $content = $deadlineAt
            ? "你有一场新的必考考试，请在 {$deadlineAt} 前完成"
            : '你有一场新的必考考试，请及时完成';

        $this->pushToUsers($userIds, '新的考试指派', $content, [
            'type' => 'exam_assignment',
            'exam_id' => $examId,
            'exam_name' => $examName,
            'deadline_at' => $deadlineAt,
        ]);
    }

    public function sendExamResult(int $userId, int $examId, int $attemptId, string $examName, float $score, bool $isPassed): void
    {
        $scoreText = $this->formatScore($score);
        $passText = $isPassed ? '已通过' : '未通过';

        $this->pushToUsers([$userId], '考试成绩已出', "《{$examName}》阅卷完成，成绩 {$scoreText} 分，{$passText}", [
            'type' => 'exam_result',
            'exam_id' => $examId,
            'attempt_id' => $attemptId,
            'exam_name' => $examName,
            'score' => $scoreText,
            'is_passed' => $isPassed,
        ]);
    }

    public function pushToUsers(array $userIds, string $title, string $alert, array $extras = []): void
    {
        $aliases = collect($userIds)
            ->map(fn ($userId) => $this->aliasForUser((int) $userId))
            ->filter()
            ->values()
            ->all();

        if (empty($aliases)) {
            return;
        }

        $this->pushToAliases($aliases, $title, $alert, $extras);
    }

    public function aliasForUser(int $userId): string
    {
        return 'user_' . $userId;
    }

    public function pushToAliases(array $aliases, string $title, string $alert, array $extras = []): void
    {
        $config = config('services.jpush');
        $enabled = filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $appKey = $config['app_key'] ?? '';
        $masterSecret = $config['master_secret'] ?? '';

        if (!$enabled || !$appKey || !$masterSecret) {
            Log::info('JPush skipped because it is not configured.', [
                'aliases' => $aliases,
                'title' => $title,
                'alert' => $alert,
                'extras' => $extras,
            ]);
            return;
        }

        $payload = [
            'platform' => $config['default_platform'] ?: 'all',
            'audience' => ['alias' => array_values($aliases)],
            'notification' => [
                'alert' => $alert,
                'android' => [
                    'alert' => $alert,
                    'title' => $title,
                    'extras' => $extras,
                ],
                'ios' => [
                    'alert' => [
                        'title' => $title,
                        'body' => $alert,
                    ],
                    'sound' => 'default',
                    'badge' => '+1',
                    'extras' => $extras,
                ],
            ],
            'options' => [
                'apns_production' => filter_var($config['apns_production'] ?? false, FILTER_VALIDATE_BOOL),
            ],
        ];

        $response = Http::withBasicAuth($appKey, $masterSecret)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post(self::PUSH_URL, $payload);

        if (!$response->successful()) {
            Log::warning('JPush push failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);
        }
    }

    private function formatScore(float $score): string
    {
        return rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.');
    }
}