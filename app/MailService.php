<?php

declare(strict_types=1);

namespace Modright;

final class MailService
{
    private ?\Closure $transport;
    private ?\Closure $smtpTransport;
    private ?\Closure $nativeMailTransport;
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config,?callable $transport=null,?callable $smtpTransport=null,?callable $nativeMailTransport=null)
    { $this->transport=$transport!==null?\Closure::fromCallable($transport):null;$this->smtpTransport=$smtpTransport!==null?\Closure::fromCallable($smtpTransport):null;$this->nativeMailTransport=$nativeMailTransport!==null?\Closure::fromCallable($nativeMailTransport):null; }

    public function send(string $to,string $subject,string $text): void
    {
        if(!empty($this->config['paused']))throw new \RuntimeException('Mail delivery is paused during maintenance.');
        $to=UserService::normalizeEmail($to);$subject=$this->line($subject);$from=UserService::normalizeEmail((string)($this->config['from_address']??''));$name=$this->line((string)($this->config['from_name']??'Cogwork Engine'));
        $message=['to'=>$to,'subject'=>$subject,'text'=>$text,'from'=>$from,'from_name'=>$name];if($this->transport!==null){($this->transport)($message);return;}
        $driver=(string)($this->config['transport']??$this->config['driver']??'mail');if($driver==='smtp'){try{if($this->smtpTransport!==null)($this->smtpTransport)($message);else$this->smtp($to,$subject,$text,$from,$name);return;}catch(\Throwable$e){if(empty($this->config['mail_fallback']))throw$e;}}
        $headers=['From: '.$name.' <'.$from.'>','Content-Type: text/plain; charset=UTF-8','MIME-Version: 1.0'];$sent=$this->nativeMailTransport!==null?(bool)($this->nativeMailTransport)($message+['headers'=>$headers]):mail($to,$subject,$text,implode("\r\n",$headers));if(!$sent)throw new \RuntimeException('PHP mail delivery failed.');
    }

    private function smtp(string$to,string$subject,string$text,string$from,string$name):void
    {
        $host=(string)($this->config['host']??'');$port=(int)($this->config['port']??587);$encryption=(string)($this->config['encryption']??'starttls');$timeout=max(2,min(30,(int)($this->config['timeout_seconds']??$this->config['timeout']??10)));if($host===''||!preg_match('/^[A-Za-z0-9.-]+$/',$host))throw new \InvalidArgumentException('Invalid SMTP host.');$deadline=microtime(true)+$timeout;$target=($encryption==='tls'?'tls://':'tcp://').$host.':'.$port;$socket=@stream_socket_client($target,$errno,$error,$timeout,STREAM_CLIENT_CONNECT);if(!$socket)throw new \RuntimeException('SMTP connection failed.');try{$this->expect($socket,[220],$deadline);$this->command($socket,'EHLO cogwork-engine',[250],$deadline);if($encryption==='starttls'){$this->command($socket,'STARTTLS',[220],$deadline);$this->applyDeadline($socket,$deadline);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('SMTP TLS negotiation failed.');$this->command($socket,'EHLO cogwork-engine',[250],$deadline);}$username=(string)($this->config['username']??'');if($username!==''){$this->command($socket,'AUTH LOGIN',[334],$deadline);$this->command($socket,base64_encode($username),[334],$deadline);$this->command($socket,base64_encode((string)($this->config['password']??'')),[235],$deadline);}$this->command($socket,'MAIL FROM:<'.$from.'>',[250],$deadline);$this->command($socket,'RCPT TO:<'.$to.'>',[250,251],$deadline);$this->command($socket,'DATA',[354],$deadline);$message='From: '.$name.' <'.$from.">\r\nTo: <".$to.">\r\nSubject: ".$subject."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".str_replace("\n.","\n..",str_replace(["\r\n","\r"],"\n",$text))."\r\n."; $this->command($socket,$message,[250],$deadline);$this->command($socket,'QUIT',[221],$deadline);}finally{fclose($socket);}
    }

    /** @param resource $socket @param list<int> $codes */
    private function command($socket,string$command,array$codes,float$deadline):void{$this->applyDeadline($socket,$deadline);if(fwrite($socket,$command."\r\n")===false)throw new \RuntimeException('SMTP write failed.');$this->expect($socket,$codes,$deadline);}
    /** @param resource $socket @param list<int> $codes */
    private function expect($socket,array$codes,float$deadline):void{$response='';do{$this->applyDeadline($socket,$deadline);$line=fgets($socket,4096);if($line===false){$metadata=stream_get_meta_data($socket);throw new \RuntimeException(!empty($metadata['timed_out'])?'SMTP operation timed out.':'SMTP connection closed unexpectedly.');}$response.=$line;}while(isset($line[3])&&$line[3]==='-');$code=(int)substr($response,0,3);if(!in_array($code,$codes,true))throw new \RuntimeException('SMTP server rejected the request ('.$code.').');}
    /** @param resource $socket */
    private function applyDeadline($socket,float$deadline):void{$remaining=$deadline-microtime(true);if($remaining<=0)throw new \RuntimeException('SMTP operation timed out.');$seconds=(int)$remaining;$microseconds=(int)(($remaining-$seconds)*1000000);stream_set_timeout($socket,$seconds,$microseconds);}
    private function line(string$value):string{if(str_contains($value,"\r")||str_contains($value,"\n"))throw new \InvalidArgumentException('Mail header contains invalid characters.');return$value;}
}
