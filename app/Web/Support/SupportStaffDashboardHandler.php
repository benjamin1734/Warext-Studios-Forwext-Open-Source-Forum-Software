<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Reporting\SupportReportingRepository;
use Forwext\Core\Support\Reporting\SupportReportingService;
use Forwext\Core\Support\Ticket\SupportTicketRepository;

final readonly class SupportStaffDashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private SupportTicketRepository $tickets,
        private SupportReportingRepository $reporting,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
    ) {}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null) return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        try{
            $service=new SupportReportingService(
                $this->tickets,
                $this->reporting,
                new PermissionGate($this->authorizer,$actor),
            );
            return Response::html(SupportStaffDashboardHtml::page($service->staffDashboard(),$this->basePath))
                ->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex, nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }
    }
}
