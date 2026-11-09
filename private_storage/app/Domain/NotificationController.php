<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\NotificationRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET  /api/v1/notifications.php        the caller's notification history
 * POST /api/v1/notifications/read.php   mark one, or all, as read
 *
 * Both routes are bearer-only and scoped to the caller's own agent_id. A
 * notification id belonging to another agent is treated as unknown rather than
 * as a 403, so this endpoint cannot be used to probe whether an id exists.
 */
final class NotificationController implements ActionInterface
{
    public function __construct(
        private readonly NotificationRepository $notifications = new NotificationRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $request->requireAuth();

        if ($request->endpoint() === 'read') {
            return $this->markRead($request, $context->agentId());
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $limit  = max(1, min(100, $request->queryInt('limit') ?? 50));
        $offset = max(0, min(100000, $request->queryInt('offset') ?? 0));

        return Response::json([
            'data' => $this->presentMany($this->notifications->listForAgent($context->agentId(), $limit, $offset)),
            'meta' => [
                'unread_count' => $this->notifications->unreadCount($context->agentId()),
                'pagination'   => [
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
            ],
        ]);
    }

    private function markRead(Request $request, int $agentId): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['id', 'mark_all'], 'notifications.read');

        $id = $body['id'] ?? null;

        if ($id !== null) {
            $notificationId = Validator::intRange($id, 1, PHP_INT_MAX, 'id');
            $this->notifications->markRead($agentId, $notificationId);
        } else {
            $this->notifications->markAllRead($agentId);
        }

        return Response::json([
            'data' => [
                'unread_count' => $this->notifications->unreadCount($agentId),
            ],
        ]);
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function presentMany(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'type'        => (string) $row['type'],
            'title'       => (string) $row['title'],
            'message'     => (string) $row['message'],
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id'   => $row['entity_id'] === null ? null : (int) $row['entity_id'],
            'read_at'     => $row['read_at'] ?? null,
            'created_at'  => (string) $row['created_at'],
        ], $rows);
    }
}
