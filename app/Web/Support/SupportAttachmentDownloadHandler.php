<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Forum\AttachmentDownloadResponseFactory;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Support\Conversation\SupportConversationOperationException;
use Forwext\Core\Support\Intake\SupportAttachmentDownloadService;
use Forwext\Core\Support\Intake\SupportTicketIntakeRepository;
use Forwext\Core\Support\Ticket\SupportTicketNotFoundException;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use InvalidArgumentException;

final readonly class SupportAttachmentDownloadHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private SupportTicketRepository $tickets,
        private SupportTicketIntakeRepository $intake,
        private StorageDriver $storage,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private AttachmentDownloadResponseFactory $responses,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $ticketValue = is_array($parameters) ? ($parameters['ticketId'] ?? null) : null;
        $attachmentValue = is_array($parameters) ? ($parameters['attachmentId'] ?? null) : null;
        if (!is_string($ticketValue) || !is_string($attachmentValue)) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $gate = new PermissionGate($this->authorizer, $actor);
            $ticketService = new SupportTicketService(
                $this->database,
                $this->tickets,
                $gate,
                $this->authorizer,
            );
            $download = (new SupportAttachmentDownloadService(
                $ticketService,
                $this->intake,
                $this->storage,
            ))->download(
                EntityId::fromString($ticketValue),
                EntityId::fromString($attachmentValue),
            );
            return $this->responses->create($download);
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|SupportTicketNotFoundException|SupportConversationOperationException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
    }
}
