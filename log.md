# Log

## [2026-07-27] decision | Path B marked DEFERRED after an audit nearly deleted it as dead code

A tracking-coverage audit across the Apointoo family reported this plugin's SHA-256 PII hasher as dead code with zero callers and recommended deleting it. A 4-member review agreed unanimously. **Both were wrong, and the deletion did not happen.**

Two compounding errors. First, the "zero callers" evidence was `grep -rn "Pii_Hasher"` against a class actually named `PII_Hasher` — a case-sensitive miss. It is referenced from `includes/integrations/forms/class-abstract-form-adapter.php`. Second, and the one that mattered: `PII_Hasher` genuinely is unreachable today, but only because it belongs to **Path B**, the intended capture path, parked behind a stub transport. `includes/capture/class-sdk-transport.php` already carries a clear `@todo` blocked on the public capture contract (`vizuh/apointoo-sdk#116`) + ADR-021 auth, including the consent gate the real transport must honour. `includes/capture/interface-transport.php:17` documents the same Path B server-secret model. Path A (`intake_send()` → POST `{lead, attribution}` to the tenant intake URL, raw PII by design because the dashboard owns hashing and upload so WordPress is never a second conversion source) is the interim path that ships.

What made three independent readers misdiagnose it: the abstract adapter's class docblock asserted subclasses "call `capture()`" when no subclass does. Fixed that docblock to name both paths and their status, and added a `DEFERRED` marker on `capture()` citing the blocking issue and recording this near-miss, per the workspace rule that parked work carries an explicit greppable marker. Two docblocks plus this log; no executable code, `class-pii-hasher.php` untouched.

A review pass on the fix caught the fix repeating the original sin: the first draft of the replacement docblock described Path A's payload shape, PII policy and dashboard-side ownership — all true, none of it visible in this file, and all of it able to go stale without anyone touching this class. Trimmed to routing status only, with each path's own docblock left as the source of truth for its behaviour. The reachability claim was likewise narrowed from "unreachable" (a whole-program assertion) to "no live caller / Path B-only helpers".

Standing rule this produced: "zero callers" is a claim about a language's resolution rules, not a grep result. Search case-insensitively, then confirm the symbol is not part of a documented-but-unwired design before proposing deletion.

## [2026-08-22] security | Align consent defaults and clear cached attribution fields
