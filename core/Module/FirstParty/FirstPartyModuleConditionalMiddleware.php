<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class FirstPartyModuleConditionalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $moduleKey,
        private FirstPartyModuleRegistry $registry,
        private FirstPartyModuleRepository $repository,
        private MiddlewareInterface $inner,
    ) {
        $this->registry->require($this->moduleKey);
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        if ($this->repository->state($this->moduleKey)->state !== FirstPartyModuleState::Enabled) {
            return $next->handle($request);
        }

        return $this->inner->process($request, $next);
    }
}
