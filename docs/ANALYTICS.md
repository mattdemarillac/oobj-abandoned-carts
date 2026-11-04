# Analytics (GA4)

What the plugin sends to Google Analytics 4 today, and what to add to tell whether "Email me my cart" brings shoppers back.

## Sent today

| Event | When | Parameters |
|---|---|---|
| `save_cart` | The form sent the cart (the REST route answered 200). | None |

It's sent with `gtag()`, only when the page has it (for example through Google Analytics for WooCommerce or Site Kit). For another analytics tool, listen for the DOM event `oobj-ac:saved`, which the form fires at the same time:

```js
document.addEventListener( 'oobj-ac:saved', () => plausible( 'Saved cart' ) );
```

Nothing is sent when the trap field was filled in, because the browser's request still gets a 200: that's deliberate, and bots rarely run the page's scripts anyway.

In GA4, mark `save_cart` as a **key event** if you want it in conversion reports.

## To add

| What | Why | How |
|---|---|---|
| `restore_cart` event | Counts shoppers who came back from the email. | After the restore redirect, the cart page sends it once: the plugin would leave a one-time flag in the session, and a small script sends the event and clears it. Parameters: `items` (count), `days_since_saved`. |
| Campaign tags on the email's link | Lets GA4 attribute the visit, and any purchase in it, to the email. | Add `utm_source=saved-cart&utm_medium=email&utm_campaign=email-my-cart` to the link. The cart page isn't cached, so the tags reach the page. |
| `save_cart_error` event | Shows whether shoppers hit the limits, the bot check or an empty cart. | Send it from the form's error path with the error code as `error` (`oobj_ac_limit`, `oobj_ac_bot_check`, …). |

## Reports to build

- **Saves:** `save_cart` by day, and per 100 sessions that reached the cart (`view_cart`).
- **Come-backs:** `restore_cart` ÷ `save_cart`, the share of emails that bring the shopper back.
- **Revenue from the email:** purchases and revenue in sessions with `session_source` = `saved-cart` (with the campaign tags above).
- **Time to come back:** `days_since_saved` on `restore_cart`.

The admin list's **Emailed** column shows the same thing per cart (sent, and came back), without GA.
