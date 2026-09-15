# Permission Security Test Matrix

Roadmap step 05.07 makes permission-boundary regression tests mandatory. The matrix lives in the normal PHPUnit `tests` tree, and `phpunit.xml.dist` executes every `*Test.php` file under that tree. A permission security regression therefore fails the same required CI job that gates production dependency checks and full/update package generation.

## Trusted actor binding

`PermissionGate` binds one validated user id to a `PermissionAuthorizer`. Request/resource target identifiers are never accepted as a replacement actor by the gate API.

- Presentation code asks `allows(permission, node)`.
- Backend mutation/read handlers call `require(permission, node)`.
- Both paths resolve through exactly the same bound actor, assignment provider and `PermissionEngine` decision.
- Denial throws `PermissionDeniedException` with the generic public message `Permission denied.`; detailed internal repository/provider exceptions are not copied into the public exception message.

The trusted authentication/session layer remains responsible for constructing a gate with the authenticated actor. Resource ownership checks remain domain responsibilities in later systems, but resource IDs must never be reused as the permission actor id.

## Mandatory matrix

`tests/Security/Permission/PermissionSecurityMatrixTest.php` covers:

| Risk | Required assertion |
| --- | --- |
| IDOR direct-user grant | A direct permission granted to user B cannot authorize a gate bound to user A. |
| BOLA/node replay | A node-scoped permission for forum/node A cannot be replayed against sibling node B. |
| Moderator/admin bypass | A moderator role may use its moderation capability but cannot obtain an administrator-only ACP capability. |
| Multi-role escalation | An allow in one role cannot bypass a deny in another role at the same membership tier. |
| Node direct-user override | Node-scoped direct-user deny outranks the same user's global allow. |
| Inheritance cross-tier behavior | Node user `inherit` falls through to global user before membership tiers, preserving the documented precedence contract. |
| Membership inheritance | Node membership `inherit` falls through to global membership and cannot turn a global deny into an allow. |
| UI/backend allow parity | UI visibility `allows()` and backend `require()` both allow the same decision. |
| UI/backend deny parity | Hidden/denied UI state cannot be paired with a backend allow; `require()` rejects with the same decision reason. |
| Numeric limit union | Multiple allowed same-tier limits resolve to the most restrictive value. |

Existing unit tests remain part of the same matrix foundation and continue to cover unknown permissions, malformed numeric rules, repository failure, bound SQL parameters, same-tier deny precedence and analyzer trace behavior.

## Security invariants locked by 05.07

1. Frontend hiding is never authorization.
2. A permission actor comes from trusted authentication context, not a route/body target id.
3. Direct-user, group, role and node scopes retain the deterministic 05.02 precedence order.
4. Same-tier deny wins over allow.
5. `inherit` means continue to the next applicable layer; it never means allow.
6. Missing assignments, unknown definitions and repository/provider failures remain fail-closed.
7. Numeric limits at the same effective membership tier use the minimum allowed limit.
8. Moderator, support, marketplace or other staff capabilities do not imply ACP capabilities unless an explicit rule grants them.
9. Permission namespace registration does not grant a capability by itself.
10. Both PHP 8.4 and PHP 8.5 execute this matrix before release packages can be produced.

## Future extension rule

Every new protected first-party system or add-on capability must extend this matrix when it introduces a new authorization shape: ownership, cross-tenant/resource access, bulk action, delegated staff action, API scope, impersonation, export, financial action or other privilege boundary. Main Step 19 API token scopes are an additional restriction and must not bypass this user/group/role decision layer.
