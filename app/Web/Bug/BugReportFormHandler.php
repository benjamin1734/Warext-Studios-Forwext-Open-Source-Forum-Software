<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Forum\UploadedAttachmentReader;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextCollector;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextRepository;
use Forwext\Core\Bug\Diagnostic\BugReportSubmissionService;
use Forwext\Core\Bug\Intake\BugReportFormPolicy;
use Forwext\Core\Bug\Intake\BugReportFormSubmissionService;
use Forwext\Core\Bug\Intake\BugReportIntakeRepository;
use Forwext\Core\Bug\Intake\BugUpload;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Upload\UploadedFile;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Storage\StorageDriver;
use InvalidArgumentException;

final readonly class BugReportFormHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private BugDiagnosticContextRepository $diagnostics,
        private BugReportIntakeRepository $intake,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private AttachmentQuotaPolicy $attachmentQuota,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private UploadedAttachmentReader $uploadedFiles,
        private BasePath $basePath,
        private BugDiagnosticContextCollector $collector,
        private BugReportFormPolicy $policy = new BugReportFormPolicy(),
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewerId = $this->viewers->resolve($request);
        if ($viewerId === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $gate = new PermissionGate($this->authorizer, $viewerId);
        $reports = new BugReportService(
            $this->database,
            $this->reports,
            $gate,
            $this->authorizer,
        );
        $submission = new BugReportFormSubmissionService(
            $this->database,
            new BugReportSubmissionService(
                $this->database,
                $reports,
                $this->collector,
                $this->diagnostics,
            ),
            $this->intake,
            $this->storage,
            $this->inspector,
            $this->policy,
        );

        try {
            if ($request->method() === HttpMethod::Post) {
                return $this->submit($request, $submission);
            }
            return $this->view($request, $reports);
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|AttachmentOperationException|HttpException) {
            $category = $request->parsedBody()['category'] ?? $request->query()['category'] ?? null;
            $suffix = is_string($category)
                && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', strtolower($category)) === 1
                ? '&category=' . rawurlencode(strtolower($category))
                : '';

            return Response::text('', 303)
                ->withHeader('Location', $this->basePath->prepend('/bugs/new?error=1' . $suffix))
                ->withHeader('Cache-Control', 'no-store');
        }
    }

    private function view(Request $request, BugReportService $reports): Response
    {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $categories = $reports->categories();
        $selected = $this->selectedCategory($categories, $request->query()['category'] ?? null);
        $created = $request->query()['created'] ?? null;
        $created = is_string($created) && preg_match('/^[a-f0-9]{32}$/D', $created) === 1 ? $created : null;
        $sourcePath = $this->safeSourcePath($request->query()['source_path'] ?? null);

        return Response::html(BugReportFormHtml::page(
            $categories,
            $selected,
            $token,
            $this->basePath,
            $created,
            ($request->query()['error'] ?? null) === '1',
            $sourcePath,
        ))->withHeader('Cache-Control', 'private, no-store');
    }

    private function submit(Request $request, BugReportFormSubmissionService $submission): Response
    {
        $body = $request->parsedBody();
        $category = $this->requiredString($body, 'category', 64);
        $title = $this->requiredString($body, 'title', 200);
        $summary = $this->requiredString($body, 'summary', 5_000);
        $reproductionSteps = $this->requiredString(
            $body,
            'reproduction_steps',
            $this->policy->reproductionMaxBytes,
        );
        $expectedResult = $this->requiredString(
            $body,
            'expected_result',
            $this->policy->expectedMaxBytes,
        );
        $actualResult = $this->requiredString(
            $body,
            'actual_result',
            $this->policy->actualMaxBytes,
        );

        $sourceRaw = $body['source_path'] ?? null;
        if ($sourceRaw !== null && !is_string($sourceRaw)) {
            throw new InvalidArgumentException('Bug source path is invalid.');
        }

        $uploads = [];
        foreach ($this->flattenUploads($request->uploads()['attachments'] ?? []) as $file) {
            if ($file->error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (count($uploads) >= $this->policy->maxAttachments) {
                throw new InvalidArgumentException('Bug attachment count exceeds the configured limit.');
            }
            $filename = $file->clientFilename;
            if (!is_string($filename) || trim($filename) === '') {
                $filename = 'attachment';
            }
            $uploads[] = new BugUpload(
                $filename,
                $this->uploadedFiles->read($file, $this->attachmentQuota->maxFileBytes),
            );
        }

        $receipt = $submission->submit(
            $request,
            $category,
            $title,
            $summary,
            $reproductionSteps,
            $expectedResult,
            $actualResult,
            $sourceRaw,
            $uploads,
        );

        return Response::text('', 303)
            ->withHeader(
                'Location',
                $this->basePath->prepend('/bugs/new?created=' . rawurlencode($receipt->report->reportId->value())),
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param list<BugReportCategory> $categories
     */
    private function selectedCategory(array $categories, mixed $requested): ?BugReportCategory
    {
        if ($categories === []) {
            return null;
        }
        $key = is_string($requested) ? strtolower(trim($requested)) : '';
        if ($key !== '') {
            foreach ($categories as $category) {
                if ($category->key === $key) {
                    return $category;
                }
            }
        }
        return $categories[0];
    }

    private function safeSourcePath(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $value = explode('#', $value, 2)[0];
        $value = explode('?', $value, 2)[0];
        if (
            $value === ''
            || strlen($value) > $this->policy->sourcePathMaxBytes
            || !str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }
        return $value;
    }

    /**
     * @return list<UploadedFile>
     */
    private function flattenUploads(mixed $value): array
    {
        if ($value instanceof UploadedFile) {
            return [$value];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Bug upload collection is invalid.');
        }

        $files = [];
        foreach ($value as $item) {
            foreach ($this->flattenUploads($item) as $file) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key, int $maxBytes): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Bug form field is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException('Bug form field is invalid: ' . $key);
        }
        return $value;
    }
}
