<?php

declare(strict_types=1);

namespace Modright;

final class PackProfile
{
    /** @return array<string,array{label:string,description:string,target:?string}> */
    public static function all(): array
    { return['standard'=>['label'=>'Standard','description'=>'Use the selected target and include normal optional content.','target'=>null],'client'=>['label'=>'Client','description'=>'Client mrpack without server-only files.','target'=>'mrpack'],'server'=>['label'=>'Dedicated server','description'=>'Standalone server ZIP using server configuration.','target'=>'server'],'lightweight'=>['label'=>'Lightweight client','description'=>'Client mrpack containing only client-required files.','target'=>'mrpack'],'development'=>['label'=>'Development','description'=>'Client mrpack with all compatible required and optional files.','target'=>'mrpack'],'optional'=>['label'=>'Optional-mod bundle','description'=>'mrpack containing only files marked optional on either environment.','target'=>'mrpack']]; }
    public static function definition(string $profile): array
    { $profiles=self::all();if(!isset($profiles[$profile]))throw new \InvalidArgumentException('Invalid build profile.');return$profiles[$profile]; }
    /** @param array<string,mixed> $entry */
    public static function includes(array $entry,string $profile): bool
    { $client=$entry['env']['client']??'required';$server=$entry['env']['server']??'required';return match($profile){'client'=>$client!=='unsupported','lightweight'=>$client==='required','development'=>$client!=='unsupported','optional'=>$client==='optional'||$server==='optional',default=>true}; }
}
