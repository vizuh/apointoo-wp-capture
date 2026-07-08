# Feature: derivação de canal (ft/lt) — portar do ClickTrail

**Origem:** integração Tallk (2026-06-24). O tracker do Apointoo Capture hoje guarda
UTM + click-ids crus, mas **não classifica canal** (paid_search / paid_social /
organic_search / organic_social / referral / direct). Tive que derivar isso
client-side no webhook do Tallk (`channelOf` em `clients/Tallk/_audit/lead-capture-v3.js`).

**Pedido (Hugo):** derivação de canal deve ser **padrão** no Apointoo, em 3 camadas,
seguindo o padrão do ClickTrail:

1. **Tracker (client JS):** `channelOf(source, medium, referrer, clickIds)` →
   expor `ftChannel`/`ltChannel` em `window.apointooTracking()` (e em
   `firstTouch.channel` / `lastTouch.channel`).
2. **Plugin (server, `includes/capture/class-attribution.php`):** chaves de canal
   no field model + resolver server-side de fallback (pra sessões sem JS), igual
   ao `class-gf-channel-resolver.php` do ClickTrail.
3. **Dashboard:** persistir + exibir canal; usar na atribuição de conversão.

**Referência pra portar (ClickTrail, plugin `click-trail-handler`):**
- `assets/js/clicutcl-attribution.js` → `SEARCH_REFERRER_RULES` +
  `SOCIAL_REFERRER_RULES` + classificação → grava `ct_ft_channel`.
- `includes/integrations/forms/class-gf-channel-resolver.php` → fallback server-side.

**Lógica já validada (de `lead-capture-v3.js`, testada):**
```
paid_social   : fbclid  OU (paid + source social)
paid_search   : gclid/gbraid/wbraid/msclkid  OU  medium ~ cpc|ppc|sem
organic_social: source/referrer ~ facebook|instagram|linkedin|tiktok|youtube|x
organic_search: source google|bing  OU  referrer ~ google|bing|yahoo|duckduckgo|ecosia
direct        : sem source e sem referrer
referral      : resto
```

Status: **client-side no Tallk como stopgap.** Promover a feature de produto
elimina o stopgap e padroniza todos os tenants.
