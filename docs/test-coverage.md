# Test coverage of the specification's testing scenarios

This maps every numbered scenario in section 10 of the build specification
("Testing scenarios") to the automated tests that check it. Each mapping was
checked against the test body, not only its name. Tests live in `tests/src`
of the base module and of `modules/content_relay_origin` and
`modules/content_relay_replica`; kernel tests use a fake origin and a fake
replica from `tests/modules/content_relay_test`, so no second site is needed.
Run the whole suite from the project root with:

```bash
ddev exec 'vendor/bin/phpunit -c phpunit.xml web/modules/custom/content_relay'
```

Add `--filter ApplyTest` (or any class or method name) to run part of it.

Statuses:

- **Covered**: a test asserts what the scenario expects.
- **Partial**: a test covers what is named; the rest is listed under Gaps or
  Manual procedures.
- **Manual**: automating it is impractical; see Manual procedures.
- **Not covered**: no test, but one could be written; listed under Gaps.

Where the implementation differs from the scenario on purpose, the decision in
`docs/decisions.md` is cited. Class names are short; "(origin)" or "(replica)"
marks a class name used in both submodules.

## Basic CRUD

- **1.** Create on origin, same UUID and values on replica. Covered: `ApplyTest::testCreateUpdateDelete`, `EndpointTest::testSignedEventIsApplied`, `DeliveryTest::testDeliveryInOrder`.
- **2.** Update makes a new replica revision with the sync log message. Covered: `ApplyTest::testCreateUpdateDelete`.
- **3.** Delete removes the copy and its sync state row. Covered: `ApplyTest::testCreateUpdateDelete`.
- **4.** Unpublish and republish, with and without Content Moderation. Covered: `ApplyTest::testUnpublishAndRepublish` without moderation; with it, `ModerationTest::testForwardDraft`, `ModerationTest::testStatesPerTranslation` and `PortableValuesTest::testModerationState`.
- **5.** Bundles not configured for sync produce no events. Covered: `DeliveryTest::testSaveRecordsLocallyOnly`.

## Structure and references

- **6.** Three levels of nested paragraphs sync intact with their UUIDs. Covered: `EmbeddedEntitiesTest::testSnapshotEmbedsTheTree`, `EmbeddedEntitiesTest::testTreeRoundTrips`.
- **7.** Reordering and removing paragraphs is reflected and orphans cleaned up. Covered: `EmbeddedEntitiesTest::testReorderAndRemove` checks order, edits and that the removed paragraph is no longer referenced. The removed paragraph stays while an earlier node revision uses it; once that revision is deleted, Entity Reference Revisions' orphan purger deletes it, which the test also asserts. The module adds no clean-up of its own.
- **8.** Add, update and delete a translation. Covered: `TranslationNormalizerTest::testTranslationLifecycleOnApply`, `MultilingualTest::testTranslationsAsOneEntity` (replica).
- **9.** Term and media not yet on the replica are created first; references resolve. Covered: dependencies sent first in `DependencyTest::testExistingDependencyIsSentFirst` (nodes) and `MediaEmbedTest::testEmbeddedMediaIsSentFirst` (media and its file); a missing term waits then resolves in `ApplyTest::testDependencyWaitsThenApplies`, a missing media item in `MediaTest::testNodeWaitsForMedia`.
- **10.** Circular references do not loop. Covered: `DependencyTest::testCircularReferences`.

## Ordering, duplicates and gaps

- **11.** The same event twice is applied once. Covered: `ApplyTest::testDuplicateIsAppliedOnce`, `EndpointTest::testSignedEventIsApplied`, `ProcessingTest::testQueued`. Replay protection is per message, and the event ID makes the apply idempotent (decision 1).
- **12.** An older revision after a newer one is refused with 409. Covered: `ApplyTest::testOutOfOrderIsStale`.
- **13.** A sequence gap is filled and the missed change recovered. Covered: `EndpointTest::testGapIsRecorded`, `ReconcileTest::testFillGaps`, `ReadEndpointTest::testStream`. Gaps use `previous_sequence` (decision 12) and are filled from current state (decision 63).
- **14.** Webhook and worker on one UUID at once: the lock holds, newest wins. Covered: `ProcessingTest::testLockedItem`.

## Delivery and failure handling

- **15.** 500 is retried with backoff until the replica recovers. Covered: `DeliveryTest::testServerErrorIsRetried`.
- **16.** Repeated timeouts dead-letter after the configured attempts. Covered: `DeliveryTest::testTimeoutsDeadLetter`.
- **17.** 400 dead-letters at once, no retries. Covered: `DeliveryTest::testFinalResponses`.
- **18.** Replay of a failed update sends the current state. Covered: `DeliveryTest::testReplaySendsCurrentState`, `OriginItemUiTest::testEventLogAndReplay` (decision 11).
- **19.** Replay of a delete for a recreated entity does not delete it. Covered: `DeliveryTest::testReplayOfDeleteForRecreatedEntity` (decision 30).
- **20.** Origin outage then recovery: queued events drain in order. Partial: a queue that builds up and drains in order is covered by `DeliveryTest::testDeliveryInOrder`, `DeliveryTest::testQueueAndCron` and `ContractTest::testPauseAndResume`; a real outage is manual procedure M2.
- **21.** Replica down for hours with many edits catches up correctly. Manual (M3). Supporting tests: `DeliveryTest::testServerErrorIsRetried` (later events held back, decision 13), `DeliveryTest::testQueuedUpdatesAreCoalesced`.
- **22.** Saving while the replica is unreachable is not slowed or failed. Covered: `DeliveryTest::testSaveRecordsLocallyOnly` asserts no HTTP request during the save (decision 11).

## Processing modes

- **23.** Immediate returns 200 with the entity in place. Covered: `ProcessingTest::testImmediate`.
- **24.** Queued returns 202, worker applies, origin learns the outcome from the status endpoint. Covered: `ProcessingTest::testQueued`, `StatusPollTest::testSettled` (decision 49).
- **25.** Auto queues events with files and applies others at once. Covered: `ProcessingTest::testAuto` (decision 50 adds "busy" cases).
- **26.** A per-event `immediate` from a manual push is honored. Covered: `ProcessingTest::testImmediateOverride`, `ManualPushTest::testPushSendsNow`.

## Conflicts and replica-only content

- **27.** Local edit then origin update: conflict flagged per configuration, edit kept in history. Covered: `ConflictTest::testOverwriteKeepsTheEditInHistory`, `ConflictTest::testRejectSetting`, `ConflictTest::testConflictEventCanReject`, `ConflictTest::testKeepLocalRestoresTheEdit`, `ModerationTest::testDraftConflict` (decisions 57, 59, 74).
- **28.** Manual pull on an edited item shows a diff and needs confirmation. Covered: `ReplicaItemUiTest::testPullForm` (an unedited item pulls straight away; an edited one shows the diff and changes nothing until "Accept origin version" or "Keep local and detach"; without the resolve permission the choice is hidden) and `ConflictTest::testPullNeedsConfirmation` (service).
- **29.** Local-only fields are never overwritten and make no conflict. Covered: `ConflictTest::testLocalOnlyFields`, `GenericNormalizerTest::testPreservedFieldsAreKept`.
- **30.** Replica-only content is never touched by sync or reconciliation. Covered: `ApplyTest::testUuidCollisionIsRefused`, `ReconcileTest::testDeletedOnOrigin`.
- **31.** An event for a UUID with no sync state is rejected and logged. Covered: `ApplyTest::testUuidCollisionIsRefused`. It is a 422 `uuid_collision` recorded in the failed events table, not only logged (decision 14).

## Files

- **32.** Image and PDF: entity at once, binary by the file queue, checksum verified. Covered: `FileSyncTest::testFileArrives`, `FileSyncTest::testContentWaitsForFiles`, `FileTest::testFileFollowsContent` (decision 36).
- **33.** Checksum mismatch is retried, then an error; content follows the "pending files" setting. Covered: `FileSyncTest::testChecksumMismatch`, `FileSyncTest::testContentWaitsForFiles`.
- **34.** An unchanged file is not downloaded again. Covered: `FileSyncTest::testFileArrives`.
- **35.** A 500 MB file streams within memory limits. Manual (M4). Supporting test: `FileCipherTest::testLargeFileStreams` (32 MB through the cipher under 4 MB of memory).
- **36.** Expired or tampered signed URLs are refused. Covered: `FileTest::testDownloadLinks` (decision 39).
- **37.** With encryption on, the file is unreadable without the per-file key. Covered: `EncryptionTest::testEncryptedFiles` (origin), `FileSyncTest::testEncryptedDownload`, `FileCipherTest::testRefusals`. Keys are derived, not random and stored (decision 45).

## Pairing

- **38.** A valid code pairs and exchanges keys. Covered: `PairingTest::testValidPairing`, `SubscriptionTest::testPairing`, `ReplicaAdminTest::testPairing`.
- **39.** An expired code is rejected. Covered: `PairingTest::testExpiredCode`.
- **40.** A reused code is rejected. Covered: `PairingTest::testReusedCode`, `PairingTest::testCodes`.
- **41.** Code length is enforced in settings and in generated codes. Covered: `OriginAdminTest::testSettings`, `OriginAdminTest::testPairingCode`, `PairingTest::testCodes`.
- **42.** Dashes, spaces and lowercase are accepted. Covered: `PairingTest::testCodes`, `ReplicaAdminTest::testPairing`, `SubscriptionTest::testPairing` (look-alike letters fold too, decision 20).
- **43.** The flood limit blocks brute force. Covered: `PairingTest::testFloodLimit`.
- **44.** An `http://` URL is refused. Covered: `PairingTest::testHttpEndpointIsRefused`, `SubscriptionTest::testHttpOriginIsRefused`, `ReplicaAdminTest::testPairingRefusals`, `ReplicaAdminTest::testDrush`.
- **45.** Bundles the code does not allow are not granted. Covered: `PairingTest::testCodeLimitsBundles`.
- **46.** Manual approval holds events until approved. Covered: `PairingTest::testManualApproval`, `OriginAdminTest::testReplicaListAndApproval`.

## Signing, encryption and authentication

- **47.** Tampered body, header and encryption mode are rejected. Covered: `MessageSignatureTest::testRefused` (body, signed header, signed and envelope mode cases), `EndpointTest::testRefusals`.
- **48.** Unknown or revoked keys are rejected. Covered: `MessageSignatureTest::testRefused`, `MessageSignatureTest::testRetiredKeyIsRefused`, `EndpointTest::testRefusals`, `EncryptionTest::testReplicaKeyRotation` (origin).
- **49.** A timestamp outside the window is rejected. Covered: `MessageSignatureTest::testRefused` (too old, from the future).
- **50.** A replayed message is rejected. Covered: `MessageSignatureTest::testReplayIsRefused`, `EndpointTest::testRefusals`, `KeyStoreTest::testSignAndVerifyThroughServices`. Refused by message ID, not event ID (decision 1).
- **51.** A message for another replica is rejected. Covered: `MessageSignatureTest::testRefused` (recipient case), `EndpointTest::testRefusals`.
- **52.** Encrypted payloads are unreadable in transit and decrypt on the replica. Covered: `EncryptionTest::testEncryptedDelivery` (origin), `EncryptionTest::testEncryptedContract` (replica), `MessageSignatureTest::testSealedMessage` (decision 43).
- **53.** With "require encryption", a signed unencrypted message is rejected. Covered: `MessageSignatureTest::testDowngradeIsRefused`, `EncryptionTest::testRequireEncryption` (origin and replica), `EncryptionTest::testEncryptedDelivery` (origin) (decision 48).
- **54.** Switching encryption mode: both accepted in the window, then the new mode enforced. Covered: `EncryptionTest::testTransitionWindow` (origin and replica) (decision 44).
- **55.** Key rotation: both keys work during overlap, the old one fails after retirement. Covered: `MessageSignatureTest::testRotationOverlap`, `EncryptionTest::testOriginKeyRotation`, `EncryptionTest::testReplicaKeyRotation` (origin), `EncryptionTest::testKeyRotation` (replica) (decision 47).
- **56.** Expired, wrong-audience, reused and wrong-scope JWTs are rejected. Covered: `JwtAuthenticatorTest::testRefused`, `JwtAuthenticatorTest::testValidToken`, `FileTest::testFileEndpoint`.
- **57.** The sync role cannot write through the read endpoint. Covered: `ReadEndpointTest::testReadOnly` (POST, PATCH, PUT and DELETE are refused on every read endpoint, with a valid token). There is no sync role: the endpoints are custom, authorized by the replica's JWT and contract, and read-only (decision 52).

## Reconciliation

- **58.** A lost delete is applied by reconciliation. Covered: `ReconcileTest::testDeletedOnOrigin` (decisions 61, 62).
- **59.** A lost update is pulled by reconciliation. Covered: `ReconcileTest::testUpdatedOnOrigin`.
- **60.** Repeating reconciliation when in sync changes nothing. Covered: `ReconcileTest::testRepeatChangesNothing`.

## Permissions and admin

- **61.** Each permission gates its action. Covered: `OriginAdminTest::testPermissions`, `OriginItemUiTest::testPushForm`, `OriginItemUiTest::testReportAndBulkPush`, `OriginItemUiTest::testEventLogAndReplay`, `ReplicaAdminTest::testPermissions`, `ReplicaItemUiTest::testPermission`, `ReplicaItemUiTest::testPullFile`, `ReplicaItemUiTest::testFailures`.
- **62.** Manual push, bulk push and replay are logged with the user. Covered: `triggered_by` for manual push (`ManualPushTest::testPushSendsNow`, `OriginItemUiTest::testPushForm`), bulk push (`ReportTest::testBulkPush`) and replay (`OriginItemUiTest::testEventLogAndReplay`).
- **63.** Drush commands work and exit non-zero on failure. Covered: every command runs through Drush, with its failure exit code: `OriginItemUiTest::testDrush`, `OriginItemUiTest::testMoreDrush` (including `validate`) and `OriginItemUiTest::testOtherEntityTypes` on the origin; `ReplicaAdminTest::testDrush`, `ReplicaItemUiTest::testDrush`, `ReplicaItemUiTest::testMoreDrush` (`pull`, `validate`, `reconcile`, `fill-gaps`, `check-schema`, `reapply-mapping`, `move-language`), `ReplicaItemUiTest::testPullFile`, `ReplicaItemUiTest::testFailures` and `ReplicaItemUiTest::testKeyDrush` (`keys`, `generate-key`, `rotate-key`, `retire-key`) on the replica.

## Performance

- **64.** Bulk resync of 10,000 nodes completes in batches. Manual (M5). Supporting tests: `ReportViewTest::testAllMatchingAndCsv` (60 rows across pages), `OriginItemUiTest::testReportAndBulkPush` (Batch API).
- **65.** Pruning removes only acknowledged events past retention. Covered: `DeliveryTest::testPruning`.
- **66.** A report of 100,000 items loads and filters quickly. Manual (M6). Supporting test: `ReportViewTest::testFilters` (decision 27).

## Entity type coverage

- **67.** Each core entity type creates, updates and deletes correctly. Covered: `AllEntityTypesApplyTest::testEveryTypeCreatesUpdatesAndDeletes` (replica) discovers every syncable type and bundle (over 30, including term, media, file, block content, menu link, path alias, user, comment and shortcut) and applies a create, an update to new sample values and a delete as origin events, checking the copy's hash each time; a type whose fields always fail validation is refused for that reason. Composite types (paragraphs) travel inside their parent: `EmbeddedEntitiesTest`, `ParagraphRollbackTest`. `AllEntityTypesTest::testEveryTypeRoundTrips` checks the snapshot round trip (decision 22).
- **68.** A custom entity type from a test module syncs with the generic normalizer. Covered: `NormalizerDiscoveryTest::testGenericNormalizerForCustomType`, `AllEntityTypesTest::testEveryTypeRoundTrips`, `ApplyTest::testNonRevisionableStaleness`.
- **69.** A non-revisionable type syncs and stale updates are rejected. Covered: `ApplyTest::testNonRevisionableStaleness`. Staleness uses the version counter and changed time, not the hash (decision 3).
- **70.** Menu link hierarchy and path aliases resolve by UUID. Covered: `PortableValuesTest::testMenuHierarchy`, `PortableValuesTest::testPathAlias`.
- **71.** Users sync without passwords, sessions or roles unless enabled. Covered: `PortableValuesTest::testUsers`.
- **72.** Types without a UUID key are not offered. Covered: `NormalizerDiscoveryTest::testSyncableEntityTypes`.
- **73.** A third-party normalizer overrides the generic one. Covered: `NormalizerDiscoveryTest::testThirdPartyNormalizerOverrides`.

## Not syncable and detaching

- **74.** A not syncable item produces no events. Covered: `ItemSyncTest::testNotSyncableItemSendsNothing`, `OriginItemUiTest::testNotSyncable`.
- **75.** "Detach" sends a detach and the replica keeps its copy; "remove" deletes it. Covered: `ItemSyncTest::testMarkingSyncedItemDetaches`, `ItemSyncTest::testRemoveBehaviorDeletesReplicaCopy`, `ItemSyncTest::testDefaultBehaviorIsConfigurable`, `DetachTest::testOriginDetachAndAttach` (decision 30).
- **76.** Unmarking queues a full push. Covered: `ItemSyncTest::testUnmarkingSendsAttachAndPush`, `ItemSyncTest::testUnmarkingNeverSentItemCreatesIt`, `DetachTest::testOriginDetachAndAttach`.
- **77.** Not syncable items are skipped by bulk push, reconciliation and manual push, with the reason. Covered: `ReportTest::testBulkPush`, `ManualPushTest::testBlockedItemsAreNotPushed`, `ReconcileTest::testNotSyncableOnOrigin`, `OriginItemUiTest::testNotSyncable`.
- **78.** A replica-scoped flag blocks one replica only. Covered: `ItemSyncTest::testReplicaScopedFlag`.

## Manual push

- **79.** "Push now" sends the current revision and shows the result inline. Covered: `ManualPushTest::testPushSendsNow`, `ManualPushTest::testOutcomes`, `OriginItemUiTest::testPushForm`.
- **80.** In-sync push reports in sync unless forced; forced makes no duplicate revision. Covered: `ManualPushTest::testInSyncUnlessForced`, `ApplyTest::testOutOfOrderIsStale` (same revision answered "unchanged") (decision 31).
- **81.** Push is an entity operation and a Drush command for every syncable type. Covered: `OriginItemUiTest::testOtherEntityTypes` checks the push, not syncable and validate pages exist for every syncable type, and pushes a term (tab and vocabulary operation), a content block (block library operation) and a path alias (a type without a page of its own) from the form, and a term and a block with Drush; nodes in `OriginItemUiTest::testNotSyncable` and `OriginItemUiTest::testDrush`.
- **82.** Users without the push permission cannot see or use it. Covered: `OriginItemUiTest::testPushForm`.

## Revision hashing

- **83.** The same entity hashes the same regardless of key order. Covered: `RevisionHasherTest::testDeterministic`, `CanonicalJsonTest::testMapsAreSortedAndListsAreNot`, `GenericNormalizerTest::testHashIsStableAcrossLoads`.
- **84.** Synced fields change the hash; local-only fields and revision metadata do not. Covered: `RevisionHasherTest::testContentChangesTheHashAndMetadataDoesNot`, `RevisionHasherTest::testLocalOnlyFieldsAreExcluded`, `GenericNormalizerTest::testHashIsStableAcrossLoads` (decision 3).
- **85.** The hash is identical on both sites after an as-is apply. Covered: `ApplyTest::testCreateUpdateDelete`, `GenericNormalizerTest::testRoundTripRecreatesTheEntity`, `AllEntityTypesTest::testEveryTypeRoundTrips`.
- **86.** A payload altered after hashing is rejected with 400. Covered: `ApplyTest::testHashMismatchIsRefused`, `RevisionHasherTest::testMatches`.
- **87.** Translations, paragraphs and file content change the hash; a file path does not. Covered: `RevisionHasherTest::testTranslationHashesAreIndependent`, `RevisionHasherTest::testEmbeddedEntitiesCountPerLanguage`, `EmbeddedEntitiesTest::testTranslatedParagraphs`, `FileNormalizerTest::testFileEntity`, `FileNormalizerTest::testFileFieldCarriesChecksum` (decision 37).
- **88.** Algorithm version change: old hashes stay valid, backfill updates them, no mass out-of-sync. Covered: `ReportTest::testAlgorithmChange` (origin: backfill adds current hashes and keeps the old ones; the report compares revisions, so only an item the replica is behind on is out of sync) and `ReconcileTest::testAlgorithmChange` (replica: only items whose revision differs are fetched) (decision 82); `RevisionHasherTest::testVersionsAreNotComparable` and `RevisionHasherTest::testMatches` cover version separation.
- **89.** The backfill Drush command hashes existing content in batches. Covered: `ReadEndpointTest::testBackfillInChunks` (123 items over two published types, in chunks of 50, string IDs included; an item already hashed is skipped; `--all` recomputes everything), `ReadEndpointTest::testBackfill`, and the command in `OriginItemUiTest::testMoreDrush`.
- **90.** "Validate" detects a replica copy edited directly in the database. Covered: `ReconcileTest::testValidateFindsDatabaseEdit` changes the title with SQL queries and clears the entity cache; `ReconcileTest::testValidate`, `ProcessingTest::testHashRequest` and `ValidationTest::testResults` cover edits through the entity API.

## As-is and mapped import

- **91.** As-is is refused when field types, names or cardinality differ, with a readable report. Covered: `SchemaTest::testCompatibility`, `MappingUiTest::testAsIsRefusedWhenItDoesNotFit`.
- **92.** `article` maps to `news_item` and the data lands correctly. Covered: `MappingTest::testMappedImport` (decision 53).
- **93.** Text format, default value, taxonomy lookup, date and concatenation transforms work, with unit tests. Covered: unit tests in `TransformTest` for every transform, including `TransformTest::testTaxonomyLookup` (items and bare UUIDs, vocabulary limit, skip or refuse missing terms, no query for nothing); in use in `MappingTest::testMappedImport`.
- **94.** An unmapped required field without a default rejects the item clearly. Covered: `MappingTest::testUnmappedRequiredField`.
- **95.** Mapping preview shows a real origin item without saving. Covered: `MappingUiTest::testMappingForm`.
- **96.** A new origin field: items flagged for drift, known fields sync, admin alerted. Covered: `MappingTest::testSchemaDrift`, `SchemaTest::testWatcher`.
- **97.** Changing a mapping and confirming re-pull updates items in batches. Covered: `MappingUiTest::testMappingForm` (one item, through the Batch API).
- **98.** Mapping config exports and imports through config sync. Covered: export in `MappingTest::testMappingIsConfiguration`, import through the config importer in `MappingTest::testMappingImportsThroughConfigSync`.
- **99.** Mapped conflict detection uses the local hash, without false flags. Covered: `MappingTest::testLocalEditOnMappedItem` (an unedited item updates with no flag; a local edit is flagged and kept in history) and `MappingTest::testMappedImport` (decision 55).

## Report and bulk push

- **100.** The report shows every syncable item per replica, including never sent. Covered: `ReportTest::testNeverSentAfterRebuild`.
- **101.** Each status filter returns the right items. Covered: statuses are computed in `ReportTest::testStatuses`, `ReportTest::testNeverSentAfterRebuild` and `ItemSyncTest::testSkippedDetachedAnswer`; through the view, in sync, pending and not syncable in `ReportViewTest::testFilters`, and detached, dead-lettered and failed in `ReportViewTest::testMoreFilters`.
- **102.** Filters combine correctly. Covered: `ReportViewTest::testFilters` (out of sync, status, bundle, replica, search), `ReportViewTest::testMoreFilters` (date range alone and with bundle, language) and `MultilingualTest::testMissingTranslationAndLanguagePush` (origin; language with out of sync).
- **103.** "Refresh status" updates in-sync state. Covered: `OriginItemUiTest::testReportAndBulkPush`. It recomputes from what replicas acknowledged rather than calling the batch hash endpoint (decision 33).
- **104.** Bulk push of selected rows queues exactly those. Covered: `ReportTest::testBulkPush`, `OriginItemUiTest::testReportAndBulkPush`.
- **105.** "Push all matching filters" covers every page via Batch API with a summary. Covered: `ReportViewTest::testAllMatchingAndCsv`, `OriginItemUiTest::testReportAndBulkPush`.
- **106.** After bulk push is processed, the same filter returns zero out of sync. Covered: `MultilingualTest::testMissingTranslationAndLanguagePush` (origin) runs the filter again after the push is delivered; `ReportTest::testBulkPush` shows the pushed row in sync.
- **107.** CSV export matches the filtered report. Covered: `ReportViewTest::testAllMatchingAndCsv`, `OriginItemUiTest::testReportAndBulkPush`.
- **108.** A site builder can customize the view without breaking bulk actions. Covered: `OriginItemUiTest::testCustomizedFilters` fixes the bundle filter and adds an exposed label filter, and "Push all matching filters", a selection and the CSV follow them; adding a column in `OriginItemUiTest::testReportAndBulkPush`.

## Replica-side detach

- **109.** Updates to a detached item return `skipped_detached`, unchanged, not retried. Covered: `DetachTest::testDetachedItemIsNotUpdated`, `ItemSyncTest::testSkippedDetachedAnswer`.
- **110.** The origin report shows the detach for that replica only; others keep receiving. Covered: `ItemSyncTest::testDetachOnOneReplicaOnly` with two replicas; `ItemSyncTest::testSkippedDetachedAnswer` and `ItemSyncTest::testNoticesThroughInbox` for the report row.
- **111.** Bulk push and "push all matching filters" skip detached items. Covered: `ReportTest::testBulkPush` (both use the same plan).
- **112.** Deleting a detached item: ignored or followed per setting. Covered: `DetachTest::testDeletesOfDetachedItems`, `ItemSyncTest::testSkippedDetachedAnswer` (deletes are still sent, decision 29).
- **113.** Reconciliation leaves detached items alone. Covered: `ReconcileTest::testDeletedOnOrigin`, `ReconcileTest::testUpdatedOnOrigin`.
- **114.** Reattach over local edits: diff shown, accept or keep local. Covered: `DetachTest::testReattachWithLocalEdits`, `ReplicaItemUiTest::testDetachAndReattach`. The page lists edited translations; the next event is forced to apply (decision 32).
- **115.** Reattach without edits pulls the latest version and returns to in sync. Covered: `DetachTest::testReattachWithoutEdits` and `ReplicaItemUiTest::testBulkDetach` (replica back in sync, attach notice sent); `ItemSyncTest::testNoticesThroughInbox` (the origin answers the attach notice by queuing and sending its latest version).
- **116.** A failed detach notice is corrected later. Covered: `DetachTest::testFailedNoticeIsRetried`, `ItemSyncTest::testSkippedDetachedAnswer`.
- **117.** Bulk detach and reattach use the Batch API. Covered: `ReplicaItemUiTest::testBulkDetach`.
- **118.** Detached items still block UUID collisions. Covered: `DetachTest::testDetachedItemIsNotUpdated` (a create for the UUID is skipped), `ReconcileTest::testUpdatedOnOrigin`.

## Detach from both sides

- **119.** Detached by both: each must reverse its own. Covered: `ItemSyncTest::testDetachedByBothSides`, `DetachTest::testDetachedByBothSides` (decision 29).
- **120.** Neither side can reverse the other's detach. Covered: `ItemSyncTest::testDetachedByBothSides`, `DetachTest::testDetachedByBothSides`.
- **121.** Detach and attach events and notices are signed and tampering is rejected. Covered: `ItemSyncTest::testNoticesThroughInbox` refuses detach and attach notices altered after signing, with no change to the report; notices travel signed and sealed (`DetachTest::testDetachedItemIsNotUpdated`, `EncryptionTest::testTransitionWindow` (origin)), and the shared verifier refuses tampered events (`EndpointTest::testRefusals`, `ContractTest::testTamperedMessagesAreRefused`).
- **122.** Every detach and reattach is logged with user and time and needs its permission. Covered: user and time on replica detaches (`DetachTest::testDetachedItemIsNotUpdated`) and origin flags (`ReportTest::testStatuses`); the detach permission in `ReplicaItemUiTest::testPermission`; the "mark not syncable" permission in `OriginAdminTest::testPermissions`.

## Multilingual

- **123.** `en`, `fr` and `zh-hans` sync as one entity with one UUID. Covered: `MultilingualTest::testTranslationsAsOneEntity` (replica), `TranslationNormalizerTest::testTranslationsShareOneSnapshot`.
- **124.** Changing only `fr` changes only its hash, and only `fr` shows out of sync. Covered: the hash in `TranslationNormalizerTest::testOneTranslationChanges` and `RevisionHasherTest::testTranslationHashesAreIndependent`; the report rows in `MultilingualTest::testTranslationRows` (origin), where only the changed translation's hashes differ.
- **125.** Adding and deleting a translation on origin is mirrored. Covered: `TranslationNormalizerTest::testTranslationLifecycleOnApply`, `MultilingualTest::testTranslationsAsOneEntity` (replica).
- **126.** A replica on `zh-hans` and `en` never receives `fr` in the payload, and is in sync. Covered: `MultilingualTest::testFilteredPayload` (origin).
- **127.** An item only in `fr` is not sent and reported "no languages". Covered: `MultilingualTest::testNoLanguages` (origin).
- **128.** `zh-hans` mapped to `zh-cn` saves correctly and the hash matches. Covered: `MultilingualTest::testLanguageMapping` (replica) (decision 69).
- **129.** An unknown incoming language is skipped or rejected, and flagged. Covered: `MultilingualTest::testUnknownLanguage` (replica), `TranslationNormalizerTest::testUnknownLanguageIsSkipped`.
- **130.** As-is check fails on a missing language or differing translatability. Covered: `SchemaTest::testCompatibility`.
- **131.** Mapped translatable source to untranslatable target uses the configured language. Covered: `MappingTest::testUntranslatableTarget`, with the origin's default language and with a named one.
- **132.** A default language change is applied; if filtered out, the replica keeps its default and flags. Covered: `MultilingualTest::testDefaultLanguageChange` (replica: into a new language in one revision; into a language the copy has, with that translation removed in a revision first; into a language translated only here, as a conflict with the local text kept) (decision 81); the filtered-out case in `MultilingualTest::testDefaultLanguageFilteredOut` (origin and replica) (decision 68).
- **133.** Language-neutral items pass a language filter. Covered: `MultilingualTest::testLanguageNeutral` (origin, `zxx`) (decision 75).
- **134.** Per-translation moderation states are kept. Covered: `ModerationTest::testStatesPerTranslation`, `PortableValuesTest::testModerationState` (decision 72).
- **135.** Translated path aliases and menu links resolve to the right translation. Covered: `PortableValuesTest::testTranslatedMenuLinksAndAliases` (each translation's title, the shared link target and each language's alias rebuilt under a new local ID) and `PortableValuesTest::testPathAlias`.
- **136.** Paragraph translations inside a translated node sync. Covered: `EmbeddedEntitiesTest::testTranslatedParagraphs` (symmetric translations).
- **137.** Term and media translations sync and translated nodes reference them correctly. Covered: `MediaTest::testTranslatedTermAndMedia`.
- **138.** A replica-local translation is kept, unhashed, and the item in sync. Covered: `MultilingualTest::testLocalTranslationsAreKept` (replica).
- **139.** The origin later adding that language is a translation conflict with a diff. Covered: `MultilingualTest::testOriginAddsLanguageMadeHere` (replica) checks the conflict, the French-only diff and "keep local".
- **140.** Detaching one translation stops only that language. Covered: `MultilingualTest::testTranslationDetach` (replica), `MultilingualTest::testTranslationBlocks` (origin) (decision 70).
- **141.** CJK, emoji and right-to-left text round-trip byte for byte with equal hashes. Covered: `RevisionHasherTest::testMultilingualTextSurvivesTransport`, `CanonicalJsonTest::testUnicodeIsWrittenAsIs`, `ApplyTest::testCreateUpdateDelete`.
- **142.** "Any translation out of sync" and "missing translation" filters work; bulk push can be limited to one language. Covered: `ReportViewTest::testFilters` and `MultilingualTest::testMissingTranslationAndLanguagePush` (origin), which also pushes Chinese only; `ReportTest::testBulkPush` for a language the rows are not in.
- **143.** Report labels show in the row's language, falling back to the default. Covered: `MultilingualTest::testTranslationRows` (origin). A row exists only for a translation the item has, so the fallback applies only to a label field that is not translatable, where Drupal gives the default's label.

## Language subscription changes

- **144.** In "all languages" mode a new origin language syncs without action. Covered: `LanguageSubscriptionTest::testAllLanguagesNeedNoDecision`.
- **145.** In "selected" mode a new language waits as pending until opted in. Covered: `LanguageSubscriptionTest::testAddedLanguageIsPending`, `MultilingualTest::testFilteredPayload` (origin).
- **146.** An ignored language stays ignored and is not offered again. Covered: `LanguageSubscriptionTest::testAddedLanguageIsPending`.
- **147.** Adding a language: origin confirms, backfill pulls translations, report goes from pending backfill to in sync. Covered: `SubscriptionTest::testProposal` and `LanguageSubscriptionTest::testAddedLanguageBackfills`, which works the queue through to in sync with the new translation (decision 71).
- **148.** Removing a language with "keep" makes local translations, unhashed. Covered: `LanguageSubscriptionTest::testDroppedLanguageIsKept`.
- **149.** Removing a language with "delete" deletes only those translations. Covered: `LanguageSubscriptionTest::testDroppedLanguageIsDeleted`.
- **150.** Switching "all" to "selected" pre-selects the received languages. Covered: `ReplicaAdminTest::testSwitchToSelectedLanguages`. With none ticked, the origin's languages as of its last schema check and this site's own are kept (decision 21).
- **151.** Switching "selected" to "all" backfills the rest. Covered: `LanguageSubscriptionTest::testAddedLanguageBackfills`.
- **152.** A per-bundle language override applies to events and the report. Covered: stored by `ReplicaAdminTest::testLanguagesPerBundle`; applied to events and report rows in `MultilingualTest::testPerBundleOverride` (origin).
- **153.** An unconfirmed subscription update is retried and nothing new is sent until confirmed. Covered: `SubscriptionTest::testProposalRetried`, `ReplicaAdminTest::testOriginUnreachable`, `ContractTest::testReplicaChangeIsVersioned`.
- **154.** The origin removing a language leaves local translations. Covered: `LanguageSubscriptionTest::testOriginRemovesLanguage`.
- **155.** Changing a langcode mapping moves translations in batches and hashes still match. Covered: `MultilingualTest::testMoveForNewMapping` (replica).
- **156.** Subscription updates and language notices are signed and tampering is rejected. Covered: a tampered subscription update in `ContractTest::testTamperedMessagesAreRefused`, a tampered language notice in `EndpointTest::testLanguageNotices`.
- **157.** Language changes are logged on both sites and blocked without the permission. Covered: blocked in `ReplicaAdminTest::testPermissions`; logged on the origin in `MultilingualTest::testLanguageNotices` (origin) and on the replica in `EndpointTest::testLanguageNotices`; recorded in contract history (`SubscriptionTest::testProposal`, `ContractTest::testHistory`).

## Subscription contract

- **158.** Pairing creates version 1 on both sites; no events before acknowledgement. Covered: `PairingTest::testValidPairing`, `PairingTest::testNoEventsBeforeAcknowledgement`, `SubscriptionTest::testPairing`.
- **159.** A language change makes a new version with `effective_from_sequence`, stored by the replica. Covered: `ContractTest::testReplicaChangeIsVersioned`, `SubscriptionTest::testProposal` (decision 19).
- **160.** Events before the change use the old contract, after it the new. Covered: `ContractTest::testReplicaChangeIsVersioned`, `ApplyTest::testContractVersions`.
- **161.** A withdrawn bundle detaches items by origin rather than deleting. Covered: `ContractTest::testOriginWithdrawsBundle`, `SubscriptionTest::testWithdrawnBundleDetachesItems`.
- **162.** A bundle the origin does not allow is "requested, not granted" with a reason. Covered: `ContractTest::testRequestedButNotGranted`, `PairingTest::testCodeLimitsBundles`, `ReplicaAdminTest::testPairing`.
- **163.** An unknown contract version returns 409 `contract_mismatch`, the contract is resent, the retry succeeds. Covered: `ContractTest::testContractMismatchResendsContract`, `ApplyTest::testContractVersions`, `StatusPollTest::testContractVersionMismatch`.
- **164.** A lost acknowledgement keeps the old contract and is retried. Covered: `ContractTest::testLostAcknowledgementIsRetried`, `PairingTest::testPushedContractIsAcknowledgedInTheResponse`.
- **165.** A paused replica gets events in order after resume. Covered: `ContractTest::testPauseAndResume`, `ReplicaAdminTest::testChangeSubscription`.
- **166.** Delivery windows and rate limits are respected. Covered: `ContractTest::testRateLimitAndWindows`.
- **167.** Encryption and key changes go through a contract update with a transition. Covered: `EncryptionTest::testTransitionWindow`, `EncryptionTest::testOriginKeyRotation` (origin), `EncryptionTest::testKeyRotation` (replica), `OriginItemUiTest::testRequireEncryptionSetting` (decisions 44, 47).
- **168.** Contract history shows user, time and differences on both sites. Covered: `ContractTest::testHistory`, `SubscriptionTest::testProposal`, `ReplicaAdminTest::testChangeSubscription`, `OriginAdminTest::testContractPage`.
- **169.** The plain-language summary matches the stored terms. Covered: `ContractTest::testSummary`, `ReplicaAdminTest::testPairing`.
- **170.** Reconciliation reports mismatched contract versions. Covered: `ReconcileTest::testContractMismatch`.
- **171.** A tampered contract or proposal is rejected on both sides. Covered: `ContractTest::testTamperedMessagesAreRefused`, `SubscriptionTest::testPushedContract`.

## Linked files

- **172.** An inline CKEditor image syncs and points at the replica's copy. Covered: `FileNormalizerTest::testLinksInText`, `FileNormalizerTest::testRoundTripThroughLocalUrls`, `FileSyncTest::testContentWaitsForFiles` (decision 38).
- **173.** A `<drupal-media>` embed syncs the media item and file first. Covered: `MediaEmbedTest::testEmbeddedMediaIsSentFirst`.
- **174.** A plain link to a public PDF syncs it and rewrites the link. Covered: `FileNormalizerTest::testLinksInText`, `FileSyncTest::testContentWaitsForFiles`.
- **175.** A private file link syncs and respects private access on the replica. Covered: `ReplicaItemUiTest::testPrivateFiles` (the file lands in private storage; downloads are refused while nothing uses it, allowed with a published article and refused once it is unpublished, except to users who may see unpublished content); `FileNormalizerTest::testLinksInText` turns `/system/files/...` into a token.
- **176.** An image style URL resolves to the original file. Covered: `FileNormalizerTest::testLinksInText`, `FileNormalizerTest::testRoundTripThroughLocalUrls` (decision 38).
- **177.** Own-domain absolute URLs are linked files; external links are left alone. Covered: `FileNormalizerTest::testLinksInText`.
- **178.** A missing linked file is reported unresolved and the item still syncs. Covered: `FileTest::testUnresolvedLink`, `FileNormalizerTest::testLinksInText`.
- **179.** Replacing a linked PDF changes the hash and delivers the new file. Covered: `FileNormalizerTest::testLinksInText`, `FileTest::testFileFollowsContent`.
- **180.** Rewritten links do not change the hash. Covered: `FileNormalizerTest::testRoundTripThroughLocalUrls`, `FileSyncTest::testContentWaitsForFiles`.
- **181.** "Leave links pointing at origin" keeps original URLs. Covered: `FileSyncTest::testLinksLeftAtOrigin` (decision 42).
- **182.** Translations with different inline files each get theirs. Covered: `FileNormalizerTest::testTranslationsHaveTheirOwnFiles`.
- **183.** "Pull file" and "Pull missing files" work; the file endpoint refuses files outside the contract or from not syncable items. Covered: `ReplicaItemUiTest::testPullFile`, `FileSyncTest::testPullMissingFiles`, `FileTest::testFileEndpoint` (decision 36).
- **184.** An expired link is renewed and the download succeeds. Covered: `FileSyncTest::testExpiredLinkIsRenewed`.
- **185.** Fetch on miss queues a pull and serves a placeholder or redirect; missing or corrupted files are re-queued. Covered: `FileSyncTest::testVerifyAndFetchOnMiss`. The re-check runs on cron (`verifyAll`), not inside reconciliation.

## Save failures on the replica

- **186.** A validation failure saves nothing, reports field violations, origin gets 422. Covered: `ApplyTest::testValidationFailureKeepsPreviousVersion`, `DeliveryTest::testFinalResponses`.
- **187.** An exception during save rolls back everything, including translations and paragraphs. Covered: `ApplyTest::testExceptionRollsBackEverything` (node revision and sync state), `MultilingualTest::testFailedSaveKeepsTranslations` (replica; every translation) and `ParagraphRollbackTest::testFailedSaveKeepsParagraphs` (changed, nested and added paragraphs, written before the failure, rolled back).
- **188.** A failed create is listed by UUID and label. Covered: `ApplyTest::testFailedCreateIsListed` (failed events store; no UI listing exists).
- **189.** A simulated deadlock is retried locally and succeeds. Covered: `ApplyTest::testTransientFailureIsRetriedLocally`.
- **190.** A transient failure past the retry limit becomes permanent. Covered: `ApplyTest::testTransientFailureGivesUp`.
- **191.** A queued failure is reported through the status endpoint as "failed on replica". Covered: `StatusPollTest::testSettled`, `ProcessingTest::testQueued`, `ReportTest::testStatuses`.
- **192.** The origin does not retry 422. Covered: `DeliveryTest::testFinalResponses`.
- **193.** A media item that fails to save holds its parent in `waiting_dependency` until it succeeds. Covered: `MediaTest::testNodeWaitsForMedia`.
- **194.** "Retry" re-applies the stored event; "Pull fresh" applies the current origin version. Covered: `ApplyTest::testRetryAppliesStoredEvent`, `ConflictTest::testPullWithoutEdits` (service level).
- **195.** Bulk "retry all failed matching filters" works after a fix. Covered: `ReplicaItemUiTest::testFailures` (the failures page's "Retry all matching the filters" batch, and `drush content-relay:retry`).
- **196.** "Dismiss" records user and reason; the item stays out of sync. Covered: `ApplyTest::testDismiss` (user, retry closed), `ReplicaItemUiTest::testFailures` (reason required and stored, from the form and Drush).
- **197.** A newer success supersedes an older failure. Covered: `ApplyTest::testNewerSuccessSupersedesFailure`.
- **198.** Admin message and status report entry appear with failures and go when resolved. Covered: `ApplyTest::testFailureIsAnnounced` (status report entry), `ReplicaItemUiTest::testFailures` (the admin message appears, and goes once the failure is dismissed).
- **199.** `apply_failed` is dispatched with enough detail for alerting. Covered: `ApplyTest::testFailureIsAnnounced`.
- **200.** Error summaries to the origin carry no stack traces, paths or secrets. Covered: `ApplyTest::testExceptionRollsBackEverything` (decision 16).
- **201.** Stored failed payloads are encrypted at rest when encryption is on. Covered: `EncryptionTest::testFailedPayloadSealedAtRest` (sealed to this site's encryption key, and opened for retry).

## Manual procedures

These need two real sites. M1 sets them up; the others assume it.

### M1. Two-site setup

1. Create two DDEV projects from this codebase, for example `cr-origin` and `cr-replica`, each with its own database, and install Drupal on both.
2. Let each web container reach the other's HTTPS name through the DDEV router: on the origin add `.ddev/docker-compose.peer.yaml` with `services: {web: {external_links: ["ddev-router:cr-replica.ddev.site"]}}`, on the replica the same with `cr-origin.ddev.site`, then `ddev restart` both.
3. Origin: `ddev drush en content_relay_origin`, then `ddev drush content-relay:generate-key signing origin_signing ../private/origin_signing.key` and `ddev drush content-relay:rotate-key signing origin_signing` to make it primary. Under Content Relay origin › Settings publish Article and Basic page, then generate a code under Pairing code.
4. Replica: `ddev drush en content_relay_replica`, create its signing key the same way, then under Content Relay replica › Pairing enter `https://cr-origin.ddev.site`, the code and the bundles. Expect "Paired. Contract version 1 is in place."
5. Run cron on both every minute during the procedures, for example `ddev exec 'while true; do drush cron; sleep 60; done'`.

### M2. Origin outage and recovery (scenario 20)

Why manual: needs a whole site down, with its cron and queues stopped, not just a missed delivery.

1. Stop the origin's cron loop, then create, edit and delete about 20 articles on the origin.
2. `ddev stop` the origin for at least 10 minutes. The replica keeps running.
3. `ddev start` the origin and restart its cron loop.
4. Check: `ddev drush content-relay:status` on the origin shows no queued or failed events after a few runs, the event log lists the events applied in sequence order, and the replica's synced items report matches the origin's content.

### M3. Replica outage for several hours (scenario 21)

Why manual: needs real elapsed time against the real retry and backoff schedule.

1. `ddev stop` the replica.
2. Over several hours, make at least 200 changes on the origin, including repeated edits of the same items, deletes, new translations and files.
3. Check during the outage: origin saves stay fast, and the event log shows the first event retrying with growing delays while later ones wait (decision 13).
4. `ddev start` the replica and leave both cron loops running.
5. Check: the origin's status report filtered to "any out of sync" is empty, there are no dead letters, and spot checks of 10 items match on both sites. If an event was dead-lettered before recovery, replay it and check again.

### M4. 500 MB file (scenario 35)

Why manual: a 500 MB fixture is too slow and large for CI.

1. Set `memory_limit = 256M` for PHP on both sites, restart.
2. On the origin, add a file field to Article, create a 500 MB file (`ddev exec 'head -c 500M /dev/urandom > /tmp/big.bin'`) and upload it to an article, or attach it with Drush.
3. Let the replica's file queue run (`ddev drush queue:run content_relay_replica_files`, or cron), and repeat with encryption on (Require encryption on the origin's settings).
4. Check: no out-of-memory errors in either site's log, the file entity shows as synced, and `sha256sum` of both copies is the same.

### M5. Bulk resync of 10,000 nodes (scenario 64)

Why manual: needs a realistic data volume and real request timeouts.

1. On the origin, generate 10,000 articles (for example with Devel generate) before or after pairing.
2. In the status report, filter to the article bundle and use "Push all matching filters", then let delivery run.
3. Check: the batch finishes without a timeout or memory error, the summary says 10,000 queued, and once delivery has run the "any out of sync" filter is empty on the origin.

### M6. Report with 100,000 items (scenario 66)

Why manual: needs a realistic data volume, and timing on CI hardware is unreliable.

1. Generate 100,000 items on the origin (several bundles and languages), then use "Refresh status".
2. Load the report and apply single and combined filters (status, bundle, language, replica, date).
3. Check: each page loads in under about 2 seconds, and `EXPLAIN` on the slowest query uses indexes on `content_relay_report` rather than a full scan.

## Gaps

None: what is not covered in full is a manual procedure above.
