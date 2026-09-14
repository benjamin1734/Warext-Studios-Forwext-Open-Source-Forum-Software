# app

Application composition and use-case delivery layer.

This directory will contain application-level orchestration such as HTTP/ACP/API controllers, request-facing application services/composition and product boot wiring that is not a reusable low-level core primitive.

Rules:

- may depend on `core/` public contracts and enabled first-party `modules/`;
- must not bypass shared domain services or permission/security boundaries;
- web/API/CLI adapters should converge on shared application/domain services rather than duplicate business rules;
- third-party add-on implementation does not live here.
