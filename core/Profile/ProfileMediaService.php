<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
final readonly class ProfileMediaService
{
    public function __construct(private ProfileStore $profiles, private StorageDriver $storage, private int $avatarMaxBytes=2097152, private int $bannerMaxBytes=8388608) {}

    public function replace(EntityId $userId, ProfileMediaKind $kind, string $contents): string
    {
        UserId::assert($userId); $limit=$kind===ProfileMediaKind::Avatar?$this->avatarMaxBytes:$this->bannerMaxBytes;
        $size=strlen($contents); if($size<1||$size>$limit){throw new ProfileException('Profile media exceeds the allowed size.');}
        $info=@getimagesizefromstring($contents); $mime=is_array($info)?($info['mime']??null):null;
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if(!is_string($mime)||!isset($extensions[$mime])){throw new ProfileException('Profile media must be a valid JPEG, PNG or WebP image.');}
        $width=(int)($info[0]??0); $height=(int)($info[1]??0); if($width<1||$height<1||$width>10000||$height>10000){throw new ProfileException('Profile media dimensions are invalid.');}
        $current=$this->profiles->find($userId); $old=$kind===ProfileMediaKind::Avatar?$current?->avatarPath:$current?->bannerPath;
        $relative=sprintf('profiles/%s/%s/%s.%s',$userId->value(),$kind->value,hash('sha256',$contents),$extensions[$mime]);
        $path=StoragePath::fromString($relative); $this->storage->put($path,$contents,StorageVisibility::Private,$mime);
        try { $this->profiles->updateMedia($userId,$kind,$relative); }
        catch(\Throwable $e){ $this->storage->delete($path,StorageVisibility::Private); throw $e; }
        if($old!==null&&$old!==$relative){$this->storage->delete(StoragePath::fromString($old),StorageVisibility::Private);}
        return $relative;
    }

    public function remove(EntityId $userId, ProfileMediaKind $kind): bool
    {
        $current=$this->profiles->find($userId); $old=$kind===ProfileMediaKind::Avatar?$current?->avatarPath:$current?->bannerPath;
        if($old===null){return false;} $this->profiles->updateMedia($userId,$kind,null); return $this->storage->delete(StoragePath::fromString($old),StorageVisibility::Private);
    }
}
