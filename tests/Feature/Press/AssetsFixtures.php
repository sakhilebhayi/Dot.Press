<?php

namespace Tests\Feature\Press;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Real file bytes for the asset tests: every fixture is built here, nothing is a stub that the pipeline would
 * wave through.
 */
trait AssetsFixtures
{
    protected User $owner;

    protected Project $project;

    protected function setUpAssets(): void
    {
        config()->set('filesystems.asset_upload_disk', 'local');
        Storage::fake('local');

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->project = Project::create([
            'user_id' => $this->owner->id,
            'team_id' => $this->owner->currentTeam->id,
            'name' => 'Dot.Press Workspace',
            'slug' => 'dot-press-workspace',
        ]);
    }

    /** A user who is a member of the owner's team with the given role. */
    protected function memberWithRole(string $role, ?Team $team = null): User
    {
        $team ??= $this->owner->currentTeam;
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return $member;
    }

    protected function outsider(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function upload(User $user, string $bytes, string $name, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->post('/api/assets', $extra + [
            'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json']);
    }

    // ---- images -------------------------------------------------------------------------------------------

    protected function jpegBytes(int $width = 40, int $height = 30, int $quality = 85): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 40, 40));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 30, 90, 220));
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** A JPEG that says, in its EXIF block, how it must be turned to be upright. */
    protected function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $jpeg = $this->jpegBytes($width, $height);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08".pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0).pack('N', 0);
        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    protected function pngBytes(int $width = 40, int $height = 30, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, $transparent ? 127 : 0));
        imagefilledrectangle($image, 2, 2, $width - 3, $height - 3, imagecolorallocatealpha($image, 20, 160, 90, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function webpBytes(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagewebp($image, null, 80);

        return (string) ob_get_clean();
    }

    protected function staticGifBytes(int $width = 20, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 240, 180, 20));
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /** Two frames of the smallest valid GIF, 1 x 1. */
    protected function animatedGifBytes(): string
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";

        return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF".$frame.$frame.';';
    }

    // ---- svg ----------------------------------------------------------------------------------------------

    protected function cleanSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100" width="200" height="100"><defs><linearGradient id="g"><stop offset="0" stop-color="#2563eb"/><stop offset="1" stop-color="#f59e0b"/></linearGradient></defs><rect x="10" y="10" width="180" height="80" rx="8" fill="url(#g)"/><text x="20" y="60" font-size="24">Hello</text></svg>';
    }

    protected function hostileSvg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100" height="100" onload="alert(1)">'
            .'<script>alert(1)</script>'
            .'<foreignObject width="100" height="100"><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="https://evil.example"></iframe></body></foreignObject>'
            .'<a xlink:href="javascript:alert(2)"><rect width="50" height="50" onclick="alert(3)" fill="red"/></a>'
            .'<image xlink:href="https://evil.example/track.png" width="10" height="10"/>'
            .'<use xlink:href="https://evil.example/x.svg#a"/>'
            .'<rect width="10" height="10" style="fill:url(https://evil.example/x)"/>'
            .'<rect width="10" height="10" fill="url(data:image/svg+xml;base64,AAAA)"/>'
            .'<animate attributeName="href" values="javascript:alert(4)"/>'
            .'<circle cx="5" cy="5" r="5" fill="#00ff00"/>'
            .'</svg>';
    }

    // ---- media --------------------------------------------------------------------------------------------

    protected function mp4Bytes(string $brand = 'isom'): string
    {
        return pack('N', 24).'ftyp'.$brand.pack('N', 512).'isom'.'mp41'
            .pack('N', 8).'moov'
            .pack('N', 8 + 64).'mdat'.str_repeat("\x01", 64);
    }

    protected function movBytes(): string
    {
        return pack('N', 20).'ftyp'.'qt  '.pack('N', 0).'qt  '.pack('N', 8).'moov';
    }

    protected function m4aBytes(): string
    {
        return pack('N', 24).'ftyp'.'M4A '.pack('N', 0).'M4A '.'mp42'.pack('N', 8).'moov'.pack('N', 8 + 32).'mdat'.str_repeat("\x02", 32);
    }

    protected function webmBytes(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02".str_repeat("\x00", 64);
    }

    protected function mp3Bytes(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 64);
    }

    protected function wavBytes(): string
    {
        return 'RIFF'.pack('V', 36 + 8).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', 8).str_repeat("\x80", 8);
    }

    protected function oggBytes(): string
    {
        return "OggS\x00\x02".str_repeat("\x00", 20)."\x01\x1Evorbis".str_repeat("\x00", 40);
    }

    /** Builds a PNG whose header claims enormous dimensions but whose data is a few bytes: a decompression bomb. */
    protected function pngBombBytes(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\x00", 64)))
            .$chunk('IEND', '');
    }
}
