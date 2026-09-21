<?php
declare(strict_types=1);
namespace Zpx\Payments;
use Zpx\Identity\Failure;

/** Fixed sandbox endpoint. Never accepts a URL, merchant credential or amount from a browser. */
final class AuthorizeNet
{
    public const API='https://apitest.authorize.net/xml/v1/request.api';
    public const FORM='https://test.authorize.net/payment/payment';
    public function __construct(private ?\Closure $transport=null) {}
    public static function configured(): bool {
        return getenv('AUTHORIZE_NET_ENVIRONMENT')==='sandbox' && (bool)getenv('AUTHORIZE_NET_API_LOGIN_ID') && (bool)getenv('AUTHORIZE_NET_TRANSACTION_KEY');
    }
    private function call(string $method,array $body=[],bool $allowDuplicate=false): array {
        if (!self::configured() || !in_array(getenv('APP_ENV'),['development','test'],true)) { throw new Failure(503,'PAYMENT_NOT_CONFIGURED','Authorize.net sandbox credentials are not configured.'); }
        $request=[$method=>['merchantAuthentication'=>['name'=>getenv('AUTHORIZE_NET_API_LOGIN_ID'),'transactionKey'=>getenv('AUTHORIZE_NET_TRANSACTION_KEY')]]+$body];
        if ($this->transport) { $response=($this->transport)($request); }
        else {
            $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",'content'=>json_encode($request,JSON_THROW_ON_ERROR),'timeout'=>20,'follow_location'=>0,'ignore_errors'=>false],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $raw=@file_get_contents(self::API,false,$context,0,1048577);
            if ($raw===false || strlen($raw)>1048576) { throw new Failure(503,'PAYMENT_PROVIDER_UNAVAILABLE','The payment provider could not be reached. Your payment status has not changed.'); }
            try { $response=json_decode(ltrim($raw,"\xEF\xBB\xBF"),true,64,JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new Failure(503,'PAYMENT_PROVIDER_UNAVAILABLE','The payment provider returned an unreadable response.'); }
        }
        if (!is_array($response) || ($response['messages']['resultCode'] ?? '')!=='Ok') {
            $code=$response['messages']['message'][0]['code'] ?? '';
            if ($allowDuplicate && $code==='E00039') { return $response; }
            throw new Failure(503,$code==='E00007'?'PAYMENT_AUTH_FAILED':'PAYMENT_PROVIDER_REJECTED',$code==='E00007'?'The sandbox rejected its configured credentials.':'The sandbox could not complete this request.');
        }
        return $response;
    }
    public function authenticate(): void { $this->call('authenticateTestRequest'); }
    public function hosted(int $amount,string $reference,?string $customerProfile=null): string {
        if ($amount<1 || $amount>1000000 || !preg_match('/^ZP[A-F0-9]{18}$/D',$reference)) { throw new \LogicException('Invalid server checkout details'); }
        $result=$this->call('getHostedPaymentPageRequest',['refId'=>$reference,'transactionRequest'=>['transactionType'=>'authCaptureTransaction','amount'=>self::dollars($amount),...($customerProfile?['profile'=>['customerProfileId'=>$customerProfile]]:[]),'order'=>['invoiceNumber'=>$reference,'description'=>'ZPX sandbox shipping'], 'transactionSettings'=>['setting'=>[['settingName'=>'duplicateWindow','settingValue'=>'28800']]]],'hostedPaymentSettings'=>['setting'=>[
            ['settingName'=>'hostedPaymentReturnOptions','settingValue'=>json_encode(['showReceipt'=>true])],
            ['settingName'=>'hostedPaymentPaymentOptions','settingValue'=>json_encode(['showCreditCard'=>true,'showBankAccount'=>false,'customerProfileId'=>$customerProfile!==null])],
            ['settingName'=>'hostedPaymentCustomerOptions','settingValue'=>json_encode(['showEmail'=>false,'requiredEmail'=>false,'addPaymentProfile'=>$customerProfile!==null])],
            ['settingName'=>'hostedPaymentShippingAddressOptions','settingValue'=>json_encode(['show'=>false,'required'=>false])],
            ['settingName'=>'hostedPaymentOrderOptions','settingValue'=>json_encode(['show'=>true,'merchantName'=>'ZPX Sandbox'])],
        ]]]);
        $token=$result['token'] ?? null;
        if (!is_string($token) || strlen($token)<20 || strlen($token)>16000) { throw new Failure(503,'PAYMENT_PROVIDER_UNAVAILABLE','No checkout token was returned.'); }
        return $token;
    }
    public function customerProfile(string $id): array {
        return $this->call('getCustomerProfileRequest',['customerProfileId'=>$id])['profile'] ?? [];
    }
    public function createProfile(string $reference): string {
        $r=$this->call('createCustomerProfileRequest',['profile'=>['merchantCustomerId'=>$reference,'description'=>'ZPX sandbox customer'],'validationMode'=>'none'],true);
        $id=(string)($r['customerProfileId'] ?? '');
        if (!$id && preg_match('/ID ([0-9]+) exists/', $r['messages']['message'][0]['text'] ?? '',$m)) { $id=$m[1]; }
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',$id) || ($this->customerProfile($id)['merchantCustomerId'] ?? '')!==$reference) { throw new Failure(503,'PAYMENT_PROFILE_UNAVAILABLE','The saved-payment profile could not be verified.'); }
        return $id;
    }
    public function profileForm(string $id): string {
        $r=$this->call('getHostedProfilePageRequest',['customerProfileId'=>$id,'hostedProfileSettings'=>['setting'=>[
            ['settingName'=>'hostedProfilePaymentOptions','settingValue'=>'showCreditCard'],
            ['settingName'=>'hostedProfileValidationMode','settingValue'=>'testMode']
        ]]]);
        $token=$r['token'] ?? '';
        if (!is_string($token) || strlen($token)<20 || strlen($token)>16000) { throw new Failure(503,'PAYMENT_PROFILE_UNAVAILABLE','The card-management form could not be prepared.'); }
        return $token;
    }
    public static function dollars(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
    public static function cents(mixed $amount): int {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) { throw new Failure(409,'PAYMENT_MISMATCH','Payment amount could not be verified.'); }
        $value=(string)$amount;
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D',$value,$m)) { throw new Failure(409,'PAYMENT_MISMATCH','Payment amount could not be verified.'); }
        return ((int)$m[1])*100+(int)str_pad($m[2] ?? '',2,'0');
    }
    public function transaction(string $id): array {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',$id)) { throw new Failure(422,'INVALID_INPUT','Use the transaction ID from the sandbox receipt.'); }
        $response=$this->call('getTransactionDetailsRequest',['transId'=>$id]);
        $transaction=$response['transaction'] ?? [];
        if ((string)($transaction['transId'] ?? '')!==$id) { throw new Failure(409,'PAYMENT_MISMATCH','Payment reference could not be verified.'); }
        return $transaction;
    }
    public function find(string $invoice): ?string {
        // Read-only, bounded local reconciliation when localhost cannot receive webhooks.
        for ($page=1;$page<=3;$page++) {
            $r=$this->call('getUnsettledTransactionListRequest',['sorting'=>['orderBy'=>'submitTimeUTC','orderDescending'=>true],'paging'=>['limit'=>100,'offset'=>$page]]);
            $rows=$r['transactions'] ?? [];
            foreach ($rows as $transaction) { if (($transaction['invoiceNumber'] ?? null)===$invoice) { return (string)$transaction['transId']; } }
            if (count($rows)<100) { break; }
        }
        return null;
    }
    public static function verifySignature(string $raw,string $header): void {
        $key=getenv('AUTHORIZE_NET_SIGNATURE_KEY') ?: '';
        if (!preg_match('/^[a-fA-F0-9]{128}$/D',$key)) { throw new Failure(503,'WEBHOOK_NOT_CONFIGURED','Webhook verification is not configured.'); }
        if (!preg_match('/^sha512=([a-f0-9]{128})$/Di',$header,$m) || !hash_equals(hash_hmac('sha512',$raw,hex2bin($key)),strtolower($m[1]))) { throw new Failure(401,'INVALID_SIGNATURE','Invalid payment notification signature.'); }
    }
}
