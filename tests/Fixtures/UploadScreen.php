<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\Server;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class UploadScreen extends ServerComponent
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    public string $caption = '';

    public ?string $photo = null;

    public int $count = 0;

    #[Upload(maxSize: '1K', mimes: ['image/png', 'image/jpeg'])]
    public function savePhoto(UploadedFile $file, string $caption = ''): void
    {
        static::record('savePhoto', $file);
        $this->photo = $file->getClientOriginalName();
        $this->caption = $caption;
    }

    #[Upload]
    public function anyFile(UploadedFile $file): void
    {
        static::record('anyFile', $file);
    }

    #[Upload(mimes: ['image/*'])]
    public function anyImage(UploadedFile $file): void
    {
        static::record('anyImage', $file);
    }

    #[Upload]
    public function withRefresh(UploadedFile $file): void
    {
        static::record('withRefresh', $file);
        $this->count++;
        $this->refresh('gallery');
        $this->refresh(HomeScreen::class, 'greeting');
    }

    #[Upload]
    public function moveIt(UploadedFile $file): void
    {
        static::record('moveIt', $file);
        $file->move(sys_get_temp_dir(), 'asgard-moved-'.getmypid());
    }

    #[Upload]
    public function takesTwo(UploadedFile $file, int $a, int $b): void
    {
        static::record('takesTwo', $file);
    }

    #[Server]
    public function serverOnly(): void
    {
        static::$calls[] = ['method' => 'serverOnly'];
    }

    #[Action]
    public function actionOnly(): void
    {
        static::$calls[] = ['method' => 'actionOnly'];
    }

    public function unmarked(UploadedFile $file): void
    {
        static::record('unmarked', $file);
    }

    #[Upload]
    public function noFile(string $name): void
    {
        static::$calls[] = ['method' => 'noFile'];
    }

    #[Upload]
    public function nullableFile(?UploadedFile $file): void
    {
        static::$calls[] = ['method' => 'nullableFile'];
    }

    #[Upload]
    public function stringFirst(string $name, UploadedFile $file): void
    {
        static::$calls[] = ['method' => 'stringFirst'];
    }

    #[Upload]
    protected function hidden(UploadedFile $file): void
    {
        static::record('hidden', $file);
    }

    #[Region('gallery')]
    public function gallery(): Node
    {
        return Node::text("Photos: {$this->count}");
    }

    protected static function record(string $method, UploadedFile $file): void
    {
        static::$calls[] = [
            'method' => $method,
            'path' => $file->getPathname(),
            'exists' => is_file($file->getPathname()),
            'valid' => $file->isValid(),
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'client_mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'bytes' => file_get_contents($file->getPathname()),
        ];
    }
}
