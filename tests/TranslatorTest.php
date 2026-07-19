<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function testEnglishAndGermanHaveIdenticalEffectiveKeysAndPlaceholders(): void
    {
        $english=(new Translator('en_US'))->all();$german=(new Translator('de_DE'))->all();self::assertSame(array_keys($english),array_keys($german));foreach($english as$key=>$value){$translated=$german[$key];$sourceForms=is_array($value)?$value:[$value];$targetForms=is_array($translated)?$translated:[$translated];self::assertCount(count($sourceForms),$targetForms,$key);foreach($sourceForms as$i=>$source){preg_match_all('/\{[a-z0-9_]+\}/i',(string)$source,$sourceTokens);preg_match_all('/\{[a-z0-9_]+\}/i',(string)$targetForms[$i],$targetTokens);sort($sourceTokens[0]);sort($targetTokens[0]);self::assertSame($sourceTokens[0],$targetTokens[0],$key);}}
    }

    public function testLocaleIsWhitelistedAndMissingKeysFallBackSafely(): void
    { self::assertSame('en_US',Translator::normalize('../../app/Config'));self::assertSame('de_DE',Translator::normalize('de_DE'));self::assertSame('Unbekannt',(new Translator('de_DE'))->text('Unknown'));self::assertSame('missing.key',(new Translator('de_DE'))->text('missing.key')); }

    public function testInterpolationPluralFormattingAndHtmlAttributes(): void
    { $german=new Translator('de_DE');self::assertSame('1 Mod',$german->plural('mods',1));self::assertSame('3 Mods',$german->plural('mods',3));self::assertSame('1.234,5',$german->number(1234.5,1));$html=$german->html('<label aria-label="Language">Language</label>');self::assertStringContainsString('aria-label="Sprache"',$html);self::assertStringContainsString('>Sprache<',$html); }

    public function testLanguageDirectoryIsProtectedByRootAndDirectoryRules(): void
    { $root=(string)file_get_contents(MODRIGHT_ROOT.'/.htaccess');$directory=(string)file_get_contents(MODRIGHT_ROOT.'/lang/.htaccess');self::assertMatchesRegularExpression('/app\|config\|lang\|storage/',$root);self::assertStringContainsString('Require all denied',$directory); }
}
