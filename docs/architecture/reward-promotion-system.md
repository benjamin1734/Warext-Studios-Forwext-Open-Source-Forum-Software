# User promotions and shared reward provider

Substep 13.08 unifies automatic user promotions and first-party rewards behind one backend-authoritative reward contract.

## Shared reward contract

Referral qualification, giveaway winner selection/redraw and trophy award/revoke can all call RewardGrantGateway. Reward grants are keyed by recipient + source type + source event id + reward key, making repeated delivery idempotent.

Reward definitions select a provider and a target. The first built-in providers are:

- secondary_group — adds only non-system secondary groups;
- role — adds only unprotected custom roles.

Staff roles, system roles, protected roles and system groups are deliberately excluded from provider target lists and rejected again at apply time. Reward automation therefore cannot manufacture ACP/moderation authority through a misconfigured reward.

A durable grant ledger stores pending/applied/failed/revoked state, provider/target snapshots and failure codes. Missing definitions and provider errors do not invalidate authoritative referral qualification, giveaway draws or trophy history. ACP retry and optional reward.retry maintenance can reconcile failed/pending grants later.

## Ownership-safe revocation

The reward engine records whether it actually created a role/group assignment. Revocation removes an assignment only when:

1. the assignment is marked as reward-managed; and
2. no other active reward entitlement still needs the same provider target.

Assignments that existed before the reward are never claimed as reward-managed and are not removed by source revocation.

Giveaway redraw revokes the previous draw reward before fulfilling the replacement winner. Trophy revocation similarly revokes rewards bound to that trophy grant.

## Reward bindings

Reward bindings attach active reward definitions to first-party giveaway or trophy definitions. Referral campaigns already carry reward_key and reward_units, so they dispatch directly through the same gateway without duplicating configuration.

The native /admin/rewards workflow manages reward definitions, bindings and retryable grants with reward.manage and dedicated CSRF.

## User promotions

Promotion definitions evaluate deterministic first-party metrics:

- account age days;
- visible post count;
- qualified referral count;
- current giveaway win count;
- active trophy/badge count.

A matching promotion grants its configured reward through the same RewardGrantGateway. This means group/role promotion is not a second access-assignment subsystem.

Each promotion can optionally enable revoke_when_unqualified. When enabled and the metric later falls below the threshold, only the promotion's own reward source is revoked, so the ownership rules above protect manual assignments and other active entitlements.

Promotion evaluation is idempotent and supports bounded single-user/batch execution in /admin/promotions for cPanel installs. Advanced deployments may register the hourly promotion evaluator. Failed/pending rewards may be retried independently.

## Permission and audit

promotion.manage controls promotion definitions/manual evaluation. reward.manage controls reward definitions/bindings/retry. Administrator templates receive both permissions; new_user/member/verified/moderator templates default to deny.

Definition/binding/retry and human-triggered evaluation changes use the central administration audit stream.

## Schema

- 20260919180000_reward_promotion_system: reward definitions/grants/ownership/bindings and permission defaults.
- 20260919181000_promotion_system: promotion rules and evaluation cursor.
- 20260919181500_promotion_revocation_policy: additive revoke_when_unqualified policy column.

The additive migration preserves already-applied 13.08 schemas instead of rewriting migration history.
