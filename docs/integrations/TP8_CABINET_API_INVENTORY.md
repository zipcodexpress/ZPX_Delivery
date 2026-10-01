# TP8 cabinet API inventory and Delivery disposition

Source: local `zpxapi_tp8` `main` at `929d5d1`, `route/app.php:153-243`; terminal client: local `terminal_452` `master` at `0494a99`, `Zippora/Service/ExpressBoxService.cs`. All 81 explicit routes are listed below. Every listed route is a TP8 route, **not** automatically a Delivery endpoint. The final fallback route in TP8 may expose additional controller methods; this inventory covers explicit cabinet routes only.

All explicit cabinet routes use `POST`. TP8 Zippora/Ziplocker methods generally use form fields and a `ret`/`msg`/`data` JSON envelope. `getAccessToken` is the exception to the cached-token guard; see the focused [contract review](TP8_TERMINAL_API_REFERENCE.md). “Terminal service” means a literal action name occurs in `ExpressBoxService`, not proof that the current UI calls it. “Direct form fields” lists only literal parameter names read in that method; helpers or inherited methods may read others. A dash is not proof that no input is needed. TP8 controller method line numbers are source pointers, not verified request/response fixtures.

## Route inventory

| TP8 route | Domain | Direct form fields | Terminal service | Source |
| --- | --- | --- | --- | --- |
| `POST /cabinet/product/getAccessToken` | Identity / access | — | — | `route/app.php:158`; `Product.php:105` |
| `POST /cabinet/product/checkAccessToken` | Identity / access | — | — | `route/app.php:159`; `Product.php:119` |
| `POST /cabinet/product/testa` | Maintenance / diagnostics | — | — | `route/app.php:160`; `Product.php:62` |
| `POST /cabinet/ziplocker/getAccessToken` | Identity / access | — | — | `route/app.php:163`; `Ziplocker.php:183` |
| `POST /cabinet/ziplocker/checkAccessToken` | Identity / access | — | — | `route/app.php:164`; `Ziplocker.php:198` |
| `POST /cabinet/ziplocker/getBoxConfig` | Configuration / catalog | — | — | `route/app.php:165`; `Ziplocker.php:320` |
| `POST /cabinet/ziplocker/getBoxModelList` | Configuration / catalog | — | — | `route/app.php:166`; `Ziplocker.php:373` |
| `POST /cabinet/ziplocker/preAuthForBox` | Deposit / allocation | — | — | `route/app.php:167`; `Ziplocker.php:412` |
| `POST /cabinet/ziplocker/blockBox` | Maintenance / diagnostics | — | — | `route/app.php:168`; `Ziplocker.php:440` |
| `POST /cabinet/ziplocker/getAppQRUrl` | Notification / scan | — | — | `route/app.php:169`; `Ziplocker.php:471` |
| `POST /cabinet/ziplocker/getAppScanResult` | Notification / scan | — | — | `route/app.php:170`; `Ziplocker.php:514` |
| `POST /cabinet/ziplocker/resendPickCode` | Pickup / retrieval | — | — | `route/app.php:171`; `Ziplocker.php:629` |
| `POST /cabinet/ziplocker/proveCode` | Pickup / retrieval | — | — | `route/app.php:172`; `Ziplocker.php:683` |
| `POST /cabinet/ziplocker/commitForDeliver` | Deposit / allocation | — | — | `route/app.php:173`; `Ziplocker.php:710` |
| `POST /cabinet/ziplocker/getPrintList` | Pickup / retrieval | — | — | `route/app.php:174`; `Ziplocker.php:753` |
| `POST /cabinet/ziplocker/preAuthForDeliver` | Deposit / allocation | — | — | `route/app.php:175`; `Ziplocker.php:830` |
| `POST /cabinet/ziplocker/getDeliverList` | Pickup / retrieval | — | — | `route/app.php:176`; `Ziplocker.php:564` |
| `POST /cabinet/ziplocker/getDeliver` | Pickup / retrieval | — | — | `route/app.php:177`; `Ziplocker.php:600` |
| `POST /cabinet/zippora/getAccessToken` | Identity / access | `apiKey`, `kts`, `cabinetId`, `sign` | yes | `route/app.php:180`; `Zippora.php:773` |
| `POST /cabinet/zippora/checkAccessToken` | Identity / access | `accessToken` | yes | `route/app.php:181`; `Zippora.php:837` |
| `POST /cabinet/zippora/getBoxConfig` | Configuration / catalog | — | yes | `route/app.php:182`; `Zippora.php:969` |
| `POST /cabinet/zippora/getBoxModelList` | Configuration / catalog | — | yes | `route/app.php:183`; `Zippora.php:1092` |
| `POST /cabinet/zippora/getBoxList` | Configuration / catalog | — | — | `route/app.php:184`; `Zippora.php:1148` |
| `POST /cabinet/zippora/getMemberFromHID` | Identity / access | `HealthId` | — | `route/app.php:185`; `Zippora.php:2874` |
| `POST /cabinet/zippora/checkStoreNDay` | Deposit / allocation | — | — | `route/app.php:186`; `Zippora.php:4716` |
| `POST /cabinet/zippora/firebaseFcmPush` | Notification / scan | — | — | `route/app.php:187`; `Zippora.php:5013` |
| `POST /cabinet/zippora/firebasegetAccessToken` | Notification / scan | — | — | `route/app.php:188`; `Zippora.php:5057` |
| `POST /cabinet/zippora/commitForAssetRent` | Deposit / allocation | — | yes | `route/app.php:189`; inherited or missing method |
| `POST /cabinet/zippora/preAuthForBoxRent` | Deposit / allocation | `boxModelId` | — | `route/app.php:190`; `Zippora.php:4444` |
| `POST /cabinet/zippora/commitForPick` | Pickup / retrieval | `storeId`, `pickupway`, `flag` | yes | `route/app.php:191`; `Zippora.php:3198` |
| `POST /cabinet/zippora/commitForPickMart` | Pickup / retrieval | `storeId` | yes | `route/app.php:192`; `Zippora.php:3826` |
| `POST /cabinet/zippora/getAppQRUrl` | Notification / scan | — | yes | `route/app.php:193`; `Zippora.php:1157` |
| `POST /cabinet/zippora/getAppScanResult` | Notification / scan | `sceneId` | yes | `route/app.php:194`; `Zippora.php:1174` |
| `POST /cabinet/zippora/getPickList` | Pickup / retrieval | `memberId`, `passedDays`, `cFlag` | yes | `route/app.php:195`; `Zippora.php:2979` |
| `POST /cabinet/zippora/getPickListPickMart` | Pickup / retrieval | `passedDays` | yes | `route/app.php:196`; `Zippora.php:3756` |
| `POST /cabinet/zippora/proveCode` | Pickup / retrieval | `code`, `codeType` | yes | `route/app.php:197`; `Zippora.php:3069` |
| `POST /cabinet/zippora/proveCodeFromMemberid` | Pickup / retrieval | `memberid`, `codeType` | yes | `route/app.php:198`; `Zippora.php:3784` |
| `POST /cabinet/zippora/proveCodePickMart` | Pickup / retrieval | `code`, `codeType` | yes | `route/app.php:199`; `Zippora.php:3800` |
| `POST /cabinet/zippora/resendPickCode` | Pickup / retrieval | `phone`, `email` | yes | `route/app.php:200`; `Zippora.php:3161` |
| `POST /cabinet/zippora/commitForSelfStore` | Deposit / allocation | `fromMemberId`, `boxId` | yes | `route/app.php:201`; `Zippora.php:3703` |
| `POST /cabinet/zippora/commitForStore` | Deposit / allocation | `courierId`, `toMemberId`, `boxId`, `trackingNo`, `tokenId`, `unitId` | yes | `route/app.php:202`; `Zippora.php:3386` |
| `POST /cabinet/zippora/commitForManualDropoff` | Deposit / allocation | `courierId`, `toMemberId`, `trackingNo`, `tokenId`, `unitId`, `manualDropoffType`, `manualDropoffLabel` | yes | `route/app.php:203`; `Zippora.php:3458` |
| `POST /cabinet/zippora/commitForStoreEn` | Deposit / allocation | `courierId`, `toMemberId`, `boxId`, `hidNo`, `trackingNo` | — | `route/app.php:204`; `Zippora.php:3639` |
| `POST /cabinet/zippora/commitForStoreN` | Deposit / allocation | `courierId`, `toMemberId`, `boxId`, `trackingNo`, `tokenId`, `unitId` | yes | `route/app.php:205`; `Zippora.php:3522` |
| `POST /cabinet/zippora/firebaseFcmPushHeyChee` | Notification / scan | — | — | `route/app.php:206`; `Zippora.php:5098` |
| `POST /cabinet/zippora/firebaseFcmPushN` | Notification / scan | — | — | `route/app.php:207`; `Zippora.php:5074` |
| `POST /cabinet/zippora/firebaseFcmPushNew` | Notification / scan | — | — | `route/app.php:208`; `Zippora.php:5041` |
| `POST /cabinet/zippora/firebasegetAccessTokenHeyChee` | Notification / scan | — | — | `route/app.php:209`; `Zippora.php:5065` |
| `POST /cabinet/zippora/getFirebaseToken` | Other cabinet operation | — | — | `route/app.php:210`; `Zippora.php:5049` |
| `POST /cabinet/zippora/identifyCard` | Identity / access | `cardCode` | yes | `route/app.php:211`; `Zippora.php:3266` |
| `POST /cabinet/zippora/identifyPackNo` | Deposit / allocation | `packCode` | yes | `route/app.php:212`; `Zippora.php:4726` |
| `POST /cabinet/zippora/preAuthForBox` | Deposit / allocation | `boxModelId`, `OrderId`, `UserId` | yes | `route/app.php:213`; `Zippora.php:1778` |
| `POST /cabinet/zippora/preAuthForBoxN` | Deposit / allocation | `boxId` | yes | `route/app.php:214`; `Zippora.php:3363` |
| `POST /cabinet/zippora/sendStore` | Deposit / allocation | `cabinetid`, `tomemberid`, `pickcode`, `toemail`, `tophone`, `unitid` | — | `route/app.php:215`; `Zippora.php:3599` |
| `POST /cabinet/zippora/commitForOcStore` | Deposit / allocation | `ocCourierId`, `boxId`, `ocOrderId`, `OrderStore`, `OrderEmail`, `OrderPhone`, `CustomerId` | yes | `route/app.php:216`; `Zippora.php:4762` |
| `POST /cabinet/zippora/getOcCustomerByOcOrderId` | Other cabinet operation | — | yes | `route/app.php:217`; inherited or missing method |
| `POST /cabinet/zippora/getOrder` | Configuration / catalog | `OrderId` | — | `route/app.php:218`; `Zippora.php:4923` |
| `POST /cabinet/zippora/commitForStoreShare` | Deposit / allocation | `courierId`, `toMemberId`, `boxId`, `trackingNo` | yes | `route/app.php:219`; `Zippora.php:4829` |
| `POST /cabinet/zippora/getUser` | Identity / access | `userId` | yes | `route/app.php:220`; `Zippora.php:4898` |
| `POST /cabinet/zippora/userLogin` | Identity / access | `userId`, `userEmail`, `userPhone`, `userPassword` | — | `route/app.php:221`; `Zippora.php:4949` |
| `POST /cabinet/zippora/readAlarmsetting` | Maintenance / diagnostics | — | yes | `route/app.php:222`; `Zippora.php:2966` |
| `POST /cabinet/zippora/setAlarm` | Maintenance / diagnostics | `startTime`, `endTime`, `flag` | yes | `route/app.php:223`; `Zippora.php:2936` |
| `POST /cabinet/zippora/commitForAsset` | Deposit / allocation | `storeId` | yes | `route/app.php:224`; `Zippora.php:4605` |
| `POST /cabinet/zippora/commitForAssetAdmin` | Deposit / allocation | `rfId`, `boxId`, `status_code`, `service_type` | yes | `route/app.php:225`; `Zippora.php:4638` |
| `POST /cabinet/zippora/commitForAssetReturn` | Deposit / allocation | `rfId`, `boxId` | yes | `route/app.php:226`; `Zippora.php:4676` |
| `POST /cabinet/zippora/getApartmentId` | Configuration / catalog | — | — | `route/app.php:227`; `Zippora.php:4238` |
| `POST /cabinet/zippora/getBoxInfoForRent` | Configuration / catalog | `boxId` | yes | `route/app.php:228`; `Zippora.php:4406` |
| `POST /cabinet/zippora/getBoxModelFromRfid` | Configuration / catalog | `rfId` | — | `route/app.php:229`; `Zippora.php:4427` |
| `POST /cabinet/zippora/getCategoryList` | Configuration / catalog | `apartmentId` | yes | `route/app.php:230`; `Zippora.php:4262` |
| `POST /cabinet/zippora/getCategoryListN` | Configuration / catalog | — | yes | `route/app.php:231`; `Zippora.php:4275` |
| `POST /cabinet/zippora/getProductInfo` | Configuration / catalog | `productId`, `categoryId`, `apartmentId` | — | `route/app.php:232`; `Zippora.php:4288` |
| `POST /cabinet/zippora/getProductInventoryList` | Configuration / catalog | — | yes | `route/app.php:233`; `Zippora.php:4310` |
| `POST /cabinet/zippora/getProductInventoryListN` | Configuration / catalog | `categoryId` | yes | `route/app.php:234`; `Zippora.php:4322` |
| `POST /cabinet/zippora/getProductList` | Configuration / catalog | `apartmentId` | — | `route/app.php:235`; `Zippora.php:4249` |
| `POST /cabinet/zippora/identifyCardForRent` | Other cabinet operation | `cardCode` | yes | `route/app.php:236`; `Zippora.php:4340` |
| `POST /cabinet/zippora/identifyCardForReturn` | Other cabinet operation | `cardCode`, `inputflag` | yes | `route/app.php:237`; `Zippora.php:4358` |
| `POST /cabinet/zippora/preAuthForAdmin` | Deposit / allocation | `boxModelId` | — | `route/app.php:238`; `Zippora.php:4488` |
| `POST /cabinet/zippora/preAuthForBoxReturn` | Deposit / allocation | `boxModelId` | yes | `route/app.php:239`; `Zippora.php:4466` |
| `POST /cabinet/zippora/proveCodeForAsset` | Pickup / retrieval | `code`, `codeType` | yes | `route/app.php:240`; `Zippora.php:4582` |
| `POST /cabinet/zippora/releaseBoxid` | Maintenance / diagnostics | `boxId` | — | `route/app.php:241`; `Zippora.php:4510` |
| `POST /cabinet/zippora/testa` | Maintenance / diagnostics | — | — | `route/app.php:242`; `Zippora.php:726` |

## Delivery implementation map

- **Implemented separately:** `GET /api/delivery/v1/devices/me/cabinet-config` is a signed, read-only structural projection for a bound, active, Delivery-only cabinet. It exposes the familiar `boxConfig.cabinets[].boxes[]` shape plus `boxModels[]`, exact installed board and door addresses, and measured compartment dimensions; every box is unavailable and every model has `availableCount: 0`. `GET /api/delivery/v1/devices/me/box-models` returns the same revision and model summary separately. Signed `POST /devices/me/pairings`, driver `POST /pairings/{id}/approve` and signed `GET /devices/me/pairings/{id}` implement final-deposit pairing. `POST /api/delivery/v1/devices/me/command-events` accepts ordered, idempotent signed terminal reports of dispatch, open and close; it never transfers custody. These use enrolled-device signatures and nonce checks; see the [OpenAPI operations](../handoff/contracts/openapi.json). They do not implement TP8 URL aliases or claim wire compatibility with the C# deserializer.
- **Existing Delivery workflows:** shipping, synthetic origin deposit, final deposit preparation and driver confirmation, driver scans, recipient claims, signed device command polling and ordered terminal observation reporting have their own `/api/delivery/v1` routes. They are not aliases of TP8 `preAuthForBox`, `commitForStore`, `proveCode`, or `commitForPick`; TP8 owns its apartment orders, wallet charges, and legacy occupancy.
- **Remain on TP8:** Product and Ziplocker services; Zippora apartment, asset/rental, marketplace, manual dropoff, payment, notification, alarm, and admin-card actions. A new Delivery requirement in one of those domains needs its own authorization, state machine, and test; copying an endpoint name would be misleading.
- **Blocked for shared hardware:** TP8 `assignBox` and `releaseBox` do not consult Delivery ownership manifests. No Delivery endpoint may return allocable shared doors or write TP8 box state until both allocator boundaries and acknowledgments are verified.
- **Security boundary:** Do not reproduce TP8 MD5 token signing or its `debugSign` error response. Delivery uses enrolled-device signatures for terminal APIs.

## Terminal API readiness by actual Delivery flow

| Terminal need | Delivery API state | Remaining work |
| --- | --- | --- |
| Device identity, configuration and model catalog | Signed cabinet configuration and box-model list exist; signed device command polling exists. Final-deposit pairing scenes can be created by the device, approved by an authenticated driver with the scanned scene code, and polled by that same device. | Enroll a distinct Delivery credential, verify the actual C# DTO mapping, and add the separate Delivery client. The current terminal still points at TP8. |
| Sender origin dropoff (`preAuthForBox`, store/commit equivalents) | Development-only synthetic origin deposit exists for a synthetic shipment; it does not use the terminal or real inventory. | Add real app/device pairing, label scan, paid size upgrade, Delivery-only compartment reservation, command dispatch, evidence and sender attestation. |
| Driver final deposit | Driver preparation, signed command polling, ordered terminal observations, and driver placement confirmation now exist. Confirmation checks the same driver/run/stop, package version, held compartment claim, Delivery-only site/ownership and three signed events before `AT_DESTINATION` custody. | Add recovery for unknown/ambiguous events, recipient notification delivery, and a physical pilot. Local tests validate software transitions only. |
| Recipient pickup (`proveCode`, pick-list, `commitForPick` equivalents) | Customer recipient claim exists, but terminal pickup authorization and execution do not. | Add one-time pickup grant verification at the enrolled destination device, command/evidence sequence, recipient removal confirmation and `COLLECTED` custody transition. |
| TP8 apartment, asset rental, marketplace, alarm and admin-card operations | No Delivery equivalent because these are separate TP8 products or cabinet maintenance features. | Keep legacy terminal screens on the existing TP8 client; add Delivery-specific functions only when this application has a defined workflow and data model. |

The new observation endpoint records signed terminal claims. It deliberately marks `physical_hardware_verified=false`; tests without a real locker cannot prove a door moved. No terminal feature should describe a signed report alone as physical delivery.

## Terminal service action names without an explicit Zippora route

`blockBox`, `checkNewDepositId`, `getAdminCardList`, `getBuildingList`, `getConfig`, `getPrintList`, `getRoomList`, `getRoomMemberList`, `getUnitList`, `getUnitMemberList`, `preAuthForStore`, `releaseblockBox`, `reportError`, `sendemailInfo`, `updateSequence`.

These names may be inherited from `Base`, served through TP8’s generic fallback route, belong to another deployed version, or be dead client methods. Check each real caller and sanitized nonproduction response before adding a Delivery equivalent. In particular, `getConfig` is implemented for operator/member modules, not as an explicit cabinet/Zippora action in this checkout.

## Next contract checks

Capture sanitized nonproduction fixtures for the effective terminal-used actions, validate against current C# DTOs on Windows, then add only the Delivery flows needed by the new terminal client. Preserve legacy host/routing and single serial controller. This inventory does not assert that all TP8 routes are safe, reachable, or needed by Delivery.
