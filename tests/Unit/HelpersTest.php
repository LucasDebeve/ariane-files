<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\ShareAccess;
use App\Service\TagResolver;
use App\Service\VideoLink;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testShareCodeNormalization(): void
    {
        self::assertSame('ABCD1234EFGH', ShareAccess::normalize(' abcd-1234 efgh '));
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', ShareAccess::generate());
        self::assertNotSame(ShareAccess::generate(), ShareAccess::generate());
    }

    public function testTagParsing(): void
    {
        self::assertSame(['Grand jeu', 'veillée'], TagResolver::parse(' Grand   jeu, veillée,, grand jeu '));
        self::assertCount(TagResolver::MAX_TAGS, TagResolver::parse(implode(',', range(1, 30))));
        self::assertSame([], TagResolver::parse(null));
    }

    public function testVideoLinks(): void
    {
        self::assertTrue(VideoLink::isValid('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        self::assertFalse(VideoLink::isValid('http://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        self::assertFalse(VideoLink::isValid('javascript:alert(1)'));
        self::assertFalse(VideoLink::isValid('https://user:pass@example.org/video'));

        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', VideoLink::embedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', VideoLink::embedUrl('https://youtu.be/dQw4w9WgXcQ'));
        self::assertSame('https://player.vimeo.com/video/76979871?dnt=1', VideoLink::embedUrl('https://vimeo.com/76979871'));
        self::assertNull(VideoLink::embedUrl('https://peertube.example.org/w/abc'));
        self::assertNull(VideoLink::embedUrl('https://www.youtube.com/watch?v="><script>'));
    }

    public function testBytesFormatting(): void
    {
        self::assertSame('512 o', AppExtension::formatBytes(512));
        self::assertSame('1,5 Ko', AppExtension::formatBytes(1536));
        self::assertSame('100 Mo', AppExtension::formatBytes(104857600));
    }
}
