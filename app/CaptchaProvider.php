<?php

declare(strict_types=1);

namespace Modright;

interface CaptchaProvider
{
    /** @return array{accepted:bool,score:?float,error:string} */
    public function verify(string $token,string $expectedAction,string $expectedHostname): array;
}
