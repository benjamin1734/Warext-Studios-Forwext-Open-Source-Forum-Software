<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
enum ProfileVisibility: string { case Public = 'public'; case Members = 'members'; case Private = 'private'; }
