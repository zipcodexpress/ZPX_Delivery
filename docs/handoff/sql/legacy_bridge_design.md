# Legacy compatibility changes — design only

No executable legacy migration is supplied because deployed schema/writers must be confirmed first. New SQL 001/002 must never run in the apartment database.

Proposed additive legacy projection: delivery_compartment_ownership keyed by existing cabinet_box.box_id; columns owner enum LEGACY/DELIVERY/FROZEN, generation, manifest_hash, applied_at. Unknown/missing mapping on an enabled HYBRID site fails closed for new allocation. Unenabled legacy-only sites retain original behavior. A versioned site flag distinguishes these cases. Do not overwrite cabinet_box.status or use blocked alone as ownership.

Required patch surfaces from inspected source:

- app/common/model/CabinetBoxModel.php: assignBox, assignBoxN, assignCabinetAndBox, occupyBox, releaseBox, block/unblock and update/status paths; reject non-LEGACY ownership for legacy action. Recheck owner at mutation, not only selection.
- app/cabinet/controller/Zippora.php and Base.php: runtime box config/availability, preAuth and store/pickup plus special asset/share/manual flows. Report externally managed doors distinctly.
- backend/controllers/UtilityController.php: actionResetLockers must exclude DELIVERY/FROZEN and show counts withheld. No synthetic pickup completion for delivery parcels.
- backend/controllers/CabinetboxController.php and topology/admin paths: cannot change/remove a mapped physical address or ownership while active. Audit other direct DB writers, including duplicated adminuser models and deployments not present in these repos.
- Zippora/Controller/BoxHelper.cs and direct serial/controller calls: central gate with action owner/generation; maintenance path audited. Do not run an unguarded older terminal on a HYBRID site.

Ordering: code that understands ownership first (feature disabled), then terminal gate, then topology validation, then frozen partition commissioning, then new sending enablement. Rollback must drain delivery parcels and transfer ownership under a new generation before returning to legacy-only binary. No apartment schema mutation performed in this review.
