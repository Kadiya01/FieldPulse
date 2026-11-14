<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminSubmissionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Imaging\ImageInspector;
use FieldPulse\Support\Paths;

/** GET /api/v1/abh/admin/submission-evidence.php?id=... */
final class AbhAdminSubmissionEvidenceController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('submissions.read');

        $submissionId = $request->queryInt('id');
        if ($submissionId === null || $submissionId < 1) {
            throw ApiException::validation('A valid submission id is required.', ['field' => 'id']);
        }

        $evidence = AbhAdminSubmissionRepository::evidence($submissionId);
        if ($evidence === null) {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Evidence not found.');
        }

        try {
            $absolute = Paths::absoluteForStoredPath((string) $evidence['storage_key']);
            $root = realpath(Paths::storageRoot());
            $real = realpath($absolute);
            if (
                $root === false
                || $real === false
                || is_link($absolute)
                || !is_file($real)
                || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ) {
                throw new \RuntimeException('Stored evidence path is not a regular private file.');
            }

            $inspection = ImageInspector::inspectPath($real);
            $hash = ImageInspector::sha256($real);
            if (
                !hash_equals((string) $evidence['sha256'], $hash)
                || (string) $inspection['mime'] !== (string) $evidence['mime_type']
                || (int) $inspection['size'] !== (int) $evidence['byte_size']
            ) {
                throw new \RuntimeException('Stored evidence integrity check failed.');
            }

            $bytes = file_get_contents($real);
            if (!is_string($bytes)) {
                throw new \RuntimeException('Stored evidence could not be read.');
            }

            return Response::binary($bytes, (string) $inspection['mime']);
        } catch (ApiException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Evidence is unavailable.');
        }
    }
}
