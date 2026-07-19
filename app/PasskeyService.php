<?php

declare(strict_types=1);

namespace Modright;

use PDO;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class PasskeyService
{
    private object $serializer;
    public function __construct(private readonly PDO $db,private readonly string $rpId,private readonly string $origin,private readonly string$sessionBinding='')
    {
        if(!preg_match('/^[A-Za-z0-9.-]+$/',$rpId)||!str_starts_with($origin,'https://'))throw new \InvalidArgumentException('Passkeys require a valid HTTPS origin and relying-party ID.');$host=parse_url($origin,PHP_URL_HOST);if(!is_string($host)||!hash_equals(mb_strtolower($host),mb_strtolower($rpId)))throw new \InvalidArgumentException('Passkey origin and relying-party ID do not match.');
        $manager=AttestationStatementSupportManager::create();$manager->add(NoneAttestationStatementSupport::create());$this->serializer=(new WebauthnSerializerFactory($manager))->create();
    }

    /** @param array<string,mixed> $user @return array{id:string,publicKey:array<string,mixed>} */
    public function beginRegistration(array $user): array
    {
        $challenge=random_bytes(32);$exclude=[];$stmt=$this->db->prepare('SELECT id FROM webauthn_credentials WHERE user_id=?');$stmt->execute([$user['id']]);foreach($stmt->fetchAll()as$row)$exclude[]=PublicKeyCredentialDescriptor::create('public-key',$this->decode((string)$row['id']));
        $options=PublicKeyCredentialCreationOptions::create(PublicKeyCredentialRpEntity::create('Cogwork Engine',$this->rpId),PublicKeyCredentialUserEntity::create((string)$user['username'],(string)$user['id'],(string)$user['display_name']),$challenge,authenticatorSelection:AuthenticatorSelectionCriteria::create(userVerification:AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,residentKey:AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED),attestation:PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,excludeCredentials:$exclude,timeout:300000);
        $id=$this->storeChallenge((string)$user['id'],'passkey_register',$challenge,$this->serializer->serialize($options,'json'));return['id'=>$id,'publicKey'=>json_decode($this->serializer->serialize($options,'json'),true,512,JSON_THROW_ON_ERROR)];
    }

    public function finishRegistration(string $userId,string $challengeId,string $responseJson,string $label): string
    {
        $challenge=$this->challenge($challengeId,$userId,'passkey_register');$options=$this->serializer->deserialize((string)$challenge['payload'],PublicKeyCredentialCreationOptions::class,'json');$credential=$this->serializer->deserialize($responseJson,PublicKeyCredential::class,'json');if(!$credential->response instanceof AuthenticatorAttestationResponse)throw new \InvalidArgumentException('Invalid passkey registration response.');$factory=new CeremonyStepManagerFactory();$source=AuthenticatorAttestationResponseValidator::create($factory->creationCeremony())->check($credential->response,$options,$this->rpId);$id=$this->encode($source->publicKeyCredentialId);$this->db->beginTransaction();try{$this->db->prepare('INSERT INTO webauthn_credentials (id,user_id,label,public_key,sign_count,transports,created_at,last_used_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$userId,mb_substr(trim($label)?:'Passkey',0,200),$this->serializer->serialize($source,'json'),$source->counter,json_encode($source->transports,JSON_THROW_ON_ERROR),Database::now(),null]);$this->useChallenge($challengeId);$this->db->commit();return$id;}catch(\Throwable$e){$this->db->rollBack();throw$e;}
    }

    /** @return array{id:string,publicKey:array<string,mixed>} */
    public function beginAuthentication(string $userId): array
    {
        $allowed=[];$stmt=$this->db->prepare('SELECT id FROM webauthn_credentials WHERE user_id=?');$stmt->execute([$userId]);foreach($stmt->fetchAll()as$row)$allowed[]=PublicKeyCredentialDescriptor::create('public-key',$this->decode((string)$row['id']));if(!$allowed)throw new \RuntimeException('No passkey is registered.');$challenge=random_bytes(32);$options=PublicKeyCredentialRequestOptions::create($challenge,$this->rpId,$allowed,PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,300000);$json=$this->serializer->serialize($options,'json');$id=$this->storeChallenge($userId,'passkey_authenticate',$challenge,$json);return['id'=>$id,'publicKey'=>json_decode($json,true,512,JSON_THROW_ON_ERROR)];
    }

    public function finishAuthentication(string $userId,string $challengeId,string $responseJson): bool
    {
        $challenge=$this->challenge($challengeId,$userId,'passkey_authenticate');$options=$this->serializer->deserialize((string)$challenge['payload'],PublicKeyCredentialRequestOptions::class,'json');$credential=$this->serializer->deserialize($responseJson,PublicKeyCredential::class,'json');if(!$credential->response instanceof AuthenticatorAssertionResponse)return false;$id=$this->encode($credential->rawId);$stmt=$this->db->prepare('SELECT * FROM webauthn_credentials WHERE id=? AND user_id=?');$stmt->execute([$id,$userId]);$stored=$stmt->fetch();if(!$stored)return false;$record=$this->serializer->deserialize((string)$stored['public_key'],CredentialRecord::class,'json');$factory=new CeremonyStepManagerFactory();$updated=AuthenticatorAssertionResponseValidator::create($factory->requestCeremony())->check($record,$credential->response,$options,$this->rpId,$userId);$this->db->beginTransaction();try{$this->db->prepare('UPDATE webauthn_credentials SET public_key=?,sign_count=?,last_used_at=? WHERE id=? AND user_id=?')->execute([$this->serializer->serialize($updated,'json'),$updated->counter,Database::now(),$id,$userId]);$this->useChallenge($challengeId);$this->db->commit();return true;}catch(\Throwable$e){$this->db->rollBack();throw$e;}
    }

    /** @return list<array<string,mixed>> */ public function credentials(string $userId): array{$stmt=$this->db->prepare('SELECT id,label,sign_count,transports,created_at,last_used_at FROM webauthn_credentials WHERE user_id=? ORDER BY created_at DESC');$stmt->execute([$userId]);return$stmt->fetchAll();}
    public function revoke(string $userId,string $id): void{$this->db->prepare('DELETE FROM webauthn_credentials WHERE id=? AND user_id=?')->execute([$id,$userId]);}
    private function storeChallenge(string$userId,string$purpose,string$challenge,string$payload):string{$id=Database::id();$wrapped=json_encode(['options'=>$payload,'binding'=>hash('sha256',$this->sessionBinding)],JSON_THROW_ON_ERROR);$this->db->prepare('INSERT INTO auth_challenges (id,user_id,purpose,challenge_hash,payload,expires_at,used_at,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$userId,$purpose,hash('sha256',$challenge),$wrapped,gmdate('c',time()+300),null,Database::now()]);return$id;}
    /** @return array<string,mixed> */ private function challenge(string$id,string$userId,string$purpose):array{$stmt=$this->db->prepare('SELECT * FROM auth_challenges WHERE id=? AND user_id=? AND purpose=? AND used_at IS NULL AND expires_at>?');$stmt->execute([$id,$userId,$purpose,Database::now()]);$row=$stmt->fetch();if(!$row)throw new \InvalidArgumentException('Passkey challenge is invalid or expired.');$payload=json_decode((string)$row['payload'],true);if(!is_array($payload)||!is_string($payload['options']??null)||!hash_equals((string)($payload['binding']??''),hash('sha256',$this->sessionBinding)))throw new \InvalidArgumentException('Passkey challenge is not valid for this session.');$row['payload']=$payload['options'];return$row;}
    private function useChallenge(string$id):void{$claim=$this->db->prepare('UPDATE auth_challenges SET used_at=? WHERE id=? AND used_at IS NULL');$claim->execute([Database::now(),$id]);if($claim->rowCount()!==1)throw new \InvalidArgumentException('Passkey challenge was already used.');}
    private function encode(string$value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private function decode(string$value):string{$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);if($decoded===false)throw new \InvalidArgumentException('Invalid credential identifier.');return$decoded;}
}
