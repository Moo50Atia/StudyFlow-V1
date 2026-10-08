# Legacy Workflow Histories

This directory contains sanitized, immutable Temporal workflow execution histories for replay testing.

## Provenance
- `legacy_staged_v1_case.json`: Generated from an end-to-end execution of `CaseWorkflow` under `legacy_staged_v1` mode (sourcing, extraction, mapping, lesson work, and review decision approval).
- **Sanitization**: Contains synthetic IDs (`00000000-0000-0000-0000-000000000080`), synthetic event keys, and mock activity outputs. Zero private tokens, cookies, or live provider credentials.
- **Authentic Production History Status**: Per Spec 016 T025 and Review R016-10, authentic production execution histories captured from live clusters prior to Spec 016 were not retained in the workspace repository. Therefore, N19 is verified against these sanitized reference legacy fixtures, and noted as unverified against historical live-cluster databases.
