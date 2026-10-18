# Waimart — Final Acceptance Report

**Date:** 18 October 2026
**Scope:** the "Final Requirements Amendment" (sections A–H), built in 13 phases. Each phase was tested and approved before the next began.
**Environment:** Windows 11, XAMPP 8.2.12 (PHP 8.2.12, MariaDB 10.4.32, Apache).

## Summary

All **17 acceptance criteria in section H are met**, each backed by automated tests that pass:

| Suite | Tests | Assertions | Result |
|---|---|---|---|
| Unit | 38 | 513 | ✅ pass |
| Integration (real database, including multi-process race tests) | 142 | 725 | ✅ pass |
| Feature (HTTP, end to end) | 47 | 295 | ✅ pass |
| Http (through Apache) | 36 | 151 | ✅ pass |
| **Total** | **263** | **1,684** | **0 failures, 0 errors, 0 skipped** (~60 s) |

Browser checks in Microsoft Edge (`tests/Browser`) also pass:
- **Checkout:** guest checkout end to end, with Place order double-clicked on purpose.
- **Accessibility:** WCAG 2.1 AA, with 0 serious or critical issues on 41 pages.
- **Layout:** 129 phone, tablet and desktop checks with no overflow and no console errors.
- **Admin forms:** a form's clicked-button value survives the double-submit guard.

Requirement-by-requirement notes are in [REQUIREMENTS_CHECKLIST.md](REQUIREMENTS_CHECKLIST.md).

## Section H — acceptance criteria and evidence

Test names are `Class::method`; files are under `tests/`.

### 1. Home delivery and pickup are both functional ✅
- **Checkout:** customers choose home/business delivery (county and town; fee and delivery days from the delivery zone) or a pickup station.
- **Delivery flow:** confirmed → processing → packed → dispatched → out for delivery → delivered.
- **Pickup flow:** confirmed → processing → ready for collection → collected (needs the customer's code).
- **Evidence:**
  - `CheckoutPrepareTest` (10 tests: zones, fees, free-delivery threshold, unsupported locations, pickup rules)
  - `ReservationLifecycleTest::testScenario7ConsumeOnceAtDispatch`
  - `CollectionWorkflowTest::testCorrectCodeCollectsAndConsumesOnce`
  - `PickupHttpTest::testDeskFlowWithPermissionsAndNoBypass`
  - browser `checkout-flow.mjs` (a real pickup order placed and tracked)

### 2. Pickup points are configurable and inactive locations cannot be selected ✅
- **Admin:** Pickup points (`pickup_points.manage`) sets code, address, county/town, hours and active status. The public "Pickup stations" page lists active points only.
- **Evidence:**
  - `FulfilmentAdminTest::testPickupPointValidation`
  - `FulfilmentAdminTest::testDeactivatedPointsLeaveCheckoutButOrdersKeepTheirSnapshot`
  - `CheckoutPrepareTest::testPickupRules` (an inactive or unknown point is rejected server-side)
  - `PickupHttpTest::testAdminFulfilmentScreensArePermissionedAndPublicDirectoryUpdates`

### 3. Orders preserve the selected fulfilment details ✅
- **Snapshot:** each order stores its fulfilment method, address or pickup-point details, delivery fee, prices and item names at the moment of ordering. Later edits or deactivations don't change past orders.
- **Evidence:**
  - `OrderPlacementTest::testPlacementCreatesOrderSnapshotsAndReservationsTogether`
  - `FulfilmentAdminTest::testDeactivatedPointsLeaveCheckoutButOrdersKeepTheirSnapshot`
  - `CheckoutPrepareTest::testDeliveryFeeComputedServerSideIgnoringPostedValues`

### 4. Guest and registered checkout both work ✅
- **Guests:** order with name and phone. They get a private tracking link, and can later attach the order to an account after a verified code.
- **Registered customers:** use saved addresses and see order history. A guest cart merges into the account cart at sign-in.
- **Evidence:**
  - `OrderFlowTest::testGuestPlacesOrderAndSeesConfirmation`
  - `OrderFlowTest::testGuestOrderLinksToAccountOnlyWithVerifiedCode`
  - `CustomerFlowTest::testGuestCartSurvivesNavigationAndMergesOnLogin`
  - `CheckoutPrepareTest::testSavedAddressMustBelongToCustomer`
  - `CartServiceTest::testMergeOnLoginSumsButNeverExceedsStock`

### 5. Stock is reserved transactionally at successful order placement ✅
- **One transaction:** order, items, reservations and ledger rows are written together. Stock rows are locked in a fixed order and rechecked under the lock. Carts never reserve stock.
- **Idempotent submit:** the checkout key makes a repeated or double-clicked submit return the same order.
- **Evidence:**
  - `OrderPlacementTest::testPlacementCreatesOrderSnapshotsAndReservationsTogether`
  - `OrderPlacementTest::testScenario10FailureMidReservationRollsBackEverything`
  - `OrderPlacementTest::testScenario11RetryWithSameKeyReturnsSameOrder`
  - `OrderFlowTest::testDoubleSubmitAndStaleFormsNeverCreateSecondOrder`
  - `CartServiceTest::testGuestCartPersistsByTokenAndNeverReservesStock`

### 6. Concurrent orders cannot oversell stock ✅
- **Real races:** separate PHP processes, each with its own database connection, race each other. Database constraints are a second line of defence: stock can't go negative, and reserved stock can't exceed stock on hand.
- **Evidence:**
  - `ConcurrencyTest::testScenario1ManyCustomersOneLastUnit` (8 buyers, 1 unit: exactly 1 wins)
  - `ConcurrencyTest::testSameSubmissionInParallelCreatesOneOrder` (6 identical submits: 1 order)
  - `HeavyConcurrencyTest::testManyBuyersWithCrossedMultiLineCartsNeverOversell` (16 buyers, crossed two-line carts: exactly the 4 that fit, no deadlock crash)
  - `SchemaSafeguardsTest::testInventoryCannotGoNegative`
  - `SchemaSafeguardsTest::testReservedCannotExceedOnHand`

### 7. Reservation expiry releases inventory safely ✅
- **Configurable windows:** `reservation_minutes` (M-Pesa, 30) and `reservation_minutes_cod` (COD, 240) are set in Admin → Settings.
- **Worker:** `bin/expire-reservations.php` runs under a named lock, and every release happens once only. Confirmed orders never expire.
- **Evidence:**
  - `ReservationLifecycleTest::testScenario3ExpiryReleasesStockForOtherCustomers`
  - `ReservationLifecycleTest::testScenario4ExpiryIsIdempotent`
  - `ReservationLifecycleTest::testExpiryRespectsNamedLock`
  - `ReservationLifecycleTest::testConfirmedOrdersNeverExpire`
  - `ConcurrencyTest::testConcurrentExpiryWorkersReleaseEachReservationOnce` (4 simultaneous workers)
  - `OrderLifecycleHttpTest::testExpiryCommandLineWorker`

### 8. Cancellation and rejection release eligible reservations exactly once ✅
- **Cancellation:** releases reserved stock once, even if repeated. Cancelling after dispatch is refused; returns are used instead. Cancelling a verified payment records a refund obligation.
- **Payment rejection:** returns the order to awaiting payment so the customer can retry. The reservation keeps its original expiry (never extended) and is then released once by the expiry worker. Staff rejecting an order itself (e.g. an unconfirmed COD order) cancel it, which releases stock immediately and exactly once.
- **Evidence:**
  - `ReservationLifecycleTest::testScenario5CancelTwiceReleasesOnce`
  - `ReservationLifecycleTest::testScenario8CancelAfterDispatchIsRefused`
  - `ReservationLifecycleTest::testCancellingAVerifiedPaymentRecordsRefundDue`
  - `PaymentFlowTest::testRejectReturnsToAwaitingPaymentAndRecordsMoneyReceived`
  - `CollectionWorkflowTest::testCancellingReadyOrderInvalidatesCodeAndReleasesStock`
  - `SchemaSafeguardsTest::testStockMovementRecordedOncePerReservationTransition`

### 9. Fulfilment consumes stock exactly once ✅
- **When stock is deducted:** at dispatch (delivery) or at verified collection (pickup), with a database-unique ledger entry per reservation. Expired reservations can never be consumed.
- **Evidence:**
  - `ReservationLifecycleTest::testScenario7ConsumeOnceAtDispatch`
  - `ReservationLifecycleTest::testExpiredReservationsCanNeverBeConsumed`
  - `CollectionWorkflowTest::testCorrectCodeCollectsAndConsumesOnce`
  - `InventoryServiceTest::testLedgerReconcilesWithInventory`

### 10. Returns use a controlled inspection and restocking workflow ✅
- **Steps:** request → approve or reject → received → each item inspected once (pass = restock, fail = write-off) → refund amount decided separately.
- **No automatic restock:** stock is never restored by the request or the receipt alone.
- **Evidence:**
  - `ReturnsRefundsReviewsTest::testScenario9InspectionDecidesRestockAndRefundIsSeparate`
  - `ReturnsRefundsReviewsTest::testEligibilityAndQuantityRules`
  - `CustomerServiceHttpTest::testReturnRequestToInspectionOverHttp`
  - `SchemaSafeguardsTest::testOrderItemReturnedCannotExceedFulfilled`

### 11. Manual M-Pesa payments remain pending until verified ✅
- **Submission:** a customer submitting an M-Pesa code doesn't confirm the order or extend the reservation, and every message says payment is not yet confirmed.
- **Verification:** finance staff verify against the statement. Underpayment can't be verified; overpayment records a refund. Each code can be used once across all orders.
- **Evidence:**
  - `PaymentFlowTest::testSubmissionNeverConfirmsOrExtendsTheReservation`
  - `PaymentFlowTest::testVerifyConfirmsAndHoldsStockAndIsSingleDecision`
  - `PaymentFlowTest::testUnderpaymentCannotBeVerifiedAndOverpaymentRecordsRefund`
  - `ReservationLifecycleTest::testSubmittedButUnverifiedPaymentStillExpires`
  - `SchemaSafeguardsTest::testMpesaCodeCannotBeReusedAcrossOrders`
  - `StorefrontPagesTest::testHowToPayNeverClaimsSubmissionEqualsPayment`

### 12. Late payment verification after reservation expiry is handled safely ✅
- **Resolution case:** verifying a payment on an expired order opens a resolution case (`requires_resolution`); stock is never promised silently.
- **Staff choices:** re-reserve if stock is available, or cancel and record a refund.
- **Evidence:**
  - `PaymentFlowTest::testScenario6LatePaymentGoesToResolutionThenReReserve`
  - `PaymentFlowTest::testScenario6LatePaymentWhenStockIsGoneIsCancelledWithRefund`
  - `HeavyConcurrencyTest::testPaymentVerificationRacingTheExpiryWorker` (8 verifications racing 2 expiry workers: each order ends either confirmed with its stock held, or flagged for staff with its stock released exactly once — never both)

### 13. COD collection is recorded separately from order delivery ✅
- **Separate record:** marking an order delivered doesn't record cash. Staff with `cod.collect` record the amount received once, with any difference and a note. A COD reconciliation report compares expected and collected amounts.
- **Evidence:**
  - `PaymentFlowTest::testCodCashRecordedSeparatelyOnceAndExplained`
  - `ReturnsRefundsReviewsTest::testCancellingTheOnlyRefundRestoresCodStatus`
  - `AdminPhase10Test::testReportDefinitions` ("booked sales" vs "cash received")

### 14. Customers can access only their own private order information ✅
- **Who sees an order:** signed-in owners see their orders. Guests use a private tracking link; the token is removed from the address bar after use.
- **Lookup:** by order number and phone, with a generic answer and rate limiting. Pickup codes are shown only to the owner or private-link holders.
- **Evidence:**
  - `OrderFlowTest::testCustomersCannotSeeEachOthersOrders`
  - `OrderFlowTest::testPrivateTrackingLinkWorksAndHidesPersonalData`
  - `OrderFlowTest::testLookupByNumberAndPhoneIsGenericAndRateLimited`
  - `PickupHttpTest::testCodeVisibleOnlyToOwnerOrPrivateLinkHolders`
  - `PaymentHttpTest::testStrangerCannotSubmitPaymentForSomeoneElsesOrder`
  - `OrderLifecycleHttpTest::testStrangersCannotCancelOrders`
  - `CustomerFlowTest::testUsersCannotTouchEachOthersAddresses`

### 15. Administrative actions are permission-checked and audited ✅
- **Permissions:** 6 roles and fine-grained permissions. Every admin route is checked automatically for staff status plus a permission.
- **Audit:** staff decisions on orders, payments, cash, stock, settings, staff accounts and exports are written to the audit log, with secrets redacted.
- **Evidence:**
  - `RouteSecurityTest` (5 tests sweeping every route)
  - `AdminCatalogueTest::testRolePermissionsAreEnforced`
  - `OrderLifecycleHttpTest::testStaffActionsArePermissionChecked`
  - `AdminPhase10HttpTest::testStaffAdministrationIsSuperAdminOnlyAndAudited`
  - `AdminPhase10HttpTest::testReportsAndCsvExportAreAudited`
  - `PaymentFlowTest::testSettingsValidationAuditAndPaymentOptions`
  - `InventoryServiceTest::testReceiveIncreasesOnHandAndWritesLedgerAndAudit`
  - `SecurityFoundationTest::testAuditLogRedactsSecrets`

### 16. All relevant automated tests pass, or any outstanding failure is explicitly documented ✅
- **Current run:** 263 tests, 1,684 assertions, 0 failures, 0 errors, 0 skipped. All browser checks pass. There are no outstanding failures.
- **Earlier failures:** every failure found during the build was fixed and retested; each is recorded in that phase's notes in the checklist.
- **Unexplained slow run:** in Phase 12, one full run took 4 h 16 min instead of about 1 min. It never recurred in later runs, including this one; see the Phase 12 notes.

### 17. Installation, scheduled expiry processing, backup and recovery are documented ✅
- **Installation:** [INSTALL.md](INSTALL.md)
- **Scheduled expiry worker:** Windows Task Scheduler and Linux cron, in [OPERATIONS.md](OPERATIONS.md) and INSTALL step 8.
- **Backup and recovery:** [BACKUP_RESTORE.md](BACKUP_RESTORE.md), covering what to back up (database, uploads, app key), scheduling, off-site copies, restore and rebuild-on-a-new-machine.
- **Restore drill done:** a live backup was restored into a second database, and all 47 tables matched by checksum. `BackupRestoreTest` repeats this on every test run.

## Before going live — owner actions

These need information or decisions from the shop owner; no code changes are required.

1. **M-Pesa details:** enter the real Till, Paybill and/or phone number in Admin → Settings. A method stays hidden at checkout until it is set.
2. **Your administrator:** `php bin/create-admin.php --email=…`. Then remove the test account `screenshot-bot@waimart.local`, which is already suspended with no roles.
3. **Scheduled tasks:** create the expiry worker (required) and nightly backup tasks (INSTALL steps 8–9), and copy backups off the machine regularly.
4. **Production settings:** `env=production`, `debug=false`, the real `app.url`, HTTPS with `session.secure=true`, and SMTP for email. Until SMTP is set, emails are only written to `storage/logs/mail-*.log`.
5. **Real catalogue:** replace the demo catalogue, delivery zones and pickup points with your own. Delete demo and test orders from development, such as WM-W5B3DB4M and WM-UJA8X375 placed by the browser tests; they release their stock on expiry.
6. **Recommended:** raise MariaDB `innodb_buffer_pool_size` to 256M ([OPERATIONS.md](OPERATIONS.md)).

## Known limitations

- **SMS is not sent.** SMS messages (order updates, pickup codes) are stored in the notifications outbox, but no SMS provider is connected. Customers still see their pickup code on their order page or private link, and by email if they gave one. Connecting a provider such as Africa's Talking is a small, separate piece of work.
- **No automatic M-Pesa confirmation.** Payment is manual by design (section A): staff verify each code against the M-Pesa statement. There is no Daraja/STK push and no card payments.
- **Broad catalogue-wide queries slow down as the catalogue grows.** At 20,000 products, category pages take about 0.16 s, but a search matching 2,000 products takes about 0.5 s, and "on sale" across the whole catalogue about 1 s. This is fine for a single store, but caching or a search engine would be needed at much larger scale.
- **MySQL 8 and PHP versions other than 8.2 were not tested.**
