# Integration Obligations Owned by Later Specs

004 is complete when its real access routes/services and fixture authorization cases pass. It does not claim that future content routes have already been secured. Each downstream implementer must apply these obligations while delivering that feature.

| Consumer | Required integration | Evidence in its own implementation |
|---|---|---|
| 005 settings | Use fresh active-admin gate; no teacher writes | Real settings route denials |
| 006 documents/cases | Set owner from actor; implement V3OwnedResource/HasV3Owner; restrictive user FK; private V3 disk without direct serving | Actual upload/list/download/case policy and owner-tamper tests |
| 007 progress | Owner-scoped teacher case queries; admin summary via closed DTO; worker identity separate from session roles | Actual case status and internal event authorization tests |
| 008 sources | Inherit case ownership; do not expose notebook/source IDs or text to admin | Actual source binding/read tests |
| 009 sections | Implement owner contract and parent relationship checks; all content teacher-only | Real Section read/list boundary tests |
| 010–012 prompts/scenes/checks | Retain owner chain, private artifacts and exact candidate identity; register model policies explicitly | Real prompt/candidate/evidence access and delivery tests |
| 013 review | Reauthorize active original teacher at submission, plus current candidate/version checks | Actual review/approve/change/download route tests |
| 014 operations | Extend the same account access service and safe projection; never write users.role or access state directly | Real admin command/account operation tests |
| 015 history | Owner detail/admin metadata projections; consume access audit without copying private content | Actual history/export privacy tests |

## Common rules

- Register production policies explicitly in `AppServiceProvider`; do not assume an interface alone gives Eloquent policy discovery.
- Scope lists/counts before pagination and resolve nested children through the authorized parent relation.
- Reject client owner IDs, keep persisted owner immutable, and do not use bulk updates to alter it.
- Legacy content controllers/middleware must not gain queries or relationships to V3-owned artifacts. A V3 admin session never becomes a source-reading worker credential.
- Private paths or signed file URLs are not authorization. Deliver through an owner-authorized controller; V3 disk must have direct serving disabled and no public symlink.
- Preserve the 004 error/redaction/no-store contract. Add new operational codes explicitly; never pass raw strings or metadata objects through the summary.
- New account mutation callers use `ChangeAccountAccess`; they cannot bypass the singleton lock, current actor check, expected version, self/last-admin guards or atomic audit.
- Admin management extensions cannot replace enrolled accounts' email/password; the retained V3 row preserves this restriction after demotion. Initial identity provisioning remains trusted.
- Background processing pause/cancellation after an account is suspended belongs to workflow/admin specs. 004 removes user access and preserves ownership; it does not run or cancel workflows.
