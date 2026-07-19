<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\RequestSecurity;
use PHPUnit\Framework\TestCase;

final class RequestSecurityTest extends TestCase
{
    public function testDirectTlsIsSecure(): void
    { self::assertTrue(RequestSecurity::https(['HTTPS'=>'on','REMOTE_ADDR'=>'203.0.113.2'])); }

    public function testForwardedProtocolRequiresTrustedProxy(): void
    { $server=['REMOTE_ADDR'=>'10.0.0.5','HTTP_X_FORWARDED_PROTO'=>'https'];self::assertFalse(RequestSecurity::https($server,[]));self::assertTrue(RequestSecurity::https($server,['10.0.0.0/24'])); }

    public function testIpv4AndIpv6TrustRanges(): void
    { self::assertTrue(RequestSecurity::trusted('192.0.2.8',['192.0.2.0/24']));self::assertFalse(RequestSecurity::trusted('192.0.3.8',['192.0.2.0/24']));self::assertTrue(RequestSecurity::trusted('2001:db8::4',['2001:db8::/32'])); }

    public function testLogoutTargetsAcceptOnlyUnambiguousRelativeOrHttpsDestinations():void
    { $fallback='/index.php?route=login';foreach(['/portal','/portal?logged_out=1','https://portal.example.test/signed-out']as$target)self::assertSame($target,RequestSecurity::logoutTarget($target,$fallback));foreach(['','//evil.test','http://portal.example.test','https://user:pass@portal.example.test','https://portal.example.test/#fragment','https://bad host.test',"/safe\r\nX-Test: bad",'/back\\slash',' /space']as$target)self::assertSame($fallback,RequestSecurity::logoutTarget($target,$fallback));}
}
