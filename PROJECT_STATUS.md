# Forwext Project Status

This file is the canonical human-readable development pointer for continuing the project across conversations and work sessions.

## Current position

- Project: **Forwext**
- Target: **Production-ready 1.0.0**
- Roadmap: **18 main phases / 96 sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Current main phase: `01`
- Current sub-step: `01.01`
- Last completed sub-step before this commit: `none`
- Current development version: `0.0.0-dev`
- Persistent server installation required yet: `no`
- First persistent install milestone: `03.02 completed`
- Blocker: `none`

## Progress rules

A sub-step is marked complete only after its stated implementation/documentation, acceptance criteria and required tests are satisfied. Skeleton files or TODO placeholders do not qualify.

When a sub-step is completed, update this file with:

```text
LAST_COMPLETED = XX.XX
NEXT = XX.XX
LAST_COMMIT = <sha>
VERSION = <version>
BLOCKER = <none or explicit blocker>
```

## Permanent release rule

Once release packaging is operational, every releasable version provides both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

Normal update packages preserve the existing database through migrations.

## Continuation protocol

A new work session should:

1. Read the master roadmap.
2. Read this status file.
3. Inspect the current GitHub repository rather than assuming files from an earlier chat are current.
4. Continue from `NEXT` without rewriting already completed work unnecessarily.
5. Run the relevant tests/acceptance checks.
6. Commit the completed work.
7. Update this status pointer.
