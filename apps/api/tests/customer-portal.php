<?php
declare(strict_types=1);
use Zpx\Identity\Secrets;
use Zpx\Payments\{AuthorizeNet,Wallet};
$own=$identity->profile($sender);
check($own['email']===$senderInput['email'] && $own['phone']===$senderInput['phone'],'authenticated profile exposes only own decrypted contact fields');
$update=['name'=>'Updated Synthetic Customer','address'=>['line1'=>'Synthetic new address','line2'=>'Unit 2','city'=>'Test City','region'=>'TX','postal_code'=>'00000','country_code'=>'US']];
failsIdentity(fn()=>$identity->updateProfile($sender,$update+['email'=>'injected@example.invalid']),422,'profile edit cannot bypass email proof');
$otherBefore=$identity->profile($recipient);
$saved=$identity->updateProfile($sender,$update);
check($saved['name']===$update['name'] && $saved['addresses'][0]['line1']===$update['address']['line1'],'profile name and address persist');
check($identity->profile($recipient)===$otherBefore,'profile edit cannot affect another account');
$addressCipher=$runtime->query("SELECT address_ciphertext FROM user_addresses WHERE user_id=$sender AND kind='PROFILE'")->fetchColumn();check(!str_contains($addressCipher,'Synthetic new address'),'edited address remains encrypted');
$all=$shipping->list($sender,'history')['items'];check(count($all)>0 && count(array_unique(array_column($all,'shipment_id')))===count($all),'combined shipment history has no duplicate parcels');
check($shipping->lookup($sender,$draft['public_reference'])['shipment_id']===$draft['shipment_id'],'reference lookup finds own parcel');
failsIdentity(fn()=>$shipping->lookup($recipient,$draft['public_reference']),404,'unclaimed reference remains hidden from another customer');
$locations=$shipping->locations()['items'];check($locations[0]['map_position']['illustrative']===true && is_float($locations[0]['map_position']['latitude']),'synthetic map coordinates are explicitly illustrative');
$profileCalls=0;$merchant='';
$walletGateway=new AuthorizeNet(function(array $request)use(&$profileCalls,&$merchant){
 $method=array_key_first($request);$r=['messages'=>['resultCode'=>'Ok']];
 if($method==='createCustomerProfileRequest'){$profileCalls++;$merchant=$request[$method]['profile']['merchantCustomerId'];return $r+['customerProfileId'=>'123456'];}
 if($method==='getCustomerProfileRequest'){return $r+['profile'=>['merchantCustomerId'=>$merchant,'paymentProfiles'=>[['customerPaymentProfileId'=>'secret-profile-ref','payment'=>['creditCard'=>['cardNumber'=>'XXXX1111','cardType'=>'Visa','expirationDate'=>'XXXX']]]]]];}
 if($method==='getHostedProfilePageRequest'){check($request[$method]['customerProfileId']==='123456','hosted card manager uses server-owned profile');return $r+['token'=>'synthetic-secure-card-manager-token'];}
 throw new RuntimeException('Unexpected provider request');
});
$wallet=new Wallet($runtime,$crypto,$walletGateway);
check($wallet->methods($sender)['items']===[],'new wallet reads without creating remote profile');
$manager=$wallet->manage($sender);check($manager['checkout_url']==='https://test.authorize.net/customer/manage','saved-card manager fixes sandbox destination');
$wallet->manage($sender);check($profileCalls===1,'retry reuses stable provider profile');
check($wallet->methods($sender)['items']===[['brand'=>'Visa','last4'=>'1111']],'wallet exposes only card brand and last four');
check($wallet->methods($recipient)['items']===[],'another customer cannot see saved card metadata');
$history=$wallet->history($sender);check(count($history['items'])>0 && !str_contains(json_encode($history),'checkout_token'),'customer payment history excludes checkout secrets');
$otherPayments=$wallet->history($recipient);check(count(array_intersect(array_column($history['items'],'payment_id'),array_column($otherPayments['items'],'payment_id')))===0,'payment histories are owner-scoped');
failsIdentity(fn()=>$wallet->manage($admin),403,'staff-only account cannot open customer vault');
$old=getenv('ZPX_ORGANIZATION_ID');putenv('ZPX_ORGANIZATION_ID='.$identityOrg);failsIdentity(fn()=>$wallet->methods($sender),403,'wallet denies cross-organization access');putenv('ZPX_ORGANIZATION_ID='.$old);
[$response,$body]=identityHttp('POST',$base.'/me/profile',$update);check($response->getCode()===401,'profile HTTP update requires authentication');
[$response,$body]=identityHttp('GET',$base.'/me/payment-methods');check($response->getCode()===401,'wallet HTTP read requires authentication');
echo "Customer portal account, history, map and vault tests passed.\n";
