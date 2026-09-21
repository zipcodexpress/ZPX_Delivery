// Generated from docs/handoff/contracts/openapi.json. Do not edit.
export interface paths {
    "/packages/{package_id}/label/pdf": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Download authenticated sender-owned active 4 by 6 inch test PDF label. */
        get: operations["shipping_label_pdf"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments/{shipment_id}/pending-payment": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Resume own pending checkout. */
        get: operations["shipping_pending_payment"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/development/payments/{payment_id}/confirm": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Local development/test adapter only; sender-owned synthetic shipment. Never charges a real provider. */
        post: operations["shipping_test_payment_confirm"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/recipient-claims": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Consume shipment-bound proof and grant receiving access; never grants locker access. */
        post: operations["shipping_claim"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/recipient-claims/challenges": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Fresh local email proof bound to shipment and authenticated verified customer; neutral response for unknown or mismatched reference. */
        post: operations["shipping_claim_challenge"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/operations/shipments/{shipment_id}/tracking": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Read shipment milestones within current staff assignments. */
        get: operations["shipping_operations_tracking"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/operations/shipments/{shipment_id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Read a shipment within current staff assignments. */
        get: operations["shipping_operations_detail"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/operations/shipments": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List shipments within current organization and staff site/hub assignments. */
        get: operations["shipping_operations_list"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/challenges": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Request rate-limited one-time verification; return neutral response to prevent account enumeration.
         * @description Request rate-limited one-time verification; return neutral response to prevent account enumeration. The initial identity implementation supports REGISTER; other purposes return PURPOSE_UNAVAILABLE until their workflows are implemented. Local delivery uses a private encrypted development inbox; no code is returned by this endpoint.
         */
        post: operations["delivery_post__auth_challenges"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}/acknowledgments": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Assigned driver acknowledges published revision before continuing.
         * @description Assigned driver acknowledges published revision before continuing.
         */
        post: operations["delivery_post__runs_run_id_acknowledgments"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/receiving-sessions/{session_id}/close": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Receiver closes intake with explicit missing items; never fabricate their receipt.
         * @description Receiver closes intake with explicit missing items; never fabricate their receipt.
         */
        post: operations["delivery_post__hub_receiving_sessions_session_id_close"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/slots": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Hub-scoped sorting slots with destination/run mapping.
         * @description Hub-scoped sorting slots with destination/run mapping.
         */
        get: operations["delivery_get__hub_slots"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/inventory": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Hub staff inventory projection; read-only.
         * @description Hub staff inventory projection; read-only.
         */
        get: operations["delivery_get__hub_inventory"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/routing-print-jobs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Print routing stickers at frozen revision; not new primary label.
         * @description Print routing stickers at frozen revision; not new primary label.
         */
        post: operations["delivery_post__hub_routing_print_jobs"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/devices/me/heartbeat": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Device reports health and journal backlog; no custody mutation.
         * @description Device reports health and journal backlog; no custody mutation.
         */
        post: operations["delivery_post__devices_me_heartbeat"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/role-grants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Operations admin grants scoped role; audit reason.
         * @description Operations admin grants scoped role; audit reason.
         */
        post: operations["delivery_post__admin_role_grants"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/drivers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Operations admin creates approved driver profile.
         * @description Operations admin creates approved driver profile.
         */
        post: operations["delivery_post__admin_drivers"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/vehicles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Dispatcher/admin creates vehicle limits.
         * @description Dispatcher/admin creates vehicle limits.
         */
        post: operations["delivery_post__admin_vehicles"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/locations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Admin creates inactive location; hardware ownership commissioning required before enablement.
         * @description Admin creates inactive location; hardware ownership commissioning required before enablement.
         */
        post: operations["delivery_post__admin_locations"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/pricing-policies": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Finance admin creates versioned pricing policy; validate rules against policy engine schema.
         * @description Finance admin creates versioned pricing policy; validate rules against policy engine schema.
         */
        post: operations["delivery_post__admin_pricing_policies"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/integrations/payments/webhook": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Payment provider callback records signature-verified event.
         * @description Verify Authorize.net SHA-512 HMAC over the raw body, then fetch transaction details and validate the stored invoice, amount and capture status. Only sandbox capture notifications supported. Localhost requires authenticated reconciliation instead of public delivery.
         */
        post: operations["delivery_post__integrations_payments_webhook"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/register": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Register new delivery account; verify both contact channels before shipping.
         * @description Register new delivery account; verify both contact channels before shipping.
         */
        post: operations["delivery_1__auth_register"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Login; browser receives secure session cookie, native receives tokens.
         * @description Login; browser receives secure session cookie, native receives tokens. Browser cookies are HttpOnly, SameSite=Strict and Secure outside local development; browser responses include csrf_token but no access/refresh tokens. Native access lasts 15 minutes; refresh rotates within a seven-day family lifetime. Replayed refresh credentials revoke the family.
         */
        post: operations["delivery_2__auth_login"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/verify-contact": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Consume one-time contact verification challenge.
         * @description Consume one-time contact verification challenge.
         */
        post: operations["delivery_3__auth_verify_contact"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/refresh": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Rotate native refresh credential; browser sessions use server session policy.
         * @description Rotate native refresh credential; browser sessions use server session policy.
         */
        post: operations["delivery_4__auth_refresh"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/logout": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Revoke current session.
         * @description Revoke current session.
         */
        post: operations["delivery_5__auth_logout"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Read authenticated new-platform profile.
         * @description Read authenticated new-platform profile.
         */
        get: operations["delivery_6__me"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/me/legacy-links": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Consume legacy-authenticated assertion once; matching email alone is insufficient.
         * @description Consume legacy-authenticated assertion once; matching email alone is insufficient.
         */
        post: operations["delivery_7__me_legacy_links"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/locations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List actor-eligible locations with site access policy.
         * @description List actor-eligible locations with site access policy.
         */
        get: operations["delivery_8__locations"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List own sent or explicitly claimed received shipments. */
        get: operations["shipping_list"];
        put?: never;
        /**
         * Verified customer creates one-package draft.
         * @description Verified customer creates one-package draft.
         */
        post: operations["delivery_9__shipments"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments/{shipment_id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Owner or verified recipient reads scoped order.
         * @description Owner or verified recipient reads scoped order.
         */
        get: operations["delivery_10__shipments_shipment_id"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments/{shipment_id}/quotes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Quote against current versioned service policy.
         * @description Quote against current versioned service policy.
         */
        post: operations["delivery_11__shipments_shipment_id_quotes"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments/{shipment_id}/payment-session": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create provider checkout; payment requires server confirmation.
         * @description Creates a synthetic-shipment checkout with the configured LOCAL_TEST or AUTHORIZE_NET_SANDBOX adapter. Sandbox form tokens are encrypted at rest; payment requires server verification. No live charges.
         */
        post: operations["delivery_12__shipments_shipment_id_payment_session"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments/{shipment_id}/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Owner cancels only before physical deposit; later use return workflow.
         * @description Owner cancels only before physical deposit; later use return workflow.
         */
        post: operations["delivery_13__shipments_shipment_id_cancel"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/shipments/{shipment_id}/tracking": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Read sanitized customer milestone timeline.
         * @description Read sanitized customer milestone timeline.
         */
        get: operations["delivery_14__shipments_shipment_id_tracking"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/packages/{package_id}/labels": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Issue/retrieve active primary label only after eligibility; not door authority.
         * @description Locked issue/retrieve of the active label after payment. Current implementation is synthetic local development only. It never authorizes a door.
         */
        post: operations["delivery_15__packages_package_id_labels"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/packages/{package_id}/labels/replace": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Hub supervisor revokes previous label after identity verification.
         * @description Hub supervisor revokes previous label after identity verification.
         */
        post: operations["delivery_16__packages_package_id_labels_replace"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/scans/resolve": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Resolve primary label for authorized actor; no custody mutation.
         * @description Resolve primary label for authorized actor; no custody mutation.
         */
        post: operations["delivery_17__scans_resolve"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/driver/runs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Driver reads only assigned runs.
         * @description Driver reads only assigned runs.
         */
        get: operations["delivery_18__driver_runs"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Assigned driver or scoped dispatcher reads ordered manifest.
         * @description Assigned driver or scoped dispatcher reads ordered manifest.
         */
        get: operations["delivery_19__runs_run_id"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Dispatcher creates draft run.
         * @description Dispatcher creates draft run.
         */
        post: operations["delivery_20__runs"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}/publish": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Dispatcher validates capacity/hours and freezes manifest revision.
         * @description Dispatcher validates capacity/hours and freezes manifest revision.
         */
        post: operations["delivery_21__runs_run_id_publish"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}/revise": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Dispatcher revises with reason; loaded parcels protected.
         * @description Dispatcher revises with reason; loaded parcels protected.
         */
        post: operations["delivery_22__runs_run_id_revise"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}/scans": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Assigned driver scans EACH package; locker transfers await correlated evidence.
         * @description Assigned driver scans EACH package; locker transfers await correlated evidence.
         */
        post: operations["delivery_23__runs_run_id_scans"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}/depart": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Assigned driver may depart only with exact loaded manifest and custody.
         * @description Assigned driver may depart only with exact loaded manifest and custody.
         */
        post: operations["delivery_24__runs_run_id_depart"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/runs/{run_id}/complete": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Close after every item resolved and outstanding custody disposition recorded.
         * @description Close after every item resolved and outstanding custody disposition recorded.
         */
        post: operations["delivery_25__runs_run_id_complete"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/receiving-sessions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Hub receiver opens intake for expected inbound run.
         * @description Hub receiver opens intake for expected inbound run.
         */
        post: operations["delivery_26__hub_receiving_sessions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/receiving-scans": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Hub receiver independently scans one parcel, transferring actual custody.
         * @description Hub receiver independently scans one parcel, transferring actual custody.
         */
        post: operations["delivery_27__hub_receiving_scans"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/hub/stage-scans": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Hub sorter scans primary label and valid destination staging slot.
         * @description Hub sorter scans primary label and valid destination staging slot.
         */
        post: operations["delivery_28__hub_stage_scans"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/devices/me/pairings": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Enrolled terminal creates short-lived customer/driver pairing scene.
         * @description Enrolled terminal creates short-lived customer/driver pairing scene.
         */
        post: operations["delivery_29__devices_me_pairings"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/pairings/{pairing_id}/approve": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Authenticated app user approves explicit site/action.
         * @description Authenticated app user approves explicit site/action.
         */
        post: operations["delivery_30__pairings_pairing_id_approve"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/devices/me/pairings/{pairing_id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Terminal polls only own scene.
         * @description Terminal polls only own scene.
         */
        get: operations["delivery_31__devices_me_pairings_pairing_id"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/locker-sessions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create action-scoped parcel session; require pairing_id or valid pickup_grant and authorize actor.
         * @description Create action-scoped parcel session; require pairing_id or valid pickup_grant and authorize actor.
         */
        post: operations["delivery_32__locker_sessions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/locker-sessions/{session_id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Read own session progress.
         * @description Read own session progress.
         */
        get: operations["delivery_33__locker_sessions_session_id"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/locker-sessions/{session_id}/attest": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Actor attests placed/removed; insufficient alone without device evidence.
         * @description Actor attests placed/removed; insufficient alone without device evidence.
         */
        post: operations["delivery_34__locker_sessions_session_id_attest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/devices/me/commands": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Enrolled terminal polls its authorized commands.
         * @description Enrolled terminal polls its authorized commands.
         */
        get: operations["delivery_35__devices_me_commands"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/devices/me/events": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Terminal submits durable correlated device observation; replay-safe.
         * @description Terminal submits durable correlated device observation; replay-safe.
         */
        post: operations["delivery_36__devices_me_events"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/exceptions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Authorized driver/staff records exception without falsifying custody.
         * @description Authorized driver/staff records exception without falsifying custody.
         */
        post: operations["delivery_37__exceptions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/returns": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Dispatcher assigns explicit return-to-hub movement.
         * @description Dispatcher assigns explicit return-to-hub movement.
         */
        post: operations["delivery_38__returns"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/packages/{package_id}/claim": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Authenticated recipient verifies invitation contact and claims access.
         * @description Authenticated recipient verifies invitation contact and claims access.
         */
        post: operations["delivery_39__packages_package_id_claim"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/packages/{package_id}/pickup-grant": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Verified recipient obtains short-lived single-package/location credential.
         * @description Verified recipient obtains short-lived single-package/location credential.
         */
        post: operations["delivery_40__packages_package_id_pickup_grant"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/refunds": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Finance role approves bounded provider refund; no physical custody change.
         * @description Finance role approves bounded provider refund; no physical custody change.
         */
        post: operations["delivery_41__refunds"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/payments/{payment_id}/hosted-session": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Prepare sandbox hosted form. */
        post: operations["shipping_hosted_session"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/payments/{payment_id}/reconcile": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Verify sandbox transaction against stored invoice and amount before enabling label. */
        post: operations["shipping_reconcile"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/operations/shipments/{shipment_id}/payments": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Read scoped shipment payment attempts; no hosted token or card details. */
        get: operations["operations_payment_history"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        TestPaymentOutcome: {
            /** @enum {string} */
            outcome: "SUCCEEDED" | "FAILED";
        };
        RecipientClaimRequest: {
            public_reference: string;
        };
        ShipmentList: {
            items: components["schemas"]["Shipment"][];
            next_cursor: string | null;
        };
        ContactChallengeRequest: {
            /** @enum {string} */
            kind: "EMAIL" | "PHONE";
            contact_value: string;
            /** @enum {string} */
            purpose: "REGISTER" | "RECIPIENT_CLAIM" | "PASSWORD_RESET";
        };
        ContactChallenge: {
            challenge_id: string;
            /** Format: date-time */
            expires_at: string;
            /** @enum {string} */
            delivery_status: "QUEUED" | "SENT";
        };
        RevisionAck: {
            revision: number;
        };
        ReceivingClose: {
            reason: string;
            missing_package_ids: string[];
        };
        HubSlot: {
            slot_id: string;
            code: string;
            hub_id: string;
            run_id: string;
            destination_location_id: string;
        };
        HubSlotList: {
            items: components["schemas"]["HubSlot"][];
        };
        HubInventory: {
            items: components["schemas"]["ResolvedPackage"][];
            next_cursor: string | null;
        };
        PrintRoutingRequest: {
            run_id: string;
            run_revision: number;
            package_ids: string[];
        };
        PrintJob: {
            print_job_id: string;
            status: string;
            /** Format: uri */
            download_url?: string;
        };
        DeviceHeartbeat: {
            software_version: string;
            ownership_generation: number;
            journal_pending: number;
            /** @enum {string} */
            health: "READY" | "DEGRADED" | "RECONCILING";
        };
        StaffGrant: {
            user_id: string;
            role_code: string;
            location_id?: string;
            reason: string;
        };
        DriverCreate: {
            user_id: string;
            engagement_type: string;
            verification_reference: string;
        };
        DriverResult: {
            driver_id: string;
            user_id: string;
            status: string;
        };
        VehicleCreate: {
            code: string;
            max_weight_g: number;
            max_volume_mm3: number;
            max_packages: number;
        };
        LocationCreate: {
            code: string;
            name: string;
            /** @enum {string} */
            site_mode: "LEGACY_ONLY" | "HYBRID_PARTITIONED" | "DELIVERY_ONLY";
            address: components["schemas"]["Address"];
            printer_available: boolean;
        };
        PolicyCreate: {
            code: string;
            version: number;
            /** Format: date-time */
            effective_at: string;
            rules: {
                [key: string]: unknown;
            };
        };
        /** @description Provider-native signed JSON body. Verify raw bytes with provider adapter before normalization; never trust client payment flags. */
        WebhookBody: {
            [key: string]: unknown;
        };
        RefundWebhookResult: {
            provider_event_id: string;
            /** @enum {string} */
            status: "RECORDED" | "DUPLICATE";
        };
        Error: {
            code: string;
            message: string;
            /** @description Opaque identifier; serialize as string. */
            correlation_id: string;
            retryable: boolean;
            current_version?: number;
            recovery_action?: string;
        };
        Address: {
            line1: string;
            line2?: string;
            city: string;
            region: string;
            postal_code: string;
            country_code: string;
        };
        Register: {
            name: string;
            /** Format: email */
            email: string;
            /** @description E.164 */
            phone: string;
            /** @description 12–72 UTF-8 bytes; longer values are rejected, never truncated. */
            password: string;
            address: components["schemas"]["Address"];
        };
        Login: {
            /** Format: email */
            email: string;
            password: string;
            /** @enum {string} */
            client_kind: "BROWSER" | "NATIVE";
        };
        AuthResult: {
            /** @description Opaque identifier; serialize as string. */
            user_id: string;
            /** @enum {string} */
            status: "VERIFICATION_REQUIRED" | "AUTHENTICATED";
            access_token?: string;
            refresh_token?: string;
            /** Format: date-time */
            expires_at?: string;
            /** @description Browser-only session-bound token; send in X-CSRF-Token for cookie-authenticated mutations. Never an access token. */
            csrf_token?: string;
        };
        VerifyContact: {
            /** @description Opaque identifier; serialize as string. */
            challenge_id: string;
            code: string;
        };
        Refresh: {
            refresh_token: string;
        };
        Profile: {
            /** @description Opaque identifier; serialize as string. */
            user_id: string;
            name: string;
            email_verified: boolean;
            phone_verified: boolean;
            roles: string[];
            addresses: components["schemas"]["Address"][];
            /** @description Browser-only session-bound token; send in X-CSRF-Token for cookie-authenticated mutations. Never an access token. */
            csrf_token?: string;
        };
        Location: {
            /** @description Opaque identifier; serialize as string. */
            id: string;
            code: string;
            name: string;
            /** @enum {string} */
            site_mode: "LEGACY_ONLY" | "HYBRID_PARTITIONED" | "DELIVERY_ONLY";
            address: components["schemas"]["Address"];
            eligible: boolean;
            printer_available: boolean;
            access_instructions: string;
            latitude?: number;
            longitude?: number;
            draft_eligible: boolean;
            development_only: boolean;
        };
        LocationList: {
            items: components["schemas"]["Location"][];
            next_cursor: string | null;
        };
        PackageSpec: {
            width_mm: number;
            height_mm: number;
            depth_mm: number;
            weight_g: number;
        };
        Recipient: {
            name: string;
            /** Format: email */
            email: string;
            phone: string;
        };
        CreateShipment: {
            /** @description Opaque identifier; serialize as string. */
            origin_location_id: string;
            /** @description Opaque identifier; serialize as string. */
            destination_location_id: string;
            recipient: components["schemas"]["Recipient"];
            package: components["schemas"]["PackageSpec"];
            service_level: string;
        };
        Shipment: {
            /** @description Opaque identifier; serialize as string. */
            shipment_id: string;
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            /** @description Opaque identifier; serialize as string. */
            origin_location_id: string;
            /** @description Opaque identifier; serialize as string. */
            destination_location_id: string;
            order_status: string;
            payment_status: string;
            package_state: string;
            version: number;
            public_reference: string;
            origin_name: string;
            destination_name: string;
            service_level: string;
            /** @enum {string} */
            relationship: "SENDER" | "RECIPIENT" | "OPERATIONS";
            package: components["schemas"]["PackageSpec"];
            /** Format: date-time */
            created_at: string;
            development_only: boolean;
        };
        QuoteRequest: {
            service_level: string;
        };
        Quote: {
            /** @description Opaque identifier; serialize as string. */
            quote_id: string;
            amount_cents: number;
            /** @enum {string} */
            currency: "USD";
            /** Format: date-time */
            expires_at: string;
            policy_version: string;
            development_only: boolean;
        };
        PaymentRequest: {
            /** @description Opaque identifier; serialize as string. */
            quote_id: string;
        };
        PaymentSession: {
            /** @description Opaque identifier; serialize as string. */
            payment_id: string;
            provider_session_reference: string;
            /** Format: uri */
            checkout_url?: string;
            /** @enum {string} */
            status: "PENDING" | "PAID" | "FAILED";
            development_only: boolean;
            /** @enum {string} */
            provider?: "LOCAL_TEST" | "AUTHORIZE_NET_SANDBOX";
            /** @description Sensitive short-lived hosted form token. POST to checkout_url; never put in a URL or persist in browser storage. */
            checkout_token?: string;
            checkout_expired?: boolean;
        };
        Label: {
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            si: string;
            /** @description Opaque identifier; serialize as string. */
            label_id: string;
            label_version: number;
            label_payload: string;
            /** @description Authenticated same-origin PDF path, with no credential in URL. */
            pdf_url: string;
            /** Format: date-time */
            expires_at: string;
            development_only: boolean;
        };
        ReplaceLabel: {
            reason: string;
            /** @description Opaque identifier; serialize as string. */
            identity_evidence_reference: string;
        };
        ResolveScan: {
            label_payload: string;
            /** @enum {string} */
            action: "INSPECT" | "INBOUND_PICKUP" | "HUB_RECEIVE" | "STAGE" | "OUTBOUND_LOAD" | "FINAL_DEPOSIT";
            /** @description Opaque identifier; serialize as string. */
            run_id?: string;
        };
        ResolvedPackage: {
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            si: string;
            /** @description Opaque identifier; serialize as string. */
            destination_location_id: string;
            state: string;
            version: number;
            allowed_actions: string[];
        };
        Stop: {
            /** @description Opaque identifier; serialize as string. */
            stop_id: string;
            sequence: number;
            /** @description Opaque identifier; serialize as string. */
            location_id: string;
            package_ids: string[];
            state: string;
        };
        Run: {
            /** @description Opaque identifier; serialize as string. */
            run_id: string;
            /** @enum {string} */
            kind: "INBOUND" | "OUTBOUND" | "RETURN";
            /** @description Opaque identifier; serialize as string. */
            driver_id: string;
            /** @description Opaque identifier; serialize as string. */
            hub_id: string;
            revision: number;
            state: string;
            stops: components["schemas"]["Stop"][];
            expected_count: number;
            loaded_count: number;
        };
        RunList: {
            items: components["schemas"]["Run"][];
            next_cursor: string | null;
        };
        PlannedStop: {
            /** @description Opaque identifier; serialize as string. */
            location_id: string;
            sequence: number;
            package_ids: string[];
        };
        CreateRun: {
            /** @enum {string} */
            kind: "INBOUND" | "OUTBOUND" | "RETURN";
            /** @description Opaque identifier; serialize as string. */
            hub_id: string;
            /** @description Opaque identifier; serialize as string. */
            driver_id: string;
            /** @description Opaque identifier; serialize as string. */
            vehicle_id: string;
            /** Format: date-time */
            planned_start: string;
            /** Format: date-time */
            planned_end: string;
            stops: components["schemas"]["PlannedStop"][];
        };
        RunRevision: {
            expected_revision: number;
            reason: string;
            stops: components["schemas"]["PlannedStop"][];
        };
        RunAction: {
            expected_revision: number;
        };
        RunScan: {
            label_payload: string;
            /** @enum {string} */
            action: "INBOUND_PICKUP" | "OUTBOUND_LOAD" | "FINAL_DEPOSIT";
            /** Format: uuid */
            client_event_id: string;
            run_revision: number;
            expected_package_version: number;
            /** @description Opaque identifier; serialize as string. */
            stop_id?: string;
            /** @description Opaque identifier; serialize as string. */
            session_id?: string;
        };
        ScanResult: {
            /** @enum {string} */
            result: "ACCEPTED" | "ALREADY_PROCESSED" | "AWAITING_DEVICE_EVIDENCE";
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            si: string;
            /** @description Opaque identifier; serialize as string. */
            final_location_id: string;
            package_version: number;
            run_revision?: number;
            stop_sequence?: number;
            /** @description Opaque identifier; serialize as string. */
            session_id?: string;
            counts: {
                expected: number;
                accepted: number;
                pending: number;
            };
            can_depart: boolean;
        };
        Receive: {
            label_payload: string;
            /** @description Opaque identifier; serialize as string. */
            inbound_run_id: string;
            /** @description Opaque identifier; serialize as string. */
            receiving_session_id: string;
            /** Format: uuid */
            client_event_id: string;
            expected_package_version: number;
        };
        Stage: {
            label_payload: string;
            slot_code: string;
            /** @description Opaque identifier; serialize as string. */
            outbound_run_id: string;
            run_revision: number;
            expected_package_version: number;
        };
        PairingCreate: {
            /** @enum {string} */
            workflow: "ORIGIN_DEPOSIT" | "INBOUND_PICKUP" | "FINAL_DEPOSIT" | "RECIPIENT_PICKUP";
        };
        Pairing: {
            /** @description Opaque identifier; serialize as string. */
            pairing_id: string;
            scene_payload: string;
            /** @enum {string} */
            status: "PENDING" | "APPROVED" | "CONSUMED" | "EXPIRED" | "CANCELLED";
            /** Format: date-time */
            expires_at: string;
        };
        PairingApproval: {
            /** @enum {string} */
            workflow: "ORIGIN_DEPOSIT" | "INBOUND_PICKUP" | "FINAL_DEPOSIT" | "RECIPIENT_PICKUP";
            /** @description Opaque identifier; serialize as string. */
            location_id: string;
        };
        LockerSessionCreate: {
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            /** @enum {string} */
            action: "ORIGIN_DEPOSIT" | "INBOUND_PICKUP" | "FINAL_DEPOSIT" | "RECIPIENT_PICKUP";
            /** @description Opaque identifier; serialize as string. */
            pairing_id?: string;
            pickup_grant?: string;
            /** @description Opaque identifier; serialize as string. */
            run_id?: string;
            expected_package_version: number;
        };
        LockerSession: {
            /** @description Opaque identifier; serialize as string. */
            session_id: string;
            status: string;
            version: number;
            compartment_display?: string;
            /** Format: date-time */
            expires_at: string;
        };
        Attestation: {
            /** @enum {boolean} */
            confirmed: true;
            /** @enum {string} */
            statement: "PARCEL_PLACED" | "PARCEL_REMOVED";
            /** Format: uuid */
            client_event_id: string;
        };
        PhysicalAddress: {
            /** @description Opaque identifier; serialize as string. */
            locker_id: string;
            board_address: number;
            door_address: number;
        };
        DeviceCommand: {
            /** Format: uuid */
            command_id: string;
            /** @description Opaque identifier; serialize as string. */
            session_id: string;
            /** @enum {string} */
            action: "OPEN" | "QUERY";
            address: components["schemas"]["PhysicalAddress"];
            protocol_profile: string;
            ownership_generation: number;
            /** Format: date-time */
            expires_at: string;
        };
        Commands: {
            items: components["schemas"]["DeviceCommand"][];
        };
        DeviceEvent: {
            /** Format: uuid */
            event_id: string;
            /** Format: uuid */
            boot_id: string;
            sequence: number;
            /** Format: uuid */
            command_id: string;
            /** @description Opaque identifier; serialize as string. */
            session_id: string;
            /** @enum {string} */
            event_type: "DISPATCH_RECORDED" | "OPEN_OBSERVED" | "CLOSE_OBSERVED" | "UNKNOWN";
            /** Format: date-time */
            observed_at: string;
            address: components["schemas"]["PhysicalAddress"];
            ownership_generation: number;
            frame_hash?: string;
        };
        EventReceipt: {
            /** @description Opaque identifier; serialize as string. */
            event_id: string;
            /** @enum {string} */
            result: "RECORDED" | "DUPLICATE" | "AWAITING_CORRELATION";
            session_status: string;
        };
        ExceptionRequest: {
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            /** @description Opaque identifier; serialize as string. */
            run_id?: string;
            /** @enum {string} */
            reason_code: "MISSING" | "DAMAGED" | "LOCKER_FULL" | "LOCKER_OFFLINE" | "ACCESS_BLOCKED" | "LABEL_UNREADABLE" | "UNKNOWN_OCCUPANCY";
            description: string;
            evidence_references?: string[];
        };
        Exception: {
            /** @description Opaque identifier; serialize as string. */
            exception_id: string;
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            status: string;
            custodian_type: string;
            /** @description Opaque identifier; serialize as string. */
            custodian_ref: string;
        };
        Tracking: {
            /** @description Opaque identifier; serialize as string. */
            shipment_id: string;
            milestones: {
                code: string;
                /** Format: date-time */
                occurred_at: string;
                location_name?: string;
            }[];
            exception_summary?: string;
        };
        Claim: {
            invitation_token: string;
            /** @description Opaque identifier; serialize as string. */
            verification_challenge_id: string;
            verification_code: string;
        };
        Grant: {
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            grant: string;
            /** @description Opaque identifier; serialize as string. */
            location_id: string;
            /** Format: date-time */
            expires_at: string;
        };
        Cancel: {
            reason: string;
        };
        ReceivingSessionRequest: {
            /** @description Opaque identifier; serialize as string. */
            hub_id: string;
            /** @description Opaque identifier; serialize as string. */
            inbound_run_id: string;
        };
        ReceivingSession: {
            /** @description Opaque identifier; serialize as string. */
            receiving_session_id: string;
            /** @description Opaque identifier; serialize as string. */
            hub_id: string;
            /** @description Opaque identifier; serialize as string. */
            inbound_run_id: string;
            expected_count: number;
            received_count: number;
            state: string;
        };
        IdentityLink: {
            legacy_assertion: string;
            nonce: string;
        };
        ReturnRequest: {
            /** @description Opaque identifier; serialize as string. */
            package_id: string;
            /** @description Opaque identifier; serialize as string. */
            hub_id: string;
            /** @description Opaque identifier; serialize as string. */
            driver_id: string;
            reason: string;
        };
        Refund: {
            /** @description Opaque identifier; serialize as string. */
            payment_id: string;
            amount_cents: number;
            reason: string;
        };
        Operation: {
            /** @description Opaque identifier; serialize as string. */
            operation_id: string;
            /** @enum {string} */
            status: "ACCEPTED" | "COMPLETED" | "PENDING";
            /** @description Opaque identifier; serialize as string. */
            resource_id?: string;
        };
        PaymentHistory: {
            items: {
                payment_id: string;
                provider: string;
                reference: string;
                transaction_id: string | null;
                amount_cents: number;
                currency: string;
                status: string;
                /** Format: date-time */
                created_at: string;
                /** Format: date-time */
                quote_expires_at: string;
            }[];
        };
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    shipping_label_pdf: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                package_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Test label PDF, private and no-store */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/pdf": string;
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_pending_payment: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PaymentSession"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_test_payment_confirm: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                payment_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["TestPaymentOutcome"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PaymentSession"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_claim: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["VerifyContact"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Shipment"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_claim_challenge: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RecipientClaimRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ContactChallenge"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_operations_tracking: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Tracking"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_operations_detail: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Shipment"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_operations_list: {
        parameters: {
            query?: {
                cursor?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ShipmentList"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__auth_challenges: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ContactChallengeRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ContactChallenge"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__runs_run_id_acknowledgments: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RevisionAck"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__hub_receiving_sessions_session_id_close: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path: {
                session_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ReceivingClose"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ReceivingSession"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_get__hub_slots: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["HubSlotList"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_get__hub_inventory: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["HubInventory"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__hub_routing_print_jobs: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PrintRoutingRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PrintJob"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__devices_me_heartbeat: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["DeviceHeartbeat"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__admin_role_grants: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["StaffGrant"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__admin_drivers: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["DriverCreate"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DriverResult"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__admin_vehicles: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["VehicleCreate"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__admin_locations: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["LocationCreate"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__admin_pricing_policies: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required for browser cookie authentication. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PolicyCreate"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_post__integrations_payments_webhook: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["WebhookBody"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @enum {string} */
                        status: "ACCEPTED" | "IGNORED";
                    };
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_1__auth_register: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Register"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthResult"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_2__auth_login: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Login"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthResult"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_3__auth_verify_contact: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["VerifyContact"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_4__auth_refresh: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Refresh"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthResult"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_5__auth_logout: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": Record<string, never>;
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_6__me: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Profile"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Sanitized unexpected server error */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_7__me_legacy_links: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["IdentityLink"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_8__locations: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["LocationList"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_list: {
        parameters: {
            query?: {
                view?: "sending" | "receiving";
                cursor?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ShipmentList"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_9__shipments: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CreateShipment"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Shipment"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_10__shipments_shipment_id: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Shipment"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_11__shipments_shipment_id_quotes: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["QuoteRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Quote"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_12__shipments_shipment_id_payment_session: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PaymentRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PaymentSession"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_13__shipments_shipment_id_cancel: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Cancel"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_14__shipments_shipment_id_tracking: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Tracking"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_15__packages_package_id_labels: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                package_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": Record<string, never>;
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Label"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_16__packages_package_id_labels_replace: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                package_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ReplaceLabel"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Label"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_17__scans_resolve: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ResolveScan"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ResolvedPackage"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_18__driver_runs: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["RunList"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_19__runs_run_id: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_20__runs: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CreateRun"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_21__runs_run_id_publish: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RunAction"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_22__runs_run_id_revise: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RunRevision"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_23__runs_run_id_scans: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RunScan"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ScanResult"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_24__runs_run_id_depart: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RunAction"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_25__runs_run_id_complete: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                run_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RunAction"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Run"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_26__hub_receiving_sessions: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ReceivingSessionRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ReceivingSession"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_27__hub_receiving_scans: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Receive"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ScanResult"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_28__hub_stage_scans: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Stage"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ResolvedPackage"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_29__devices_me_pairings: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PairingCreate"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Pairing"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_30__pairings_pairing_id_approve: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                pairing_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PairingApproval"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Pairing"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_31__devices_me_pairings_pairing_id: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                pairing_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Pairing"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_32__locker_sessions: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["LockerSessionCreate"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["LockerSession"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_33__locker_sessions_session_id: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                session_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["LockerSession"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_34__locker_sessions_session_id_attest: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Quoted aggregate version; run_revision in body also required for cross-aggregate operations. */
                "If-Match": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                session_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Attestation"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["LockerSession"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_35__devices_me_commands: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Commands"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_36__devices_me_events: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["DeviceEvent"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["EventReceipt"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_37__exceptions: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ExceptionRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Exception"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_38__returns: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ReturnRequest"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_39__packages_package_id_claim: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                package_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Claim"];
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_40__packages_package_id_pickup_grant: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                package_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Grant"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delivery_41__refunds: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Refund"];
            };
        };
        responses: {
            /** @description Successful result */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Operation"];
                };
            };
            /** @description Structured error */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Structured error */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_hosted_session: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                payment_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": Record<string, never>;
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PaymentSession"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    shipping_reconcile: {
        parameters: {
            query?: never;
            header: {
                "Idempotency-Key": string;
                /** @description Required when authenticated by browser cookie; not needed for native bearer. */
                "X-CSRF-Token"?: string;
            };
            path: {
                payment_id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    transaction_id?: string;
                };
            };
        };
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PaymentSession"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    operations_payment_history: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                shipment_id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful result */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PaymentHistory"];
                };
            };
            /** @description Structured error */
            default: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
}
