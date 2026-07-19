<?php

declare(strict_types=1);

namespace Modright;

final class MaintenanceService
{
    public function __construct(private readonly SystemSettings $settings) {}

    /** @return array<string,mixed> */
    public function state(?int $now=null): array
    {
        $state=$this->settings->group('maintenance');$now??=time();
        $start=!empty($state['starts_at'])?strtotime((string)$state['starts_at']):false;
        $end=!empty($state['ends_at'])?strtotime((string)$state['ends_at']):false;
        $state['active']=(bool)$state['enabled']&&($start===false||$start<=$now)&&($end===false||$end>$now);
        $state['retry_after']=$state['active']&&$end!==false?max(1,$end-$now):null;return$state;
    }

    public function active(): bool{return(bool)$this->state()['active'];}
}
