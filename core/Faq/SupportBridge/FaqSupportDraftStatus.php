<?php
declare(strict_types=1);
namespace Forwext\Core\Faq\SupportBridge;
enum FaqSupportDraftStatus:string { case Pending='pending'; case Applied='applied'; case Rejected='rejected'; }
