<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Security\TokenService;
use FieldPulse\Support\Logger;

final class AgentLoginController implements ActionInterface
{
    private const ENDPOINT = 'auth.agent_login';

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        $imei = Validator::imei($body['imei'] ?? null);
        $ip = $request->clientIp();
        $limits = RateLimiter::limitsFor(self::ENDPOINT);

        RateLimiter::enforce(self::ENDPOINT, $imei, $ip, $limits['limit'], $limits['window']);

        try {
            $agent = $this->agents->findByImei($imei);

            if (
                $agent === null
                || $agent['status'] !== AgentRepository::ACTIVE
                || ($agent['role'] ?? 'AGENT') !== 'AGENT'
            ) {
                throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'IMEI or account is not valid.');
            }

            $tokens = TokenService::i()->issueBootstrap($agent, $request->userAgent());

            $this->audit->recordSafe([
                'actor_agent_id' => (int) $agent['id'],
                'action' => 'auth.agent_login',
                'entity_type' => 'agent',
                'entity_id' => (int) $agent['id'],
                'ip_address' => $ip,
                'metadata' => ['imei_hash' => RateLimiter::hashIdentifier($imei)],
            ]);

            RateLimiter::record(self::ENDPOINT, $imei, $ip, true);

            return Response::json([
                'access_token' => $tokens['access_token'],
                'token_type' => $tokens['token_type'],
                'expires_in' => $tokens['expires_in'],
                'refresh_expires_at' => $tokens['refresh_expires_at'],
                'agent' => [
                    'id' => (int) $agent['id'],
                    'agent_code' => (string) $agent['agent_code'],
                    'full_name' => (string) $agent['full_name'],
                    'role' => 'AGENT',
                ],
                'device_bound' => false,
                'next_step' => 'device.register',
            ])->withCookie(array_merge(
                [
                    'name' => TokenService::refreshCookieName(),
                    'value' => $tokens['refresh_token'],
                ],
                TokenService::refreshCookieAttributes()
            ));
        } catch (ApiException $e) {
            RateLimiter::record(self::ENDPOINT, $imei, $ip, false, $e->errorCode());
            if ($e->status() === 429) {
                throw $e;
            }

            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'IMEI or account is not valid.');
        } catch (\Throwable $e) {
            RateLimiter::record(self::ENDPOINT, $imei, $ip, false, 'LOGIN_FAILED');
            Logger::channel('app')->warning('auth.agent_login_failed', [
                'imei_hash' => RateLimiter::hashIdentifier($imei),
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
