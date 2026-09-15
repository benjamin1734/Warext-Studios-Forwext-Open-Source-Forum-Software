# Role Appearance and Banner System

Roadmap step 05.05 keeps role authorization and role presentation deliberately separate. `Forwext\Core\Domain\Access\Role` remains the source for role identity, name, kind, protection and priority. `RoleAppearance` stores only presentation preferences and cannot grant, deny or modify permissions.

## Supported presentation controls

- Text color: validated six-digit `#RRGGBB` value.
- Optional two-color gradient with a bounded 0–360 degree angle.
- Built-in icon allowlist: shield, star, crown, hammer, check and diamond.
- Optional banner text and validated banner background color.
- Built-in pattern allowlist: none, stripes, dots, grid and diagonal.
- Built-in animation allowlist: none, pulse, glow, shimmer and rainbow.
- Independent visibility on profile and post surfaces.
- Independent mobile visibility plus responsive native CSS.
- Role priority remains the existing `Role::priority()` value and is emitted as presentation metadata; appearance data never owns or overrides it.

## Security boundary

Appearance input never accepts arbitrary HTML, CSS class names, CSS declarations, JavaScript or external URLs. Colors are value objects, icons/patterns/animations are enums, gradient angles are bounded and banner text is length/control-character validated. The native HTML renderer escapes role names and banner text before output.

This boundary is intentional: visual customization must not create XSS/CSS-injection capabilities and must never alter the 05.02 permission engine.

## Persistence

`forwext_role_appearances` has a one-to-one primary/foreign key relationship with `forwext_roles`. Deleting a role cascades its appearance record. Presentation fields are normalized independently from role membership and permission rules.

`DatabaseRoleAppearanceRepository` uses parameterized queries for both reads and upserts. Invalid persisted enum/color values fail during hydration rather than being converted into arbitrary presentation values.

## Rendering

`Forwext\App\Web\Access\RoleAppearanceHtml` receives an authoritative `Role`, a matching `RoleAppearance`, a profile/post display context and the current mobile-mode decision. It returns no markup when that appearance is hidden for the requested surface.

`resources/css/role-appearance.css` provides the native baseline for gradients, banners, patterns, built-in icon glyphs and animations. It includes compact mobile sizing and disables motion under `prefers-reduced-motion: reduce`.

Future profile/post/forum templates may call the same renderer. They must not infer permissions from appearance, role priority, colors, icon choice or banner visibility.
