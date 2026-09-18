<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Faq\SupportBridge\FaqSupportBridgeService;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Support\Conversation\SupportCannedResponse;
use Forwext\Core\Support\Conversation\SupportConversationOperationException;
use Forwext\Core\Support\Conversation\SupportConversationRepository;
use Forwext\Core\Support\Conversation\SupportConversationService;
use Forwext\Core\Support\Conversation\SupportTicketNotifier;
use Forwext\Core\Support\Intake\SupportTicketIntakeRepository;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketNotFoundException;
use Forwext\Core\Support\Ticket\SupportTicketOperationException;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use InvalidArgumentException;
use ValueError;

final readonly class SupportTicketDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private SupportTicketRepository $tickets,
        private SupportTicketIntakeRepository $intake,
        private SupportConversationRepository $conversation,
        private FaqSupportBridgeService $faqBridge,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private UserRepository $users,
        private SupportTicketNotifier $notifier,
        private BasePath $basePath,
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
        if (!is_string($ticketValue)) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $ticketId = EntityId::fromString($ticketValue);
            $gate = new PermissionGate($this->authorizer, $actor);
            $ticketService = new SupportTicketService(
                $this->database,
                $this->tickets,
                $gate,
                $this->authorizer,
            );
            $service = new SupportConversationService(
                $this->database,
                $this->tickets,
                $ticketService,
                $this->intake,
                $this->conversation,
                $gate,
                $this->notifier,
            );

            if ($request->method() === HttpMethod::Post) {
                $ticket = $ticketService->ticket($ticketId);
                return $this->mutate($request, $actor, $ticket, $service);
            }

            return $this->view($request, $ticketId, $service, $gate);
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (SupportTicketNotFoundException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        } catch (SupportConversationOperationException|SupportTicketOperationException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function view(
        Request $request,
        EntityId $ticketId,
        SupportConversationService $service,
        PermissionGate $gate,
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $view = $service->view($ticketId);
        $ticket = $view->ticket;
        $description = $this->intake->description($ticketId);
        $faqRecommendations = in_array($ticket->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true)
            ? $this->faqBridge->recommend(
                $gate->actorId(),
                $ticket->categoryKey,
                $ticket->subject . ' ' . ($description ?? ''),
            )
            : [];
        $actorIsRequester = $ticket->isRequester($gate->actorId());
        $canReply = $actorIsRequester
            ? $gate->allows(PermissionKey::fromString('support.ticket.reply_own'))
            : $gate->allows(PermissionKey::fromString(SupportConversationService::REPLY_ALL_PERMISSION));

        $requesterName = $ticket->requesterUserId === null
            ? null
            : $this->users->find($ticket->requesterUserId)?->username()->display();
        $assigneeName = $ticket->assignedUserId === null
            ? null
            : $this->users->find($ticket->assignedUserId)?->username()->display();

        $html = SupportTicketDetailHtml::page(
            $view,
            $description,
            $this->intake->fieldValues($ticketId),
            $this->intake->context($ticketId),
            $this->intake->attachments($ticketId),
            $token,
            $this->basePath,
            new SupportTicketDetailCapabilities(
                $canReply,
                $gate->allows(PermissionKey::fromString(SupportConversationService::INTERNAL_NOTE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(SupportTicketService::ASSIGN_PERMISSION)),
                $gate->allows(PermissionKey::fromString(SupportTicketService::MANAGE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(SupportConversationService::ESCALATE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(SupportConversationService::MERGE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(SupportConversationService::SPLIT_PERMISSION)),
                $gate->allows(PermissionKey::fromString(SupportConversationService::CANNED_MANAGE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(FaqSupportBridgeService::SUGGEST_DRAFT_PERMISSION)),
            ),
            $requesterName,
            $assigneeName,
            ($request->query()['updated'] ?? null) === '1',
            $faqRecommendations,
        );

        return Response::html($html)->withHeader('Cache-Control', 'private, no-store');
    }

    private function mutate(
        Request $request,
        EntityId $actor,
        SupportTicket $ticket,
        SupportConversationService $service,
    ): Response {
        $ticketId = $ticket->ticketId;
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Support ticket action is missing.');
        }

        switch ($action) {
            case 'reply':
                $service->reply(
                    $ticketId,
                    is_string($body['body'] ?? null) ? (string) $body['body'] : '',
                    $this->optionalString($body, 'canned_response_key', 64),
                );
                break;
            case 'note':
                $service->internalNote($ticketId, $this->requiredString($body, 'body', 10000));
                break;
            case 'assign':
                $service->assign($ticketId, $this->assignee($body));
                break;
            case 'status':
                $service->changeStatus(
                    $ticketId,
                    SupportTicketStatus::from($this->requiredString($body, 'status', 24)),
                );
                break;
            case 'escalate':
                $service->escalate($ticketId, $this->requiredInt($body, 'level', 1, 5));
                break;
            case 'merge':
                $service->merge(
                    $ticketId,
                    EntityId::fromString($this->requiredHexId($body, 'target_ticket_id')),
                );
                break;
            case 'split':
                $service->split(
                    $ticketId,
                    EntityId::fromString($this->requiredHexId($body, 'message_id')),
                    $this->requiredString($body, 'subject', 200),
                );
                break;
            case 'faq_draft':
                $messageId = EntityId::fromString($this->requiredHexId($body, 'message_id'));
                $message = $this->conversation->message($messageId)
                    ?? throw new InvalidArgumentException('FAQ draft source message was not found.');
                $this->faqBridge->suggestDraft(
                    $actor,
                    $ticket,
                    $message,
                    $this->optionalString($body, 'faq_category_key', 64),
                );
                break;
            case 'canned_save':
                $service->saveCannedResponse(new SupportCannedResponse(
                    strtolower($this->requiredString($body, 'key', 64)),
                    $this->requiredString($body, 'title', 120),
                    $this->requiredString($body, 'body', 10000),
                    ($body['active'] ?? null) === '1'
                        || ($body['active'] ?? null) === 1
                        || ($body['active'] ?? null) === true,
                    $this->optionalInt($body, 'sort_order', 0, 65535, 100),
                ));
                break;
            default:
                throw new InvalidArgumentException('Unknown support ticket action.');
        }

        return Response::text('', 303)
            ->withHeader(
                'Location',
                $this->basePath->prepend('/support/tickets/' . rawurlencode($ticketId->value()) . '?updated=1'),
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $body */
    private function assignee(array $body): ?EntityId
    {
        $value = $body['assignee_username'] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Support assignee username is invalid.');
        }
        $user = $this->users->findByUsername(Username::fromString($value));
        if ($user === null) {
            throw new InvalidArgumentException('Support assignee was not found.');
        }
        return $user->id();
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key, int $maxBytes): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Support action field is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException('Support action field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function optionalString(array $body, string $key, int $maxBytes): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException('Support optional action field is invalid: ' . $key);
        }
        return trim($value) === '' ? null : trim($value);
    }

    /** @param array<string,mixed> $body */
    private function requiredHexId(array $body, string $key): string
    {
        $value = $this->requiredString($body, $key, 32);
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Support entity id is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function requiredInt(array $body, string $key, int $min, int $max): int
    {
        $value = filter_var($body[$key] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Support integer field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function optionalInt(array $body, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return $default;
        }
        return $this->requiredInt($body, $key, $min, $max);
    }
}
