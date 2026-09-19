# Giveaway winner selection and transparency

Substep 13.05 consumes the immutable participation records from 13.04 and produces an auditable winner-selection chain. It does not grant balances, roles, trophies, marketplace items, or any other cross-system reward; shared reward fulfillment remains 13.08.

## Cryptographic draw algorithm

Each draw generates a fresh 256-bit seed with PHP random_bytes. The participant population is converted to a canonical list ordered by user id and entry id. Only entry id, user id, and configured entry weight are copied into the immutable draw snapshot; network/device anti-abuse fingerprints are never copied into proof data.

The canonical population SHA-256 is calculated from those rows. The disclosed seed, population hash, and a counter are hashed with SHA-256. The first 63 bits are interpreted as a non-negative integer. Rejection sampling removes modulo bias before mapping the value to the weighted ticket range.

Algorithm identifier: sha256-rejection-63-v1.

Because the seed and the immutable population snapshot are stored with the draw, Forwext can recompute the ticket and winner later instead of trusting a mutable "winner" field alone.

## Atomicity and concurrency

Winner selection requires giveaway.manage and a giveaway in the closed lifecycle state.

The giveaway row is locked with SELECT ... FOR UPDATE inside the same database transaction that creates the draw snapshot, draw record, central audit event, and durable winner notification. Concurrent draw requests therefore cannot create two primary draws. A unique giveaway/sequence key and one-child parent key provide a second database-level guard.

The initial draw is sequence 1 and can only be created once.

## Redraw rules

A redraw:

- requires an existing primary/current draw;
- requires a 10-500 byte UTF-8 reason;
- creates a new immutable draw instead of modifying the old result;
- links to the immediately previous draw;
- excludes every previous winner in the chain from the new population;
- records the prior winner and new proof data in central administration audit;
- notifies the new winner and, when the old account still exists, informs the replaced winner.

If no unused participant remains, redraw is rejected.

## Durable proof snapshots

Draw populations are copied into forwext_giveaway_draw_population without foreign keys to user or participation rows. This is deliberate: later account deletion or participation cleanup must not silently destroy historical draw proof material.

The snapshot contains pseudonymous internal ids and weights only. It excludes IP addresses, User-Agent values, HMAC anti-abuse fingerprints, email addresses, and profile data.

The proof page recomputes each draw from its own immutable snapshot and validates chain parent/sequence order. It shows the algorithm, seed, population hash, ticket, proof hash, winner identity, redraw reason, and whether recomputation succeeded.

## Notification and reward boundary

giveaway.winner is deduplicated per draw id and points the winner to the proof screen. A redraw also emits giveaway.winner_replaced for the superseded winner when possible.

No reward is automatically fulfilled in 13.05. The common reward provider introduced in 13.08 will consume the current verified winner without creating a second balance/reward implementation here.
