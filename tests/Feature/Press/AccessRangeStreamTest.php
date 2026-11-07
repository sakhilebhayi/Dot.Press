<?php

namespace Tests\Feature\Press;

use App\Services\Press\RangeStream;
use PHPUnit\Framework\TestCase;

class AccessRangeStreamTest extends TestCase
{
    public function test_parse_range(): void
    {
        $this->assertNull(RangeStream::parseRange('', 10));
        $this->assertNull(RangeStream::parseRange('garbage', 10));
        $this->assertNull(RangeStream::parseRange('bytes=0-1,4-5', 10), 'multiple ranges are answered with the whole file');
        $this->assertNull(RangeStream::parseRange('bytes=-', 10));
        $this->assertSame([0, 9], RangeStream::parseRange('bytes=0-', 10));
        $this->assertSame([2, 5], RangeStream::parseRange('bytes=2-5', 10));
        $this->assertSame([2, 9], RangeStream::parseRange('bytes=2-500', 10), 'an end past the file is clamped');
        $this->assertSame([7, 9], RangeStream::parseRange('bytes=-3', 10));
        $this->assertSame([0, 9], RangeStream::parseRange('bytes=-50', 10));
        $this->assertFalse(RangeStream::parseRange('bytes=10-12', 10));
        $this->assertFalse(RangeStream::parseRange('bytes=5-2', 10));
        $this->assertFalse(RangeStream::parseRange('bytes=-0', 10));
        $this->assertFalse(RangeStream::parseRange('bytes=0-1', 0));
    }
}
