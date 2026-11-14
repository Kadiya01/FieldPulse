<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhNotificationRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET/POST /api/v1/abh/admin/notifications.php */
final class AbhAdminNotificationsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();

        if ($request->method() === 'GET') {
            $limit = $request->queryInt('limit', 25) ?? 25;
            $offset = $request->queryInt('offset', 0) ?? 0;
            if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
                throw ApiException::validation('Invalid pagination values.', ['field' => 'limit']);
            }
            return Response::json([
                'data' => AbhNotificationRepository::listForAdmin($admin->id(), $limit, $offset),
            ]);
        }

        if ($request->method() === 'POST') {
            $body = $request->json();
            Validator::assertNoUnknownKeys($body, ['notification_id', 'mark_all'], 'notification read request');
            $markAll = ($body['mark_all'] ?? false) === true;
            $notificationId = null;
            if (!$markAll) {
                $notificationId = Validator::intRange($body['notification_id'] ?? null, 1, 2147483647, 'notification_id');
            } elseif (array_key_exists('notification_id', $body)) {
                throw ApiException::validation('Do not provide a notification_id when mark_all is true.');
            }

            $updated = AbhNotificationRepository::markAdminRead($admin->id(), $notificationId);
            return Response::json(['data' => ['marked_read' => $updated]]);
        }

        throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
    }
}
