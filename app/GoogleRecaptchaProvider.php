<?php

declare(strict_types=1);

namespace Modright;

final class GoogleRecaptchaProvider implements CaptchaProvider
{
    private \Closure $request;
    public function __construct(private readonly string $secret,private readonly float $minimumScore=.5,private readonly bool $failOpen=true,?callable $request=null)
    { $this->request=$request!==null?\Closure::fromCallable($request):$this->post(...); }

    public function verify(string $token,string $expectedAction,string $expectedHostname): array
    {
        if($token===''||strlen($token)>4096)return['accepted'=>false,'score'=>null,'error'=>'missing_or_invalid_token'];
        try{$response=($this->request)($token);if(!is_array($response))throw new \RuntimeException('Invalid provider response.');$score=isset($response['score'])?(float)$response['score']:null;$timestamp=strtotime((string)($response['challenge_ts']??''));$valid=!empty($response['success'])&&hash_equals($expectedAction,(string)($response['action']??''))&&hash_equals(mb_strtolower($expectedHostname),mb_strtolower((string)($response['hostname']??'')))&&$timestamp!==false&&$timestamp>=time()-120&&$timestamp<=time()+30&&$score!==null&&$score>=$this->minimumScore;return['accepted'=>$valid,'score'=>$score,'error'=>$valid?'':implode(',',array_map('strval',$response['error-codes']??['verification_failed']))];}
        catch(\Throwable){return['accepted'=>$this->failOpen,'score'=>null,'error'=>'provider_unavailable'];}
    }

    /** @return array<string,mixed> */
    private function post(string $token): array
    { $ch=curl_init('https://www.google.com/recaptcha/api/siteverify');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['secret'=>$this->secret,'response'=>$token]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);$body=curl_exec($ch);if($body===false||(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE)!==200)throw new \RuntimeException('Provider request failed.');$decoded=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new \RuntimeException('Invalid provider response.');return$decoded; }
}
