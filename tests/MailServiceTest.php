<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\MailService;
use PHPUnit\Framework\TestCase;

final class MailServiceTest extends TestCase
{
    public function testSmtpSuccessDoesNotCallMailFallback(): void
    {
        $smtp=[];$fallback=false;$mail=new MailService($this->config(),null,function(array$message)use(&$smtp):void{$smtp=$message;},function()use(&$fallback):bool{$fallback=true;return true;});
        $mail->send('USER@Example.test','Security notice','Body');
        self::assertSame('user@example.test',$smtp['to']);self::assertSame('Security notice',$smtp['subject']);self::assertFalse($fallback);
    }

    public function testSmtpFailureUsesExplicitMailFallback(): void
    {
        $native=[];$config=array_replace($this->config(),['mail_fallback'=>true]);$mail=new MailService($config,null,static function():void{throw new \RuntimeException('simulated SMTP failure');},function(array$message)use(&$native):bool{$native=$message;return true;});
        $mail->send('user@example.test','Fallback notice','Body');
        self::assertSame('user@example.test',$native['to']);self::assertSame('Fallback notice',$native['subject']);
    }

    public function testSmtpTimeoutWithoutFallbackIsReported(): void
    {
        $mail=new MailService($this->config(),null,static function():void{throw new \RuntimeException('SMTP operation timed out.');},static fn():bool=>true);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('SMTP operation timed out.');$mail->send('user@example.test','Timeout','Body');
    }

    public function testNativeMailFailureIsReportedWithoutSendingRealMail(): void
    {
        $mail=new MailService(['transport'=>'mail','from_address'=>'noreply@example.test'],null,null,static fn():bool=>false);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('PHP mail delivery failed.');$mail->send('user@example.test','Failure','Body');
    }

    /** @return array<string,mixed> */
    private function config(): array
    { return['transport'=>'smtp','from_address'=>'noreply@example.test','from_name'=>'Cogwork Engine','mail_fallback'=>false]; }
}
