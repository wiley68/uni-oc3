Goal:
Port/rebuild UniCredit module for OpenCart 3.x.

References:

- reference-uni-oc4 → behavior/business logic
- reference-jet-oc3 → OC3 patterns and Journal compatibility
- reference-oc3-core → platform conventions
- reference-oc3-store → optional runtime/debug reference

Phase 11 (local): Admin Orders bank-status column + order-info financing panel (`ADMIN_PANEL`); homepage advertising from cache-only CP shop; shared presentation pipeline; 10 self-healing `oc_event` rows. See `docs/RUNTIME_VERIFICATION.md` Phase 11.

Workflow:
ChatGPT → Codex planning prompt
Codex → implementation plan
Cursor → phased implementation
Manual deployment/testing
Codex → final audit
Cursor → audit fixes
