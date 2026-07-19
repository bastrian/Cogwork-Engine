<?php

declare(strict_types=1);

namespace Modright;

final class Translator
{
    public const DEFAULT_LOCALE='en_US';
    public const SUPPORTED=['en_US'=>'English','de_DE'=>'Deutsch'];
    private array $messages;
    private array $fallback;

    public function __construct(private readonly string $locale)
    { $this->fallback=$this->load(self::DEFAULT_LOCALE);$this->messages=$locale===self::DEFAULT_LOCALE?$this->fallback:$this->load($locale); }

    public static function normalize(?string $locale): string
    { return isset(self::SUPPORTED[(string)$locale])?(string)$locale:self::DEFAULT_LOCALE; }

    public static function current(): self
    { $locale=self::normalize((string)($_SESSION['locale']??$_COOKIE['modright_locale']??self::DEFAULT_LOCALE));return new self($locale); }

    public function locale(): string { return $this->locale; }
    public function languageTag(): string { return str_replace('_','-',$this->locale); }

    /** @param array<string,string|int|float> $parameters */
    public function text(string $key,array $parameters=[]): string
    { $text=(string)($this->messages[$key]??$this->fallback[$key]??$key);foreach($parameters as$name=>$value)$text=str_replace('{'.$name.'}',(string)$value,$text);return$text; }

    public function plural(string $key,int $count,array $parameters=[]): string
    { $forms=$this->messages[$key]??$this->fallback[$key]??[$key,$key];if(!is_array($forms))$forms=[$forms,$forms];$text=(string)($forms[$count===1?0:1]??$forms[0]??$key);return$this->replace($text,['count'=>$count]+$parameters); }

    public function date(?string $value): string
    { if(!$value)return$this->text('Never');$time=strtotime($value);if($time===false)return$value;return$this->locale==='de_DE'?date('d.m.Y H:i',$time).' UTC':date('Y-m-d H:i',$time).' UTC'; }

    public function number(int|float $value,int $decimals=0): string
    { return number_format($value,$decimals,$this->locale==='de_DE'?',':'.',$this->locale==='de_DE'?'.':','); }

    public function bytes(int $bytes): string
    { if($bytes<1024)return$this->text('{count} B',['count'=>$bytes]);if($bytes<1048576)return$this->text('{count} KB',['count'=>$this->number($bytes/1024,1)]);return$this->text('{count} MB',['count'=>$this->number($bytes/1048576,1)]); }

    /** Translate exact static text nodes and selected attributes in server-rendered markup. */
    public function html(string $html): string
    { if($this->locale===self::DEFAULT_LOCALE)return$html;$translated=preg_replace_callback('/(?<=>)([^<>]+)(?=<)/u',function(array$m):string{preg_match('/^(\s*)(.*?)(\s*)$/us',$m[1],$parts);$text=$parts[2]??'';return($parts[1]??'').($text!==''?$this->text($text):'').($parts[3]??'');},$html)??$html;return preg_replace_callback('/\b(placeholder|aria-label|title|data-confirm)="([^"]+)"/u',fn(array$m):string=>$m[1].'="'.Security::escape($this->text(html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8'))).'"',$translated)??$translated; }

    /** @return array<string,mixed> */
    public function all(): array { return$this->messages; }

    /** @return array<string,mixed> */
    private function load(string $locale): array
    { $locale=self::normalize($locale);$path=MODRIGHT_ROOT.'/lang/'.$locale.'.php';$messages=require$path;if(!is_array($messages))throw new \RuntimeException('Invalid language file.');return$messages; }

    /** @param array<string,string|int|float> $parameters */
    private function replace(string $text,array $parameters): string
    { foreach($parameters as$name=>$value)$text=str_replace('{'.$name.'}',(string)$value,$text);return$text; }
}
