<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Forum\UploadedAttachmentReader;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
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
use Forwext\Core\Support\Conversation\SupportConversationRepository;
use Forwext\Core\Support\Intake\SupportContextRegistry;
use Forwext\Core\Support\Intake\SupportContextType;
use Forwext\Core\Support\Intake\SupportContextUnavailableException;
use Forwext\Core\Support\Intake\SupportSubmissionPolicy;
use Forwext\Core\Support\Intake\SupportSubmissionRateLimitException;
use Forwext\Core\Support\Intake\SupportSubmissionRateLimiter;
use Forwext\Core\Support\Intake\SupportTicketIntakeRepository;
use Forwext\Core\Support\Intake\SupportTicketSubmissionService;
use Forwext\Core\Support\Intake\SupportUpload;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use InvalidArgumentException;
use ValueError;

final readonly class SupportTicketFormHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private SupportTicketRepository $tickets,
        private SupportTicketIntakeRepository $intake,
        private SupportConversationRepository $conversation,
        private SupportSubmissionRateLimiter $rateLimiter,
        private SupportContextRegistry $contexts,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private AttachmentQuotaPolicy $attachmentQuota,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private UploadedAttachmentReader $uploadedFiles,
        private BasePath $basePath,
        private SupportSubmissionPolicy $policy = new SupportSubmissionPolicy(),
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewerId = $this->viewers->resolve($request);
        if ($viewerId === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $gate = new PermissionGate($this->authorizer, $viewerId);
        $ticketService = new SupportTicketService(
            $this->database,
            $this->tickets,
            $gate,
            $this->authorizer,
        );
        $submission = new SupportTicketSubmissionService(
            $this->database,
            $ticketService,
            $this->intake,
            $this->contexts,
            $this->rateLimiter,
            $this->storage,
            $this->inspector,
            $gate,
            $this->policy,
            $this->conversation,
        );

        try {
            if ($request->method() === HttpMethod::Post) {
                return $this->submit($request, $submission);
            }
            return $this->view($request, $ticketService, $submission);
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (SupportSubmissionRateLimitException) {
            return Response::text('Too Many Requests', 429)
                ->withHeader('Retry-After', (string) $this->policy->duplicateWindowSeconds)
                ->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|ValueError|SupportContextUnavailableException|AttachmentOperationException|HttpException) {
            $category = $request->parsedBody()['category'] ?? $request->query()['category'] ?? null;
            $suffix = is_string($category) && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', strtolower($category)) === 1
                ? '&category=' . rawurlencode(strtolower($category))
                : '';
            return Response::text('', 303)
                ->withHeader('Location', $this->basePath->prepend('/support/new?error=1' . $suffix))
                ->withHeader('Cache-Control', 'no-store');
        }
    }

    private function view(
        Request $request,
        SupportTicketService $tickets,
        SupportTicketSubmissionService $submission,
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $categories = $tickets->categories();
        $selected = $this->selectedCategory($categories, $request->query()['category'] ?? null);
        $fields = $selected === null ? [] : $submission->fields($selected->key);
        [$contextType, $contextId] = $this->queryContext($request);

        $created = $request->query()['created'] ?? null;
        $created = is_string($created) && preg_match('/^[a-f0-9]{32}$/D', $created) === 1 ? $created : null;

        return Response::html(SupportTicketFormHtml::page(
            $categories,
            $selected,
            $fields,
            $token,
            $this->basePath,
            $created,
            ($request->query()['error'] ?? null) === '1',
            $contextType,
            $contextId,
        ))->withHeader('Cache-Control', 'private, no-store');
    }

    private function submit(Request $request, SupportTicketSubmissionService $submission): Response
    {
        $body = $request->parsedBody();
        $category = $this->requiredString($body, 'category', 64);
        $subject = $this->requiredString($body, 'subject', 200);
        $description = $this->requiredString($body, 'description', $this->policy->descriptionMaxBytes);

        $fields = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'field_')) {
                $fieldKey = substr($key, 6);
                if ($fieldKey === '' || isset($fields[$fieldKey])) {
                    throw new InvalidArgumentException('Support field key is invalid.');
                }
                $fields[$fieldKey] = $value;
            }
        }

        $contextTypeRaw = $body['context_type'] ?? null;
        $contextIdRaw = $body['context_id'] ?? null;
        $contextType = null;
        $contextId = null;
        if (is_string($contextTypeRaw) && trim($contextTypeRaw) !== '') {
            $contextType = SupportContextType::from(trim($contextTypeRaw));
            if (!is_string($contextIdRaw) || preg_match('/^[a-f0-9]{32}$/D', trim($contextIdRaw)) !== 1) {
                throw new InvalidArgumentException('Support context id is invalid.');
            }
            $contextId = EntityId::fromString(trim($contextIdRaw));
        } elseif (is_string($contextIdRaw) && trim($contextIdRaw) !== '') {
            throw new InvalidArgumentException('Support context type is required when an id is supplied.');
        }

        $uploads = [];
        foreach ($this->flattenUploads($request->uploads()['attachments'] ?? []) as $file) {
            if ($file->error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (count($uploads) >= $this->policy->maxAttachments) {
                throw new InvalidArgumentException('Support attachment count exceeds the configured limit.');
            }
            $filename = $file->clientFilename;
            if (!is_string($filename) || trim($filename) === '') {
                $filename = 'attachment';
            }
            $uploads[] = new SupportUpload(
                $filename,
                $this->uploadedFiles->read($file, $this->attachmentQuota->maxFileBytes),
            );
        }

        $receipt = $submission->submit(
            $category,
            $subject,
            $description,
            $fields,
            $contextType,
            $contextId,
            $uploads,
        );

        return Response::text('', 303)
            ->withHeader(
                'Location',
                $this->basePath->prepend('/support/tickets/' . rawurlencode($receipt->ticket->ticketId->value())),
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param list<SupportCategory> $categories
     */
    private function selectedCategory(array $categories, mixed $requested): ?SupportCategory
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

    /** @return array{?SupportContextType,?string} */
    private function queryContext(Request $request): array
    {
        $type = $request->query()['context_type'] ?? null;
        $id = $request->query()['context_id'] ?? null;
        if (!is_string($type) || !is_string($id)
            || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
        ) {
            return [null, null];
        }
        try {
            return [SupportContextType::from($type), $id];
        } catch (ValueError) {
            return [null, null];
        }
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
            throw new InvalidArgumentException('Support upload collection is invalid.');
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
            throw new InvalidArgumentException('Support form field is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException('Support form field is invalid: ' . $key);
        }
        return $value;
    }
}
