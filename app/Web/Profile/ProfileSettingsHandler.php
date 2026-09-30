<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Upload\UploadedFile;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileMediaService;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class ProfileSettingsHandler implements RequestHandlerInterface
{
    private const AVATAR_MAX_BYTES = 8_388_608;
    private const BANNER_MAX_BYTES = 16_777_216;

    public function __construct(
        private UserRepository $users,
        private ProfileService $profiles,
        private ProfileMediaService $media,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $user = $this->users->find($actor);
        if ($user === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $profile = $this->profiles->getOrDefault($actor, $now);

        if ($request->method() === HttpMethod::Post) {
            try {
                $this->submit($request, $profile, $now);
                return Response::redirect($this->basePath->prepend('/account/profile?updated=1'), 303)
                    ->withHeader('Cache-Control', 'no-store');
            } catch (InvalidArgumentException|ValueError|ProfileException|HttpException) {
                return $this->view($request, $profile, $user->username()->display(), true);
            }
        }

        return $this->view(
            $request,
            $profile,
            $user->username()->display(),
            false,
            ($request->query()['updated'] ?? null) === '1',
        );
    }

    private function submit(Request $request, UserProfile $profile, DateTimeImmutable $now): void
    {
        $actor = $profile->userId;
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Profile settings action is missing.');
        }

        if ($action === 'save_profile') {
            $about = $body['about'] ?? null;
            $profileVisibility = $body['profile_visibility'] ?? null;
            $aboutVisibility = $body['about_visibility'] ?? null;
            $socialVisibility = $body['social_visibility'] ?? null;
            $mediaVisibility = $body['media_visibility'] ?? null;
            foreach ([$about, $profileVisibility, $aboutVisibility, $socialVisibility, $mediaVisibility] as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Profile settings form is incomplete.');
                }
            }

            $this->profiles->update(
                $actor,
                $actor,
                $about,
                ProfileVisibility::from($profileVisibility),
                ProfileVisibility::from($aboutVisibility),
                ProfileVisibility::from($socialVisibility),
                ProfileVisibility::from($mediaVisibility),
                $profile->socialLinks,
                $profile->tabs,
                $now,
            );
            return;
        }

        $kind = match ($action) {
            'upload_avatar', 'remove_avatar' => ProfileMediaKind::Avatar,
            'upload_banner', 'remove_banner' => ProfileMediaKind::Banner,
            default => throw new InvalidArgumentException('Profile settings action is invalid.'),
        };

        if (str_starts_with($action, 'remove_')) {
            $this->media->remove($actor, $actor, $kind);
            return;
        }

        $upload = $request->uploads()['media'] ?? null;
        if (!$upload instanceof UploadedFile) {
            throw new InvalidArgumentException('Profile media upload is missing.');
        }

        $this->media->replace(
            $actor,
            $actor,
            $kind,
            $this->readUpload(
                $upload,
                $kind === ProfileMediaKind::Avatar ? self::AVATAR_MAX_BYTES : self::BANNER_MAX_BYTES,
            ),
        );
    }

    private function view(
        Request $request,
        UserProfile $profile,
        string $username,
        bool $error,
        bool $updated = false,
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        return Response::html(ProfileSettingsHtml::page(
            $profile,
            $username,
            $token,
            $this->basePath,
            $updated,
            $error,
        ), $error ? 422 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    private function readUpload(UploadedFile $file, int $maxBytes): string
    {
        if (!$file->isSuccessful() || $file->size < 1 || $file->size > $maxBytes) {
            throw new HttpException('Profile media upload is invalid.');
        }
        if (!is_uploaded_file($file->temporaryPath)) {
            throw new HttpException('Profile media source is not a verified PHP HTTP upload.');
        }

        $handle = @fopen($file->temporaryPath, 'rb');
        if ($handle === false) {
            throw new HttpException('Profile media upload cannot be opened.');
        }

        $contents = '';
        try {
            while (!feof($handle)) {
                $remaining = ($maxBytes + 1) - strlen($contents);
                if ($remaining <= 0) {
                    throw new HttpException('Profile media upload exceeds the maximum size.');
                }
                $chunk = fread($handle, min(1_048_576, $remaining));
                if ($chunk === false) {
                    throw new HttpException('Profile media upload cannot be read.');
                }
                $contents .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        if (strlen($contents) !== $file->size) {
            throw new HttpException('Profile media upload size does not match PHP metadata.');
        }

        return $contents;
    }
}
