# Sample add-on

A maintained sample is available at:

`examples/addons/Warext/HelloWorld`

It demonstrates:

- canonical `Warext/HelloWorld` identity;
- strict PHP 8.4 source;
- typed `user_ui` capability disclosure;
- a real `AddonUiRegistration`;
- a namespaced widget using the existing `footer.before` slot.

The sample intentionally avoids fake discovery/bootstrap APIs. It only uses registration types that exist in the current Forwext source tree.
