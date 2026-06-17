# BA_Svelte

`BA_Svelte` is the shared Magento 2 Svelte platform for BA storefront modules.

## What You Build With It

In practice a BA storefront module built on `BA_Svelte` usually looks like this:

1. Layout XML declares a root `SvelteBlock`
2. That root points at a `.svelte` component in your module
3. XML arguments, a `view_model`, and `computed_props` become component props
4. Optional view xml child blocks/containers become named Svelte slots
5. The shared runtime mounts the root component on the page
6. Your Svelte code imports shared BA platform helpers through `@modules`

That keeps the authoring model simple:

- normal Magento layout and blocks
- normal Svelte components
- no extra registry file
- no second naming system
- Magento-native `before`, `after`, `move`, and `remove` still work

## The Main Pieces

- `BA\Svelte\Block\SvelteBlock`
  Any svelte block on frontend. Can be root or child components.
- `BA\Svelte\Block\SvelteLink`
  Root-style Svelte block for sorted Magento link collections such as `top.links`.
- `view/frontend/templates/root.phtml`
  Emits the `.svelte-root` wrapper, serialized config, and optional server fallback markup.
- `view/frontend/web/svelte-src`
  Shared Vite source used to build the runtime bundle against the deployed static-content tree.
- `view/frontend/web/js/lib/state.js`
  Public customer-section and store hydration facade.
- `view/frontend/web/js/lib/events.js`
  Public storefront event facade.
- `view/frontend/web/js/lib/messages.js`
  Public message and Magento fragment update facade.
- `view/frontend/web/js/lib/forms.js`
  Public validation and AJAX form facade.
- `view/frontend/web/js/lib/commerce.js`
  Public add-to-cart facade.
- `view/frontend/web/js/lib/magento.js`
  Public Magento URL and JSON request helper facade.
- `view/frontend/web/js/lib/i18n.js`
  Public translation helper.
- `BuildSveltePlugin`
  Hooks into `setup:static-content:deploy` and builds the merged storefront Svelte bundle in each deployed static-content root that contains `BA_Svelte`.

## Start A New Root Mount

The normal starting point is one root block in layout XML.

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

`svelte_component` uses Magento template notation:

- `Vendor_Module::example-root.svelte`

That resolves to:

- `view/frontend/web/svelte/example-root.svelte`

The matching root component can then read normal props plus the container helpers:

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

## Add Magento-Native Extension Points

Obviously, as its just view xml, a second module can extend the xml we added above:

```xml
<referenceContainer name="example.root.container.actions">
    <block class="BA\Svelte\Block\SvelteBlock"
           name="example.root.primary_action">
        <arguments>
            <argument name="svelte_component" xsi:type="string">Vendor_Module::actions/primary-button.svelte</argument>
            <argument name="label" xsi:type="string">Continue</argument>
        </arguments>
    </block>
</referenceContainer>
```

Given the parent module uses `$state` in a seperate file imported using `@modules`, you can bring that in, and change the state from your added component.

Every mounted component receives:

- normal props from XML arguments and `computed_props`
- `containers`
- `get_container(name)`
- `has_container(name)`
- `default_container`

Render nested configs through:

- `@modules/BA_Svelte/svelte/container-renderer.svelte`
- `@modules/BA_Svelte/svelte/block-renderer.svelte`
- `@modules/BA_Svelte/svelte/renderer.svelte`

If you need to render one child block directly and optionally override props inline:

```svelte
<script>
    import BlockRenderer from '@modules/BA_Svelte/svelte/block-renderer.svelte';

    let {
        default_container: defaultContainer,
    } = $props();
</script>

<BlockRenderer block={defaultContainer} value={row.value} />
```

You can also target a child by layout block name:

```svelte
<BlockRenderer block="price_renderer" blocks={defaultContainer} value={row.value} />
```

## Add A Static HTML Fallback

Root mounts can optionally render server HTML first and then let the runtime progressively enhance it.

To do that, add a normal Magento child block with alias `fallback` under the root `SvelteBlock`.

Example:

```xml
<block class="BA\Svelte\Block\SvelteBlock"
       name="example.root"
       template="BA_Svelte::root.phtml">
    <arguments>
        <argument name="svelte_component" xsi:type="string">Vendor_Module::example-root.svelte</argument>
    </arguments>

    <block class="Magento\Framework\View\Element\Template"
           name="example.root.fallback"
           as="fallback"
           template="Vendor_Module::example/fallback.phtml"/>
</block>
```

Behavior:

- the fallback markup stays visible initially
- BA_Svelte mounts the Svelte app into a hidden host inside the same root wrapper
- after the first successful client render, the runtime removes the fallback and reveals the mounted app
- if config parsing or mount fails, the fallback stays in place

This is root-mount behavior only. It is not a Svelte hydration contract and it does not preserve DOM identity between server HTML and the client component.

## Use A Svelte Mount In Sorted Link Collections

If the component needs to participate in link collections that expect `SortLinkInterface`, use `BA\Svelte\Block\SvelteLink`.

```xml
<referenceBlock name="top.links">
    <block class="BA\Svelte\Block\SvelteLink"
           name="example.account.link"
           after="my-account-link">
        <arguments>
            <argument name="sortOrder" xsi:type="number">60</argument>
            <argument name="svelte_component" xsi:type="string">Vendor_Module::account-link.svelte</argument>
            <argument name="view_model" xsi:type="object">Vendor\Module\ViewModel\AccountLink</argument>
        </arguments>
        <action method="setTemplate">
            <argument name="template" xsi:type="string">Vendor_Module::link.phtml</argument>
        </action>
    </block>
</referenceBlock>
```

Use this for:

- `top.links`
- customer account link groups
- theme-specific link builders that sort children through Magento link APIs

## Pass Data Into Components

There are three normal ways to get data into a component:

### 1. Plain XML Arguments

Any non-reserved XML argument on a `SvelteBlock` is normalized into component props.

```xml
<argument name="heading" xsi:type="string">Example</argument>
<argument name="show_summary" xsi:type="boolean">true</argument>
```

### 2. `view_model`

You can attach a Magento `view_model` and let `BA_Svelte` reflect its getter methods into props.

```xml
<argument name="view_model" xsi:type="object">Vendor\Module\ViewModel\Example</argument>
```

Example view model:

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

That becomes:

- `message`

Reflection rules:

- `getCountryOptions()` becomes `country_options`
- `isGuestCheckoutAllowed()` becomes `guest_checkout_allowed`
- `hasShippingPolicy()` becomes `shipping_policy`

Only public zero-argument getter methods are included. Reflected `view_model` props are merged before `computed_props`, and explicit XML props still win if keys collide.

### 3. `computed_props`

Use resolver-backed props for values that should be calculated at render time.

Built-in resolvers:

- `url`
- `asset`
- `translate`

Example:

```xml
<argument name="computed_props" xsi:type="array">
    <item name="endpointUrl" xsi:type="array">
        <item name="resolver" xsi:type="string">url</item>
        <item name="path" xsi:type="string">rest/V1/example</item>
    </item>
</argument>
```

### Add a new `computed_props` using di pool

If the built-in resolvers are not enough, add your own resolver class in your module and register it into `BA\Svelte\Model\PropResolverPool` through DI.

Example resolver:

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\Model\PropResolver;

use BA\Svelte\Api\PropResolverInterface;
use Magento\Framework\View\Element\Template;

class ExamplePropResolver implements PropResolverInterface
{
    public function resolve(string $propName, array $definition, Template $block): mixed
    {
        $value = $definition['value'] ?? null;

        return is_string($value) ? strtoupper($value) : null;
    }
}
```

Register it in your module `etc/di.xml`:

```xml
<type name="BA\Svelte\Model\PropResolverPool">
    <arguments>
        <argument name="resolvers" xsi:type="array">
            <item name="example" xsi:type="object">Vendor\Module\Model\PropResolver\ExamplePropResolver</item>
        </argument>
    </arguments>
</type>
```

Then use it in layout XML:

```xml
<argument name="computed_props" xsi:type="array">
    <item name="exampleValue" xsi:type="array">
        <item name="resolver" xsi:type="string">example</item>
        <item name="value" xsi:type="string">hello world</item>
    </item>
</argument>
```

Rules for custom resolvers:

- implement `BA\Svelte\Api\PropResolverInterface`
- return data that can be safely serialized into component props
- read your resolver-specific config from the `$definition` array
- use the provided Magento `$block` when you need store-aware URLs, assets, or request/render context

## Inspect The Final Prop Contract

If you want to see what a mounted block will actually receive, use the CLI helper:

```bash
bin/magento ba:svelte:props checkout_index_index checkout.root
```

The command loads `default` plus the handle you pass, resolves the named BA_Svelte block, and prints a typed Svelte 5 `$props()` snippet.

Use `--store=<code>` when the layout or computed props depend on store scope.

## Write Svelte Code Against `@modules`

Svelte modules in this setup import shared code through the `@modules` Vite alias.

Example:

```js
import ContainerRenderer from '@modules/BA_Svelte/svelte/container-renderer.svelte';
import { _ } from '@modules/BA_Svelte/js/lib/i18n.js';
import { buildRestUrl } from '@modules/BA_Svelte/js/lib/magento.js';
```

`@modules` points at the deployed static-content root for the current storefront, not at `app/code` directly.

So:

```js
import ContainerRenderer from '@modules/BA_Svelte/svelte/container-renderer.svelte';
```

resolves against:

```text
pub/static/<area>/<Vendor>/<Theme>/<Locale>/BA_Svelte/svelte/container-renderer.svelte
```

Why this matters:

- imports stay short
- one BA module can reuse public code from another BA module
- the build runs against the same merged static asset tree Magento serves

Rules:

- files under `svelte/` and documented `js/` entrypoints are fair to reuse
- files under `svelte-src/` are build/runtime internals unless documented otherwise

## The Public JS APIs You Should Actually Use

Most feature modules should build on the public facades below and avoid `js/lib/runtime/*`, `http.js`, and `url.js` directly.

Current stability note:

- `state.js`, `magento.js`, and `i18n.js` are already used internally and are the most proven `js/lib` surfaces today.
- The other `js/lib` public facades below are still very alpha. Their API shape and behavior will change.

### State

```js
import {
    createCustomerSectionStore,
    getCachedCustomerSection,
    loadCustomerSection,
    loadCustomerSections,
    reloadCustomerSection,
    reloadCustomerSections,
    syncCustomerSectionsCache,
} from '@modules/BA_Svelte/js/lib/state.js';
```

Use `state.js` for:

- customer sections
- cache-first reads from `mage-cache-storage`
- forced reloads through `customer/section/load`
- shared section store patterns

Rules:

- use `loadCustomerSection()` for normal hydration
- use `reloadCustomerSection()` when you want cache bypass
- use `createCustomerSectionStore()` when a feature wants one reusable store contract
- if you update section payloads locally, persist them with `syncCustomerSectionsCache()`

Migration shim that still exists:

- `@modules/BA_Svelte/js/lib/customer-sections.js`

This shim is also very alpha and can change.

### Translation

```js
import { _ } from '@modules/BA_Svelte/js/lib/i18n.js';
```

This uses `window.baTranslate()` and falls back to the original string when no Svelte translation entry exists.

`BA_Svelte` scans Svelte-backed `.svelte`, `.ts`, and `.js` source files on the Magento side and exposes a translated hashmap to the storefront runtime.

Use `_('Literal phrase')` for any Svelte-authored text that needs Magento translation. Dynamic expressions, concatenated strings, and template literals with expressions are not extracted into the BA_Svelte translation map, so they fall back to the original source text.

Example:

```svelte
<script>
    import { _ } from '@modules/BA_Svelte/js/lib/i18n.js';

    let { buttonLabel = 'Open size guide' } = $props();
</script>

<button type="button">
    {_(buttonLabel)}
</button>
```

### Price Rendering

If a module needs standard price output, use the shared price component:

```js
import Price from '@modules/BA_Svelte/svelte/catalog/price.svelte';
```

This component handles:

- normal final price rendering
- special price plus old price output
- locale-aware currency formatting
- optional minimal price / `As low as` output
- optional minimal-price links back to the product URL

Main props:

- `final_price`
- `regular_price`
- `has_special_price`
- `currency_code`
- `currency_symbol`
- `locale`
- `precision`
- `show_minimal_price`
- `use_link_for_as_low_as`
- `minimal_price`
- `minimal_price_label`
- `product_url`
- `special_price_label`
- `old_price_label`

Example:

```svelte
<script>
    import Price from '@modules/BA_Svelte/svelte/catalog/price.svelte';

    let price = {
        final_price: 79.99,
        regular_price: 99.99,
        has_special_price: true,
        currency_code: 'GBP',
        locale: 'en-GB',
        special_price_label: 'Special Price',
        old_price_label: 'Was',
    };
</script>

<Price {...price} />
```

The component is for rendering only. Decide what the price data should be in Magento or in your owning feature module, then pass the normalized values in as props.

### Events

Status: very alpha. Expect change.

```js
import {
    AJAX_ADD_TO_CART_ERROR_EVENT,
    AJAX_ADD_TO_CART_EVENT,
    CATALOG_ADD_TO_CART_REDIRECT_EVENT,
    CUSTOMER_SECTIONS_UPDATED_EVENT,
    STOREFRONT_MESSAGE_EVENT,
    dispatchCompatEvent,
    dispatchStorefrontEvent,
    listenForStorefrontEvent,
} from '@modules/BA_Svelte/js/lib/events.js';
```

Use `events.js` for:

- canonical BA storefront events
- compatibility events where Magento or jQuery listeners still need to hear them

Rules:

- use namespaced `ba:*` event names for platform events
- keep payloads plain objects
- use `dispatchStorefrontEvent()` and `listenForStorefrontEvent()` instead of ad hoc `CustomEvent` helpers
- use `dispatchCompatEvent()` only when compatibility listeners matter

### Messages

Status: very alpha. Expect change.

```js
import {
    STORE_MESSAGE_TYPES,
    applyMessagePayload,
    dispatchStorefrontMessage,
    updateMessageFragment,
} from '@modules/BA_Svelte/js/lib/messages.js';
```

Use `messages.js` for:

- client-side success, error, and info messages
- Magento message fragments returned from AJAX responses

Rules:

- use `dispatchStorefrontMessage()` for client-generated messages
- use `applyMessagePayload()` when a server response may contain messages or message HTML
- `[data-placeholder="messages"]` is the default fragment target unless a feature deliberately overrides it

### Forms

Status: very alpha. Expect change.

```js
import {
    applyValidationRules,
    createAjaxFormController,
    redirectTo,
    resolveFormElement,
    validateForm,
} from '@modules/BA_Svelte/js/lib/forms.js';
```

Use `forms.js` for:

- validation
- AJAX submit lifecycle
- loading and disabled states
- redirect behavior after submit

Rules:

- `validateForm()` is the replacement for manual Magento validation bootstraps in Svelte-driven flows
- `createAjaxFormController()` is the main AJAX form API
- the controller owns `aria-busy`, deduplicated submits, and button state
- redirects should go through `redirectTo()`

### Commerce

Status: very alpha. Expect change.

```js
import {
    createAddToCartController,
} from '@modules/BA_Svelte/js/lib/commerce.js';
```

Use `commerce.js` for:

- add-to-cart behavior

Rules:

- `createAddToCartController()` is the official JS API
- `<ba-add-to-cart>` is the official declarative markup API for the same flow
- stock patching, fragment refreshes, and compatibility events are internal implementation details behind that contract

Migration shim that still exists:

- `@modules/BA_Svelte/js/lib/catalog/add-to-cart.js`

This shim is also very alpha and can change.

### Magento Utilities

```js
import {
    buildRestUrl,
    buildStorefrontUrl,
    requestMagentoJson,
} from '@modules/BA_Svelte/js/lib/magento.js';
```

Use `magento.js` for:

- storefront URL construction
- store-scoped REST URLs
- generic JSON transport to Magento

Rules:

- prefer server-resolved URLs via props when the route is already known during render
- use `buildRestUrl()` instead of feature-local `rest/${storeCode}/V1/...` glue
- use `requestMagentoJson()` for transport concerns only, not feature-specific business rules

## Use Declarative Runtime Elements Where They Fit

The runtime bootstrap itself is internal, but these markup contracts are public:

- `<ba-collapsible>`
- `<ba-accordion>`
- `<ba-modal>`
- `<ba-add-to-cart>`
- `<ba-quantity-switch>`

Example modal:

```html
<ba-modal trigger=".open-size-guide">
    <h2>Size guide</h2>
    <button type="button" data-ba-modal-close>Close</button>
</ba-modal>
```

Example add-to-cart wrapper:

```html
<ba-add-to-cart>
    <form data-role="tocart-form" action="/checkout/cart/add" method="post">
        ...
        <button type="submit" class="action tocart primary">
            <span>Add to Cart</span>
        </button>
    </form>
</ba-add-to-cart>
```

`<ba-quantity-switch>` is also a public contract. Its controller lives behind the runtime, so feature modules should use the element instead of importing quantity-switch internals.

## Replace Magento JS With BA_Svelte Contracts

When you are modernizing a module, these are the preferred replacements:

| Replace this | Use this BA_Svelte surface | Do not use |
| --- | --- | --- |
| `customer-data` wrappers | `state.js` | feature-local customer section adapters |
| `Magento_Ui/js/modal/modal` | `Popup.svelte` or `<ba-modal>` | runtime modal internals |
| `Magento_Catalog/js/validate-product` | `forms.js` plus `commerce.js` | feature-local validation bootstraps |
| `catalogAddToCart` | `commerce.js` or `<ba-add-to-cart>` | feature-local add-to-cart wrappers |
| repeated REST URL glue | `magento.js` | feature-local URL helpers |
| feature-local JSON fetch wrappers | `magento.js` | `http.js` from feature code |
| hand-rolled `CustomEvent` helpers | `events.js` | inline event utilities everywhere |
| feature-local flash/server message plumbing | `messages.js` | duplicate message fragment implementations |

Internal paths that should not appear in new feature code:

- `@modules/BA_Svelte/js/lib/runtime.js`
- `@modules/BA_Svelte/js/lib/runtime/*`
- `@modules/BA_Svelte/js/lib/http.js`
- `@modules/BA_Svelte/js/lib/url.js`

## Popup Pattern

The default popup direction is native.

Use:

- `@modules/BA_Svelte/svelte/popup.svelte`
- `<ba-modal>`

Example:

```svelte
<script>
    import Popup from '@modules/BA_Svelte/svelte/popup.svelte';

    let popup = null;
</script>

<button type="button" onclick={() => popup?.open()}>
    Open size guide
</button>

<Popup bind:this={popup} title="Size guide">
    <p>Use your usual fit. This product runs true to size.</p>
</Popup>
```

Exit criteria for a migrated popup:

- it does not rely on Magento modal widget lifecycle
- it can render with native dialog semantics
- it can move to `Popup.svelte` or `<ba-modal>` without behavior loss

## Build And Deploy

### Normal Magento Flow

1. Run `bin/magento setup:static-content:deploy`
2. `BA_Svelte` detects deployed storefront roots containing BA_Svelte assets
3. For each deployed root, it runs `npm run build` inside `view/frontend/web/svelte-src`

### Manual Build

```bash
cd src/app/code/BA/Svelte/view/frontend/web/svelte-src
npm run build
```

If you need to point at one deployed storefront root explicitly:

```bash
cd src/app/code/BA/Svelte/view/frontend/web/svelte-src
SCD_ROOT=/absolute/path/to/pub/static/<area>/<Vendor>/<Theme>/<Locale> npm run build
```

## Notes

- `assets.phtml` deduplicates the shared CSS and JS bundle if multiple Svelte modules include it on the same page.
- `SvelteBlock` is for root mounts that render `BA_Svelte::root.phtml` and nested pluggable components inside root containers.
- `SvelteLink` is for sorted link collections.
- Use `.svelte-root` blocks or the documented custom elements for new work. Do not build feature modules against runtime internals.
