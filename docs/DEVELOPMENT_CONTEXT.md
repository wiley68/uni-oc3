Goal:
Port/rebuild UniCredit module for OpenCart 3.x.

References:

- reference-uni-oc4 → behavior/business logic
- reference-jet-oc3 → OC3 patterns and Journal compatibility
- reference-oc3-core → platform conventions
- reference-oc3-store → optional runtime/debug reference

**Current operational docs (release 2.0.2):** `docs/CONTRACTS.md`, `docs/RUNTIME_VERIFICATION.md`.
Historical plan: `docs/MASTER_IMPLEMENTATION_PLAN.md`.

Phase 11 (local): Admin Orders bank-status column + order-info financing panel (`ADMIN_PANEL`); homepage advertising from cache-only CP shop; shared presentation pipeline; presentation `oc_event` rows. See `docs/RUNTIME_VERIFICATION.md` Phase 11.

Open audit doc dependencies:

```text
DOC-032-02 OPEN → AUD-016 (cart amount authority)
DOC-032-03 OPEN → AUD-030 (retention enforcement)
F-033-02 OPEN IMPROVEMENT → AUD-005/AUD-006 (real DB concurrency)
```

Workflow:
ChatGPT → Codex planning prompt
Codex → implementation plan
Cursor → phased implementation
Manual deployment/testing
Codex → final audit
Cursor → audit fixes
