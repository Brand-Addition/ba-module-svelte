# BA_Svelte

Shared Magento 2 Svelte runtime for root component mounts with Magento-native named containers.

## Responsibilities

- `BA\Svelte\Block\SvelteBlock` serializes one root Svelte component into `component + props + containers`.
- `BA\Svelte\Block\ComponentBlock` serializes nested Svelte components that live inside named Magento containers.
- `view/frontend/templates/root.phtml` emits one `.svelte-root` mount node with the serialized component config.
- `view/frontend/web/svelte-src` contains the shared Vite bootstrap plus the generic container/component renderers.
- `view/frontend/web/js/lib/http.js` provides shared JSON request helpers for Svelte modules.
- `BuildSveltePlugin` hooks into `setup:static-content:deploy` and compiles the merged SCD asset tree into `pub/static/.../js/dist`.

## Architecture

The root mount is intentionally simple:

1. Magento layout declares a root `SvelteBlock` with a normal `svelte_component`
2. The root block exposes named child containers with Magento `<container />`
3. Nested `ComponentBlock` children inside those containers become container items
4. The shared runtime mounts the root component directly
5. The root component renders named containers through `ContainerRenderer`

That keeps the developer workflow low-friction:

- no extra registry file per root component
- no second naming layer to keep in sync
- normal Magento component paths stay the public API

## Why Named Containers Exist

Without named containers, a root Svelte component can only receive one flat list of nested children. That makes layout extension possible, but it forces parent components to know child block names or child order. Named containers let Magento express semantic slots like `shipping`, `footer`, or `actions` directly in layout XML.

That gives you:

- stable Magento-native extension points
- `before`, `after`, `move`, and `remove` support
- clearer parent component contracts
- no extra JS file per root mount

## Layout Usage

### Root Component

```xml
<block class="BA\Svelte\Block\SvelteBlock"
       name="example.root"
       template="BA_Svelte::root.phtml">
    <arguments>
        <argument name="svelte_component" xsi:type="string">Vendor_Module::example-root.svelte</argument>
        <argument name="heading" xsi:type="string">Example</argument>
        <argument name="computed_props" xsi:type="array">
            <item name="endpointUrl" xsi:type="array">
                <item name="resolver" xsi:type="string">url</item>
                <item name="path" xsi:type="string">rest/V1/example</item>
            </item>
        </argument>
    </arguments>

    <container name="example.root.container.content" as="content"/>
    <container name="example.root.container.actions" as="actions"/>
</block>
```

### Nested Container Components

```xml
<referenceContainer name="example.root.container.actions">
    <block class="BA\Svelte\Block\ComponentBlock"
           name="example.root.primary_action">
        <arguments>
            <argument name="svelte_component" xsi:type="string">Vendor_Module::actions/primary-button.svelte</argument>
            <argument name="label" xsi:type="string">Continue</argument>
        </arguments>
    </block>
</referenceContainer>
```

`svelte_component` uses Magento template notation. `Vendor_Module::example-root.svelte` resolves to `view/frontend/web/svelte/example-root.svelte`.

## Runtime Contract

Every mounted Svelte component receives:

- its normal props from XML arguments and `computed_props`
- `containers`: the full named container map
- `get_container(name)`: returns one named container as an array of component configs
- `has_container(name)`: returns whether a container contains components
- `default_container`: shorthand for the `default` container

Nested component configs are rendered through:

- `@modules/BA_Svelte/svelte/container-renderer.svelte`
- `@modules/BA_Svelte/svelte/renderer.svelte`

Example:

```svelte
<script>
    import ContainerRenderer from '@modules/BA_Svelte/svelte/container-renderer.svelte';

    let {
        heading = 'Example',
        get_container: getContainer,
    } = $props();
</script>

<section>
    <h2>{heading}</h2>
    <ContainerRenderer items={getContainer?.('content') ?? []} />
</section>
```

### Reflected `view_model` Props

You can also attach a standard Magento `view_model` object to a `SvelteBlock` or `ComponentBlock` in layout XML:

```xml
<argument name="view_model" xsi:type="object">Vendor\Module\ViewModel\Example</argument>
```

`BA_Svelte` reflects that object using Magento's getter conventions, similar to web API output processing:

- `getCountryOptions()` becomes `country_options`
- `isGuestCheckoutAllowed()` becomes `guest_checkout_allowed`
- `hasShippingPolicy()` becomes `shipping_policy`

Only public zero-argument getter methods are included. In practice, that means:

- use `get*`, `is*`, or `has*` methods
- add native return types or docblocks Magento reflection can read
- keep the view model intentionally simple and serialization-friendly

Reflected `view_model` data is merged into component props before `computed_props`. Explicit XML props still win over reflected values with the same key.

Quick example:

```xml
<block class="BA\Svelte\Block\SvelteBlock"
       name="example.root"
       template="BA_Svelte::root.phtml">
    <arguments>
        <argument name="svelte_component" xsi:type="string">Vendor_Module::example-root.svelte</argument>
        <argument name="view_model" xsi:type="object">Vendor\Module\ViewModel\Example</argument>
    </arguments>
</block>
```

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

class Example implements ArgumentInterface
{
    /**
     * @return string
     **/
    public function getMessage(): string
    {
        return 'Hello from Magento';
    }
}
```

```svelte
<script>
    let { message = '' } = $props();
</script>

<p>{message}</p>
```

That renders `Hello from Magento` on the page.


## `@modules` Imports

Svelte components in this setup often import shared code like:

- `@modules/BA_Svelte/svelte/container-renderer.svelte`
- `@modules/BA_Svelte/js/lib/i18n.js`
- `@modules/BA_SvelteCheckout/js/stores/checkoutStore.js`

`@modules` is a Vite alias defined in `view/frontend/web/svelte-src/vite.config.js`. It points at the deployed Magento static-content root for the current storefront, not at `app/code` directly.

That means an import like:

```js
import ContainerRenderer from '@modules/BA_Svelte/svelte/container-renderer.svelte';
```

resolves against the static-content tree:

```text
pub/static/frontend/<Vendor>/<Theme>/<Locale>/BA_Svelte/svelte/container-renderer.svelte
```

and that file exists because Magento copied `BA_Svelte/view/frontend/web/...` into `pub/static/.../BA_Svelte/...` during static content deploy.

### Why It Exists

This alias gives Svelte code one stable import base across modules:

- imports stay short and readable
- modules can import shared helpers from other Magento modules
- the build works against the same merged asset tree Magento actually serves

Without it, relative imports across modules would be brittle and tied to the temporary layout of the deployed static files.

### Is It Extensible

Yes, within the Magento static asset model.

Any module that publishes frontend assets into `view/frontend/web` becomes importable through `@modules/<Module_Name>/...` after static content deploy. In practice that means you can add:

- shared Svelte wrappers
- shared JS stores
- utility modules
- leaf Svelte components intended for reuse

Example:

```js
import { minicartStore } from '@modules/BA_SvelteMinicart/js/stores/minicartStore.js';
```

### Extension Boundary

`@modules` is only a path alias. It does not add a package system, versioning layer, or public/private API enforcement by itself.

So it is extensible in the sense that more modules can expose importable files, but those imports should still be treated as intentional API surface. A good rule is:

- files under `svelte/` and `js/` that are documented in a module README are fair to reuse
- files under `svelte-src/` are build/runtime internals unless explicitly documented otherwise

If you need stricter extensibility later, the next step would be documentation and conventions around which `@modules/<Module>/...` paths are public, not changing the alias itself.

## Config Resolution

Dynamic values come from resolver-backed config arrays.

Built-in resolvers:

- `url`: generates a frontend URL from `path`
- `asset`: generates a static asset URL from `file`
- `translate`: resolves a translated string from `text`

Use:

- normal XML arguments plus `computed_props` on `SvelteBlock`
- normal XML arguments plus `computed_props` on `ComponentBlock`


## Shared HTTP Helper

`BA_Svelte` exposes a small JSON-oriented fetch wrapper at:

```js
import { requestJson } from '@modules/BA_Svelte/js/lib/http.js';
```

It standardizes the common frontend request defaults used across modules:

- `credentials: 'same-origin'`
- `Accept: application/json`
- `X-Requested-With: XMLHttpRequest`
- automatic JSON body serialization for plain objects and arrays
- safe JSON response parsing, including empty `204` responses
- consistent error handling with optional response validation hooks

Example:

```js
const payload = await requestJson('/customer/section/load', {
    query: {
        sections: 'cart',
        force_new_section_timestamp: 'true',
    },
    errorMessage: 'Unable to load cart section.',
});
```

Module-specific wrappers can stay thin and add only local behavior, such as custom headers or success flags:

```js
import { requestJson } from '@modules/BA_Svelte/js/lib/http.js';

export function requestWishlist(url, params = {}) {
    return requestJson(url, {
        query: params,
        headers: {
            'X-BA-Wishlist-Request': 'true',
        },
        validateResponse: (payload, response) => response.ok && payload?.success !== false,
        errorMessage: 'Wishlist request failed.',
    });
}
```

## Build Workflow

### Normal Magento flow

1. Run `bin/magento setup:static-content:deploy`
2. `BA_Svelte` detects deployed storefront roots containing `BA_Svelte/svelte-src/src/main.js`
3. For each root, it runs `npm run build` inside `BA_Svelte/view/frontend/web/svelte-src`

### Manual build

```bash
cd src/app/code/BA/Svelte/view/frontend/web/svelte-src
npm run build
```

If you need to target a specific deployed storefront root manually:

```bash
cd src/app/code/BA/Svelte/view/frontend/web/svelte-src
SCD_ROOT=/absolute/path/to/pub/static/frontend/<Vendor>/<Theme>/<Locale> npm run build
```

## Notes

- `assets.phtml` deduplicates the shared CSS and JS bundle if multiple Svelte modules include it on the same page.
- `ComponentBlock` should be used for nested pluggable parts. `SvelteBlock` should be used for root mounts that render `BA_Svelte::root.phtml`.
